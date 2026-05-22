<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Rest;

use OxyAI\Oxygen\Ai\AiGateway;
use OxyAI\Oxygen\Ai\PlanModeService;
use OxyAI\Oxygen\Ai\TripleShotService;
use OxyAI\Oxygen\Conversion\ConverterKernelAdapter;
use OxyAI\Oxygen\History\HistoryStore;
use OxyAI\Oxygen\Inspirations\SiteInspirationStore;
use OxyAI\Oxygen\Security\CapabilityService;
use WP_Error;
use WP_REST_Request;

final class AiController
{
    use ResponseFactory;

    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly AiGateway $aiGateway,
        private readonly ConverterKernelAdapter $converter,
        private readonly HistoryStore $history,
        private readonly PlanModeService $planMode,
        private readonly TripleShotService $tripleShot,
        private readonly SiteInspirationStore $inspirations
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('oxyai/v1', '/generate', [
                'methods' => 'POST',
                'permission_callback' => fn (): bool => $this->capabilities->canUseRest(),
                'callback' => fn (WP_REST_Request $request) => $this->generate($request),
            ]);

            register_rest_route('oxyai/v1', '/generate-and-convert', [
                'methods' => 'POST',
                'permission_callback' => fn (): bool => $this->capabilities->canUseRest(),
                'callback' => fn (WP_REST_Request $request) => $this->generateAndConvert($request),
            ]);

            register_rest_route('oxyai/v1', '/plan', [
                'methods' => 'POST',
                'permission_callback' => fn (): bool => $this->capabilities->canUseRest(),
                'callback' => fn (WP_REST_Request $request) => $this->plan($request),
            ]);

            register_rest_route('oxyai/v1', '/triple-shot', [
                'methods' => 'POST',
                'permission_callback' => fn (): bool => $this->capabilities->canUseRest(),
                'callback' => fn (WP_REST_Request $request) => $this->tripleShot($request),
            ]);

            register_rest_route('oxyai/v1', '/site-inspirations', [
                'methods' => 'GET',
                'permission_callback' => fn (): bool => $this->capabilities->canUseRest(),
                'callback' => fn () => $this->ok(['success' => true, 'siteInspirations' => $this->inspirations->all()]),
            ]);
        });
    }

    public function generate(WP_REST_Request $request)
    {
        $input = $this->input($request);
        if (trim((string) ($input['prompt'] ?? '')) === '') {
            return $this->error(new WP_Error('oxyai_empty_prompt', __('Prompt is required.', 'oxyai-oxygen'), ['status' => 400]));
        }

        $source = $this->aiGateway->generate($input);
        if (is_wp_error($source)) {
            return $this->error($source);
        }

        $payload = [
            'success' => true,
            'source' => $source->toArray(),
        ];

        $this->history->add([
            'type' => 'generate',
            'prompt' => (string) ($input['prompt'] ?? ''),
            'provider' => (string) ($input['provider'] ?? ''),
            'preset' => (string) ($input['preset'] ?? ''),
            'source' => $source->toArray(),
        ]);

        return $this->ok($payload);
    }

    public function generateAndConvert(WP_REST_Request $request)
    {
        $input = $this->input($request);
        $source = $this->aiGateway->generate($input);
        if (is_wp_error($source)) {
            return $this->error($source);
        }

        $options = $request->get_param('options');
        $converted = $this->converter->convert($source, is_array($options) ? $options : []);
        if (is_wp_error($converted)) {
            return $this->error($converted);
        }

        $this->history->add([
            'type' => 'generate-and-convert',
            'prompt' => (string) ($input['prompt'] ?? ''),
            'provider' => (string) ($input['provider'] ?? ''),
            'preset' => (string) ($input['preset'] ?? ''),
            'source' => $source->toArray(),
            'audit' => $converted['oxygen']['audit'] ?? [],
        ]);

        return $this->ok($converted);
    }

    public function plan(WP_REST_Request $request)
    {
        $result = $this->planMode->plan($this->input($request));
        return is_wp_error($result) ? $this->error($result) : $this->ok($result);
    }

    public function tripleShot(WP_REST_Request $request)
    {
        $result = $this->tripleShot->generate($this->input($request));
        if (!is_wp_error($result)) {
            $this->history->add([
                'type' => 'triple-shot',
                'prompt' => (string) ($request->get_param('prompt') ?? ''),
                'provider' => (string) ($request->get_param('provider') ?? ''),
                'preset' => (string) ($request->get_param('preset') ?? ''),
                'source' => $result,
            ]);
        }

        return is_wp_error($result) ? $this->error($result) : $this->ok($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(WP_REST_Request $request): array
    {
        return [
            'prompt' => $request->get_param('prompt'),
            'provider' => $request->get_param('provider'),
            'preset' => $request->get_param('preset'),
            'siteInspiration' => $request->get_param('siteInspiration'),
            'context' => $request->get_param('context'),
        ];
    }
}
