# OxyAI Oxygen Architecture

OxyAI Oxygen is packaged as one WordPress plugin.

The plugin contains both layers:

- The vendored converter kernel in `vendor/oxygen-html-converter`.
- The OxyAI product layer in `src`, `assets`, and REST/admin/builder modules.

There is no runtime dependency on a separate `oxygen-html-converter` plugin. The cloned upstream repository in the parent workspace is only a reference/fork source for maintenance.

## Runtime Flow

```text
Admin page / Oxygen builder / MCP client
  -> OxyAI REST controller
  -> SourceBundle + validation
  -> AI provider if requested
  -> ConverterKernelAdapter
  -> Vendored oxygen-html-converter kernel
  -> OxygenPayloadAdapter
  -> Builder paste payload, direct Oxygen page mutation, preview, audit, or remote tool response
```

## Main Modules

- `Admin\AdminPage`: WordPress Tools page and provider settings.
- `Assets\AssetLoader`: Admin and Oxygen builder scripts/styles.
- `Ai\*`: OpenAI, Anthropic, OpenAI-compatible generation adapters, Plan Mode, and Triple Shot variants.
- `Source\*`: Structured HTML/CSS/JS source bundle contract.
- `Conversion\*`: Adapter around the vendored Oxygen converter kernel.
- `Rest\*`: Preview, convert, generate, history, presets, and MCP-style routes.
- `History\HistoryStore`: Opt-in local history.
- `Presets\PresetStore`: Built-in and saved design presets.
- `Inspirations\SiteInspirationStore`: First-party prompt inspiration directions.
- `Builder\*`: Builder-context detection and insertion payload preparation.
- `Oxygen\OxygenPageMutationService`: direct Oxygen tree reads/writes, automatic restore backups, append/replace/replace-node operations, and render-cache refresh.

## UX Surfaces

- Admin product console: workflow cards, source composer, Plan Mode, Triple Shot variants, site inspirations, conversion options, AI settings, presets, result audit, page picker, direct apply/dry-run controls, and MCP endpoint guidance.
- Oxygen builder panel: mode selector for paste/generate/replace/chat, selected-element target capture, Plan Mode, Triple Shot variants, site inspiration steering, context notes, chat thread, source fields, option notes, direct convert-and-insert, direct Save to Page, and clipboard fallback.
- Remote tooling: one `/wp-json/oxyai/v1/mcp` endpoint exposing MCP-style initialization, tool listing, generation, Plan Mode, Triple Shot variants, site inspirations, preview, conversion, prompt instructions, page context, page-specific staging, direct page apply, Oxygen tree reads, restore backups, replacement-payload preparation, insertion-payload preparation, and preset listing.

## Direct Page Mutation

MCP clients can now work on live Oxygen pages without relying on a browser paste event:

1. `list_oxygen_pages` finds a target.
2. `get_page_context` or `get_oxygen_tree` inspects current page state.
3. `apply_html_to_oxygen_page` converts HTML/CSS/JS and applies it with `operation=append`, `replace`, or `replace_node`.
4. `dryRun=true` returns the proposed merged tree without saving.
5. A successful write stores an OxyAI restore backup in post meta before the Oxygen tree is changed.
6. `restore_oxygen_page_backup` rolls back a previous direct write.

The mutation service writes Oxygen 6 data through `Breakdance\Data\set_meta` when available and falls back to `_oxygen_data` post meta. It clears Oxygen dependency/CSS caches after writes.

For active builder sessions, the sidebar still offers builder paste insertion because it preserves the user's current unsaved canvas state. Save to Page is available when direct persisted-page mutation is the desired workflow.

## Oxydance Parity Targets

Public Oxydance Pilot documentation describes prompt generation, HTML/CSS/JS conversion, BYOK providers, selected-element edit mode, Plan Mode, Triple Shot, site inspirations, global settings, Headspin tokens, history, self-hosted OpenAI-compatible providers, and permission tiers. OxyAI's architecture keeps the same core workflow while adding MCP/direct-page tools for Codex and other agents.

Implemented in OxyAI:

- Prompt-to-source generation.
- HTML/CSS/JS to native Oxygen conversion.
- BYOK OpenAI, Anthropic, and OpenAI-compatible providers.
- Builder sidebar with selected target capture and chat.
- Plan Mode question stepper.
- Triple Shot variant fan-out.
- Curated first-party site inspiration library.
- History, presets, MCP token generation, page context, staged handoff, direct page apply, dry-run, and restore backups.

Next parity work:

- Side-by-side Triple Shot comparison and visual preview.
- Deeper Oxygen global color/typography/token extraction.
- Token usage accounting per generation.

## Security Model

- Admin-only by default through `manage_options`.
- Capability can be customized with `oxyai_oxygen_required_capability`.
- REST calls require WordPress REST nonce for browser use.
- MCP calls require either an authenticated admin session or the configured `x-oxyai-token`.
- AI keys are saved through the plugin settings and encrypted with site salts when OpenSSL is available.
- History is opt-in and excludes secrets.
