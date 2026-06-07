# OxyAI Oxygen

> AI-assisted HTML, CSS, and JavaScript to native **Oxygen 6** builder elements — with a built-in MCP endpoint for Codex and other agentic clients.

[![WordPress](https://img.shields.io/badge/WordPress-7.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Oxygen Builder](https://img.shields.io/badge/Oxygen-6.x-2271B1)](https://oxygenbuilder.com/)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Release](https://img.shields.io/github/v/release/PlayUPSK/OxyAI?include_prereleases&label=release)](https://github.com/PlayUPSK/OxyAI/releases)

OxyAI Oxygen is a single WordPress plugin that turns raw HTML/CSS/JS — or a natural-language prompt — into real, editable Oxygen 6 elements on the canvas. It wraps the open-source [`oxygen-html-converter`](vendor/oxygen-html-converter) kernel with a product layer that adds AI generation, presets, history, a builder sidebar, and an MCP-compatible REST surface so external tools (Codex, IDE agents, scripts) can read, generate, stage, and apply changes to live Oxygen pages.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Usage](#usage)
  - [Manual Conversion](#manual-conversion)
  - [AI Generation](#ai-generation)
  - [Oxygen Builder Sidebar](#oxygen-builder-sidebar)
  - [Direct Page Apply with Dry-Run](#direct-page-apply-with-dry-run)
- [Codex / MCP Integration](#codex--mcp-integration)
  - [Connecting Codex](#connecting-codex)
  - [Recommended Workflow](#recommended-workflow)
  - [Available Tools](#available-tools)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [Security](#security)
- [Development](#development)
- [Roadmap](#roadmap)
- [License](#license)
- [Credits](#credits)

---

## Features

### Conversion
- Convert separate HTML, CSS, and JavaScript inputs into native Oxygen 6 elements.
- Strict source-bundle validation before conversion.
- Tailwind detection with property-mapping fallback for unknown utilities.
- Animation, grid, icon, framework, and interaction heuristics for richer output.

### AI Generation
- BYOK adapters for **OpenAI**, **Anthropic**, and any **OpenAI-compatible** endpoint.
- **Plan Mode** — clarifying questions before generation to reduce wasted tokens.
- **Triple Shot** — three alternate source-bundle directions in one call.
- First-party **site inspirations** library for prompt steering.

### Workflow
- Workflow-first admin console: manual convert, AI generate, selected-content replace, MCP-driven flows.
- Native Oxygen builder modal and sidebar with selected-element target capture and chat thread.
- Opt-in local **history** store.
- Built-in and saved **design presets**.

### Remote Tooling (MCP)
- One REST endpoint speaking MCP-style initialize / list / call.
- Page picker + context tools: `list_oxygen_pages`, `get_page_context`, `get_oxygen_tree`.
- Staged handoff (`convert_and_stage_page`) and direct write (`apply_html_to_oxygen_page`) with `dryRun` and automatic restore backups.
- Auto-generated MCP token, regenerable from the setup panel.

---

## Requirements

| Component       | Version    |
| --------------- | ---------- |
| WordPress       | 7.0+       |
| PHP             | 8.4+       |
| Oxygen Builder  | 6.x        |
| Capability      | `manage_options` (configurable) |

OpenSSL is recommended so saved provider API keys are encrypted with site salts at rest.

---

## Installation

### Option 1 — Release zip (recommended)

1. Download the latest `oxyai-X.Y.Z.zip` from [Releases](https://github.com/PlayUPSK/OxyAI/releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and select the zip.
3. Activate **OxyAI Oxygen**.
4. Open **Tools → OxyAI Oxygen**.

### Option 2 — Manual install

```bash
cd wp-content/plugins
git clone https://github.com/PlayUPSK/OxyAI.git oxyai-oxygen
```

Then activate **OxyAI Oxygen** in WordPress and open **Tools → OxyAI Oxygen**.

> The converter kernel is vendored under `vendor/oxygen-html-converter/` — no separate plugin install is required.

### Updates

Once a release is installed, the plugin updates itself from this repo's GitHub
releases — no WordPress.org listing required. It checks the latest release every
~6 hours, shows the update on **Plugins → Installed Plugins** (with the standard
"Enable auto-updates" toggle), and serves release notes in the **View details**
modal.

The standard plugin auto-update toggle remains in control. To override it:

- Disable entirely: define `OXYAI_OXYGEN_DISABLE_AUTO_UPDATES` as `true` in `wp-config.php`.
- Force-enable or force-disable at the site level: `add_filter('oxyai_oxygen_enable_auto_updates', '__return_true')` or `add_filter('oxyai_oxygen_enable_auto_updates', '__return_false')`.
- Private repo / rate limits: provide a token via `OXYAI_OXYGEN_GITHUB_TOKEN` or the `oxyai_oxygen_github_token` filter (not needed for the public repo).

---

## Quick Start

1. **Activate** the plugin and open **Tools → OxyAI Oxygen**.
2. **Add a provider key** in the AI settings panel (OpenAI, Anthropic, or OpenAI-compatible base URL + key).
3. **Pick a target page** from the page picker.
4. Either:
   - Paste HTML/CSS/JS into the source composer, click **Dry run**, review, then **Apply to page**, or
   - Describe what you want in the prompt box, optionally run **Plan Mode** or **Triple Shot**, then **Apply to page**.
5. (Optional) Copy the **MCP endpoint URL and token** from the setup panel and connect it to Codex.

---

## Usage

### Manual Conversion

Open **Tools → OxyAI Oxygen → Manual conversion**. Paste each source into its dedicated field:

```html
<!-- HTML -->
<section class="hero">
  <h1>Headline</h1>
  <p>Subhead</p>
</section>
```

```css
/* CSS */
.hero { padding: 4rem 2rem; text-align: center; }
.hero h1 { font-size: 3rem; }
```

Hit **Preview** for an audit, then **Convert**. The result becomes a builder paste payload (in-builder) or is written directly to a chosen page (admin).

### AI Generation

In the prompt composer:

- Type a description of the section you want.
- Choose a **preset** or **site inspiration** for design direction.
- Optionally enable **Plan Mode** — the model returns clarifying questions first.
- Optionally choose **Triple Shot** to get three variants.

The generated source bundle runs through the same converter pipeline as manual input.

### Oxygen Builder Sidebar

Inside the Oxygen builder, OxyAI exposes a sidebar with four modes:

| Mode       | What it does                                                              |
| ---------- | ------------------------------------------------------------------------- |
| **Paste**    | Convert pasted HTML/CSS/JS and insert at the current target.            |
| **Generate** | Prompt-to-Oxygen, with optional Plan Mode / Triple Shot.                |
| **Replace**  | Capture the currently selected element and replace it with new output.  |
| **Chat**     | Multi-turn chat with selected-element awareness and context notes.      |

There's also a **Save to Page** action that writes the canvas state to the persisted post (with restore backup).

### Direct Page Apply with Dry-Run

The admin composer can write changes directly to any Oxygen page without opening the builder:

1. Pick a page with the page picker.
2. Paste / generate source.
3. Click **Dry run** — OxyAI returns the proposed merged tree without saving.
4. Click **Apply to page** — OxyAI saves a restore backup, writes the new Oxygen tree, and refreshes render caches.
5. Use **Restore** if anything looks wrong; backups are listed per page.

---

## Codex / MCP Integration

OxyAI exposes a single MCP-style endpoint that Codex and other agentic clients can connect to.

### Connecting Codex

1. Open **Tools → OxyAI Oxygen → Setup**.
2. Copy the **MCP endpoint URL**:
   ```
   https://your-site.tld/wp-json/oxyai/v1/mcp
   ```
3. Copy the auto-generated **`x-oxyai-token`** value (regenerate if needed).
4. In Codex, register the endpoint with the token as an `x-oxyai-token` header or `Authorization: Bearer <token>` header.

Do not put the MCP token in the URL. Query-string tokens are disabled by default because URLs are commonly retained in server logs, browser history, analytics, and screenshots. If a legacy client cannot send headers, site code can opt into query-string tokens with the `oxyai_oxygen_allow_mcp_query_token` filter.

### Recommended Workflow

```text
1. get_prompt_instructions          # always start here
2. list_site_inspirations           # optional design steering
3. plan_generation OR triple_shot   # optional refinement
4. list_oxygen_pages                # find target post ID
5. get_page_context                 # inspect current state
6. <generate HTML/CSS/JS>
7. convert_and_stage_page           # for review inside Oxygen builder
   — OR —
   apply_html_to_oxygen_page dryRun=true   # preview merged tree
   apply_html_to_oxygen_page dryRun=false  # commit
8. restore_oxygen_page_backup       # rollback if needed
```

### Available Tools

| Tool                            | Purpose                                                   |
| ------------------------------- | --------------------------------------------------------- |
| `get_prompt_instructions`       | Canonical system prompt and usage guidelines.             |
| `list_site_inspirations`        | First-party prompt steering library.                      |
| `plan_generation`               | Returns clarifying questions before generating.           |
| `triple_shot_generation`        | Three alternate source-bundle directions.                 |
| `list_oxygen_pages`             | Enumerate Oxygen-enabled posts.                           |
| `get_page_context`              | Summarized state of a target page.                        |
| `get_oxygen_tree`               | Raw Oxygen tree for a post.                               |
| `convert_and_stage_page`        | Stage a converted payload for builder review.             |
| `apply_html_to_oxygen_page`     | Write directly to a page (`append` / `replace` / `replace_node`); supports `dryRun`. |
| `list_oxygen_page_backups`      | List restore backups for a post.                          |
| `restore_oxygen_page_backup`    | Roll back a previous direct write.                        |
| `list_presets`                  | Built-in and saved design presets.                        |

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full surface.

---

## Configuration

All settings live under **Tools → OxyAI Oxygen**.

### AI Providers

| Provider              | Required fields                                  |
| --------------------- | ------------------------------------------------ |
| OpenAI                | API key, model                                   |
| Anthropic             | API key, model                                   |
| OpenAI-compatible     | Base URL, API key, model                         |

Provider keys are encrypted with site salts when OpenSSL is available.

### Capability Filter

Restrict access beyond the default `manage_options`:

```php
add_filter('oxyai_oxygen_required_capability', static function (): string {
    return 'edit_others_pages';
});
```

### Plugin Constants

Defined in [`oxyai-oxygen.php`](oxyai-oxygen.php):

| Constant                          | Purpose                                  |
| --------------------------------- | ---------------------------------------- |
| `OXYAI_OXYGEN_VERSION`            | Current plugin version.                  |
| `OXYAI_OXYGEN_PATH`               | Absolute filesystem path.                |
| `OXYAI_OXYGEN_URL`                | Public URL to plugin folder.             |
| `OXYAI_OXYGEN_OPTION`             | `wp_options` key for settings.           |
| `OXYAI_OXYGEN_HISTORY_OPTION`     | `wp_options` key for history.            |
| `OXYAI_OXYGEN_PRESETS_OPTION`     | `wp_options` key for presets.            |

---

## Architecture

```text
Admin page / Oxygen builder / MCP client
   -> OxyAI REST controller
   -> SourceBundle + validation
   -> AI provider (if requested)
   -> ConverterKernelAdapter
   -> Vendored oxygen-html-converter kernel
   -> OxygenPayloadAdapter
   -> Builder paste payload, direct Oxygen page mutation, preview, audit, or remote tool response
```

### Main Modules

- `Admin\AdminPage` — Tools page and provider settings.
- `Ai\*` — Provider adapters, Plan Mode, Triple Shot.
- `Source\*` — Structured HTML/CSS/JS source bundle contract.
- `Conversion\*` — Adapter around the vendored converter kernel.
- `Rest\*` — Preview, convert, generate, history, presets, MCP routes.
- `Builder\*` — Builder-context detection and insertion payload prep.
- `Oxygen\OxygenPageMutationService` — Direct Oxygen tree reads/writes, restore backups, cache refresh.
- `History\HistoryStore`, `Presets\PresetStore`, `Inspirations\SiteInspirationStore`.

Full details: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

---

## Security

- Admin-only by default (`manage_options`).
- Capability customizable via the `oxyai_oxygen_required_capability` filter.
- Browser REST calls require a WordPress REST nonce.
- MCP calls require an authenticated admin session **or** the configured `x-oxyai-token` / bearer token header.
- AI keys are encrypted with site salts when OpenSSL is available.
- History is opt-in and excludes secrets.
- Direct page writes always create a restore backup before mutating Oxygen data.

---

## Development

### Repo Layout

```
oxyai-oxygen.php             # plugin bootstrap
src/                         # OxyAI product layer (PSR-4: OxyAI\Oxygen\)
vendor/oxygen-html-converter # vendored converter kernel (PSR-4: OxyHtmlConverter\)
docs/                        # architecture notes
.github/workflows/           # release automation
```

### Autoload

PSR-4 mappings (see [`composer.json`](composer.json)):

```json
{
  "OxyAI\\Oxygen\\":        "src/",
  "OxyHtmlConverter\\":     "vendor/oxygen-html-converter/src/"
}
```

The plugin registers its own `spl_autoload_register` — running `composer install` is **not** required at runtime.

### Releasing

Bump the `Version:` header in `oxyai-oxygen.php` and the `OXYAI_OXYGEN_VERSION` constant, then push to `main`. The [release workflow](.github/workflows/release-plugin.yml) packages an installable zip and publishes a GitHub release tagged `vX.Y.Z`.

---

## Roadmap

Already shipped: prompt-to-source generation, HTML/CSS/JS conversion, BYOK providers, builder sidebar, Plan Mode, Triple Shot, site inspirations, history, presets, MCP token, page context, staged handoff, direct page apply, dry-run, restore backups.

Next:

- Side-by-side Triple Shot comparison with visual preview.
- Deeper Oxygen global color / typography / token extraction.
- Per-generation token usage accounting.

---

## License

Released under the **GPL v3 or later** — see [`LICENSE`](LICENSE).

The vendored `oxygen-html-converter` kernel under `vendor/oxygen-html-converter/` retains its own GPL-compatible license — see [`vendor/oxygen-html-converter/LICENSE`](vendor/oxygen-html-converter/LICENSE).

---

## Credits

- Built by [Denis Uhrík](https://github.com/PlayUPSK).
- Conversion kernel: [`oxygen-html-converter`](vendor/oxygen-html-converter).
- Inspired by the Oxydance Pilot workflow.
