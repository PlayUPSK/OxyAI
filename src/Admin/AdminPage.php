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
        $optionName = OXYAI_OXYGEN_OPTION;
        ?>
        <div class="wrap ox-app">

            <header class="ox-top">
                <div class="ox-top__brand">
                    <div class="ox-top__mark" aria-hidden="true">Ox</div>
                    <div>
                        <h1><?php echo esc_html__('OxyAI', 'oxyai-oxygen'); ?></h1>
                        <p class="ox-top__sub"><?php echo esc_html__('Convert HTML, CSS, and JS into native Oxygen 6 elements.', 'oxyai-oxygen'); ?></p>
                    </div>
                </div>
                <div class="ox-top__actions">
                    <button type="button" class="ox-btn ox-btn--ghost" data-ox-shortcut>
                        <span class="ox-kbd">Ctrl/⌘</span><span class="ox-kbd">⇧</span><span class="ox-kbd">Y</span>
                        <span class="ox-sr"><?php echo esc_html__('Builder shortcut', 'oxyai-oxygen'); ?></span>
                    </button>
                    <button type="button" class="ox-btn" data-ox-open-setup>
                        <span aria-hidden="true">⚙</span>
                        <?php echo esc_html__('Setup', 'oxyai-oxygen'); ?>
                    </button>
                </div>
            </header>

            <nav class="ox-tabs" role="tablist" aria-label="<?php echo esc_attr__('Choose how to start', 'oxyai-oxygen'); ?>">
                <button type="button" class="ox-tab is-active" role="tab" aria-selected="true" tabindex="0" id="oxyai-tab-generate" aria-controls="oxyai-panel-generate" data-ox-mode="generate">
                    <span class="ox-tab__icon" aria-hidden="true">✦</span>
                    <span class="ox-tab__text">
                        <span class="ox-tab__title"><?php echo esc_html__('Generate with AI', 'oxyai-oxygen'); ?></span>
                        <span class="ox-tab__hint"><?php echo esc_html__('Describe what to build', 'oxyai-oxygen'); ?></span>
                    </span>
                </button>
                <button type="button" class="ox-tab" role="tab" aria-selected="false" tabindex="-1" id="oxyai-tab-paste" aria-controls="oxyai-panel-paste" data-ox-mode="paste">
                    <span class="ox-tab__icon" aria-hidden="true">⌘</span>
                    <span class="ox-tab__text">
                        <span class="ox-tab__title"><?php echo esc_html__('Paste code', 'oxyai-oxygen'); ?></span>
                        <span class="ox-tab__hint"><?php echo esc_html__('Bring HTML, CSS, JS', 'oxyai-oxygen'); ?></span>
                    </span>
                </button>
                <button type="button" class="ox-tab" role="tab" aria-selected="false" tabindex="-1" id="oxyai-tab-apply" aria-controls="oxyai-panel-apply" data-ox-mode="apply">
                    <span class="ox-tab__icon" aria-hidden="true">↗</span>
                    <span class="ox-tab__text">
                        <span class="ox-tab__title"><?php echo esc_html__('Send to page', 'oxyai-oxygen'); ?></span>
                        <span class="ox-tab__hint"><?php echo esc_html__('Write into Oxygen', 'oxyai-oxygen'); ?></span>
                    </span>
                </button>
            </nav>

            <div class="ox-layout">

                <main class="ox-main">

                    <!-- GENERATE PANEL -->
                    <section class="ox-card ox-panel-content" data-ox-panel="generate" id="oxyai-panel-generate" role="tabpanel" aria-labelledby="oxyai-tab-generate" tabindex="0">
                        <div class="ox-card__body">
                            <label class="ox-field">
                                <span class="ox-field__label"><?php echo esc_html__('What should OxyAI build?', 'oxyai-oxygen'); ?></span>
                                <textarea id="oxyai-prompt" class="ox-textarea ox-textarea--prompt" placeholder="<?php echo esc_attr__('e.g. Premium hero for a roofing company with a CTA and three trust points.', 'oxyai-oxygen'); ?>"></textarea>
                            </label>

                            <div class="ox-row">
                                <label class="ox-field">
                                    <span class="ox-field__label"><?php echo esc_html__('Style preset', 'oxyai-oxygen'); ?></span>
                                    <select id="oxyai-preset" class="ox-select">
                                        <option value=""><?php echo esc_html__('No preset', 'oxyai-oxygen'); ?></option>
                                        <?php foreach ($presets as $preset): ?>
                                            <option value="<?php echo esc_attr((string) ($preset['slug'] ?? '')); ?>"><?php echo esc_html((string) ($preset['name'] ?? 'Preset')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="ox-field">
                                    <span class="ox-field__label"><?php echo esc_html__('Site inspiration', 'oxyai-oxygen'); ?></span>
                                    <select id="oxyai-site-inspiration" class="ox-select">
                                        <option value=""><?php echo esc_html__('None', 'oxyai-oxygen'); ?></option>
                                        <?php foreach ($inspirations as $inspiration): ?>
                                            <option value="<?php echo esc_attr((string) ($inspiration['slug'] ?? '')); ?>"><?php echo esc_html((string) ($inspiration['name'] ?? 'Inspiration')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>

                            <details class="ox-disclosure">
                                <summary><?php echo esc_html__('Refine with Plan Mode or Triple Shot', 'oxyai-oxygen'); ?></summary>
                                <div class="ox-disclosure__body">
                                    <p class="ox-field__hint"><?php echo esc_html__('Plan Mode asks clarifying questions first. Triple Shot generates three variations to pick from.', 'oxyai-oxygen'); ?></p>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                        <button type="button" class="ox-btn ox-btn--soft" id="oxyai-run-plan"><?php echo esc_html__('Plan first', 'oxyai-oxygen'); ?></button>
                                        <button type="button" class="ox-btn ox-btn--soft" id="oxyai-run-triple-shot"><?php echo esc_html__('Triple Shot', 'oxyai-oxygen'); ?></button>
                                    </div>
                                </div>
                            </details>

                            <div id="oxyai-plan" class="ox-plan" hidden></div>
                            <div id="oxyai-variants" class="ox-variants" hidden></div>
                        </div>
                        <div class="ox-card__footer">
                            <button type="button" class="ox-btn ox-btn--ghost" id="oxyai-clear-prompt"><?php echo esc_html__('Clear', 'oxyai-oxygen'); ?></button>
                            <button type="button" class="ox-btn ox-btn--primary" id="oxyai-run-generate">
                                <?php echo esc_html__('Generate source', 'oxyai-oxygen'); ?> <span aria-hidden="true">→</span>
                            </button>
                        </div>
                    </section>

                    <!-- PASTE PANEL -->
                    <section class="ox-card ox-panel-content" data-ox-panel="paste" id="oxyai-panel-paste" role="tabpanel" aria-labelledby="oxyai-tab-paste" tabindex="0" hidden>
                        <div class="ox-card__body">
                            <div class="ox-source">
                                <div class="ox-source__tabs" role="tablist" aria-label="<?php echo esc_attr__('Source language', 'oxyai-oxygen'); ?>">
                                    <button type="button" class="ox-source__tab is-active" role="tab" id="oxyai-tab-source-html" aria-controls="oxyai-source-html" aria-selected="true" tabindex="0" data-oxyai-tab="html">HTML</button>
                                    <button type="button" class="ox-source__tab" role="tab" id="oxyai-tab-source-css" aria-controls="oxyai-source-css" aria-selected="false" tabindex="-1" data-oxyai-tab="css">CSS</button>
                                    <button type="button" class="ox-source__tab" role="tab" id="oxyai-tab-source-js" aria-controls="oxyai-source-js" aria-selected="false" tabindex="-1" data-oxyai-tab="js">JS</button>
                                </div>
                                <div data-oxyai-panel="html" id="oxyai-source-html" role="tabpanel" aria-labelledby="oxyai-tab-source-html" class="ox-source__panel">
                                    <label class="ox-field">
                                        <span class="ox-sr"><?php echo esc_html__('HTML source', 'oxyai-oxygen'); ?></span>
                                        <textarea id="oxyai-html" class="ox-textarea ox-textarea--code" placeholder="<?php echo esc_attr__('Paste HTML here…', 'oxyai-oxygen'); ?>"></textarea>
                                    </label>
                                </div>
                                <div data-oxyai-panel="css" id="oxyai-source-css" role="tabpanel" aria-labelledby="oxyai-tab-source-css" class="ox-source__panel" hidden>
                                    <label class="ox-field">
                                        <span class="ox-sr"><?php echo esc_html__('CSS source', 'oxyai-oxygen'); ?></span>
                                        <textarea id="oxyai-css" class="ox-textarea ox-textarea--code" placeholder="<?php echo esc_attr__('Optional CSS — kept as a CSS Code block when needed.', 'oxyai-oxygen'); ?>"></textarea>
                                    </label>
                                </div>
                                <div data-oxyai-panel="js" id="oxyai-source-js" role="tabpanel" aria-labelledby="oxyai-tab-source-js" class="ox-source__panel" hidden>
                                    <label class="ox-field">
                                        <span class="ox-sr"><?php echo esc_html__('JavaScript source', 'oxyai-oxygen'); ?></span>
                                        <textarea id="oxyai-js" class="ox-textarea ox-textarea--code" placeholder="<?php echo esc_attr__('Optional JavaScript.', 'oxyai-oxygen'); ?>"></textarea>
                                    </label>
                                </div>
                            </div>

                            <details class="ox-disclosure">
                                <summary><?php echo esc_html__('Conversion options', 'oxyai-oxygen'); ?></summary>
                                <div class="ox-disclosure__body">
                                    <p class="ox-field__hint"><?php echo esc_html__('Defaults work for most pasted markup. Toggle if you know what you need.', 'oxyai-oxygen'); ?></p>
                                    <div class="ox-chips">
                                        <label class="ox-chip"><input type="checkbox" id="oxyai-safe-mode"> <?php echo esc_html__('Safe mode', 'oxyai-oxygen'); ?></label>
                                        <label class="ox-chip"><input type="checkbox" id="oxyai-inline-styles" checked> <?php echo esc_html__('Map styles', 'oxyai-oxygen'); ?></label>
                                        <label class="ox-chip"><input type="checkbox" id="oxyai-include-css" checked> <?php echo esc_html__('Keep complex CSS', 'oxyai-oxygen'); ?></label>
                                        <label class="ox-chip"><input type="checkbox" id="oxyai-use-selectors" checked> <?php echo esc_html__('Register classes', 'oxyai-oxygen'); ?></label>
                                    </div>
                                </div>
                            </details>
                        </div>
                        <div class="ox-card__footer">
                            <button type="button" class="ox-btn ox-btn--ghost" id="oxyai-run-preview"><?php echo esc_html__('Preview', 'oxyai-oxygen'); ?></button>
                            <button type="button" class="ox-btn ox-btn--primary" id="oxyai-run-convert">
                                <?php echo esc_html__('Convert to Oxygen', 'oxyai-oxygen'); ?> <span aria-hidden="true">→</span>
                            </button>
                        </div>
                    </section>

                    <!-- APPLY PANEL -->
                    <section class="ox-card ox-panel-content" data-ox-panel="apply" id="oxyai-panel-apply" role="tabpanel" aria-labelledby="oxyai-tab-apply" tabindex="0" hidden>
                        <div class="ox-card__body">
                            <label class="ox-field">
                                <span class="ox-field__label"><?php echo esc_html__('Target page', 'oxyai-oxygen'); ?></span>
                                <div style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;">
                                    <select id="oxyai-target-page" class="ox-select">
                                        <option value=""><?php echo esc_html__('Load pages first…', 'oxyai-oxygen'); ?></option>
                                    </select>
                                    <button type="button" class="ox-btn" id="oxyai-load-pages"><?php echo esc_html__('Load pages', 'oxyai-oxygen'); ?></button>
                                </div>
                            </label>

                            <label class="ox-field">
                                <span class="ox-field__label"><?php echo esc_html__('Operation', 'oxyai-oxygen'); ?></span>
                                <div class="ox-segmented" role="tablist" data-ox-operation-group>
                                    <button type="button" class="is-active" data-ox-operation="append"><?php echo esc_html__('Append to page', 'oxyai-oxygen'); ?></button>
                                    <button type="button" data-ox-operation="replace"><?php echo esc_html__('Replace whole page', 'oxyai-oxygen'); ?></button>
                                </div>
                                <input type="hidden" id="oxyai-page-operation" value="append">
                            </label>

                            <p class="ox-field__hint"><?php echo esc_html__('Apply converts current HTML/CSS/JS and writes it into Oxygen with an automatic restore backup.', 'oxyai-oxygen'); ?></p>
                        </div>
                        <div class="ox-card__footer">
                            <button type="button" class="ox-btn" id="oxyai-dry-run-page"><?php echo esc_html__('Dry run', 'oxyai-oxygen'); ?></button>
                            <button type="button" class="ox-btn ox-btn--success" id="oxyai-apply-page">
                                <?php echo esc_html__('Apply to page', 'oxyai-oxygen'); ?> <span aria-hidden="true">→</span>
                            </button>
                        </div>
                    </section>

                </main>

                <!-- SIDEBAR -->
                <aside class="ox-side" aria-label="<?php echo esc_attr__('Result and tips', 'oxyai-oxygen'); ?>">

                    <section class="ox-card">
                        <div class="ox-card__head">
                            <div>
                                <h2 class="ox-card__title"><?php echo esc_html__('Result', 'oxyai-oxygen'); ?></h2>
                            </div>
                            <span id="oxyai-status" class="ox-status"><?php echo esc_html__('Ready', 'oxyai-oxygen'); ?></span>
                        </div>
                        <div class="ox-card__body">
                            <div id="oxyai-audit" class="ox-audit" aria-live="polite"></div>

                            <details class="ox-disclosure" id="oxyai-output-disclosure">
                                <summary><?php echo esc_html__('Oxygen JSON output', 'oxyai-oxygen'); ?></summary>
                                <div class="ox-disclosure__body">
                                    <pre id="oxyai-output" class="ox-output"></pre>
                                    <div style="display:flex;justify-content:flex-end;">
                                        <button type="button" class="ox-btn ox-btn--ghost" id="oxyai-copy-output"><?php echo esc_html__('Copy JSON', 'oxyai-oxygen'); ?></button>
                                    </div>
                                </div>
                            </details>
                        </div>
                    </section>

                    <section class="ox-card">
                        <div class="ox-card__body">
                            <h3 class="ox-side__title"><?php echo esc_html__('Where to use OxyAI', 'oxyai-oxygen'); ?></h3>
                            <ol class="ox-tips">
                                <li>
                                    <span class="ox-tips__num">1</span>
                                    <div>
                                        <strong><?php echo esc_html__('Inside Oxygen builder', 'oxyai-oxygen'); ?></strong>
                                        <span><?php echo esc_html__('Open any page in Oxygen, then click the floating OxyAI button or press', 'oxyai-oxygen'); ?> <kbd class="ox-kbd">Ctrl/⌘ ⇧ Y</kbd>.</span>
                                    </div>
                                </li>
                                <li>
                                    <span class="ox-tips__num">2</span>
                                    <div>
                                        <strong><?php echo esc_html__('Here on the admin page', 'oxyai-oxygen'); ?></strong>
                                        <span><?php echo esc_html__('Best for testing snippets, copying JSON, or pushing into a specific page.', 'oxyai-oxygen'); ?></span>
                                    </div>
                                </li>
                                <li>
                                    <span class="ox-tips__num">3</span>
                                    <div>
                                        <strong><?php echo esc_html__('Through Codex / MCP', 'oxyai-oxygen'); ?></strong>
                                        <span><?php echo esc_html__('Connect Codex with the URL from Setup. Codex can stage pages remotely.', 'oxyai-oxygen'); ?></span>
                                    </div>
                                </li>
                            </ol>
                        </div>
                    </section>

                </aside>

            </div>

            <!-- ===== SETUP MODAL ===== -->
            <div class="ox-modal" id="oxyai-setup-modal" role="dialog" aria-modal="true" aria-labelledby="oxyai-setup-title" hidden>
                <div class="ox-modal__backdrop" data-ox-close-setup></div>
                <div class="ox-modal__dialog">
                    <header class="ox-modal__head">
                        <h2 id="oxyai-setup-title"><?php echo esc_html__('Setup', 'oxyai-oxygen'); ?></h2>
                        <button type="button" class="ox-modal__close" data-ox-close-setup aria-label="<?php echo esc_attr__('Close setup', 'oxyai-oxygen'); ?>">×</button>
                    </header>
                    <form method="post" action="options.php" id="oxyai-setup-form" class="ox-modal__body">
                        <?php settings_fields('oxyai_oxygen_settings'); ?>

                        <section class="ox-modal__section">
                            <h3 class="ox-modal__section-title">
                                <?php echo esc_html__('AI provider', 'oxyai-oxygen'); ?>
                                <small><?php echo esc_html__('Used by all Generate buttons', 'oxyai-oxygen'); ?></small>
                            </h3>
                            <label class="ox-field">
                                <span class="ox-field__label"><?php echo esc_html__('Provider', 'oxyai-oxygen'); ?></span>
                                <select name="<?php echo esc_attr($optionName); ?>[provider]" id="oxyai-provider-select" class="ox-select">
                                    <option value="openai" <?php selected($settings['provider'], 'openai'); ?>>OpenAI</option>
                                    <option value="anthropic" <?php selected($settings['provider'], 'anthropic'); ?>>Anthropic</option>
                                    <option value="compatible" <?php selected($settings['provider'], 'compatible'); ?>><?php echo esc_html__('OpenAI-compatible / local', 'oxyai-oxygen'); ?></option>
                                </select>
                            </label>

                            <div class="ox-provider" data-oxyai-provider-fields="openai">
                                <div class="ox-row">
                                    <label class="ox-field">
                                        <span class="ox-field__label"><?php echo esc_html__('API key', 'oxyai-oxygen'); ?></span>
                                        <input type="password" class="ox-input" name="<?php echo esc_attr($optionName); ?>[openai_api_key]" placeholder="<?php echo esc_attr($settings['openai_api_key'] ? __('Configured. Leave blank to keep.', 'oxyai-oxygen') : __('sk-…', 'oxyai-oxygen')); ?>">
                                    </label>
                                    <label class="ox-field">
                                        <span class="ox-field__label"><?php echo esc_html__('Model', 'oxyai-oxygen'); ?></span>
                                        <input type="text" class="ox-input" name="<?php echo esc_attr($optionName); ?>[openai_model]" value="<?php echo esc_attr((string) $settings['openai_model']); ?>">
                                    </label>
                                </div>
                            </div>

                            <div class="ox-provider" data-oxyai-provider-fields="anthropic" hidden>
                                <div class="ox-row">
                                    <label class="ox-field">
                                        <span class="ox-field__label"><?php echo esc_html__('API key', 'oxyai-oxygen'); ?></span>
                                        <input type="password" class="ox-input" name="<?php echo esc_attr($optionName); ?>[anthropic_api_key]" placeholder="<?php echo esc_attr($settings['anthropic_api_key'] ? __('Configured. Leave blank to keep.', 'oxyai-oxygen') : __('sk-ant-…', 'oxyai-oxygen')); ?>">
                                    </label>
                                    <label class="ox-field">
                                        <span class="ox-field__label"><?php echo esc_html__('Model', 'oxyai-oxygen'); ?></span>
                                        <input type="text" class="ox-input" name="<?php echo esc_attr($optionName); ?>[anthropic_model]" value="<?php echo esc_attr((string) $settings['anthropic_model']); ?>">
                                    </label>
                                </div>
                            </div>

                            <div class="ox-provider" data-oxyai-provider-fields="compatible" hidden>
                                <label class="ox-field">
                                    <span class="ox-field__label"><?php echo esc_html__('Endpoint URL', 'oxyai-oxygen'); ?></span>
                                    <input type="url" class="ox-input" name="<?php echo esc_attr($optionName); ?>[compatible_endpoint]" value="<?php echo esc_attr((string) $settings['compatible_endpoint']); ?>" placeholder="http://localhost:11434">
                                </label>
                                <div class="ox-row">
                                    <label class="ox-field">
                                        <span class="ox-field__label"><?php echo esc_html__('API key (optional)', 'oxyai-oxygen'); ?></span>
                                        <input type="password" class="ox-input" name="<?php echo esc_attr($optionName); ?>[compatible_api_key]" placeholder="<?php echo esc_attr($settings['compatible_api_key'] ? __('Configured. Leave blank to keep.', 'oxyai-oxygen') : __('Optional', 'oxyai-oxygen')); ?>">
                                    </label>
                                    <label class="ox-field">
                                        <span class="ox-field__label"><?php echo esc_html__('Model', 'oxyai-oxygen'); ?></span>
                                        <input type="text" class="ox-input" name="<?php echo esc_attr($optionName); ?>[compatible_model]" value="<?php echo esc_attr((string) $settings['compatible_model']); ?>">
                                    </label>
                                </div>
                            </div>
                        </section>

                        <section class="ox-modal__section">
                            <h3 class="ox-modal__section-title">
                                <?php echo esc_html__('Codex / MCP', 'oxyai-oxygen'); ?>
                                <small><?php echo esc_html__('Remote tooling', 'oxyai-oxygen'); ?></small>
                            </h3>
                            <p class="ox-modal__hint"><?php echo esc_html__('Give this URL to Codex (or any MCP client). Regenerate only if you want to revoke old access.', 'oxyai-oxygen'); ?></p>

                            <label class="ox-field">
                                <span class="ox-field__label"><?php echo esc_html__('MCP token', 'oxyai-oxygen'); ?></span>
                                <div class="ox-token-row">
                                    <input type="text" id="oxyai-mcp-token" class="ox-input" name="<?php echo esc_attr($optionName); ?>[mcp_token]" value="<?php echo esc_attr((string) $settings['mcp_token']); ?>">
                                    <button type="button" class="ox-btn" id="oxyai-regenerate-token"><?php echo esc_html__('Regenerate', 'oxyai-oxygen'); ?></button>
                                </div>
                            </label>

                            <label class="ox-field">
                                <span class="ox-field__label"><?php echo esc_html__('Codex URL', 'oxyai-oxygen'); ?></span>
                                <code class="ox-endpoint" id="oxyai-codex-url" data-base-url="<?php echo esc_attr($mcpUrl); ?>"><?php echo esc_html($codexUrl); ?></code>
                                <div style="display:flex;justify-content:flex-end;margin-top:6px;">
                                    <button type="button" class="ox-btn ox-btn--ghost" id="oxyai-copy-codex-url"><?php echo esc_html__('Copy URL', 'oxyai-oxygen'); ?></button>
                                </div>
                            </label>
                        </section>

                        <section class="ox-modal__section">
                            <h3 class="ox-modal__section-title"><?php echo esc_html__('History', 'oxyai-oxygen'); ?></h3>
                            <label class="ox-chip" style="align-self:flex-start;">
                                <input type="checkbox" name="<?php echo esc_attr($optionName); ?>[history_enabled]" value="1" <?php checked(!empty($settings['history_enabled'])); ?>>
                                <?php echo esc_html__('Store prompt/source history locally', 'oxyai-oxygen'); ?>
                            </label>
                        </section>

                    </form>

                    <div class="ox-modal__footer">
                        <button type="button" class="ox-btn" data-ox-close-setup><?php echo esc_html__('Cancel', 'oxyai-oxygen'); ?></button>
                        <button type="submit" form="oxyai-setup-form" class="ox-btn ox-btn--primary" id="oxyai-submit-setup"><?php echo esc_html__('Save setup', 'oxyai-oxygen'); ?></button>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }
}
