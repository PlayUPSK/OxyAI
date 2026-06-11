<?php

declare(strict_types=1);

/**
 * Updates tab — version status, GitHub token, auto-update, changelog.
 *
 * @var array<string, mixed> $settings
 * @var string $optionName
 * @var \OxyAI\Oxygen\Settings\SettingsRepository $repository
 * @var \OxyAI\Oxygen\Updates\GitHubUpdater|null $updater
 */

if (!defined('ABSPATH')) {
    exit;
}

$status = $updater !== null
    ? $updater->status()
    : [
        'installed' => defined('OXYAI_OXYGEN_VERSION') ? OXYAI_OXYGEN_VERSION : '',
        'latest' => '',
        'update_available' => false,
        'changelog' => '',
        'release_url' => '',
        'published_at' => '',
        'verification' => 'unknown',
        'checked' => false,
    ];

$verificationLabels = [
    'verified' => __('Verified (sha256 matched)', 'oxyai-oxygen'),
    'verifiable' => __('Will be verified (sha256 digest published)', 'oxyai-oxygen'),
    'unverified' => __('Unverified (no checksum published)', 'oxyai-oxygen'),
    'failed' => __('Verification failed — last update was blocked', 'oxyai-oxygen'),
    'unknown' => __('Unknown', 'oxyai-oxygen'),
];
$verification = (string) ($status['verification'] ?? 'unknown');
$verificationLabel = $verificationLabels[$verification] ?? $verificationLabels['unknown'];
$verificationPass = in_array($verification, ['verified', 'verifiable'], true);
?>
<div class="ox-layout ox-layout--wide">
    <main class="ox-main">

        <section class="ox-card">
            <div class="ox-card__head">
                <div>
                    <h2 class="ox-card__title"><?php echo esc_html__('Plugin updates', 'oxyai-oxygen'); ?></h2>
                    <p class="ox-card__hint"><?php echo esc_html__('Updates are served from GitHub releases and verified before install.', 'oxyai-oxygen'); ?></p>
                </div>
                <span class="ox-status <?php echo !empty($status['update_available']) ? 'is-working' : 'is-success'; ?>">
                    <?php echo !empty($status['update_available'])
                        ? esc_html__('Update available', 'oxyai-oxygen')
                        : esc_html__('Up to date', 'oxyai-oxygen'); ?>
                </span>
            </div>
            <div class="ox-card__body">
                <dl class="ox-meta">
                    <div class="ox-meta__row">
                        <dt><?php echo esc_html__('Installed version', 'oxyai-oxygen'); ?></dt>
                        <dd><?php echo esc_html((string) ($status['installed'] ?? '')); ?></dd>
                    </div>
                    <div class="ox-meta__row">
                        <dt><?php echo esc_html__('Latest release', 'oxyai-oxygen'); ?></dt>
                        <dd>
                            <?php if (($status['latest'] ?? '') !== ''): ?>
                                <?php echo esc_html((string) $status['latest']); ?>
                                <?php if (($status['release_url'] ?? '') !== ''): ?>
                                    — <a href="<?php echo esc_url((string) $status['release_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('view on GitHub', 'oxyai-oxygen'); ?></a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php echo esc_html__('Not checked yet', 'oxyai-oxygen'); ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div class="ox-meta__row">
                        <dt><?php echo esc_html__('Package verification', 'oxyai-oxygen'); ?></dt>
                        <dd>
                            <span class="ox-badge <?php echo $verificationPass ? 'ox-badge--pass' : 'ox-badge--fail'; ?>" aria-hidden="true"><?php echo $verificationPass ? '✓' : '!'; ?></span>
                            <?php echo esc_html($verificationLabel); ?>
                        </dd>
                    </div>
                </dl>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ox-field__actions">
                    <input type="hidden" name="action" value="<?php echo esc_attr(\OxyAI\Oxygen\Admin\AdminActions::CHECK_UPDATES); ?>">
                    <?php wp_nonce_field(\OxyAI\Oxygen\Admin\AdminActions::CHECK_UPDATES); ?>
                    <button type="submit" class="ox-btn ox-btn--primary"><?php echo esc_html__('Check for updates now', 'oxyai-oxygen'); ?></button>
                </form>
            </div>
        </section>

        <section class="ox-card">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(\OxyAI\Oxygen\Admin\AdminActions::SAVE_UPDATES); ?>">
                <?php wp_nonce_field(\OxyAI\Oxygen\Admin\AdminActions::SAVE_UPDATES); ?>

                <div class="ox-card__head">
                    <div>
                        <h2 class="ox-card__title"><?php echo esc_html__('Update settings', 'oxyai-oxygen'); ?></h2>
                        <p class="ox-card__hint"><?php echo esc_html__('A token is only required for private repositories or to raise GitHub rate limits.', 'oxyai-oxygen'); ?></p>
                    </div>
                </div>

                <div class="ox-card__body">
                    <label class="ox-chip" style="align-self:flex-start;">
                        <input type="checkbox" name="auto_update_enabled" value="1" <?php checked(!empty($settings['auto_update_enabled'])); ?>>
                        <?php echo esc_html__('Enable automatic background updates', 'oxyai-oxygen'); ?>
                    </label>
                    <p class="ox-field__hint"><?php echo esc_html__('The OXYAI_OXYGEN_DISABLE_AUTO_UPDATES constant and the oxyai_oxygen_enable_auto_updates filter still take precedence.', 'oxyai-oxygen'); ?></p>

                    <label class="ox-field" style="margin-top:14px;">
                        <span class="ox-field__label"><?php echo esc_html__('GitHub token', 'oxyai-oxygen'); ?></span>
                        <input type="password" class="ox-input" autocomplete="off" name="github_token" placeholder="<?php
                            $maskedGh = $repository->maskedSecret('github_token');
                            echo esc_attr($maskedGh !== ''
                                /* translators: %s: masked token preview */
                                ? sprintf(__('Saved: %s — leave blank to keep', 'oxyai-oxygen'), $maskedGh)
                                : 'ghp_…');
                        ?>">
                    </label>
                    <p class="ox-field__hint"><?php echo esc_html__('Encrypted at rest. The OXYAI_OXYGEN_GITHUB_TOKEN constant overrides this value.', 'oxyai-oxygen'); ?></p>
                </div>

                <div class="ox-card__footer">
                    <button type="submit" class="ox-btn ox-btn--primary"><?php echo esc_html__('Save update settings', 'oxyai-oxygen'); ?></button>
                </div>
            </form>
        </section>

        <?php if (($status['changelog'] ?? '') !== ''): ?>
            <section class="ox-card">
                <div class="ox-card__head">
                    <div>
                        <h2 class="ox-card__title"><?php echo esc_html__('Latest release notes', 'oxyai-oxygen'); ?></h2>
                        <?php if (($status['published_at'] ?? '') !== ''): ?>
                            <p class="ox-card__hint"><?php echo esc_html((string) $status['published_at']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ox-card__body">
                    <div class="ox-changelog"><?php echo wp_kses_post((string) $status['changelog']); ?></div>
                </div>
            </section>
        <?php endif; ?>

    </main>
</div>
