# OxyAI Oxygen

OxyAI Oxygen is a WordPress plugin that converts HTML, CSS, and JavaScript into native Oxygen 6 builder elements. It uses the public `oxygen-html-converter` project as the conversion kernel and adds a product layer for direct source conversion, AI generation, history, presets, builder UI, and MCP-style remote tooling.

This is one installable plugin. The converter kernel is vendored inside this plugin under `vendor/oxygen-html-converter`; users do not install a separate converter plugin.

## Requirements

- WordPress `7.0+`
- PHP `8.5+`
- Oxygen Builder `6.x`
- Administrator access by default

## Current Capabilities

- Admin conversion page for separate HTML, CSS, and JavaScript input.
- Oxygen builder modal for direct source import.
- Workflow-first UI showing manual conversion, AI generation, selected-content replacement, and remote/MCP usage.
- Native Oxygen builder chat with selected-element target capture and context notes.
- REST preview and conversion endpoints.
- OpenAI, Anthropic, and OpenAI-compatible provider adapters.
- Strict source-bundle validation before conversion.
- Opt-in history store.
- Design presets.
- Plan Mode for clarifying questions before generation.
- Triple Shot generation for three alternate source-bundle directions.
- First-party site inspiration directions for prompt steering.
- MCP-style REST tool endpoint for remote conversion workflows.
- Codex bridge tools for prompt instructions, page lookup, page context, staging generated payloads, direct page insertion, dry-run, and backup restore.
- MCP/Codex token is generated automatically and can be regenerated from the setup panel.

## Install

Copy `oxyai-oxygen` into `wp-content/plugins/`, activate it in WordPress, then open `Tools -> OxyAI Oxygen`.

The vendored converter kernel is under `vendor/oxygen-html-converter` and remains GPL-compatible.

See `docs/ARCHITECTURE.md` for the one-plugin architecture.

## Codex

The MCP token is generated automatically in `Tools -> OxyAI Oxygen`. Connect Codex to:

```text
/wp-json/oxyai/v1/mcp
```

Codex workflow:

1. Call `get_prompt_instructions`.
2. Optionally call `list_site_inspirations`, `plan_generation`, or `triple_shot_generation`.
3. Call `list_oxygen_pages` or `get_page_context`.
4. Generate HTML/CSS/JS for the target page.
5. For review inside Oxygen, call `convert_and_stage_page` with `postId`, `html`, `css`, and optional `js`.
6. For direct insertion, call `apply_html_to_oxygen_page` with `dryRun=true`, then call it again with `dryRun=false` after approval.
7. Use `list_oxygen_page_backups` and `restore_oxygen_page_backup` if you need to roll back a direct write.

The admin composer also has a page picker. You can paste raw HTML/CSS/JS, choose a page, run Dry run, then Apply to page. OxyAI writes Oxygen data directly and creates a restore backup before changing the page.
