# Token-efficient MCP tools

This document describes read/edit primitives that make agent-driven page edits
cheaper (fewer tokens) and safer, plus Streamable HTTP transport affordances.
All behaviour lives in `OxygenPageMutationService` (+ `OxygenTreeToolsTrait`),
`OxygenElementCapabilityService`, `ElementWriteValidator`, `RateLimiter`, and
`McpController`.

## Recommended edit workflow

Agents should follow this loop and **never round-trip a full tree** to make an
edit:

1. **`get_oxygen_tree`** (`view:"outline"` or `view:"html"`) — see the page
   cheaply.
2. **`find_oxygen_nodes`** — locate the `nodeId`(s) to edit.
3. **`list_oxygen_element_capabilities(elementType)`** — before setting
   properties on an element type you have not already inspected this session.
   These are the exact rules the write validator enforces.
4. **`patch_oxygen_node`** (single node) or **`apply_oxygen_operations`**
   (multiple ops in one backup / one rate-limit hit).

## Registry-enforced writes (guardrail)

`ElementWriteValidator` consults the hand-curated capability registry on every
`update_node` / `patch_node` / `set_node_type` / `insert_node`:

- **Known element + contradictory write** → `422`-style reject naming the
  correct path/tool, e.g. *"Use design.spacing.padding (object with
  top/right/bottom/left), not design.padding"*, or *"Element X does not
  natively support the 'grid' design bucket"*, or (on insert) *"required
  content path(s) missing: content.content.text"*.
- **Unknown / runtime-only element** → allowed, with an `mcpWarnings`
  `unknown_element_type` entry so the agent knows the write was not vetted.
- **Anti-pattern guard** → raw `<style>`/`<script>`/landmark tags
  (`section/header/footer/main/article/nav/aside`) placed in a text/heading
  content property are rejected and routed to `apply_html_to_oxygen_page`.
  Inline tags (`span/a/strong/em/b/i/br`) and inline `style=` are allowed;
  `CssCode`/`CodeBlock`-style elements are exempt.
- Strictness is filterable via `oxyai_oxygen_write_validation_mode`
  (`strict` default | `warn` downgrades rejects to warnings).

## Single-node deep merge — `patch_oxygen_node`

`{postId, nodeId, data}` recursively deep-merges a partial `data` object into a
node: scalars and list arrays replace wholesale, associative arrays merge
key-by-key, and the node id plus untouched properties are preserved. Returns a
**compact confirmation only** — `{success, nodeId, changedPaths[], backupId}` —
never the tree. Also available as `{op:"patch_node",targetNodeId,data}` inside
`apply_oxygen_operations`.

## Write rate limiting

Live (non-dryRun) write tools share a transient-based sliding-window limiter
(`RateLimiter`), default **60 writes / 5 min**, filterable via
`oxyai_oxygen_mcp_write_rate_limit` (`['max'=>int,'window'=>seconds]`; `max=0`
disables). Exhaustion returns a `429`-style error with `retryAfter` and steers
the agent toward batching with `apply_oxygen_operations`.

## Reads

- **`get_oxygen_tree` outline view** — returns a compact outline
  (`{id, type, label, parentId, childIds, classes}`) with `design` data and
  inline SVG stripped. ~10–20× smaller than the raw tree (the
  `tests/smoke/token-byte-budget.php` smoke test asserts outline **and** html
  views are each <10% of the full-tree JSON on a representative ~40-node tree).
  - `view`: `"outline"` (default) | `"html"` | `"full"`
  - `view:"html"`: a type-aware **readable HTML reconstruction** of the tree
    (not a render). Each node emits a representative tag
    (`Section→<section>`, `Heading→<h{n}>`, `Text→<p>`, `Image→<img src alt>`,
    `Button/TextLink→<a href>`, containers→`<div>`, unknown→`<div data-type>`),
    every element annotated `data-node-id="N"`, text previews capped ~120 chars.
    The cheapest way for an agent to "see" a page layout. Honours
    `nodeId`/`depth` scoping.
  - `view:"full"` is **size-gated**: payloads over 50KB fall back to the
    outline with `{summarized: true, note: "...use nodeId/depth scoping or
    view:'html'"}`.
  - `nodeId`: focus a single node/subtree
  - `depth`: limit outline/html depth
  - `includeBackups`: include full backup payloads (default `false`; otherwise only `backupCount`)
