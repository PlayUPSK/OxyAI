# Token-efficient MCP tools (v0.5.0)

This release adds read/edit primitives that make agent-driven page edits cheaper
(fewer tokens) and safer, plus Streamable HTTP transport affordances. All new
behaviour lives in `OxygenPageMutationService` (+ `OxygenTreeToolsTrait`),
`OxygenElementCapabilityService`, and `McpController`.

## Reads

- **`get_oxygen_tree` outline view** — returns a compact outline
  (`{id, type, label, parentId, childIds, classes}`) with `design` data and
  inline SVG stripped. ~10–20× smaller than the raw tree.
  - `view`: `"outline"` (default) | `"full"`
  - `nodeId`: focus a single node/subtree
  - `depth`: limit outline depth
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
  `changedNodeIds`. Default is `"full"` on the existing apply tools (back-compat,
  still also returns `outline`); `apply_oxygen_operations` defaults to `"outline"`.

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

`tests/smoke/mcp-tree-tools.php` exercises the pure tree logic (outline
stripping, find, batch ops mirroring the email/phone stack, CSS-block
idempotency, `idMap`, root-delete rejection). `tests/smoke/mcp-tool-surface.php`
asserts the controller wiring. Both run under plain PHP 8.4 with WP stubs.
