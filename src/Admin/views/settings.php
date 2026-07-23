<?php

declare(strict_types=1);

/**
 * Settings tab — AI provider, API keys, models, history.
 *
 * @var array<string, mixed> $settings
 * @var string $optionName
 * @var \OxyAI\Oxygen\Settings\SettingsRepository $repository
 */

if (!defined('ABSPATH')) {
    exit;
}

$provider = (string) ($settings['provider'] ?? 'openai');

$maskedHint = static function (string $masked, string $emptyPlaceholder) {
    if ($masked === '') {
        return $emptyPlaceholder;
    }
    /* translators: %s: masked API key preview, e.g. sk-ab••••wxyz */
    return sprintf(__('Saved: %s — leave blank to keep', 'oxyai-oxygen'), $masked);
};
?>
<form method="post" action="options.php" class="ox-card ox-form">
    <?php settings_fields('oxyai_oxygen_settings'); ?>

    <div class="ox-card__head">
        <div>
            <h2 class="ox-card__title"><?php echo esc_html__('AI provider & keys', 'oxyai-oxygen'); ?></h2>
            <p class="ox-card__hint"><?php echo esc_html__('Used by every Generate action. Keys are encrypted at rest and never shown in full.', 'oxyai-oxygen'); ?></p>
        </div>
    </div>

    <div class="ox-card__body">
        <label class="ox-field">
            <span class="ox-field__label"><?php echo esc_html__('Provider', 'oxyai-oxygen'); ?></span>
            <select name="<?php echo esc_attr($optionName); ?>[provider]" id="oxyai-provider-select" class="ox-select">
                <option value="openai" <?php selected($provider, 'openai'); ?>>OpenAI</option>
                <option value="anthropic" <?php selected($provider, 'anthropic'); ?>>Anthropic</option>
                <option value="compatible" <?php selected($provider, 'compatible'); ?>><?php echo esc_html__('OpenAI-compatible / local', 'oxyai-oxygen'); ?></option>
            </select>
        </label>

        <div class="ox-provider" data-oxyai-provider-fields="openai" <?php echo $provider === 'openai' ? '' : 'hidden'; ?>>
            <div class="ox-row">
                <label class="ox-field">
                    <span class="ox-field__label"><?php echo esc_html__('OpenAI API key', 'oxyai-oxygen'); ?></span>
                    <input type="password" class="ox-input" autocomplete="off" name="<?php echo esc_attr($optionName); ?>[openai_api_key]" placeholder="<?php echo esc_attr($maskedHint($repository->maskedSecret('openai_api_key'), 'sk-…')); ?>">
                </label>
                <label class="ox-field">
                    <span class="ox-field__label"><?php echo esc_html__('Model', 'oxyai-oxygen'); ?></span>
                    <input type="text" class="ox-input" name="<?php echo esc_attr($optionName); ?>[openai_model]" value="<?php echo esc_attr((string) ($settings['openai_model'] ?? '')); ?>">
                </label>
            </div>
        </div>

        <div class="ox-provider" data-oxyai-provider-fields="anthropic" <?php echo $provider === 'anthropic' ? '' : 'hidden'; ?>>
            <div class="ox-row">
                <label class="ox-field">
                    <span class="ox-field__label"><?php echo esc_html__('Anthropic API key', 'oxyai-oxygen'); ?></span>
                    <input type="password" class="ox-input" autocomplete="off" name="<?php echo esc_attr($optionName); ?>[anthropic_api_key]" placeholder="<?php echo esc_attr($maskedHint($repository->maskedSecret('anthropic_api_key'), 'sk-ant-…')); ?>">
                </label>
                <label class="ox-field">
                    <span class="ox-field__label"><?php echo esc_html__('Model', 'oxyai-oxygen'); ?></span>
                    <input type="text" class="ox-input" name="<?php echo esc_attr($optionName); ?>[anthropic_model]" value="<?php echo esc_attr((string) ($settings['anthropic_model'] ?? '')); ?>">
                </label>
            </div>
        </div>

        <div class="ox-provider" data-oxyai-provider-fields="compatible" <?php echo $provider === 'compatible' ? '' : 'hidden'; ?>>
            <label class="ox-field">
                <span class="ox-field__label"><?php echo esc_html__('Endpoint URL', 'oxyai-oxygen'); ?></span>
                <input type="url" class="ox-input" name="<?php echo esc_attr($optionName); ?>[compatible_endpoint]" value="<?php echo esc_attr((string) ($settings['compatible_endpoint'] ?? '')); ?>" placeholder="http://localhost:11434">
            </label>
            <div class="ox-row">
                <label class="ox-field">
                    <span class="ox-field__label"><?php echo esc_html__('API key (optional)', 'oxyai-oxygen'); ?></span>
                    <input type="password" class="ox-input" autocomplete="off" name="<?php echo esc_attr($optionName); ?>[compatible_api_key]" placeholder="<?php echo esc_attr($maskedHint($repository->maskedSecret('compatible_api_key'), __('Optional', 'oxyai-oxygen'))); ?>">
                </label>
                <label class="ox-field">
                    <span class="ox-field__label"><?php echo esc_html__('Model', 'oxyai-oxygen'); ?></span>
                    <input type="text" class="ox-input" name="<?php echo esc_attr($optionName); ?>[compatible_model]" value="<?php echo esc_attr((string) ($settings['compatible_model'] ?? '')); ?>">
                </label>
            </div>
        </div>

        <hr class="ox-rule">

        <h3 class="ox-modal__section-title"><?php echo esc_html__('History', 'oxyai-oxygen'); ?></h3>
        <label class="ox-chip" style="align-self:flex-start;">
            <input type="checkbox" name="<?php echo esc_attr($optionName); ?>[history_enabled]" value="1" <?php checked(!empty($settings['history_enabled'])); ?>>
            <?php echo esc_html__('Store prompt/source history locally', 'oxyai-oxygen'); ?>
        </label>

        <?php
        // Preserve update-related options so saving this form does not reset them.
        ?>
        <input type="hidden" name="<?php echo esc_attr($optionName); ?>[auto_update_enabled]" value="<?php echo !empty($settings['auto_update_enabled']) ? '1' : '0'; ?>">
    </div>

    <div class="ox-card__footer">
        <button type="submit" class="ox-btn ox-btn--primary"><?php echo esc_html__('Save settings', 'oxyai-oxygen'); ?></button>
    </div>
</form>
