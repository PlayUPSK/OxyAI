<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Rest;

use OxyAI\Oxygen\Ai\AiGateway;
use OxyAI\Oxygen\Builder\BuilderInsertionService;
use OxyAI\Oxygen\Codex\PageContextService;
use OxyAI\Oxygen\Codex\PromptInstructionService;
use OxyAI\Oxygen\Conversion\ConverterKernelAdapter;
use OxyAI\Oxygen\Oxygen\OxygenPageMutationService;
use OxyAI\Oxygen\Presets\PresetStore;
use OxyAI\Oxygen\Security\CapabilityService;
use OxyAI\Oxygen\Source\SourceBundle;
use WP_Error;
use WP_REST_Request;

final class McpController
{
    use ResponseFactory;

    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly ConverterKernelAdapter $converter,
        private readonly AiGateway $aiGateway,
        private readonly PresetStore $presets,
        private readonly OxygenPageMutationService $pageMutations = new OxygenPageMutationService(),
        private readonly BuilderInsertionService $insertionService = new BuilderInsertionService(),
        private ?PromptInstructionService $instructions = null,
        private ?PageContextService $pages = null
    ) {
        $this->instructions = $this->instructions ?: new PromptInstructionService($this->presets);
        $this->pages = $this->pages ?: new PageContextService();
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('oxyai/v1', '/mcp', [
                'methods' => 'POST',
                'permission_callback' => fn (WP_REST_Request $request): bool => $this->capabilities->canUseMcp($request),
                'callback' => fn (WP_REST_Request $request) => $this->handle($request),
            ]);
        });
    }

    public function handle(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (is_array($json) && isset($json['jsonrpc'], $json['method'])) {
            return $this->handleJsonRpc($json);
        }

        $tool = (string) $request->get_param('tool');
        $input = $request->get_param('input');
        $input = is_array($input) ? $input : [];

        $result = $this->callTool($tool, $input);

        if (is_wp_error($result)) {
            return $this->error($result);
        }

        if ($result instanceof \OxyAI\Oxygen\Source\SourceBundle) {
            return $this->ok(['success' => true, 'source' => $result->toArray()]);
        }

        return $this->ok($result);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleJsonRpc(array $request)
    {
        $id = $request['id'] ?? null;
        $method = (string) ($request['method'] ?? '');
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        if ($method === 'initialize') {
            return $this->ok($this->jsonRpcResult($id, [
                'protocolVersion' => '2024-11-05',
                'serverInfo' => [
                    'name' => 'oxyai-oxygen',
                    'version' => OXYAI_OXYGEN_VERSION,
                ],
                'capabilities' => [
                    'tools' => new \stdClass(),
                ],
            ]));
        }

        if ($method === 'tools/list') {
            return $this->ok($this->jsonRpcResult($id, ['tools' => $this->toolDefinitions()]));
        }

        if ($method === 'tools/call') {
            $name = (string) ($params['name'] ?? '');
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $result = $this->callTool($name, $arguments);

            if (is_wp_error($result)) {
                return $this->ok($this->jsonRpcError($id, -32000, $result->get_error_message(), $result->get_error_data()));
            }

            if ($result instanceof \OxyAI\Oxygen\Source\SourceBundle) {
                $result = ['success' => true, 'source' => $result->toArray()];
            }

            return $this->ok($this->jsonRpcResult($id, [
                'content' => [[
                    'type' => 'text',
                    'text' => wp_json_encode($result),
                ]],
            ]));
        }

        if ($method === 'notifications/initialized') {
            return $this->ok(null, 202);
        }

        return $this->ok($this->jsonRpcError($id, -32601, 'Method not found.'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function callTool(string $tool, array $input)
    {
        return match ($tool) {
            'get_prompt_instructions' => ['success' => true, 'instructions' => $this->instructions->getInstructions()],
            'list_oxygen_pages' => ['success' => true, 'pages' => $this->pages->listPages((string) ($input['search'] ?? ''))],
            'get_page_context' => $this->pages->getContext((int) ($input['postId'] ?? $input['id'] ?? 0)),
            'stage_page_payload' => $this->pages->stagePayload((int) ($input['postId'] ?? $input['id'] ?? 0), $input),
            'convert_and_stage_page' => $this->convertAndStagePage($input),
            'get_oxygen_tree' => $this->pageMutations->getTree((int) ($input['postId'] ?? $input['id'] ?? 0)),
            'apply_html_to_oxygen_page' => $this->applyHtmlToPage($input),
            'apply_oxygen_json_to_page' => $this->applyOxygenJsonToPage($input),
            'list_oxygen_page_backups' => [
                'success' => true,
                'backups' => $this->pageMutations->listBackups((int) ($input['postId'] ?? $input['id'] ?? 0)),
            ],
            'restore_oxygen_page_backup' => $this->pageMutations->restoreBackup(
                (int) ($input['postId'] ?? $input['id'] ?? 0),
                (string) ($input['backupId'] ?? '')
            ),
            'list_design_presets' => ['success' => true, 'presets' => $this->presets->all()],
            'generate_html_css_js' => $this->aiGateway->generate($input),
            'preview_conversion' => $this->converter->preview(SourceBundle::fromArray($input), is_array($input['options'] ?? null) ? $input['options'] : []),
            'convert_html_to_oxygen' => $this->converter->convert(SourceBundle::fromArray($input), is_array($input['options'] ?? null) ? $input['options'] : []),
            'replace_selected_subtree' => $this->replaceSelectedSubtree($input),
            'insert_into_builder' => $this->prepareInsert($input),
            default => new WP_Error('oxyai_unknown_mcp_tool', __('Unknown MCP tool.', 'oxyai-oxygen'), ['status' => 400]),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function toolDefinitions(): array
    {
        return [
            $this->tool('get_prompt_instructions', 'Use this first. Fetch OxyAI prompt rules, output schema, and design presets for generating Oxygen-compatible HTML/CSS/JS.', []),
            $this->tool('list_oxygen_pages', 'Find WordPress pages/templates that can receive generated OxyAI payloads.', [
                'search' => ['type' => 'string', 'description' => 'Optional page title search.'],
            ]),
            $this->tool('get_page_context', 'Fetch page metadata, Oxygen-related meta keys, and any staged OxyAI handoff for a page.', [
                'postId' => ['type' => 'integer', 'description' => 'WordPress post/page/template ID.'],
            ], ['postId']),
            $this->tool('stage_page_payload', 'Stage generated HTML/CSS/JS for a specific page. The user can apply it from the OxyAI Oxygen builder sidebar.', [
                'postId' => ['type' => 'integer'],
                'html' => ['type' => 'string'],
                'css' => ['type' => 'string'],
                'js' => ['type' => 'string'],
                'prompt' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
            ], ['postId', 'html']),
            $this->tool('convert_and_stage_page', 'Convert HTML/CSS/JS, store the Oxygen payload with the page handoff, and let the user apply it from the builder sidebar.', [
                'postId' => ['type' => 'integer'],
                'html' => ['type' => 'string'],
                'css' => ['type' => 'string'],
                'js' => ['type' => 'string'],
                'prompt' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'options' => ['type' => 'object'],
            ], ['postId', 'html']),
            $this->tool('get_oxygen_tree', 'Read the persisted Oxygen page tree, node count, next node id, and available OxyAI restore backups.', [
                'postId' => ['type' => 'integer'],
            ], ['postId']),
            $this->tool('apply_html_to_oxygen_page', 'Directly convert HTML/CSS/JS and apply it to a WordPress Oxygen page. WRITES TO THE LIVE PAGE — always call once with dryRun:true first to inspect the proposed tree unless the user has explicitly approved the content. A restore backup is created when dryRun is false, but treat direct writes as hard to reverse: verify the page renders after applying. Use append for new sections; replace_node for a selected element; replace only when overwriting the whole page is intended.', [
                'postId' => ['type' => 'integer'],
                'html' => ['type' => 'string'],
                'css' => ['type' => 'string'],
                'js' => ['type' => 'string'],
                'operation' => ['type' => 'string', 'description' => 'append, replace, or replace_node. Defaults to append.'],
                'targetNodeId' => ['type' => 'integer', 'description' => 'Required for replace_node.'],
                'dryRun' => ['type' => 'boolean', 'description' => 'Return the proposed tree without saving.'],
                'options' => ['type' => 'object'],
            ], ['postId', 'html']),
            $this->tool('apply_oxygen_json_to_page', 'Directly apply a converted Oxygen rawJson/documentTree/element payload to a page. WRITES TO THE LIVE PAGE — always call once with dryRun:true first unless the user has explicitly approved the content. A restore backup is created when dryRun is false, but treat direct writes as hard to reverse: verify the page renders after applying. For replace_node, pass either a documentTree wrapper or a single node object with id, data, and children.', [
                'postId' => ['type' => 'integer'],
                'rawJson' => ['type' => 'string'],
                'oxygen' => ['type' => 'object'],
                'operation' => ['type' => 'string', 'description' => 'append, replace, or replace_node. Defaults to append.'],
                'targetNodeId' => ['type' => 'integer'],
                'dryRun' => ['type' => 'boolean'],
            ], ['postId']),
            $this->tool('list_oxygen_page_backups', 'List recent OxyAI restore backups for a page.', [
                'postId' => ['type' => 'integer'],
            ], ['postId']),
            $this->tool('restore_oxygen_page_backup', 'Restore a previous OxyAI backup for an Oxygen page.', [
                'postId' => ['type' => 'integer'],
                'backupId' => ['type' => 'string'],
            ], ['postId', 'backupId']),
            $this->tool('convert_html_to_oxygen', 'Convert HTML/CSS/JS into builder-safe Oxygen payload JSON.', [
                'html' => ['type' => 'string'],
                'css' => ['type' => 'string'],
                'js' => ['type' => 'string'],
            ], ['html']),
            $this->tool('preview_conversion', 'Preview conversion summary and audit before creating a payload.', [
                'html' => ['type' => 'string'],
                'css' => ['type' => 'string'],
                'js' => ['type' => 'string'],
            ], ['html']),
            $this->tool('generate_html_css_js', 'Generate a strict HTML/CSS/JS source bundle from a prompt using the configured provider.', [
                'prompt' => ['type' => 'string'],
                'preset' => ['type' => 'string'],
                'context' => ['type' => 'object'],
            ], ['prompt']),
            $this->tool('replace_selected_subtree', 'Generate and convert a replacement payload for a captured builder subtree. Use from an active builder context or with captured context.', [
                'prompt' => ['type' => 'string'],
                'context' => ['type' => 'object'],
                'options' => ['type' => 'object'],
            ], ['prompt']),
            $this->tool('insert_into_builder', 'Prepare a converted Oxygen payload for browser-session builder insertion.', [
                'rawJson' => ['type' => 'string'],
                'oxygen' => ['type' => 'object'],
            ]),
            $this->tool('list_design_presets', 'List design presets available to OxyAI.', []),
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<int, string> $required
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => true,
            ],
        ];
    }

    /**
     * @param mixed $id
     * @param mixed $result
     * @return array<string, mixed>
     */
    private function jsonRpcResult($id, $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param mixed $id
     * @param mixed $data
     * @return array<string, mixed>
     */
    private function jsonRpcError($id, int $code, string $message, $data = null): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
                'data' => $data,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function replaceSelectedSubtree(array $input)
    {
        if (trim((string) ($input['prompt'] ?? '')) === '') {
            return new WP_Error('oxyai_empty_prompt', __('Prompt is required for subtree replacement.', 'oxyai-oxygen'), ['status' => 400]);
        }

        $source = $this->aiGateway->generate([
            'prompt' => (string) $input['prompt'],
            'provider' => $input['provider'] ?? null,
            'preset' => $input['preset'] ?? null,
            'context' => $input['context'] ?? [],
        ]);
        if (is_wp_error($source)) {
            return $source;
        }

        $converted = $this->converter->convert($source, is_array($input['options'] ?? null) ? $input['options'] : []);
        if (is_wp_error($converted)) {
            return $converted;
        }

        $converted['replacement'] = $this->insertionService->prepareInsertPayload($converted['oxygen'] ?? []);
        return $converted;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function prepareInsert(array $input): array
    {
        $oxygen = is_array($input['oxygen'] ?? null) ? $input['oxygen'] : $input;

        return [
            'success' => true,
            'insert' => $this->insertionService->prepareInsertPayload($oxygen),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function convertAndStagePage(array $input)
    {
        $postId = (int) ($input['postId'] ?? $input['id'] ?? 0);
        $converted = $this->converter->convert(
            SourceBundle::fromArray($input),
            is_array($input['options'] ?? null) ? $input['options'] : []
        );
        if (is_wp_error($converted)) {
            return $converted;
        }

        $payload = $input;
        $payload['oxygen'] = $converted['oxygen'] ?? [];
        $payload['insert'] = $this->insertionService->prepareInsertPayload(is_array($converted['oxygen'] ?? null) ? $converted['oxygen'] : []);
        $staged = $this->pages->stagePayload($postId, $payload);

        return [
            'success' => !empty($staged['success']),
            'handoff' => $staged['handoff'] ?? null,
            'oxygen' => $converted['oxygen'] ?? null,
            'audit' => $converted['oxygen']['audit'] ?? null,
            'message' => __('Converted payload staged for the page.', 'oxyai-oxygen'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function applyHtmlToPage(array $input)
    {
        $postId = (int) ($input['postId'] ?? $input['id'] ?? 0);
        $converted = $this->converter->convert(
            SourceBundle::fromArray($input),
            is_array($input['options'] ?? null) ? $input['options'] : []
        );
        if (is_wp_error($converted)) {
            return $converted;
        }

        $applied = $this->pageMutations->applyOxygen(
            $postId,
            is_array($converted['oxygen'] ?? null) ? $converted['oxygen'] : [],
            $input
        );
        if (is_wp_error($applied)) {
            return $applied;
        }

        $applied['conversion'] = [
            'audit' => $converted['oxygen']['audit'] ?? null,
            'stats' => $converted['oxygen']['stats'] ?? null,
        ];

        return $applied;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function applyOxygenJsonToPage(array $input)
    {
        $postId = (int) ($input['postId'] ?? $input['id'] ?? 0);
        $oxygen = is_array($input['oxygen'] ?? null) ? $input['oxygen'] : $input;

        return $this->pageMutations->applyOxygen($postId, $oxygen, $input);
    }
}
