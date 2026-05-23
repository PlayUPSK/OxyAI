<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Rest;

use OxyAI\Oxygen\Ai\AiGateway;
use OxyAI\Oxygen\Builder\BuilderInsertionService;
use OxyAI\Oxygen\Codex\OxygenElementCapabilityService;
use OxyAI\Oxygen\Codex\PageContextService;
use OxyAI\Oxygen\Codex\PromptInstructionService;
use OxyAI\Oxygen\Conversion\ConverterKernelAdapter;
use OxyAI\Oxygen\Ai\PlanModeService;
use OxyAI\Oxygen\Ai\TripleShotService;
use OxyAI\Oxygen\Inspirations\SiteInspirationStore;
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
        private readonly ?PlanModeService $planMode = null,
        private readonly ?TripleShotService $tripleShot = null,
        private readonly SiteInspirationStore $inspirations = new SiteInspirationStore(),
        private readonly OxygenElementCapabilityService $elementCapabilities = new OxygenElementCapabilityService(),
        private readonly BuilderInsertionService $insertionService = new BuilderInsertionService(),
        private ?PromptInstructionService $instructions = null,
        private ?PageContextService $pages = null
    ) {
        $this->instructions = $this->instructions ?: new PromptInstructionService($this->presets, $this->inspirations, $this->elementCapabilities);
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
        $encodingWarnings = $this->inspectRequestEncoding($request);

        $json = $request->get_json_params();
        if (is_array($json) && isset($json['jsonrpc'], $json['method'])) {
            return $this->handleJsonRpc($json, $encodingWarnings);
        }

        $tool = (string) $request->get_param('tool');
        $input = $request->get_param('input');
        $input = is_array($input) ? $input : [];

        if ($this->shouldBlockUnsafeEncodedApply($tool, $input, $encodingWarnings)) {
            return $this->error(new WP_Error(
                'oxyai_non_ascii_payload_blocked',
                __('Raw non-ASCII bytes are not accepted for live apply_* MCP writes. Retry with dryRun=true or send non-ASCII characters as JSON unicode escapes (\\uXXXX).', 'oxyai-oxygen'),
                ['status' => 400, 'mcpWarnings' => $encodingWarnings]
            ));
        }

        $result = $this->callTool($tool, $input);

        if (is_wp_error($result)) {
            return $this->error($this->errorWithWarnings($result, $encodingWarnings));
        }

        if ($result instanceof \OxyAI\Oxygen\Source\SourceBundle) {
            return $this->ok($this->attachWarnings(['success' => true, 'source' => $result->toArray()], $encodingWarnings));
        }

        if (is_array($result)) {
            $result = $this->attachWarnings($result, $encodingWarnings);
        }

        return $this->ok($result);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function inspectRequestEncoding(WP_REST_Request $request): array
    {
        $body = (string) $request->get_body();
        if ($body === '' || preg_match('/[^\x00-\x7F]/', $body) !== 1) {
            return [];
        }

        $warning = [
            'code' => 'non_ascii_payload',
            'severity' => 'warning',
            'message' => 'Request body contains raw non-ASCII bytes. WordPress storage paths can double-encode these and corrupt diacritics. Send non-ASCII characters as JSON unicode escapes (\\uXXXX) in html, css, js, and oxygen fields.',
        ];

        do_action('oxyai_oxygen_mcp_non_ascii_input', $body);

        return [$warning];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>> $warnings
     * @return array<string, mixed>
     */
    private function attachWarnings(array $payload, array $warnings): array
    {
        if ($warnings === []) {
            return $payload;
        }

        $existing = isset($payload['mcpWarnings']) && is_array($payload['mcpWarnings']) ? $payload['mcpWarnings'] : [];
        $payload['mcpWarnings'] = array_merge($existing, $warnings);

        return $payload;
    }

    /**
     * @param array<int, array<string, string>> $warnings
     */
    private function errorWithWarnings(WP_Error $error, array $warnings): WP_Error
    {
        if ($warnings === []) {
            return $error;
        }

        $data = $error->get_error_data();
        $data = is_array($data) ? $data : ['data' => $data];
        $data['mcpWarnings'] = array_merge(
            isset($data['mcpWarnings']) && is_array($data['mcpWarnings']) ? $data['mcpWarnings'] : [],
            $warnings
        );

        return new WP_Error($error->get_error_code(), $error->get_error_message(), $data);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<int, array<string, string>> $encodingWarnings
     */
    private function handleJsonRpc(array $request, array $encodingWarnings = [])
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

            if ($this->shouldBlockUnsafeEncodedApply($name, $arguments, $encodingWarnings)) {
                return $this->ok($this->jsonRpcError($id, -32000, __('Raw non-ASCII bytes are not accepted for live apply_* MCP writes. Retry with dryRun=true or send non-ASCII characters as JSON unicode escapes (\\uXXXX).', 'oxyai-oxygen'), [
                    'status' => 400,
                    'mcpWarnings' => $encodingWarnings,
                ]));
            }

            $result = $this->callTool($name, $arguments);

            if (is_wp_error($result)) {
                $result = $this->errorWithWarnings($result, $encodingWarnings);
                return $this->ok($this->jsonRpcError($id, -32000, $result->get_error_message(), $result->get_error_data()));
            }

            if ($result instanceof \OxyAI\Oxygen\Source\SourceBundle) {
                $result = ['success' => true, 'source' => $result->toArray()];
            }

            if (is_array($result)) {
                $result = $this->attachWarnings($result, $encodingWarnings);
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
     * @param array<int, array<string, string>> $encodingWarnings
     */
    private function shouldBlockUnsafeEncodedApply(string $tool, array $input, array $encodingWarnings): bool
    {
        if ($encodingWarnings === [] || !in_array($tool, ['apply_html_to_oxygen_page', 'apply_oxygen_json_to_page'], true)) {
            return false;
        }

        $dryRun = $input['dryRun'] ?? ($input['options']['dryRun'] ?? false);
        return empty($dryRun);
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
            'recompile_oxygen_css' => $this->pageMutations->recompileCss(
                (int) ($input['postId'] ?? $input['id'] ?? 0)
            ),
            'list_design_presets' => ['success' => true, 'presets' => $this->presets->all()],
            'list_site_inspirations' => ['success' => true, 'siteInspirations' => $this->inspirations->all()],
            'list_oxygen_element_capabilities' => $this->elementCapabilities->all(
                is_scalar($input['elementType'] ?? null) ? (string) $input['elementType'] : null
            ),
            'plan_generation' => $this->planGeneration($input),
            'triple_shot_generation' => $this->tripleShotGeneration($input),
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
        $tools = [
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
            $this->tool('apply_html_to_oxygen_page', 'WRITES TO THE LIVE PAGE - always call once with dryRun:true first to inspect the proposed tree unless the user has explicitly approved the content. Directly converts HTML/CSS/JS and applies it to a WordPress Oxygen page. A restore backup is created when dryRun is false, but treat direct writes as hard to reverse: verify the page renders after applying. Use append for new sections; replace_node for a selected element; replace only when overwriting the whole page is intended.', [
                'postId' => ['type' => 'integer'],
                'html' => ['type' => 'string'],
                'css' => ['type' => 'string'],
                'js' => ['type' => 'string'],
                'operation' => ['type' => 'string', 'description' => 'append, replace, or replace_node. Defaults to append.'],
                'targetNodeId' => ['type' => 'integer', 'description' => 'Required for replace_node.'],
                'dryRun' => ['type' => 'boolean', 'description' => 'Return the proposed tree without saving.'],
                'registerSelectors' => ['type' => 'boolean', 'description' => 'Register and attach semantic classes as Oxygen selector IDs. Defaults true.'],
                'options' => ['type' => 'object'],
            ], ['postId', 'html']),
            $this->tool('apply_oxygen_json_to_page', 'WRITES TO THE LIVE PAGE - always call once with dryRun:true first unless the user has explicitly approved the content. Directly applies a converted Oxygen rawJson/documentTree/element payload to a page. A restore backup is created when dryRun is false, but treat direct writes as hard to reverse: verify the page renders after applying. For replace_node, pass either a documentTree wrapper or a single node object with id, data, and children.', [
                'postId' => ['type' => 'integer'],
                'rawJson' => ['type' => 'string'],
                'oxygen' => ['type' => 'object'],
                'operation' => ['type' => 'string', 'description' => 'append, replace, or replace_node. Defaults to append.'],
                'targetNodeId' => ['type' => 'integer'],
                'dryRun' => ['type' => 'boolean'],
                'registerSelectors' => ['type' => 'boolean', 'description' => 'Register and attach semantic classes as Oxygen selector IDs. Defaults true.'],
            ], ['postId']),
            $this->tool('list_oxygen_page_backups', 'List recent OxyAI restore backups for a page.', [
                'postId' => ['type' => 'integer'],
            ], ['postId']),
            $this->tool('restore_oxygen_page_backup', 'Restore a previous OxyAI backup for an Oxygen page.', [
                'postId' => ['type' => 'integer'],
                'backupId' => ['type' => 'string'],
            ], ['postId', 'backupId']),
            $this->tool('recompile_oxygen_css', 'Force Oxygen to regenerate the compiled stylesheet for a page. Removes the on-disk post-{id}.css file, busts every known cache meta, and invokes available Breakdance rebuild functions. Call after apply_* operations when the page renders unstyled despite a successful write.', [
                'postId' => ['type' => 'integer'],
            ], ['postId']),
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
                'siteInspiration' => ['type' => 'string'],
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
            $this->tool('list_site_inspirations', 'List first-party OxyAI site inspiration directions for generation prompts.', []),
            $this->tool('list_oxygen_element_capabilities', 'List Oxygen 6 and Breakdance Elements for Oxygen styling capabilities, auto-mapping rules, required content paths, and runtime element catalog. Use this before deciding which CSS can become native design properties or before hand-authoring any Oxygen/EssentialElements JSON.', [
                'elementType' => ['type' => 'string', 'description' => 'Optional full element type, for example OxygenElements\\\\Container or EssentialElements\\\\Button.'],
            ]),
        ];

        if ($this->planMode !== null) {
            $tools[] = $this->tool('plan_generation', 'Ask 1-4 clarifying design questions or return a ready prompt before generating HTML/CSS/JS.', [
                'prompt' => ['type' => 'string'],
                'preset' => ['type' => 'string'],
                'siteInspiration' => ['type' => 'string'],
                'context' => ['type' => 'object'],
            ], ['prompt']);
        }

        if ($this->tripleShot !== null) {
            $tools[] = $this->tool('triple_shot_generation', 'Generate three source-bundle variants with conversion, editorial, and product-clarity directions.', [
                'prompt' => ['type' => 'string'],
                'preset' => ['type' => 'string'],
                'siteInspiration' => ['type' => 'string'],
                'context' => ['type' => 'object'],
            ], ['prompt']);
        }

        return $tools;
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
        $input = $this->withExplicitRegisterSelectorsDefault($input);
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
        $input = $this->withExplicitRegisterSelectorsDefault($input);
        $oxygen = is_array($input['oxygen'] ?? null) ? $input['oxygen'] : $input;

        return $this->pageMutations->applyOxygen($postId, $oxygen, $input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function withExplicitRegisterSelectorsDefault(array $input): array
    {
        $nestedOptions = is_array($input['options'] ?? null) ? $input['options'] : [];
        if (!array_key_exists('registerSelectors', $input) && !array_key_exists('registerSelectors', $nestedOptions)) {
            $input['registerSelectors'] = true;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function planGeneration(array $input)
    {
        if ($this->planMode === null) {
            return new WP_Error('oxyai_plan_unavailable', __('Plan Mode is not available.', 'oxyai-oxygen'), ['status' => 500]);
        }

        return $this->planMode->plan($input);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function tripleShotGeneration(array $input)
    {
        if ($this->tripleShot === null) {
            return new WP_Error('oxyai_triple_shot_unavailable', __('Triple Shot is not available.', 'oxyai-oxygen'), ['status' => 500]);
        }

        return $this->tripleShot->generate($input);
    }
}