- **`find_oxygen_nodes`** — `{type, textContains, class, hasLink}` → outline
  entries. Avoids fetching and scanning the whole tree.

## Edits

- **`apply_oxygen_operations`** — apply a sequence of node ops in **one call /
  one backup**. Ops: `update_node` (`set`/`unset` dot-paths), `set_node_type`,
  `delete_node`, `move_node`, `insert_node`, `upsert_css`, `remove_css`.
  Patching a few fields costs ~hundreds of bytes vs resending a whole subtree.
- **`upsert_css_block` / `remove_css_block` / `list_css_blocks`** — idempotent
  keyed `CssCode` blocks (marker comment `/* oxyai-css-block:KEY */`). Re-running
  with the same key replaces the block instead of stacking nodes.
- **`idMap` + `changedNodeIds`** on `apply_oxygen_json_to_page` /
  `apply_oxygen_operations`, plus a **`preserveIds`** option on
  `apply_oxygen_json_to_page` (keeps incoming ids when unique and
  non-colliding; `replace_node` still forces the root to `targetNodeId`).
- **`recompile: true`** documented on the apply tools (saves a separate
  `recompile_oxygen_css` call).
- **`dryRunView`** — `"outline"` returns only the compact outline +
  `changedNodeIds`. The default is now `"outline"` on **all** apply tools
  (previously `"full"` on `apply_html_to_oxygen_page` /
  `apply_oxygen_json_to_page`); pass `"full"` explicitly to also receive the
  whole proposed tree. Live (non-dryRun) writes always return compact
  confirmations (ids/paths/permalink), never trees.

## Capabilities

- **`list_oxygen_element_capabilities(elementType)`** now returns a focused
  payload — that element + its contract + a concrete `exampleNode` — instead of
  the multi-hundred-KB runtime catalog. Omit `elementType` for the full catalog.
  The focused view also surfaces `knownNativeSelectorGaps` (grid/flex-wrap are
  captured but **not** emitted by the Oxygen selector compiler, so use a class
  rule or `upsert_css_block` for those layouts).

## Transport (Streamable HTTP) — best-effort, verify against a live client

`McpController` now negotiates the client `protocolVersion`, returns an
`Mcp-Session-Id` header on `initialize`, and answers `GET`/`DELETE` on the MCP
endpoint with a spec-correct `405 + Allow: POST` (instead of WordPress' default
404, which some clients treat as a fatal transport error).

This targets the observed symptom where a client reports the server as
"connected" but enumerates zero tools. It is **best-effort** and should be
verified against the actual client after deploy. If a client still fails to load
tools, front the endpoint with [`mcp-remote`](https://www.npmjs.com/package/mcp-remote)
as a stdio↔HTTP bridge.

## Tests

All run under plain PHP 8.4 with WP stubs (`php tests/smoke/<name>.php`):

- `tests/smoke/mcp-tree-tools.php` — pure tree logic (outline stripping, find,
  batch ops, CSS-block idempotency, `idMap`, root-delete rejection).
- `tests/smoke/mcp-write-validation.php` — registry enforcement (valid passes /
  invalid rejected / unknown warns / warn-mode downgrade), required-content-path
  checks on insert, the raw-HTML anti-pattern guard (reject + CssCode exempt +
  inline tags allowed), `patch_node` deep-merge semantics, and the `view:"html"`
  reconstruction.
- `tests/smoke/rate-limiter.php` — sliding-window limiter, 429 shape, bucket
  isolation, `max=0` disable.
- `tests/smoke/token-byte-budget.php` — enforces the "10–20× smaller" claim
  (outline/html each <10% of full on a ~40-node tree).
- `tests/smoke/mcp-tool-surface.php` — controller wiring (tools defined +
  routed, rate limit + html view present).
