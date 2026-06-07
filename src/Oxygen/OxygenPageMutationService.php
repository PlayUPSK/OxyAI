<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Oxygen;

use WP_Error;

final class OxygenPageMutationService
{
    use OxygenTreeToolsTrait;

    private const BACKUPS_META_KEY = '_oxyai_oxygen_tree_backups';
    private const MAX_BACKUPS = 10;

    /** Map of incoming->assigned node ids from the last mergeTree() call. */
    private array $lastIdMap = [];

    /** Node ids touched by the last mergeTree() call. */
    private array $lastChangedNodeIds = [];

    /**
     * Read the persisted Oxygen page tree.
     *
     * @param array<string, mixed> $options
     *        view?: "outline" (default) | "full"
     *        nodeId?: int   focus a single node/subtree
     *        depth?: int    limit outline depth
     *        includeBackups?: bool  include full backup payloads (default false)
     * @return array<string, mixed>|WP_Error
     */
    public function getTree(int $postId, array $options = [])
    {
        $post = get_post($postId);
        if (!$post) {
            return new WP_Error('oxyai_page_not_found', __('Page not found.', 'oxyai-oxygen'), ['status' => 404]);
        }

        $tree = $this->readTree($postId);
        $view = strtolower((string) ($options['view'] ?? 'outline'));
        $backups = $this->listBackups($postId);

        $result = [
            'success' => true,
            'postId' => $postId,
            'metaKey' => $this->metaKey(),
            'hasTree' => $tree !== null,
            'view' => $view === 'full' ? 'full' : 'outline',
            'nodeCount' => $tree !== null ? $this->countTreeNodes($tree) : 0,
            'nextNodeId' => $tree !== null ? $this->calculateNextNodeId($tree['root'] ?? []) : 1,
            'backupCount' => count($backups),
        ];

        if (!empty($options['includeBackups'])) {
            $result['backups'] = $backups;
        }

        if ($tree === null) {
            return $result;
        }

        $focusId = isset($options['nodeId']) && is_numeric($options['nodeId']) ? (int) $options['nodeId'] : null;

        if ($view === 'full') {
            if ($focusId !== null) {
                $parent = null;
                $node = $this->findNodeCopy($tree['root'] ?? [], $focusId, $parent);
                $result['found'] = $node !== null;
                $result['node'] = $node;
            } else {
                $result['tree'] = $tree;
            }
            return $result;
        }

        $summary = $this->summarizeTree($tree, $options);
        $result['nodes'] = $summary['nodes'];
        if (($summary['found'] ?? true) === false) {
            $result['found'] = false;
            $result['nodeId'] = $summary['nodeId'] ?? $focusId;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $oxygen
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function applyOxygen(int $postId, array $oxygen, array $options = [])
    {
        $post = get_post($postId);
        if (!$post) {
            return new WP_Error('oxyai_page_not_found', __('Page not found.', 'oxyai-oxygen'), ['status' => 404]);
        }

        $incomingTree = $this->treeFromOxygen($oxygen);
        if ($incomingTree === null) {
            return new WP_Error(
                'oxyai_invalid_oxygen_payload',
                __('No valid Oxygen element tree was provided. Pass one of: {"documentTree":{"root":{...}}}, {"root":{...}}, {"element":{...}}, rawJson/json string, oxygen.element, or a bare node with data.type plus id and/or children.', 'oxyai-oxygen'),
                ['status' => 400, 'expectedKeys' => ['documentTree', 'root', 'element', 'rawJson', 'json', 'oxygen', 'data', 'id', 'children']]
            );
        }

        $operation = $this->normalizeOperation((string) ($options['operation'] ?? $options['mode'] ?? 'append'));
        $targetNodeId = isset($options['targetNodeId']) && is_numeric($options['targetNodeId'])
            ? (int) $options['targetNodeId']
            : null;
        $dryRun = !empty($options['dryRun']);
        $dryRunView = strtolower((string) ($options['dryRunView'] ?? 'full'));
        $preserveIds = filter_var($options['preserveIds'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $registerSelectorsInput = $options['registerSelectors'] ?? $options['options']['registerSelectors'] ?? true;
        $registerSelectors = filter_var($registerSelectorsInput, FILTER_VALIDATE_BOOLEAN);
        $selectorRegistration = null;
        $selectorRegistrationService = null;
        if ($registerSelectors) {
            $selectorRegistrationService = new SelectorRegistrationService();
            $selectorRegistration = $selectorRegistrationService->registerTreeSelectors($incomingTree, false);
        }

        $existingTree = $this->readTree($postId);
        $newTree = $this->mergeTree($existingTree, $incomingTree, $operation, $targetNodeId, $preserveIds);
        if (is_wp_error($newTree)) {
            return $newTree;
        }

        $result = [
            'success' => true,
            'dryRun' => $dryRun,
            'operation' => $operation,
            'postId' => $postId,
            'targetNodeId' => $targetNodeId,
            'metaKey' => $this->metaKey(),
            'idMap' => $this->lastIdMap,
            'changedNodeIds' => $this->lastChangedNodeIds,
            'beforeNodeCount' => $existingTree !== null ? $this->countTreeNodes($existingTree) : 0,
            'afterNodeCount' => $this->countTreeNodes($newTree),
            'viewUrl' => get_permalink($postId),
            'editUrl' => get_edit_post_link($postId, 'raw'),
        ];

        if ($selectorRegistration !== null) {
            $result['selectorRegistration'] = $selectorRegistration;
        }

        if ($dryRun) {
            // Token-efficient by default: outline + changed ids. Pass
            // dryRunView:"full" to also receive the entire proposed tree.
            $result['outline'] = $this->summarizeTree($newTree)['nodes'];
            if ($dryRunView === 'full') {
                $result['tree'] = $newTree;
            }
            return $result;
        }

        $backupId = $this->storeBackup($postId, $existingTree, [
            'operation' => $operation,
            'targetNodeId' => $targetNodeId,
        ]);

        $this->writeTree($postId, $newTree);
        if ($selectorRegistrationService !== null && is_array($selectorRegistration['selectors'] ?? null)) {
            $selectorRegistrationService->persistSelectors($selectorRegistration['selectors']);
        }
        $this->refreshCaches($postId);

        $shouldRecompile = !empty($options['recompile']) || !empty($options['options']['recompile']);
        if ($shouldRecompile) {
            $result['recompile'] = $this->recompileCss($postId);
        }

        $result['backupId'] = $backupId;
        $result['message'] = __('Oxygen page tree updated. A restore backup was created.', 'oxyai-oxygen');

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBackups(int $postId): array
    {
        $backups = get_post_meta($postId, self::BACKUPS_META_KEY, true);
        if (!is_array($backups)) {
            return [];
        }

        return array_values(array_filter($backups, static fn ($backup): bool => is_array($backup)));
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function restoreBackup(int $postId, string $backupId)
    {
        $post = get_post($postId);
        if (!$post) {
            return new WP_Error('oxyai_page_not_found', __('Page not found.', 'oxyai-oxygen'), ['status' => 404]);
        }

        $backups = $this->listBackups($postId);
        $backup = null;
        foreach ($backups as $candidate) {
            if ((string) ($candidate['id'] ?? '') === $backupId) {
                $backup = $candidate;
                break;
            }
        }

        if ($backup === null) {
            return new WP_Error('oxyai_backup_not_found', __('Backup not found.', 'oxyai-oxygen'), ['status' => 404]);
        }

        $tree = is_array($backup['tree'] ?? null) ? $backup['tree'] : null;

        if ($tree !== null) {
            $corruptedTypes = $this->findCorruptedTypes($tree);
            if ($corruptedTypes !== []) {
                return new WP_Error(
                    'oxyai_backup_corrupted',
                    __('This backup is corrupted: element type names are missing namespace separators (likely from a pre-fix wp_unslash on the meta store). Restoring would replace the page with unresolvable elements. Aborting.', 'oxyai-oxygen'),
                    ['status' => 422, 'corruptedTypes' => array_values(array_unique($corruptedTypes))]
                );
            }
        }

        if ($tree === null) {
            $this->deleteTree($postId);
        } else {
            $this->writeTree($postId, $this->normalizeDocumentTree($tree));
        }

        $this->refreshCaches($postId);

        return [
            'success' => true,
            'postId' => $postId,
            'backupId' => $backupId,
            'restoredAt' => gmdate('c'),
            'nodeCount' => $tree !== null ? $this->countTreeNodes($tree) : 0,
            'message' => __('Backup restored to the Oxygen page tree.', 'oxyai-oxygen'),
        ];
    }

    private function metaKey(): string
    {
        if (function_exists('\\Breakdance\\BreakdanceOxygen\\Strings\\__bdox')) {
            $prefix = \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix');
            if (is_string($prefix) && $prefix !== '') {
                return $prefix . 'data';
            }
        }

        return '_oxygen_data';
    }

    private function metaPrefix(): string
    {
        if (function_exists('\\Breakdance\\BreakdanceOxygen\\Strings\\__bdox')) {
            $prefix = \Breakdance\BreakdanceOxygen\Strings\__bdox('_meta_prefix');
            if (is_string($prefix) && $prefix !== '') {
                return $prefix;
            }
        }

        return '_oxygen_';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readTree(int $postId): ?array
    {
        $payload = $this->readMetaPayload($postId);
        if ($payload === null) {
            return null;
        }

        if (isset($payload['tree_json_string']) && is_string($payload['tree_json_string'])) {
            $decoded = json_decode($payload['tree_json_string'], true);
            if (is_array($decoded)) {
                return $this->normalizeDocumentTree($decoded);
            }
        }

        foreach (['root', 'content', 'data', 'nodes'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->normalizeDocumentTree($key === 'root' ? $payload : $payload[$key]);
            }
        }

        if (isset($payload['elements']) && is_array($payload['elements']) && is_array($payload['elements'][0] ?? null)) {
            return $this->normalizeDocumentTree((array) $payload['elements'][0]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readMetaPayload(int $postId): ?array
    {
        $metaKey = $this->metaKey();
        $value = is_callable('\\Breakdance\\Data\\get_meta')
            ? call_user_func('\\Breakdance\\Data\\get_meta', $postId, $metaKey)
            : get_post_meta($postId, $metaKey, true);

        if ($value === '' || $value === null || $value === false) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode(wp_unslash($value), true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tree
     */
    private function writeTree(int $postId, array $tree): void
    {
        $tree = $this->normalizeDocumentTree($tree);
        $encodedTree = wp_json_encode($tree);
        if (!is_string($encodedTree)) {
            $encodedTree = '{}';
        }

        $payload = ['tree_json_string' => $encodedTree];
        $metaKey = $this->metaKey();

        if (is_callable('\\Breakdance\\Data\\set_meta')) {
            call_user_func('\\Breakdance\\Data\\set_meta', $postId, $metaKey, $payload);
            return;
        }

        update_post_meta($postId, $metaKey, wp_slash((string) wp_json_encode($payload)));
    }

    private function deleteTree(int $postId): void
    {
        delete_post_meta($postId, $this->metaKey());
    }

    /**
     * @param array<string, mixed>|null $existingTree
     * @param array<string, mixed> $incomingTree
     * @return array<string, mixed>|WP_Error
     */
    private function mergeTree(?array $existingTree, array $incomingTree, string $operation, ?int $targetNodeId, bool $preserveIds = false)
    {
        $incomingTree = $this->normalizeDocumentTree($incomingTree);
        $this->lastIdMap = [];
        $this->lastChangedNodeIds = [];

        if ($operation === 'replace') {
            $root = $incomingTree['root'] ?? [];
            if (is_array($root)) {
                if ($preserveIds && $this->canPreserveIncomingIds($root, [])) {
                    $this->recordPreservedIdAssignments($root);
                } else {
                    $this->reindexElementTree($root, 1, $this->lastIdMap);
                }
                $this->setLastChangedNodeIdsFromSubtree($root);
            }
            return $this->normalizeDocumentTree(is_array($root) ? $root : $incomingTree);
        }

        if ($operation === 'append') {
            $incomingRoot = $incomingTree['root'] ?? [];
            if (!is_array($incomingRoot)) {
                return new WP_Error('oxyai_invalid_oxygen_payload', __('Converted Oxygen tree has no root node.', 'oxyai-oxygen'), ['status' => 400]);
            }

            if ($existingTree === null) {
                if ($preserveIds && $this->canPreserveIncomingIds($incomingRoot, [])) {
                    $this->recordPreservedIdAssignments($incomingRoot);
                } else {
                    $this->reindexElementTree($incomingRoot, 1, $this->lastIdMap);
                }
                $this->setLastChangedNodeIdsFromSubtree($incomingRoot);

                return $this->normalizeDocumentTree($incomingRoot);
            }

            $tree = $this->normalizeDocumentTree($existingTree);
            if ($preserveIds && $this->canPreserveIncomingIds($incomingRoot, $tree['root'] ?? [])) {
                $this->recordPreservedIdAssignments($incomingRoot);
            } else {
                $nextId = $this->calculateNextNodeId($tree['root'] ?? []);
                $this->reindexElementTree($incomingRoot, $nextId, $this->lastIdMap);
            }
            $this->setLastChangedNodeIdsFromSubtree($incomingRoot);

            if (!isset($tree['root']['children']) || !is_array($tree['root']['children'])) {
                $tree['root']['children'] = [];
            }
            $tree['root']['children'][] = $incomingRoot;

            return $this->normalizeDocumentTree($tree);
        }

        if ($operation === 'replace_node') {
            if ($existingTree === null) {
                return new WP_Error('oxyai_missing_existing_tree', __('The page has no existing Oxygen tree to replace a node in.', 'oxyai-oxygen'), ['status' => 400]);
            }
            if ($targetNodeId === null || $targetNodeId < 1) {
                return new WP_Error('oxyai_missing_target_node', __('targetNodeId is required for replace_node.', 'oxyai-oxygen'), ['status' => 400]);
            }

            $tree = $this->normalizeDocumentTree($existingTree);
            $incomingRoot = $incomingTree['root'] ?? [];
            if (!is_array($incomingRoot)) {
                return new WP_Error('oxyai_invalid_oxygen_payload', __('Converted Oxygen tree has no root node.', 'oxyai-oxygen'), ['status' => 400]);
            }

            $beforeFingerprint = $this->fingerprintNonTargetNodes($tree, $targetNodeId);

            if ($preserveIds && $this->canPreserveIncomingIdsForReplacement($incomingRoot, $tree['root'] ?? [], $targetNodeId)) {
                $this->recordPreservedIdAssignments($incomingRoot, $targetNodeId);
                $incomingRoot['id'] = $targetNodeId;
            } else {
                $nextId = $this->calculateNextNodeId($tree['root'] ?? []);
                $this->reindexElementTreePreservingRoot($incomingRoot, $targetNodeId, $nextId, $this->lastIdMap);
            }
            $this->setLastChangedNodeIdsFromSubtree($incomingRoot);

            if (!$this->replaceNode($tree['root'], $targetNodeId, $incomingRoot)) {
                return new WP_Error('oxyai_target_node_not_found', __('Target node was not found in the Oxygen tree.', 'oxyai-oxygen'), ['status' => 404]);
            }

            $afterFingerprint = $this->fingerprintNonTargetNodes($tree, $targetNodeId);
            $diff = $this->diffNodeFingerprints($beforeFingerprint, $afterFingerprint);
            if ($diff !== []) {
                return new WP_Error(
                    'oxyai_replace_node_unscoped',
                    __('replace_node modified or removed nodes outside the target subtree. Aborting to prevent page corruption. Use operation=replace to rewrite the whole tree, or report this with the changedNodes data.', 'oxyai-oxygen'),
                    ['status' => 500, 'targetNodeId' => $targetNodeId, 'changedNodes' => $diff]
                );
            }

            return $this->normalizeDocumentTree($tree);
        }

        return new WP_Error('oxyai_invalid_operation', __('Unsupported Oxygen page operation.', 'oxyai-oxygen'), ['status' => 400]);
    }

    /**
     * @param array<string, mixed> $oxygen
     * @return array<string, mixed>|null
     */
    private function treeFromOxygen(array $oxygen): ?array
    {
        if (isset($oxygen['documentTree']) && is_array($oxygen['documentTree'])) {
            return $this->normalizeDocumentTree($oxygen['documentTree']);
        }

        if (isset($oxygen['oxygen']) && is_array($oxygen['oxygen'])) {
            return $this->treeFromOxygen($oxygen['oxygen']);
        }

        if (isset($oxygen['rawJson']) && is_string($oxygen['rawJson'])) {
            $decoded = json_decode($oxygen['rawJson'], true);
            if (is_array($decoded)) {
                return $this->treeFromOxygen($decoded);
            }
        }

        if (isset($oxygen['json']) && is_string($oxygen['json'])) {
            $decoded = json_decode($oxygen['json'], true);
            if (is_array($decoded)) {
                return $this->treeFromOxygen($decoded);
            }
        }

        if (isset($oxygen['element']) && is_array($oxygen['element'])) {
            return $this->normalizeDocumentTree($oxygen['element']);
        }

        if (isset($oxygen['root']) && is_array($oxygen['root'])) {
            return $this->normalizeDocumentTree($oxygen);
        }

        if ($this->looksLikeElementNode($oxygen)) {
            return $this->normalizeDocumentTree($oxygen);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function looksLikeElementNode(array $candidate): bool
    {
        if (!isset($candidate['data']) || !is_array($candidate['data'])) {
            return false;
        }

        $hasId = isset($candidate['id']) && is_numeric($candidate['id']);
        $hasChildren = isset($candidate['children']) && is_array($candidate['children']);
        $hasType = isset($candidate['data']['type']) && is_string($candidate['data']['type']);

        return $hasType && ($hasId || $hasChildren);
    }

    private function normalizeOperation(string $operation): string
    {
        $operation = strtolower(str_replace('-', '_', trim($operation)));
        return in_array($operation, ['append', 'replace', 'replace_node'], true) ? $operation : 'append';
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function normalizeDocumentTree(array $tree): array
    {
        $documentTree = isset($tree['root']) && is_array($tree['root'])
            ? $tree
            : ['root' => $tree];

        if (!isset($documentTree['_nextNodeId']) || !is_int($documentTree['_nextNodeId']) || $documentTree['_nextNodeId'] < 1) {
            $documentTree['_nextNodeId'] = $this->calculateNextNodeId($documentTree['root'] ?? []);
        }

        if (!isset($documentTree['status']) || !is_string($documentTree['status']) || trim($documentTree['status']) === '') {
            $documentTree['status'] = 'exported';
        }

        return $documentTree;
    }

    /**
     * @param mixed $root
     */
    private function calculateNextNodeId($root): int
    {
        return max(1, $this->findMaxNodeId(is_array($root) ? $root : []) + 1);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function findMaxNodeId(array $node): int
    {
        $max = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : 0;
        $children = $node['children'] ?? [];
        if (!is_array($children)) {
            return $max;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $max = max($max, $this->findMaxNodeId($child));
            }
        }

        return $max;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<int, int> $map incoming id -> assigned id (recorded)
     */
    private function reindexElementTree(array &$element, int $nextId, array &$map = []): int
    {
        $oldId = isset($element['id']) && is_numeric($element['id']) ? (int) $element['id'] : null;
        $assigned = max(1, $nextId++);
        $element['id'] = $assigned;
        if ($oldId !== null) {
            $map[$oldId] = $assigned;
        }

        $children = $element['children'] ?? [];
        if (!is_array($children)) {
            return $nextId;
        }

        foreach ($children as &$child) {
            if (is_array($child)) {
                $nextId = $this->reindexElementTree($child, $nextId, $map);
            }
        }
        unset($child);
        $element['children'] = $children;

        return $nextId;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<int, int> $map incoming id -> assigned id (recorded)
     */
    private function reindexElementTreePreservingRoot(array &$element, int $rootId, int $nextId, array &$map = []): int
    {
        $oldId = isset($element['id']) && is_numeric($element['id']) ? (int) $element['id'] : null;
        $element['id'] = $rootId;
        if ($oldId !== null) {
            $map[$oldId] = $rootId;
        }

        $children = $element['children'] ?? [];
        if (!is_array($children)) {
            return $nextId;
        }

        foreach ($children as &$child) {
            if (is_array($child)) {
                $nextId = $this->reindexElementTree($child, $nextId, $map);
            }
        }
        unset($child);
        $element['children'] = $children;

        return $nextId;
    }

    /**
     * Collect every node id present in a subtree.
     *
     * @param mixed $node
     * @param array<int, int> $ids
     */
    private function collectNodeIds($node, array &$ids): void
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['id']) && is_numeric($node['id'])) {
            $ids[] = (int) $node['id'];
        }
        foreach (($node['children'] ?? []) as $child) {
            $this->collectNodeIds($child, $ids);
        }
    }

    /**
     * Record an incoming->assigned map for a preserveIds path. Children keep
     * their ids; the root may be forced to a different assigned id.
     *
     * @param array<string, mixed> $node
     */
    private function recordPreservedIdAssignments(array $node, ?int $forcedRootId = null, bool $isRoot = true): void
    {
        if (isset($node['id']) && is_numeric($node['id'])) {
            $oldId = (int) $node['id'];
            $this->lastIdMap[$oldId] = $isRoot && $forcedRootId !== null ? $forcedRootId : $oldId;
        }

        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->recordPreservedIdAssignments($child, null, false);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function setLastChangedNodeIdsFromSubtree(array $node): void
    {
        $ids = [];
        $this->collectNodeIds($node, $ids);
        $this->lastChangedNodeIds = array_values(array_unique($ids));
    }

    /**
     * True when the incoming subtree's ids are all present, unique, and do not
     * collide with ids already in the destination tree (optionally ignoring the
     * subtree being replaced). Enables preserveIds without corrupting the tree.
     *
     * @param array<string, mixed> $incomingRoot
     * @param array<string, mixed> $destinationRoot
     */
    private function canPreserveIncomingIds(array $incomingRoot, array $destinationRoot, ?int $ignoreSubtreeId = null): bool
    {
        $incomingIds = [];
        $this->collectNodeIds($incomingRoot, $incomingIds);
        if ($incomingIds === [] || count($incomingIds) !== count(array_unique($incomingIds))) {
            return false;
        }
        // Every node must carry an id.
        $incomingCount = $this->countElementNodes($incomingRoot);
        if (count($incomingIds) !== $incomingCount) {
            return false;
        }

        $destIds = [];
        $this->collectNodeIds($destinationRoot, $destIds);
        if ($ignoreSubtreeId !== null) {
            $ignore = [];
            $ignoreNode = null;
            $parent = null;
            $ignoreNode = $this->findNodeCopy($destinationRoot, $ignoreSubtreeId, $parent);
            if (is_array($ignoreNode)) {
                $this->collectNodeIds($ignoreNode, $ignore);
            }
            $destIds = array_values(array_diff($destIds, $ignore));
        }

        return array_intersect($incomingIds, $destIds) === [];
    }

    /**
     * replace_node preserveIds forces the incoming root id to the target id,
     * so reject the preserve path if that would duplicate a descendant id.
     *
     * @param array<string, mixed> $incomingRoot
     * @param array<string, mixed> $destinationRoot
     */
    private function canPreserveIncomingIdsForReplacement(array $incomingRoot, array $destinationRoot, int $targetNodeId): bool
    {
        if (!$this->canPreserveIncomingIds($incomingRoot, $destinationRoot, $targetNodeId)) {
            return false;
        }

        $rootId = isset($incomingRoot['id']) && is_numeric($incomingRoot['id']) ? (int) $incomingRoot['id'] : null;
        if ($rootId === $targetNodeId) {
            return true;
        }

        $descendantIds = [];
        foreach (($incomingRoot['children'] ?? []) as $child) {
            $this->collectNodeIds($child, $descendantIds);
        }

        return !in_array($targetNodeId, $descendantIds, true);
    }

    /**
     * Walk the tree and collect a fingerprint for every node OUTSIDE the
     * target subtree. Used to detect when replace_node corrupts the rest
     * of the page.
     *
     * @param array<string, mixed> $tree
     * @return array<string, string>
     */
    private function fingerprintNonTargetNodes(array $tree, int $targetNodeId): array
    {
        $fingerprints = [];
        $this->collectNodeFingerprints($tree['root'] ?? [], $targetNodeId, $fingerprints, 'root');
        return $fingerprints;
    }

    /**
     * @param mixed $node
     * @param array<string, string> $fingerprints
     */
    private function collectNodeFingerprints($node, int $skipSubtreeId, array &$fingerprints, string $path): void
    {
        if (!is_array($node)) {
            return;
        }

        $nodeId = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : 0;
        $nodeKey = $path . '#id=' . ($nodeId > 0 ? (string) $nodeId : 'missing');

        if ($nodeId === $skipSubtreeId) {
            return;
        }

        $children = $node['children'] ?? [];
        $childLinks = [];
        if (is_array($children)) {
            foreach ($children as $index => $child) {
                if (!is_array($child)) {
                    $childLinks[] = $index . ':non-node';
                    continue;
                }

                $childId = isset($child['id']) && is_numeric($child['id']) ? (int) $child['id'] : 0;
                $childLinks[] = $index . ':' . ($childId > 0 ? (string) $childId : 'missing');
            }
        }

        $shallow = $node;
        unset($shallow['children']);
        $shallow['_oxyai_child_links'] = $childLinks;
        $fingerprints[$nodeKey] = md5(serialize($shallow));

        if (!is_array($children)) {
            return;
        }

        foreach ($children as $index => $child) {
            $this->collectNodeFingerprints($child, $skipSubtreeId, $fingerprints, $path . '.children[' . $index . ']');
        }
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return array<int, array<string, mixed>>
     */
    private function diffNodeFingerprints(array $before, array $after): array
    {
        $changes = [];
        foreach ($before as $nodeKey => $hash) {
            if (!array_key_exists($nodeKey, $after)) {
                $changes[] = ['nodeKey' => $nodeKey, 'change' => 'removed'];
                continue;
            }
            if ($after[$nodeKey] !== $hash) {
                $changes[] = ['nodeKey' => $nodeKey, 'change' => 'modified'];
            }
        }
        foreach ($after as $nodeKey => $hash) {
            if (!array_key_exists($nodeKey, $before)) {
                $changes[] = ['nodeKey' => $nodeKey, 'change' => 'added_outside_target'];
            }
        }

        usort($changes, static fn (array $a, array $b): int => [$a['nodeKey'] ?? '', $a['change'] ?? ''] <=> [$b['nodeKey'] ?? '', $b['change'] ?? '']);

        return $changes;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $replacement
     */
    private function replaceNode(array &$node, int $targetNodeId, array $replacement): bool
    {
        if ((int) ($node['id'] ?? 0) === $targetNodeId) {
            $node = $replacement;
            return true;
        }

        $children = $node['children'] ?? [];
        if (!is_array($children)) {
            return false;
        }

        foreach ($children as &$child) {
            if (is_array($child) && $this->replaceNode($child, $targetNodeId, $replacement)) {
                $node['children'] = $children;
                return true;
            }
        }
        unset($child);

        return false;
    }

    /**
     * @param array<string, mixed>|null $tree
     * @param array<string, mixed> $operation
     */
    private function storeBackup(int $postId, ?array $tree, array $operation): string
    {
        $backupId = wp_generate_uuid4();
        $backups = $this->listBackups($postId);
        array_unshift($backups, [
            'id' => $backupId,
            'createdAt' => gmdate('c'),
            'postId' => $postId,
            'metaKey' => $this->metaKey(),
            'operation' => $operation,
            'tree' => $tree,
            'nodeCount' => $tree !== null ? $this->countTreeNodes($tree) : 0,
        ]);

        $payload = array_slice($backups, 0, self::MAX_BACKUPS);

        // wp_unslash() inside update_metadata() would strip the backslash from
        // element type names like "OxygenElements\Container", producing the
        // unresolvable "OxygenElementsContainer" on restore. wp_slash() makes
        // that unslash a round-trip.
        update_post_meta($postId, self::BACKUPS_META_KEY, wp_slash($payload));

        return $backupId;
    }

    /**
     * Returns the list of corrupted type strings found in the tree.
     * A type is considered corrupted when it includes the "Elements" namespace
     * segment but has no backslash, e.g. "OxygenElementsContainer" instead of
     * "OxygenElements\\Container".
     *
     * @param array<string, mixed> $tree
     * @return array<int, string>
     */
    private function findCorruptedTypes(array $tree): array
    {
        $corrupted = [];
        $this->collectCorruptedTypes($tree['root'] ?? $tree, $corrupted);
        return $corrupted;
    }

    /**
     * @param mixed $node
     * @param array<int, string> $corrupted
     */
    private function collectCorruptedTypes($node, array &$corrupted): void
    {
        if (!is_array($node)) {
            return;
        }

        $type = $node['data']['type'] ?? null;
        if (is_string($type) && $type !== '' && $this->isCorruptedTypeName($type)) {
            $corrupted[] = $type;
        }

        $children = $node['children'] ?? [];
        if (is_array($children)) {
            foreach ($children as $child) {
                $this->collectCorruptedTypes($child, $corrupted);
            }
        }
    }

    private function isCorruptedTypeName(string $type): bool
    {
        if (str_contains($type, '\\')) {
            return false;
        }

        return (bool) preg_match('/^[A-Z][A-Za-z0-9_]*Elements[A-Z][A-Za-z0-9_]*$/', $type);
    }

    /**
     * @param array<string, mixed> $tree
     */
    private function countTreeNodes(array $tree): int
    {
        $root = $tree['root'] ?? $tree;
        return is_array($root) ? $this->countElementNodes($root) : 0;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function countElementNodes(array $node): int
    {
        $count = 1;
        $children = $node['children'] ?? [];
        if (!is_array($children)) {
            return $count;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $count += $this->countElementNodes($child);
            }
        }

        return $count;
    }

    private function refreshCaches(int $postId): void
    {
        $this->invalidateOxygenCaches($postId);

        if (is_callable('\\Breakdance\\Render\\generateCacheForPost')) {
            call_user_func('\\Breakdance\\Render\\generateCacheForPost', $postId);
        }
    }

    /**
     * Best-effort full CSS recompile. The default refreshCaches() invalidates
     * the cache meta and asks Breakdance to regenerate, but the on-disk
     * stylesheet at uploads/oxygen/css/post-{id}.css is not always rewritten.
     * This method:
     *   - busts every known Oxygen/Breakdance cache meta
     *   - removes the on-disk compiled stylesheet so the next render writes
     *     a fresh one
     *   - tries every known rebuild entry point exposed by
     *     Oxygen 6 / Breakdance Oxygen
     *   - fires an action so site code can hook custom regeneration
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function recompileCss(int $postId)
    {
        $post = get_post($postId);
        if (!$post) {
            return new WP_Error('oxyai_page_not_found', __('Page not found.', 'oxyai-oxygen'), ['status' => 404]);
        }

        $this->invalidateOxygenCaches($postId);
        $removedFiles = $this->removeCompiledCssFiles($postId);
        $invokedRebuilders = $this->invokeKnownRebuilders($postId);

        do_action('oxyai_oxygen_recompile_css', $postId);

        return [
            'success' => true,
            'postId' => $postId,
            'removedFiles' => $removedFiles,
            'invokedRebuilders' => $invokedRebuilders,
            'message' => __('Best-effort CSS recompile triggered. Verify the page in the browser before reporting success.', 'oxyai-oxygen'),
        ];
    }

    private function invalidateOxygenCaches(int $postId): void
    {
        $prefix = $this->metaPrefix();
        $cacheSuffixes = [
            'dependency_cache',
            'css_file_paths_cache',
            'dynamic_css',
            'oxy_css_cache',
            'css_cache',
            'render_cache',
            'global_settings_cache',
        ];

        foreach ($cacheSuffixes as $suffix) {
            delete_post_meta($postId, $prefix . $suffix);
        }

        clean_post_cache($postId);
    }

    /**
     * @return array<int, string>
     */
    private function removeCompiledCssFiles(int $postId): array
    {
        $removed = [];
        $upload = wp_upload_dir();
        $base = isset($upload['basedir']) && is_string($upload['basedir']) ? $upload['basedir'] : '';
        if ($base === '') {
            return $removed;
        }

        $candidates = [
            $base . '/oxygen/css/post-' . $postId . '.css',
            $base . '/breakdance/oxygen/css/post-' . $postId . '.css',
        ];

        $filtered = apply_filters('oxyai_oxygen_compiled_css_paths', $candidates, $postId);
        $candidates = is_array($filtered) ? $filtered : $candidates;

        foreach ($candidates as $path) {
            if (!is_string($path) || $path === '' || !file_exists($path)) {
                continue;
            }
            if (wp_delete_file($path)) {
                $removed[] = $this->relativeUploadPath($base, $path);
            } else {
                do_action('oxyai_oxygen_compiled_css_delete_failed', $path, $postId);
            }
        }

        return $removed;
    }

    /**
     * @return array<int, string>
     */
    private function invokeKnownRebuilders(int $postId): array
    {
        $invoked = [];
        $defaultRebuilders = [
            '\\Breakdance\\Render\\generateCacheForPost',
            '\\Breakdance\\Render\\refreshDynamicCssForPost',
            '\\Breakdance\\Render\\regenerateStylesForPost',
            '\\Breakdance\\Compile\\regenerateCssForPost',
        ];
        $filtered = apply_filters('oxyai_oxygen_css_rebuilders', $defaultRebuilders, $postId);
        $rebuilders = is_array($filtered) ? $filtered : $defaultRebuilders;

        foreach ($rebuilders as $callable) {
            if (!is_string($callable) || !is_callable($callable)) {
                continue;
            }
            try {
                call_user_func($callable, $postId);
                $invoked[] = $callable;
            } catch (\Throwable $exception) {
                do_action('oxyai_oxygen_recompile_exception', $exception, $postId, $callable);
            }
        }

        return $invoked;
    }

    private function relativeUploadPath(string $base, string $path): string
    {
        $base = rtrim(wp_normalize_path($base), '/') . '/';
        $path = wp_normalize_path($path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($path);
    }
}
