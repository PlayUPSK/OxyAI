<?php

declare(strict_types=1);

/**
 * Console tab — Generate / Paste / Apply workflow.
 *
 * @var array<string, mixed> $presets
 * @var array<string, mixed> $inspirations
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
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
                            <strong><?php echo esc_html__('Through any MCP client', 'oxyai-oxygen'); ?></strong>
                            <span><?php echo esc_html__('Connect Claude, Cursor, Codex and more from the Connect tab.', 'oxyai-oxygen'); ?></span>
                        </div>
                    </li>
                </ol>
            </div>
        </section>

    </aside>

</div>
