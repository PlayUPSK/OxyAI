<?php

declare(strict_types=1);

namespace OxyAI\Oxygen;

use OxyAI\Oxygen\Admin\AdminPage;
use OxyAI\Oxygen\Ai\AiGateway;
use OxyAI\Oxygen\Ai\PlanModeService;
use OxyAI\Oxygen\Ai\PromptCompiler;
use OxyAI\Oxygen\Ai\StructuredOutputValidator;
use OxyAI\Oxygen\Ai\TripleShotService;
use OxyAI\Oxygen\Assets\AssetLoader;
use OxyAI\Oxygen\Codex\PageContextService;
use OxyAI\Oxygen\Codex\OxygenElementCapabilityService;
use OxyAI\Oxygen\Codex\PromptInstructionService;
use OxyAI\Oxygen\Conversion\ConverterKernelAdapter;
use OxyAI\Oxygen\History\HistoryStore;
use OxyAI\Oxygen\Inspirations\SiteInspirationStore;
use OxyAI\Oxygen\Oxygen\OxygenPageMutationService;
use OxyAI\Oxygen\Presets\PresetStore;
use OxyAI\Oxygen\Rest\AiController;
use OxyAI\Oxygen\Rest\CodexController;
use OxyAI\Oxygen\Rest\ConvertController;
use OxyAI\Oxygen\Rest\HistoryController;
use OxyAI\Oxygen\Rest\McpController;
use OxyAI\Oxygen\Security\CapabilityService;
use OxyAI\Oxygen\Settings\SettingsRepository;

final class Plugin
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        $settings = new SettingsRepository();
        $capabilities = new CapabilityService($settings);
        $presets = new PresetStore();
        $inspirations = new SiteInspirationStore();
        $history = new HistoryStore($settings);
        $converter = new ConverterKernelAdapter();
        $aiGateway = new AiGateway($settings, new PromptCompiler($presets, $inspirations), new StructuredOutputValidator());
        $planMode = new PlanModeService($aiGateway, $presets, $inspirations);
        $tripleShot = new TripleShotService($aiGateway);
        $pageContext = new PageContextService();
        $elementCapabilities = new OxygenElementCapabilityService();
        $promptInstructions = new PromptInstructionService($presets, $inspirations, $elementCapabilities);
        $pageMutations = new OxygenPageMutationService();

        (new AdminPage($settings, $presets, $inspirations, $capabilities))->register();
        (new AssetLoader($capabilities))->register();
        (new ConvertController($capabilities, $converter))->register();
        (new AiController($capabilities, $aiGateway, $converter, $history, $planMode, $tripleShot, $inspirations))->register();
        (new HistoryController($capabilities, $history, $presets))->register();
        (new McpController($capabilities, $converter, $aiGateway, $presets, pageMutations: $pageMutations, planMode: $planMode, tripleShot: $tripleShot, inspirations: $inspirations, elementCapabilities: $elementCapabilities))->register();
        (new CodexController($capabilities, $promptInstructions, $pageContext, $converter, $pageMutations))->register();

        add_action('admin_notices', [$this, 'showOxygenNotice']);
    }

    public function showOxygenNotice(): void
    {
        if ($this->isOxygenActive()) {
            return;
        }

        echo '<div class="notice notice-warning"><p>' . esc_html__('OxyAI Oxygen is active, but Oxygen Builder 6 was not detected. Conversion tools are available, but builder insertion requires Oxygen.', 'oxyai-oxygen') . '</p></div>';
    }

    public function isOxygenActive(): bool
    {
        return (
            defined('__BREAKDANCE_PLUGIN_FILE__')
            && defined('BREAKDANCE_MODE')
            && BREAKDANCE_MODE === 'oxygen'
        ) || defined('CT_VERSION') || class_exists('\\OxygenElements\\Container');
    }
}
