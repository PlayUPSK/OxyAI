<?php

declare(strict_types=1);

/**
 * Connect tab — requirements, MCP endpoint/token, client snippets.
 *
 * @var array<string, mixed> $settings
 * @var string $mcpUrl
 * @var \OxyAI\Oxygen\Admin\ClientSnippets $snippets
 * @var \OxyAI\Oxygen\Admin\RequirementsChecker $requirements
 */

if (!defined('ABSPATH')) {
    exit;
}

$token = (string) ($settings['mcp_token'] ?? '');
$maskedToken = (new \OxyAI\Oxygen\Settings\SettingsRepository())->mask($token);
$checks = $requirements->checks();
// Snippets render with the placeholder; JS swaps in the real token on reveal.
$placeholderSnippets = $snippets->render($mcpUrl, null);
?>
<div class="ox-layout ox-layout--wide">
    <main class="ox-main">

        <section class="ox-card">
            <div class="ox-card__head">
                <div>
                    <h2 class="ox-card__title"><?php echo esc_html__('Requirements', 'oxyai-oxygen'); ?></h2>
                    <p class="ox-card__hint"><?php echo esc_html__('Everything an MCP client needs to reach this site.', 'oxyai-oxygen'); ?></p>
                </div>
            </div>
            <div class="ox-card__body">
                <ul class="ox-checklist">
                    <?php foreach ($checks as $check): ?>
                        <li class="ox-checklist__item">
                            <span class="ox-badge <?php echo $check['pass'] ? 'ox-badge--pass' : 'ox-badge--fail'; ?>" aria-hidden="true"><?php echo $check['pass'] ? '✓' : '✕'; ?></span>
                            <span class="ox-checklist__label">
                                <strong><?php echo esc_html($check['label']); ?></strong>
                                <span class="ox-checklist__detail"><?php echo esc_html($check['detail']); ?></span>
                            </span>
                            <span class="ox-sr"><?php echo $check['pass'] ? esc_html__('Pass', 'oxyai-oxygen') : esc_html__('Fail', 'oxyai-oxygen'); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <section class="ox-card">
            <div class="ox-card__head">
                <div>
                    <h2 class="ox-card__title"><?php echo esc_html__('Endpoint & token', 'oxyai-oxygen'); ?></h2>
                    <p class="ox-card__hint"><?php echo esc_html__('Send the token as an x-oxyai-token header or Authorization: Bearer value — never in the URL.', 'oxyai-oxygen'); ?></p>
                </div>
            </div>
            <div class="ox-card__body">
                <label class="ox-field">
                    <span class="ox-field__label"><?php echo esc_html__('MCP endpoint', 'oxyai-oxygen'); ?></span>
                    <code class="ox-endpoint" id="oxyai-mcp-url" data-base-url="<?php echo esc_attr($mcpUrl); ?>"><?php echo esc_html($mcpUrl); ?></code>
                    <div class="ox-field__actions">
                        <button type="button" class="ox-btn ox-btn--ghost" data-ox-copy data-ox-copy-target="oxyai-mcp-url"><?php echo esc_html__('Copy endpoint', 'oxyai-oxygen'); ?></button>
                    </div>
                </label>

                <label class="ox-field">
                    <span class="ox-field__label"><?php echo esc_html__('MCP token', 'oxyai-oxygen'); ?></span>
                    <div class="ox-token-row">
                        <code
                            class="ox-endpoint"
                            id="oxyai-mcp-token"
                            data-token="<?php echo esc_attr($token); ?>"
                            data-masked="<?php echo esc_attr($maskedToken); ?>"
                        ><?php echo esc_html($maskedToken); ?></code>
                        <button type="button" class="ox-btn" data-ox-token-reveal aria-pressed="false"><?php echo esc_html__('Reveal', 'oxyai-oxygen'); ?></button>
                    </div>
                    <div class="ox-field__actions">
                        <button type="button" class="ox-btn ox-btn--ghost" data-ox-token-copy><?php echo esc_html__('Copy token', 'oxyai-oxygen'); ?></button>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Regenerate the MCP token? Connected clients will stop working until updated.', 'oxyai-oxygen')); ?>');" style="display:inline;">
                            <input type="hidden" name="action" value="<?php echo esc_attr(\OxyAI\Oxygen\Admin\AdminActions::REGENERATE_TOKEN); ?>">
                            <?php wp_nonce_field(\OxyAI\Oxygen\Admin\AdminActions::REGENERATE_TOKEN); ?>
                            <button type="submit" class="ox-btn"><?php echo esc_html__('Regenerate', 'oxyai-oxygen'); ?></button>
                        </form>
                    </div>
                    <p class="ox-field__hint"><?php echo esc_html__('The token reveals only in this browser. Snippets below interpolate it after you reveal it.', 'oxyai-oxygen'); ?></p>
                </label>
            </div>
        </section>

        <section class="ox-card">
            <div class="ox-card__head">
                <div>
                    <h2 class="ox-card__title"><?php echo esc_html__('Connect your AI client', 'oxyai-oxygen'); ?></h2>
                    <p class="ox-card__hint"><?php echo esc_html__('Copy a snippet into your client config. Reveal the token first to embed it automatically.', 'oxyai-oxygen'); ?></p>
                </div>
            </div>
            <div class="ox-card__body">
                <div class="ox-snippets">
                    <?php foreach ($placeholderSnippets as $snippet): ?>
                        <article class="ox-snippet" data-ox-snippet="<?php echo esc_attr($snippet['id']); ?>">
                            <header class="ox-snippet__head">
                                <div>
                                    <strong class="ox-snippet__label"><?php echo esc_html($snippet['label']); ?></strong>
                                    <span class="ox-snippet__note"><?php echo esc_html($snippet['note']); ?></span>
                                </div>
                                <button type="button" class="ox-btn ox-btn--ghost" data-ox-snippet-copy><?php echo esc_html__('Copy', 'oxyai-oxygen'); ?></button>
                            </header>
                            <pre class="ox-snippet__code" data-ox-snippet-template="<?php echo esc_attr($snippet['snippet']); ?>"><code><?php echo esc_html($snippet['snippet']); ?></code></pre>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

    </main>
</div>
