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
use OxyAI\Oxygen\Oxygen\SelectorRegistrationService;
use OxyAI\Oxygen\Presets\PresetStore;
use OxyAI\Oxygen\Security\CapabilityService;
use OxyAI\Oxygen\Security\RateLimiter;
use OxyAI\Oxygen\Source\SourceBundle;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

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
        private ?PageContextService $pages = null,
        private ?RateLimiter $rateLimiter = null
    ) {
        $this->instructions = $this->instructions ?: new PromptInstructionService($this->presets, $this->inspirations, $this->elementCapabilities);
        $this->pages = $this->pages ?: new PageContextService();
        $this->rateLimiter = $this->rateLimiter ?: new RateLimiter();
    }

    /**
     * Live (non-dryRun) write tools subject to rate limiting.
     */
    private const WRITE_TOOLS = [
        'apply_html_to_oxygen_page',
        'apply_oxygen_json_to_page',
        'apply_oxygen_operations',
        'patch_oxygen_node',
        'upsert_css_block',
        'remove_css_block',
    ];

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('oxyai/v1', '/mcp', [
                [
                    'methods' => 'POST',
                    'permission_callback' => fn (WP_REST_Request $request): bool => $this->capabilities->canUseMcp($request),
                    'callback' => fn (WP_REST_Request $request) => $this->handle($request),
                ],
                [
                    // Streamable HTTP transport: clients may probe GET (server->client
                    // SSE) or DELETE (end session). This server is stateless and does
                    // not offer a server-initiated stream, so answer with a spec-correct
                    // 405 + Allow header instead of WordPress' default 404, which some
                    // MCP clients treat as a fatal transport error.
                    'methods' => 'GET, DELETE',
                    'permission_callback' => fn (WP_REST_Request $request): bool => $this->capabilities->canUseMcp($request),
                    'callback' => fn (WP_REST_Request $request) => $this->handleUnsupportedTransportMethod(),
                ],
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
            $response = $this->ok($this->jsonRpcResult($id, [
                'protocolVersion' => $this->negotiateProtocolVersion($params['protocolVersion'] ?? null),
                'serverInfo' => [
                    'name' => 'oxyai-oxygen',
                    'version' => OXYAI_OXYGEN_VERSION,
                ],
                'capabilities' => [
                    'tools' => new \stdClass(),
                ],
            ]));

            // Advertise a session id for Streamable HTTP clients. The server is
            // stateless and does not require the id back, but supplying it lets
            // clients that expect the header complete their handshake.
            $response->header('Mcp-Session-Id', wp_generate_uuid4());

            return $response;
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
            return $this->ok($this->jsonRpcResult(null, new \stdClass()), 202);
        }

        return $this->ok($this->jsonRpcError($id, -32601, 'Method not found.'));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, array<string, string>> $encodingWarnings
     */
    private function shouldBlockUnsafeEncodedApply(string $tool, array $input, array $encodingWarnings): bool
    {
        if ($encodingWarnings === [] || !in_array($tool, ['apply_html_to_oxygen_page', 'apply_oxygen_json_to_page', 'apply_oxygen_operations', 'upsert_css_block'], true)) {
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
        $rateLimited = $this->enforceWriteRateLimit($tool, $input);
        if ($rateLimited !== null) {
            return $rateLimited;
        }

        return match ($tool) {
            'get_prompt_instructions' => ['success' => true, 'instructions' => $this->instructions->getInstructions()],
            'list_oxygen_pages' => ['success' => true, 'pages' => $this->pages->listPages((string) ($input['search'] ?? ''))],
            'get_page_context' => $this->pages->getContext((int) ($input['postId'] ?? $input['id'] ?? 0)),
            'stage_page_payload' => $this->pages->stagePayload((int) ($input['postId'] ?? $input['id'] ?? 0), $input),
            'convert_and_stage_page' => $this->convertAndStagePage($input),
            'get_oxygen_tree' => $this->pageMutations->getTree((int) ($input['postId'] ?? $input['id'] ?? 0), $this->treeViewOptions($input)),
            'find_oxygen_nodes' => $this->pageMutations->findNodes((int) ($input['postId'] ?? $input['id'] ?? 0), $this->nodeFilter($input)),
            'apply_html_to_oxygen_page' => $this->applyHtmlToPage($input),
            'apply_oxygen_json_to_page' => $this->applyOxygenJsonToPage($input),
            'apply_oxygen_operations' => $this->applyOxygenOperations($input),
            'patch_oxygen_node' => $this->pageMutations->patchNode(
                (int) ($input['postId'] ?? $input['id'] ?? 0),
                (int) ($input['nodeId'] ?? 0),
                is_array($input['data'] ?? null) ? $input['data'] : [],
                $this->writeOptions($input)
            ),
            'upsert_css_block' => $this->pageMutations->upsertCssBlock(
                (int) ($input['postId'] ?? $input['id'] ?? 0),
                (string) ($input['css'] ?? ''),
                (string) ($input['key'] ?? ''),
                $this->writeOptions($input)
            ),
            'remove_css_block' => $this->pageMutations->removeCssBlock(
                (int) ($input['postId'] ?? $input['id'] ?? 0),
                (string) ($input['key'] ?? ''),
                $this->writeOptions($input)
            ),
            'list_css_blocks' => $this->pageMutations->listCssBlocks((int) ($input['postId'] ?? $input['id'] ?? 0)),
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
            'repair_oxygen_selectors' => (new SelectorRegistrationService())->repairPersistedSelectors(),
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
            $this->tool('get_oxygen_tree', 'STEP 1 of the edit workflow. Read the persisted Oxygen page tree. Returns a compact OUTLINE by default ({id,type,label,parentId,childIds,classes}) with design data and inline SVG stripped - 10-20x cheaper than the full tree. NEVER round-trip the full tree to make edits. view:"html" returns a type-aware readable HTML reconstruction (each element tagged data-node-id) - the cheapest way to "see" a page layout. view:"full" returns the raw tree but is size-gated: payloads over 50KB fall back to the outline with summarized:true, so scope with nodeId/depth or use view:"html". nodeId focuses a single subtree; depth limits outline/html depth; includeBackups:true also returns backup payloads.', [
                'postId' => ['type' => 'integer'],
                'view' => ['type' => 'string', 'description' => 'outline (default), html (type-aware readable reconstruction), or full (raw, size-gated at 50KB).'],
                'nodeId' => ['type' => 'integer', 'description' => 'Focus the outline/tree/html on a single node and its descendants.'],
                'depth' => ['type' => 'integer', 'description' => 'Limit outline/html depth from the root (or focused node).'],
                'includeBackups' => ['type' => 'boolean', 'description' => 'Include full restore backup payloads. Defaults false (only backupCount is returned).'],
            ], ['postId']),
            $this->tool('find_oxygen_nodes', 'STEP 2 of the edit workflow. Find nodes in the persisted Oxygen tree by filter and return compact outline entries (id, type, label, parentId, childIds). Use this to locate the nodeId(s) you want to edit instead of fetching and scanning the whole tree.', [
                'postId' => ['type' => 'integer'],
                'type' => ['type' => 'string', 'description' => 'Case-insensitive substring match against the full element type, e.g. "MenuCustomArea" or "TextLink".'],
                'textContains' => ['type' => 'string', 'description' => 'Match nodes whose text/url/icon label contains this string.'],
                'class' => ['type' => 'string', 'description' => 'Match nodes carrying this CSS class or selector ref.'],
                'hasLink' => ['type' => 'boolean', 'description' => 'Match nodes that do (true) or do not (false) have a content link url.'],
            ], ['postId']),
            $this->tool('apply_html_to_oxygen_page', 'WRITES TO THE LIVE PAGE - always call once with dryRun:true first to inspect the proposed tree unless the user has explicitly approved the content. Directly converts HTML/CSS/JS and applies it to a WordPress Oxygen page. A restore backup is created when dryRun is false, but treat direct writes as hard to reverse: verify the page renders after applying. Use append for new sections; replace_node for a selected element; replace only when overwriting the whole page is intended.', [
                'postId' => ['type' => 'integer'],
                'html' => ['type' => 'string'],
                'css' => ['type' => 'string'],
                'js' => ['type' => 'string'],
                'operation' => ['type' => 'string', 'description' => 'append, replace, or replace_node. Defaults to append.'],
                'targetNodeId' => ['type' => 'integer', 'description' => 'Required for replace_node.'],
                'dryRun' => ['type' => 'boolean', 'description' => 'Return the proposed change without saving.'],
                'dryRunView' => ['type' => 'string', 'description' => 'On dryRun: "full" (default) also returns the whole proposed tree; "outline" returns only the compact outline + changedNodeIds (token-efficient).'],
                'recompile' => ['type' => 'boolean', 'description' => 'After a live write, force a full CSS recompile (saves a separate recompile_oxygen_css call).'],
                'registerSelectors' => ['type' => 'boolean', 'description' => 'Register and attach semantic classes as Oxygen selector IDs. Defaults true.'],
                'options' => ['type' => 'object'],
            ], ['postId', 'html']),
            $this->tool('apply_oxygen_json_to_page', 'WRITES TO THE LIVE PAGE - always call once with dryRun:true first unless the user has explicitly approved the content. Directly applies a converted Oxygen rawJson/documentTree/element payload to a page. A restore backup is created when dryRun is false, but treat direct writes as hard to reverse: verify the page renders after applying. For replace_node, pass either a documentTree wrapper or a single node object with id, data, and children. The response includes idMap (incoming id -> assigned id) and changedNodeIds so you can target follow-up edits.', [
                'postId' => ['type' => 'integer'],
                'rawJson' => ['type' => 'string'],
                'oxygen' => ['type' => 'object'],
                'operation' => ['type' => 'string', 'description' => 'append, replace, or replace_node. Defaults to append.'],
                'targetNodeId' => ['type' => 'integer'],
                'dryRun' => ['type' => 'boolean'],
                'dryRunView' => ['type' => 'string', 'description' => 'On dryRun: "full" (default) also returns the whole proposed tree; "outline" returns only the compact outline + changedNodeIds.'],
                'preserveIds' => ['type' => 'boolean', 'description' => 'Keep the incoming node ids instead of renumbering, when they are unique and do not collide with the existing tree. Defaults false (renumber). replace_node always forces the root to targetNodeId.'],
                'recompile' => ['type' => 'boolean', 'description' => 'After a live write, force a full CSS recompile.'],
                'registerSelectors' => ['type' => 'boolean', 'description' => 'Register and attach semantic classes as Oxygen selector IDs. Defaults true.'],
            ], ['postId']),
            $this->tool('apply_oxygen_operations', 'STEP 4 (multi-op). WRITES TO THE LIVE PAGE (unless dryRun) - apply a sequence of small node operations to the existing tree in ONE call and ONE backup. Far cheaper than resending whole subtrees, and counts as a single write against the rate limit. Each op is a map: {op:"patch_node",targetNodeId,data:{...}} (recursive deep-merge); {op:"update_node",targetNodeId,set:{"data.properties.content.content.text":"..."},unset:["data.properties..."]}; {op:"set_node_type",targetNodeId,type,set?,unset?}; {op:"delete_node",targetNodeId}; {op:"move_node",nodeId,toParent,index?}; {op:"insert_node",parentId,node,index?}; {op:"upsert_css",key,css}; {op:"remove_css",key}. set/unset use dot-paths into the node. Writes are validated against the element capability registry: a known element type written to a contradictory design path (e.g. flat design.padding, or an unsupported bucket) or inserted without its required content paths is rejected with a 422-style error naming the correct path; unknown/runtime element types are allowed with mcpWarnings. Call list_oxygen_element_capabilities(elementType) before setting properties on an unfamiliar type. Returns idMap + changedNodeIds + changedPaths; dryRun returns the outline.', [
                'postId' => ['type' => 'integer'],
                'ops' => ['type' => 'array', 'description' => 'Ordered list of node operations (see tool description).'],
                'dryRun' => ['type' => 'boolean', 'description' => 'Return the proposed outline + changedNodeIds without saving.'],
                'dryRunView' => ['type' => 'string', 'description' => 'On dryRun: "outline" (default) or "full" to also return the whole proposed tree.'],
                'recompile' => ['type' => 'boolean', 'description' => 'After a live write, force a full CSS recompile.'],
            ], ['postId', 'ops']),
            $this->tool('patch_oxygen_node', 'STEP 4 (single node) and the preferred way to edit ONE element. WRITES TO THE LIVE PAGE (unless dryRun). Deep-merges a partial data object into a single node: scalars and lists replace, associative objects merge key-by-key, the node id and all untouched properties are preserved. Validated against the element capability registry (same guardrail as apply_oxygen_operations): contradictory design paths or value-shape mismatches on a known element type are rejected with a 422 naming the correct path; unknown types pass with mcpWarnings. Call list_oxygen_element_capabilities(elementType) first when unsure of the data shape. Returns a compact confirmation only: {success,nodeId,changedPaths[],backupId} - never the tree.', [
                'postId' => ['type' => 'integer'],
                'nodeId' => ['type' => 'integer', 'description' => 'Id of the node to patch (from get_oxygen_tree/find_oxygen_nodes).'],
                'data' => ['type' => 'object', 'description' => 'Partial node.data object to deep-merge, e.g. {"properties":{"content":{"content":{"text":"New"}}}}.'],
                'dryRun' => ['type' => 'boolean', 'description' => 'Validate and report changedPaths without saving.'],
                'recompile' => ['type' => 'boolean', 'description' => 'After a live write, force a full CSS recompile.'],
            ], ['postId', 'nodeId', 'data']),
            $this->tool('upsert_css_block', 'WRITES TO THE LIVE PAGE (unless dryRun) - create or replace a keyed CssCode block on the page. Idempotent: re-running with the same key updates the existing block instead of stacking new nodes. Use for custom CSS overrides scoped by id-independent selectors. Pair with remove_css_block to revert.', [
                'postId' => ['type' => 'integer'],
                'key' => ['type' => 'string', 'description' => 'Stable identifier for this block ([A-Za-z0-9_-], max 64). Re-using it replaces the previous block.'],
                'css' => ['type' => 'string', 'description' => 'Raw CSS. Stored verbatim under a keyed marker comment.'],
                'dryRun' => ['type' => 'boolean'],
                'recompile' => ['type' => 'boolean', 'description' => 'After a live write, force a full CSS recompile.'],
            ], ['postId', 'key', 'css']),
            $this->tool('remove_css_block', 'WRITES TO THE LIVE PAGE (unless dryRun) - remove a keyed CssCode block previously created with upsert_css_block.', [
                'postId' => ['type' => 'integer'],
                'key' => ['type' => 'string'],
                'dryRun' => ['type' => 'boolean'],
                'recompile' => ['type' => 'boolean'],
            ], ['postId', 'key']),
            $this->tool('list_css_blocks', 'List keyed CssCode blocks on a page (key, nodeId, length).', [
                'postId' => ['type' => 'integer'],
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
            $this->tool('repair_oxygen_selectors', 'Repair persisted Oxygen selector records created by older OxyAI versions. Use when Oxygen editor reports IO-TS decoding failures for oxySelectors, missing locked fields, polluted .breakdance class names, array properties, or string font_weight values.', []),
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
            $this->tool('list_oxygen_element_capabilities', 'STEP 3 of the edit workflow - ALWAYS call this (with elementType) before setting design/content properties on an element type you have not already inspected this session. Returns that element\'s supported design buckets, native CSS properties, native value shapes, required content paths, and a concrete exampleNode showing the exact data shape. These are the same rules the write validator enforces, so checking here first avoids 422 rejections. Omit elementType for the full catalog (large).', [
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
     * Echo the client's requested protocol version when we recognise it, else
     * fall back to the baseline. Keeps newer Streamable HTTP clients happy
     * without dropping older ones.
     *
     * @param mixed $requested
     */
    private function negotiateProtocolVersion($requested): string
    {
        $supported = ['2025-06-18', '2025-03-26', '2024-11-05'];
        return is_string($requested) && in_array($requested, $supported, true)
            ? $requested
            : '2024-11-05';
    }

    private function handleUnsupportedTransportMethod(): WP_REST_Response
    {
        $response = $this->ok([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32601,
                'message' => 'This MCP endpoint only supports POST. It does not offer a server-initiated SSE stream or session deletion.',
            ],
        ], 405);
        $response->header('Allow', 'POST');

        return $response;
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
     */
    private function applyOxygenOperations(array $input)
    {
        $postId = (int) ($input['postId'] ?? $input['id'] ?? 0);
        $ops = is_array($input['ops'] ?? null) ? $input['ops'] : (is_array($input['operations'] ?? null) ? $input['operations'] : []);

        return $this->pageMutations->applyOperations($postId, $ops, $this->writeOptions($input));
    }

    /**
     * Options for read/outline tools.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function treeViewOptions(array $input): array
    {
        $options = [];
        if (isset($input['view']) && is_string($input['view'])) {
            $options['view'] = $input['view'];
        }
        if (isset($input['nodeId']) && is_numeric($input['nodeId'])) {
            $options['nodeId'] = (int) $input['nodeId'];
        }
        if (isset($input['depth']) && is_numeric($input['depth'])) {
            $options['depth'] = (int) $input['depth'];
        }
        if (array_key_exists('includeBackups', $input)) {
            $options['includeBackups'] = filter_var($input['includeBackups'], FILTER_VALIDATE_BOOLEAN);
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function nodeFilter(array $input): array
    {
        $filter = [];
        foreach (['type', 'textContains', 'class'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $filter[$key] = $input[$key];
            }
        }
        if (array_key_exists('hasLink', $input)) {
            $filter['hasLink'] = filter_var($input['hasLink'], FILTER_VALIDATE_BOOLEAN);
        }

        return $filter;
    }

    /**
     * Apply the sliding-window write rate limit to live (non-dryRun) write
     * tools. Returns a 429-style WP_Error when exhausted, else null.
     *
     * @param array<string, mixed> $input
     */
    private function enforceWriteRateLimit(string $tool, array $input): ?WP_Error
    {
        if (!in_array($tool, self::WRITE_TOOLS, true) || $this->rateLimiter === null) {
            return null;
        }

        $dryRun = $input['dryRun'] ?? ($input['options']['dryRun'] ?? false);
        if (!empty($dryRun)) {
            return null;
        }

        return $this->rateLimiter->hit('mcp');
    }

    /**
     * Common write-path options (dryRun, recompile) forwarded to the mutation service.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function writeOptions(array $input): array
    {
        $nestedOptions = is_array($input['options'] ?? null) ? $input['options'] : [];
        $options = [];
        foreach (['dryRun', 'recompile', 'preserveIds'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $options[$flag] = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN);
            } elseif (array_key_exists($flag, $nestedOptions)) {
                $options[$flag] = filter_var($nestedOptions[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (isset($input['dryRunView']) && is_string($input['dryRunView'])) {
            $options['dryRunView'] = $input['dryRunView'];
        } elseif (isset($nestedOptions['dryRunView']) && is_string($nestedOptions['dryRunView'])) {
            $options['dryRunView'] = $nestedOptions['dryRunView'];
        }

        return $options;
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
