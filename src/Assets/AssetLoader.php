<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Assets;

use OxyAI\Oxygen\Security\CapabilityService;

final class AssetLoader
{
    public function __construct(private readonly CapabilityService $capabilities)
    {
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdmin']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueBuilder']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueBuilder'], 9999);
    }

    public function enqueueAdmin(string $hook): void
    {
        if ($hook !== 'tools_page_oxyai-oxygen') {
            return;
        }

        wp_enqueue_style('oxyai-admin', OXYAI_OXYGEN_URL . 'assets/css/admin.css', [], OXYAI_OXYGEN_VERSION);
        wp_enqueue_script('oxyai-admin', OXYAI_OXYGEN_URL . 'assets/js/admin.js', [], OXYAI_OXYGEN_VERSION, true);
        wp_localize_script('oxyai-admin', 'oxyaiOxygen', $this->scriptData());
    }

    public function enqueueBuilder(string $hook = ''): void
    {
        if (!$this->capabilities->canUse() || !$this->isBuilderRequest($hook)) {
            return;
        }

        wp_enqueue_style('oxyai-builder', OXYAI_OXYGEN_URL . 'assets/css/builder.css', [], OXYAI_OXYGEN_VERSION);
        wp_enqueue_script('oxyai-builder', OXYAI_OXYGEN_URL . 'assets/js/builder.js', [], OXYAI_OXYGEN_VERSION, true);
        wp_localize_script('oxyai-builder', 'oxyaiOxygen', $this->scriptData());
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptData(): array
    {
        return [
            'restUrl' => esc_url_raw(rest_url('oxyai/v1')),
            'builderCssUrl' => esc_url_raw(OXYAI_OXYGEN_URL . 'assets/css/builder.css'),
            'nonce' => wp_create_nonce('wp_rest'),
            'strings' => [
                'ready' => __('Ready.', 'oxyai-oxygen'),
                'working' => __('Working...', 'oxyai-oxygen'),
                'failed' => __('Request failed.', 'oxyai-oxygen'),
                'converted' => __('Converted successfully.', 'oxyai-oxygen'),
                'generated' => __('AI source generated.', 'oxyai-oxygen'),
                'inserted' => __('Converted JSON pasted into Oxygen.', 'oxyai-oxygen'),
                'copied' => __('Copied to clipboard.', 'oxyai-oxygen'),
            ],
        ];
    }

    private function isBuilderRequest(string $hook = ''): bool
    {
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash((string) $_GET['page'])) : '';
        $oxygen = isset($_GET['oxygen']) ? sanitize_text_field(wp_unslash((string) $_GET['oxygen'])) : '';
        $breakdance = isset($_GET['breakdance']) ? sanitize_text_field(wp_unslash((string) $_GET['breakdance'])) : '';
        $ctBuilder = isset($_GET['ct_builder']) ? sanitize_text_field(wp_unslash((string) $_GET['ct_builder'])) : '';

        if ($page === 'oxyai-oxygen') {
            return false;
        }

        return str_contains($page, 'oxygen')
            || str_contains($page, 'ct_')
            || $oxygen === 'builder'
            || $breakdance === 'builder'
            || $ctBuilder !== ''
            || isset($_GET['ct_inner'])
            || isset($_GET['ct_preview'])
            || !empty($_GET['breakdance_iframe'])
            || !empty($_GET['oxygen_iframe'])
            || !empty($_GET['breakdance_gutenberg_iframe'])
            || (defined('OXYGEN_IFRAME') && OXYGEN_IFRAME);
    }
}
