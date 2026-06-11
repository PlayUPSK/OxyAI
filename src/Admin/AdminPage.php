<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Admin;

use OxyAI\Oxygen\Inspirations\SiteInspirationStore;
use OxyAI\Oxygen\Presets\PresetStore;
use OxyAI\Oxygen\Security\CapabilityService;
use OxyAI\Oxygen\Settings\SettingsRepository;
use OxyAI\Oxygen\Updates\GitHubUpdater;

final class AdminPage
{
    private const TABS = ['console', 'settings', 'connect', 'updates'];

    private AdminActions $actions;
    private ClientSnippets $snippets;
    private RequirementsChecker $requirements;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PresetStore $presets,
        private readonly SiteInspirationStore $inspirations,
        private readonly CapabilityService $capabilities,
        private readonly ?GitHubUpdater $updater = null
    ) {
        $this->actions = new AdminActions($settings, $capabilities, $updater);
        $this->snippets = new ClientSnippets();
        $this->requirements = new RequirementsChecker();
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
        $this->actions->register();
    }

    public function render(): void
    {
        if (!$this->capabilities->canUse()) {
            wp_die(esc_html__('You do not have permission to use OxyAI Oxygen.', 'oxyai-oxygen'));
        }

        $activeTab = $this->currentTab();
        $settings = $this->settings->all();
        $settings['mcp_token'] = $this->settings->ensureMcpToken();
        $mcpUrl = rest_url('oxyai/v1/mcp');
        $optionName = OXYAI_OXYGEN_OPTION;

        // Data each partial may use.
        $presets = $this->presets->all();
        $inspirations = $this->inspirations->all();
        $repository = $this->settings;
        $snippets = $this->snippets;
        $requirements = $this->requirements;
        $updater = $this->updater;

        $tabs = [
            'console' => __('Console', 'oxyai-oxygen'),
            'settings' => __('Settings', 'oxyai-oxygen'),
            'connect' => __('Connect', 'oxyai-oxygen'),
            'updates' => __('Updates', 'oxyai-oxygen'),
        ];
        ?>
        <div class="wrap ox-app" data-ox-active-tab="<?php echo esc_attr($activeTab); ?>">

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
                </div>
            </header>

            <?php $this->renderNotice(); ?>

            <nav class="ox-nav" role="tablist" aria-label="<?php echo esc_attr__('OxyAI sections', 'oxyai-oxygen'); ?>">
                <?php foreach ($tabs as $slug => $label): ?>
                    <?php $isActive = $slug === $activeTab; ?>
                    <a
                        class="ox-nav__tab<?php echo $isActive ? ' is-active' : ''; ?>"
                        role="tab"
                        id="oxyai-nav-<?php echo esc_attr($slug); ?>"
                        aria-controls="oxyai-section-<?php echo esc_attr($slug); ?>"
                        aria-selected="<?php echo $isActive ? 'true' : 'false'; ?>"
                        tabindex="<?php echo $isActive ? '0' : '-1'; ?>"
                        href="<?php echo esc_url(add_query_arg(['page' => 'oxyai-oxygen', 'tab' => $slug], admin_url('tools.php'))); ?>"
                        data-ox-nav="<?php echo esc_attr($slug); ?>"
                    ><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php foreach (self::TABS as $slug): ?>
                <section
                    class="ox-section"
                    id="oxyai-section-<?php echo esc_attr($slug); ?>"
                    role="tabpanel"
                    aria-labelledby="oxyai-nav-<?php echo esc_attr($slug); ?>"
                    tabindex="0"
                    <?php echo $slug === $activeTab ? '' : 'hidden'; ?>
                >
                    <?php require __DIR__ . '/views/' . $slug . '.php'; ?>
                </section>
            <?php endforeach; ?>

        </div>
        <?php
    }

    private function currentTab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'console';
        return in_array($tab, self::TABS, true) ? $tab : 'console';
    }

    private function renderNotice(): void
    {
        $notice = isset($_GET['ox_notice']) ? sanitize_key((string) wp_unslash($_GET['ox_notice'])) : '';
        if ($notice === '') {
            return;
        }

        $messages = [
            'token-regenerated' => __('A new MCP token was generated. Update your connected clients.', 'oxyai-oxygen'),
            'updates-saved' => __('Update settings saved.', 'oxyai-oxygen'),
            'updates-checked' => __('Checked GitHub for the latest release.', 'oxyai-oxygen'),
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        echo '<div class="ox-flash" role="status">' . esc_html($messages[$notice]) . '</div>';
    }
}
