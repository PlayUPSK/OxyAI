<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Admin;

use OxyAI\Oxygen\Inspirations\SiteInspirationStore;
use OxyAI\Oxygen\Presets\PresetStore;
use OxyAI\Oxygen\Security\CapabilityService;
use OxyAI\Oxygen\Settings\SettingsRepository;

final class AdminPage
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PresetStore $presets,
        private readonly SiteInspirationStore $inspirations,
        private readonly CapabilityService $capabilities
    ) {
    }

    public function register(): void
    {
        add_action('admin_init', fn (): bool => $this->settings->register() === null);
        add_action('admin_menu', function (): void {
            add_management_page(
                __('OxyAI Oxygen', 'oxyai-oxygen'),
                __('OxyAI Oxygen', 'oxyai-oxygen'),
                $this->capabilities->requiredCapability(),
                'oxyai-oxygen',
                [$this, 'render']
            );
        });
    }

    public function render(): void
    {
        if (!$this->capabilities->canUse()) {
            wp_die(esc_html__('You do not have permission to use OxyAI Oxygen.', 'oxyai-oxygen'));
        }

        $settings = $this->settings->all();
        $settings['mcp_token'] = $this->settings->ensureMcpToken();
        $presets = $this->presets->all();
        $inspirations = $this->inspirations->all();
        $mcpUrl = rest_url('oxyai/v1/mcp');
        $codexUrl = add_query_arg('oxyai_token', (string) $settings['mcp_token'], $mcpUrl);
        ?>
        <div class="wrap oxyai-wrap">
            <section class="oxyai-hero">
                <div>
                    <p class="oxyai-eyebrow"><?php echo esc_html__('Oxygen-native AI builder assistant', 'oxyai-oxygen'); ?></p>
                    <h1><?php echo esc_html__('OxyAI Oxygen', 'oxyai-oxygen'); ?></h1>
                    <p><?php echo esc_html__('Use one simple workflow: create source, convert it to native Oxygen, then insert it in the builder.', 'oxyai-oxygen'); ?></p>
                </div>
                <div class="oxyai-hero-actions">
                    <button type="button" class="button button-primary" data-oxyai-scroll="composer"><?php echo esc_html__('Open composer', 'oxyai-oxygen'); ?></button>
                    <button type="button" class="button" data-oxyai-scroll="setup"><?php echo esc_html__('Setup AI & Codex', 'oxyai-oxygen'); ?></button>
                </div>
            </section>

            <section class="oxyai-start" aria-label="<?php echo esc_attr__('Start here', 'oxyai-oxygen'); ?>">
                <article>
                    <strong><?php echo esc_html__('1. In Oxygen', 'oxyai-oxygen'); ?></strong>
                    <p><?php echo esc_html__('Open a page in Oxygen and click the OxyAI sidebar button. Use Chat for selected elements or Paste for code.', 'oxyai-oxygen'); ?></p>
                    <span><?php echo esc_html__('Shortcut: Ctrl/Cmd + Shift + Y', 'oxyai-oxygen'); ?></span>
                </article>
                <article>
                    <strong><?php echo esc_html__('2. In this admin page', 'oxyai-oxygen'); ?></strong>
                    <p><?php echo esc_html__('Paste HTML/CSS/JS or generate source with AI, then preview and copy Oxygen JSON.', 'oxyai-oxygen'); ?></p>
                    <span><?php echo esc_html__('Best for testing snippets', 'oxyai-oxygen'); ?></span>
                </article>
                <article>
                    <strong><?php echo esc_html__('3. From Codex', 'oxyai-oxygen'); ?></strong>
                    <p><?php echo esc_html__('Connect Codex to the MCP endpoint, fetch page context, then stage generated code for a specific page.', 'oxyai-oxygen'); ?></p>
                    <span><?php echo esc_html__('Token is generated automatically', 'oxyai-oxygen'); ?></span>
                </article>
            </section>

            <div class="oxyai-layout">
                <main class="oxyai-main">
                    <section class="oxyai-card" id="oxyai-composer">
                        <div class="oxyai-section-title">
                            <div>
                                <p class="oxyai-card-kicker"><?php echo esc_html__('Create source', 'oxyai-oxygen'); ?></p>
                                <h2><?php echo esc_html__('Composer', 'oxyai-oxygen'); ?></h2>
                            </div>
                            <div class="oxyai-actions">
                                <button type="button" class="button" id="oxyai-run-plan"><?php echo esc_html__('Plan first', 'oxyai-oxygen'); ?></button>
                                <button type="button" class="button" id="oxyai-run-triple-shot"><?php echo esc_html__('Triple Shot', 'oxyai-oxygen'); ?></button>
                                <button type="button" class="button" id="oxyai-run-generate"><?php echo esc_html__('Generate source', 'oxyai-oxygen'); ?></button>
                                <button type="button" class="button" id="oxyai-run-preview"><?php echo esc_html__('Preview', 'oxyai-oxygen'); ?></button>
                                <button type="button" class="button button-primary" id="oxyai-run-convert"><?php echo esc_html__('Convert', 'oxyai-oxygen'); ?></button>
                            </div>
                        </div>

                        <div class="oxyai-composer-help">
                            <p><?php echo esc_html__('Use only what you need. HTML is required; CSS and JavaScript are optional. AI generation fills these fields first so you can review before converting.', 'oxyai-oxygen'); ?></p>
                        </div>

                        <label for="oxyai-preset"><?php echo esc_html__('Design preset', 'oxyai-oxygen'); ?></label>
                        <select id="oxyai-preset">
                            <option value=""><?php echo esc_html__('No preset', 'oxyai-oxygen'); ?></option>
                            <?php foreach ($presets as $preset): ?>
                                <option value="<?php echo esc_attr((string) ($preset['slug'] ?? '')); ?>"><?php echo esc_html((string) ($preset['name'] ?? 'Preset')); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="oxyai-site-inspiration"><?php echo esc_html__('Site inspiration', 'oxyai-oxygen'); ?></label>
                        <select id="oxyai-site-inspiration">
                            <option value=""><?php echo esc_html__('No inspiration', 'oxyai-oxygen'); ?></option>
                            <?php foreach ($inspirations as $inspiration): ?>
                                <option value="<?php echo esc_attr((string) ($inspiration['slug'] ?? '')); ?>"><?php echo esc_html((string) ($inspiration['name'] ?? 'Inspiration')); ?> - <?php echo esc_html((string) ($inspiration['description'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="oxyai-prompt"><?php echo esc_html__('Prompt for AI generation', 'oxyai-oxygen'); ?></label>
                        <textarea id="oxyai-prompt" class="oxyai-textarea oxyai-textarea-small" placeholder="<?php echo esc_attr__('Example: Create a premium hero section for a roofing company with a CTA and three trust points.', 'oxyai-oxygen'); ?>"></textarea>

                        <div id="oxyai-plan" class="oxyai-plan" hidden></div>
                        <div id="oxyai-variants" class="oxyai-variants" hidden></div>

                        <div class="oxyai-source-tabs" role="tablist" aria-label="<?php echo esc_attr__('Source fields', 'oxyai-oxygen'); ?>">
                            <button type="button" class="is-active" data-oxyai-tab="html">HTML</button>
                            <button type="button" data-oxyai-tab="css">CSS</button>
                            <button type="button" data-oxyai-tab="js">JS</button>
                        </div>

                        <div class="oxyai-source-panels">
                            <label data-oxyai-panel="html">
                                <span><?php echo esc_html__('HTML', 'oxyai-oxygen'); ?></span>
                                <textarea id="oxyai-html" class="oxyai-textarea" placeholder="<?php echo esc_attr__('Paste HTML here...', 'oxyai-oxygen'); ?>"></textarea>
                            </label>
                            <label data-oxyai-panel="css" hidden>
                                <span><?php echo esc_html__('CSS', 'oxyai-oxygen'); ?></span>
                                <textarea id="oxyai-css" class="oxyai-textarea" placeholder="<?php echo esc_attr__('Optional CSS here...', 'oxyai-oxygen'); ?>"></textarea>
                            </label>
                            <label data-oxyai-panel="js" hidden>
                                <span><?php echo esc_html__('JavaScript', 'oxyai-oxygen'); ?></span>
                                <textarea id="oxyai-js" class="oxyai-textarea" placeholder="<?php echo esc_attr__('Optional JavaScript here...', 'oxyai-oxygen'); ?>"></textarea>
                            </label>
                        </div>

                        <details class="oxyai-details">
                            <summary><?php echo esc_html__('Conversion options', 'oxyai-oxygen'); ?></summary>
                            <div class="oxyai-options">
                                <label><input type="checkbox" id="oxyai-safe-mode"> <?php echo esc_html__('Safe mode: strip scripts and handlers', 'oxyai-oxygen'); ?></label>
                                <label><input type="checkbox" id="oxyai-inline-styles" checked> <?php echo esc_html__('Map supported CSS to Oxygen properties', 'oxyai-oxygen'); ?></label>
                                <label><input type="checkbox" id="oxyai-include-css" checked> <?php echo esc_html__('Keep complex CSS as CSS Code', 'oxyai-oxygen'); ?></label>
                                <label><input type="checkbox" id="oxyai-use-selectors"> <?php echo esc_html__('Preserve classes for selector strategy', 'oxyai-oxygen'); ?></label>
                            </div>
                        </details>
                    </section>

                    <section class="oxyai-card">
                        <div class="oxyai-section-title">
                            <div>
                                <p class="oxyai-card-kicker"><?php echo esc_html__('Output', 'oxyai-oxygen'); ?></p>
                                <h2><?php echo esc_html__('Result', 'oxyai-oxygen'); ?></h2>
                            </div>
                            <div class="oxyai-actions">
                                <button type="button" class="button" id="oxyai-copy-output"><?php echo esc_html__('Copy JSON', 'oxyai-oxygen'); ?></button>
                                <button type="button" class="button button-primary" id="oxyai-apply-page"><?php echo esc_html__('Apply to page', 'oxyai-oxygen'); ?></button>
                            </div>
                        </div>
                        <div id="oxyai-status" class="oxyai-status"><?php echo esc_html__('Ready.', 'oxyai-oxygen'); ?></div>
                        <div class="oxyai-page-apply">
                            <label>
                                <span><?php echo esc_html__('Target WordPress page', 'oxyai-oxygen'); ?></span>
                                <select id="oxyai-target-page">
                                    <option value=""><?php echo esc_html__('Load pages first', 'oxyai-oxygen'); ?></option>
                                </select>
                            </label>
                            <label>
                                <span><?php echo esc_html__('Page action', 'oxyai-oxygen'); ?></span>
                                <select id="oxyai-page-operation">
                                    <option value="append"><?php echo esc_html__('Append to page', 'oxyai-oxygen'); ?></option>
                                    <option value="replace"><?php echo esc_html__('Replace whole page', 'oxyai-oxygen'); ?></option>
                                </select>
                            </label>
                            <div class="oxyai-page-apply-actions">
                                <button type="button" class="button" id="oxyai-load-pages"><?php echo esc_html__('Load pages', 'oxyai-oxygen'); ?></button>
                                <button type="button" class="button" id="oxyai-dry-run-page"><?php echo esc_html__('Dry run', 'oxyai-oxygen'); ?></button>
                            </div>
                            <p><?php echo esc_html__('Apply converts the current HTML/CSS/JS and writes it into Oxygen data with an automatic restore backup.', 'oxyai-oxygen'); ?></p>
                        </div>
                        <div id="oxyai-audit" class="oxyai-audit"></div>
                        <textarea id="oxyai-output" class="oxyai-textarea oxyai-output" readonly placeholder="<?php echo esc_attr__('Converted Oxygen JSON appears here.', 'oxyai-oxygen'); ?>"></textarea>
                    </section>
                </main>

                <aside class="oxyai-sidebar" id="oxyai-setup">
                    <section class="oxyai-card oxyai-guide-card">
                        <h2><?php echo esc_html__('Recommended flow', 'oxyai-oxygen'); ?></h2>
                        <ol class="oxyai-steps">
                            <li><strong><?php echo esc_html__('Builder chat', 'oxyai-oxygen'); ?></strong><span><?php echo esc_html__('Use this for editing a specific selected element.', 'oxyai-oxygen'); ?></span></li>
                            <li><strong><?php echo esc_html__('Admin composer', 'oxyai-oxygen'); ?></strong><span><?php echo esc_html__('Use this for testing snippets before opening Oxygen.', 'oxyai-oxygen'); ?></span></li>
                            <li><strong><?php echo esc_html__('Codex handoff', 'oxyai-oxygen'); ?></strong><span><?php echo esc_html__('Use this when Codex should generate code for a specific page.', 'oxyai-oxygen'); ?></span></li>
                        </ol>
                    </section>

                    <section class="oxyai-card">
                        <h2><?php echo esc_html__('Setup', 'oxyai-oxygen'); ?></h2>
                        <form method="post" action="options.php">
                            <?php settings_fields('oxyai_oxygen_settings'); ?>

                            <details class="oxyai-details" open>
                                <summary><?php echo esc_html__('AI provider', 'oxyai-oxygen'); ?></summary>
                                <label>
                                    <span><?php echo esc_html__('Provider used by Generate buttons', 'oxyai-oxygen'); ?></span>
                                    <select name="<?php echo esc_attr(OXYAI_OXYGEN_OPTION); ?>[provider]" id="oxyai-provider-select">
                                        <option value="openai" <?php selected($settings['provider'], 'openai'); ?>>OpenAI</option>
                                        <option value="anthropic" <?php selected($settings['provider'], 'anthropic'); ?>>Anthropic</option>
                                        <option value="compatible" <?php selected($settings['provider'], 'compatible'); ?>>OpenAI-compatible / local</option>
                                    </select>
                                </label>

                                <div data-oxyai-provider-fields="openai">
                                    <label><span>OpenAI API key</span><input type="password" name="<?php echo esc_attr(OXYAI_OXYGEN_OPTION); ?>[openai_api_key]" placeholder="<?php echo esc_attr($settings['openai_api_key'] ? 'Configured. Leave blank to keep.' : 'Paste key once'); ?>"></label>
                                    <label><span>OpenAI model</span><input type="text" name="<?php echo esc_attr(OXYAI_OXYGEN_OPTION); ?>[openai_model]" value="<?php echo esc_attr((string) $settings['openai_model']); ?>"></label>
                                </div>

                                <div data-oxyai-provider-fields="anthropic">
                                    <label><span>Anthropic API key</span><input type="password" name="<?php echo esc_attr(OXYAI_OXYGEN_OPTION); ?>[anthropic_api_key]" placeholder="<?php echo esc_attr($settings['anthropic_api_key'] ? 'Configured. Leave blank to keep.' : 'Paste key once'); ?>"></label>
                                    <label><span>Anthropic model</span><input type="text" name="<?php echo esc_attr(OXYAI_OXYGEN_OPTION); ?>[anthropic_model]" value="<?php echo esc_attr((string) $settings['anthropic_model']); ?>"></label>
                                </div>

                                <div data-oxyai-provider-fields="compatible">
                                    <label><span>Endpoint</span><input type="url" name="<?php echo esc_attr(OXYAI_OXYGEN_OPTION); ?>[compatible_endpoint]" value="<?php echo esc_attr((string) $settings['compatible_endpoint']); ?>" placeholder="http://localhost:11434"></label>
                                    <label><span>API key, optional</span><input type="password" name="<?php echo esc_attr(OXYAI_OXYGEN_OPTION); ?>[compatible_api_key]" placeholder="<?php echo esc_attr($settings['compatible_api_key'] ? 'Configured. Leave blank to keep.' : 'Optional'); ?>"></label>
                                    <label><span>Model</span><input type="text" name="<?php echo esc_attr(OXYAI_OXYGEN_OPTION); ?>[compatible_model]" value="<?php echo esc_attr((string) $settings['compatible_model']); ?>"></label>
                                </div>
                            </details>

                            <details class="oxyai-details" open>
                                <summary><?php echo esc_html__('Codex / MCP connection', 'oxyai-oxygen'); ?></summary>
                                <p><?php echo esc_html__('Token is generated for you. Regenerate only if you want to revoke old Codex access.', 'oxyai-oxygen'); ?></p>
                                <label>
                                    <span><?php echo esc_html__('MCP token', 'oxyai-oxygen'); ?></span>
                                    <div class="oxyai-token-row">
                                        <input type="text" id="oxyai-mcp-token" name="<?php echo esc_attr(OXYAI_OXYGEN_OPTION); ?>[mcp_token]" value="<?php echo esc_attr((string) $settings['mcp_token']); ?>">
                                        <button type="button" class="button" id="oxyai-regenerate-token"><?php echo esc_html__('Regenerate', 'oxyai-oxygen'); ?></button>
                                    </div>
                                </label>
                                <label>
                                    <span><?php echo esc_html__('Codex URL', 'oxyai-oxygen'); ?></span>
                                    <code class="oxyai-endpoint" id="oxyai-codex-url" data-base-url="<?php echo esc_attr($mcpUrl); ?>"><?php echo esc_html($codexUrl); ?></code>
                                </label>
                                <button type="button" class="button" id="oxyai-copy-codex-url"><?php echo esc_html__('Copy Codex URL', 'oxyai-oxygen'); ?></button>
                                <p class="description"><?php echo esc_html__('Give this URL to Codex as the OxyAI MCP server. Codex can fetch prompt rules, inspect pages, and stage generated code for a page.', 'oxyai-oxygen'); ?></p>
                            </details>

                            <details class="oxyai-details">
                                <summary><?php echo esc_html__('History', 'oxyai-oxygen'); ?></summary>
                                <label><input type="checkbox" name="<?php echo esc_attr(OXYAI_OXYGEN_OPTION); ?>[history_enabled]" value="1" <?php checked(!empty($settings['history_enabled'])); ?>> <?php echo esc_html__('Store prompt/source history locally', 'oxyai-oxygen'); ?></label>
                            </details>

                            <?php submit_button(__('Save setup', 'oxyai-oxygen'), 'primary'); ?>
                        </form>
                    </section>
                </aside>
            </div>
        </div>
        <?php
    }
}
