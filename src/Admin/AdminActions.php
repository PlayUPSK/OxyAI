<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Admin;

use OxyAI\Oxygen\Security\CapabilityService;
use OxyAI\Oxygen\Settings\SettingsRepository;
use OxyAI\Oxygen\Updates\GitHubUpdater;

/**
 * Nonce-protected, capability-gated state-changing actions for the admin page,
 * handled via admin-post.php so they work without the JS REST stack.
 */
final class AdminActions
{
    public const REGENERATE_TOKEN = 'oxyai_oxygen_regenerate_token';
    public const SAVE_UPDATES = 'oxyai_oxygen_save_updates';
    public const CHECK_UPDATES = 'oxyai_oxygen_check_updates';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CapabilityService $capabilities,
        private readonly ?GitHubUpdater $updater = null
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::REGENERATE_TOKEN, [$this, 'handleRegenerateToken']);
        add_action('admin_post_' . self::SAVE_UPDATES, [$this, 'handleSaveUpdates']);
        add_action('admin_post_' . self::CHECK_UPDATES, [$this, 'handleCheckUpdates']);
    }

    public function handleRegenerateToken(): void
    {
        $this->guard(self::REGENERATE_TOKEN);
        $this->settings->regenerateMcpToken();
        $this->redirect('connect', 'token-regenerated');
    }

    public function handleSaveUpdates(): void
    {
        $this->guard(self::SAVE_UPDATES);

        $this->settings->set('auto_update_enabled', !empty($_POST['auto_update_enabled']));

        $token = isset($_POST['github_token'])
            ? trim((string) wp_unslash($_POST['github_token']))
            : '';
        // Only overwrite when a new value is supplied (blank = keep current).
        if ($token !== '') {
            $this->settings->set('github_token', $token);
        }

        $this->redirect('updates', 'updates-saved');
    }

    public function handleCheckUpdates(): void
    {
        $this->guard(self::CHECK_UPDATES);
        if ($this->updater !== null) {
            $this->updater->refresh();
        }
        $this->redirect('updates', 'updates-checked');
    }

    private function guard(string $action): void
    {
        if (!$this->capabilities->canUse()) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'oxyai-oxygen'));
        }
        check_admin_referer($action);
    }

    private function redirect(string $tab, string $notice): void
    {
        $url = add_query_arg(
            [
                'page' => 'oxyai-oxygen',
                'tab' => $tab,
                'ox_notice' => $notice,
            ],
            admin_url('tools.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}
