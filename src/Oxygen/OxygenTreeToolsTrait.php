<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Oxygen;

use OxyAI\Oxygen\Codex\ElementWriteValidator;
use WP_Error;

/**
 * Token-efficient read/query/edit helpers for the Oxygen page tree.
 *
 * Composed into {@see OxygenPageMutationService}. Every "InTree" / "*Node*"
 * helper here is pure (no WordPress calls) so it can be unit/smoke tested with
 * in-memory trees; the public WP wrappers (getTree/applyOperations/...) read
 * and persist around them.
 */
trait OxygenTreeToolsTrait
{
    private const CSS_BLOCK_PREFIX = '/* oxyai-css-block:';
    private const CSS_CODE_TYPE = 'OxygenElements\\CssCode';

    // ------------------------------------------------------------------
    // #1 Outline / subtree reads (pure)
    // ------------------------------------------------------------------

    /**
     * Produce a compact, token-efficient outline of a document tree.
     * Strips design data, inline SVG, and placeholder content; keeps id,
     * short type, a human label, friendly classes, parent and child ids.
     *
     * @param array<string, mixed> $documentTree
     * @param array<string, mixed> $options nodeId?:int, depth?:int
     * @return array<string, mixed>
     */
    public function summarizeTree(array $documentTree, array $options = []): array
    {
        $tree = $this->normalizeDocumentTree($documentTree);
        $root = is_array($tree['root'] ?? null) ? $tree['root'] : [];

        $focusId = isset($options['nodeId']) && is_numeric($options['nodeId']) ? (int) $options['nodeId'] : null;
        $maxDepth = isset($options['depth']) && is_numeric($options['depth']) ? max(0, (int) $options['depth']) : null;

        $start = $root;
        $startParent = null;
        if ($focusId !== null) {
            $found = $this->findNodeCopy($root, $focusId, $startParent);
            if ($found === null) {
                return ['nodes' => [], 'found' => false, 'nodeId' => $focusId];
            }
            $start = $found;
        }

        $nodes = [];
        $this->collectOutline($start, $startParent, 0, $maxDepth, $nodes);

        return ['nodes' => $nodes, 'found' => true];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $nodes
     */
    private function collectOutline(array $node, ?int $parentId, int $depth, ?int $maxDepth, array &$nodes): void
    {
        $nodes[] = $this->summarizeNode($node, $parentId);

        if ($maxDepth !== null && $depth >= $maxDepth) {
            return;
        }

        $id = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : null;
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectOutline($child, $id, $depth + 1, $maxDepth, $nodes);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function summarizeNode(array $node, ?int $parentId): array
    {
        $childIds = [];
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child) && isset($child['id']) && is_numeric($child['id'])) {
                $childIds[] = (int) $child['id'];
            }
        }

        $summary = [
            'id' => isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : null,
            'type' => $this->shortType((string) ($node['data']['type'] ?? '')),
            'parentId' => $parentId,
            'childIds' => $childIds,
        ];

        $label = $this->nodeLabel($node);
        if ($label !== null && $label !== '') {
            $summary['label'] = $label;
        }

        $classes = $this->friendlyClasses($node);
        if ($classes !== []) {
            $summary['classes'] = $classes;
        }

        return $summary;
    }

    private function shortType(string $type): string
    {
        $type = (string) $type;
        $pos = strrpos($type, '\\');
        return $pos === false ? $type : substr($type, $pos + 1);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeLabel(array $node): ?string
    {
        $content = $node['data']['properties']['content']['content'] ?? null;
        if (is_array($content)) {
            if (isset($content['text']) && is_string($content['text'])) {
                return $this->trimLabel(wp_strip_all_tags($content['text']));
            }
            if (isset($content['link']['url']) && is_string($content['link']['url'])) {
                return $this->trimLabel($content['link']['url']);
            }
            if (isset($content['icon']['name']) && is_string($content['icon']['name'])) {
                return 'icon:' . $content['icon']['name'];
            }
            if (isset($content['shortcode']['full_shortcode']) && is_string($content['shortcode']['full_shortcode'])) {
                return $this->trimLabel($content['shortcode']['full_shortcode']);
            }
            if (isset($content['css_code']) && is_string($content['css_code'])) {
                $key = $this->cssBlockKeyFromCode($content['css_code']);
                return $key !== null ? 'css-block:' . $key : 'css';
            }
        }

        $image = $node['data']['properties']['content']['image'] ?? null;
        if (is_array($image)) {
            $alt = $image['alt'] ?? ($image['media']['filename'] ?? null);
            if (is_string($alt) && $alt !== '') {
                return $this->trimLabel($alt);
            }
        }

        return null;
    }

    private function trimLabel(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        return $this->stringLength($value) > 60 ? $this->stringSlice($value, 0, 57) . '...' : $value;
    }

    private function stringLength(string $value): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($value) : strlen($value);
    }

    private function stringSlice(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? (string) mb_substr($value, $start, $length) : substr($value, $start, $length);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function friendlyClasses(array $node): array
    {
        $classes = $node['data']['properties']['settings']['advanced']['classes'] ?? null;
        if (!is_array($classes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($c): string => is_string($c) ? $c : '',
            $classes
        ), static fn (string $c): bool => $c !== ''));
    }

    // ------------------------------------------------------------------
    // #4 Type-aware readable HTML reconstruction (pure)
    // ------------------------------------------------------------------

    /**
     * Reconstruct a readable, type-aware HTML view of the tree. NOT a real
     * render: each node emits a representative tag annotated with
     * data-node-id="N". The cheapest way for an agent to "see" a page.
     *
     * @param array<string, mixed> $documentTree
     * @param array<string, mixed> $options nodeId?:int, depth?:int
     * @return array{html: string, found: bool, nodeId?: int}
     */
    public function renderTreeHtml(array $documentTree, array $options = []): array
    {
        $tree = $this->normalizeDocumentTree($documentTree);
        $root = is_array($tree['root'] ?? null) ? $tree['root'] : [];

        $focusId = isset($options['nodeId']) && is_numeric($options['nodeId']) ? (int) $options['nodeId'] : null;
        $maxDepth = isset($options['depth']) && is_numeric($options['depth']) ? max(0, (int) $options['depth']) : null;

        $start = $root;
        if ($focusId !== null) {
            $parent = null;
            $found = $this->findNodeCopy($root, $focusId, $parent);
            if ($found === null) {
                return ['html' => '', 'found' => false, 'nodeId' => $focusId];
            }
            $start = $found;
        }

        return ['html' => $this->renderNodeHtml($start, 0, $maxDepth), 'found' => true];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderNodeHtml(array $node, int $depth, ?int $maxDepth): string
    {
        $type = (string) ($node['data']['type'] ?? '');
        $short = $this->shortType($type);
        $id = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : 0;
        $indent = str_repeat('  ', $depth);

        [$tag, $attrs, $void] = $this->htmlTagFor($node, $short);
        $idAttr = ' data-node-id="' . $id . '"';
        $typeAttr = $this->isGenericTag($short, $tag) ? ' data-type="' . $this->escAttr($short) . '"' : '';

        if ($void) {
            return $indent . '<' . $tag . $idAttr . $typeAttr . $attrs . '>' . "\n";
        }

        $open = $indent . '<' . $tag . $idAttr . $typeAttr . $attrs . '>';
        $close = '</' . $tag . '>';

        $text = $this->htmlTextPreview($node);
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $atDepthLimit = $maxDepth !== null && $depth >= $maxDepth;

        if ($children === [] || $atDepthLimit) {
            $inner = $text;
            if ($atDepthLimit && $children !== []) {
                $inner .= ($inner !== '' ? ' ' : '') . '<!-- ' . count($children) . ' child node(s) -->';
            }
            return $open . $inner . $close . "\n";
        }

        $out = $open . ($text !== '' ? "\n" . $indent . '  ' . $text : '') . "\n";
        foreach ($children as $child) {
            if (is_array($child)) {
                $out .= $this->renderNodeHtml($child, $depth + 1, $maxDepth);
            }
        }
        $out .= $indent . $close . "\n";

        return $out;
    }

    /**
     * Map an element node to [tag, extraAttributes, isVoid].
     *
     * @param array<string, mixed> $node
     * @return array{0: string, 1: string, 2: bool}
     */
    private function htmlTagFor(array $node, string $short): array
    {
        $content = $node['data']['properties']['content']['content'] ?? [];
        $content = is_array($content) ? $content : [];

        switch ($short) {
            case 'root':
                return ['div', '', false];
            case 'Section':
                return ['section', '', false];
            case 'Header':
                return ['header', '', false];
            case 'Heading':
                $level = $this->headingLevel($content);
                return ['h' . $level, '', false];
            case 'Text':
                return ['p', '', false];
            case 'RichText':
                return ['div', '', false];
            case 'Image':
            case 'Image2':
                $image = $node['data']['properties']['content']['image'] ?? [];
                $image = is_array($image) ? $image : [];
                $src = (string) ($image['url'] ?? ($image['src'] ?? ''));
                $alt = (string) ($image['alt'] ?? '');
                $attrs = ($src !== '' ? ' src="' . $this->escAttr($src) . '"' : '') . ' alt="' . $this->escAttr($alt) . '"';
                return ['img', $attrs, true];
            case 'Button':
            case 'TextLink':
            case 'ContainerLink':
                $url = (string) ($content['link']['url'] ?? '');
                return ['a', $url !== '' ? ' href="' . $this->escAttr($url) . '"' : '', false];
            case 'CssCode':
                return ['style', '', false];
            case 'JavaScriptCode':
                return ['script', '', false];
            case 'HtmlCode':
                return ['div', '', false];
            case 'BasicList':
                return ['ul', '', false];
            case 'Container':
                return ['div', '', false];
            default:
                return ['div', '', false];
        }
    }

    private function isGenericTag(string $short, string $tag): bool
    {
        // Annotate data-type whenever the tag does not uniquely identify the
        // element (i.e. it collapsed to a generic <div>) and the type is not
        // already a plain container.
        return $tag === 'div' && !in_array($short, ['Container', 'root', 'RichText', 'HtmlCode'], true);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function headingLevel(array $content): int
    {
        $tag = $content['tag'] ?? null;
        if (is_string($tag) && preg_match('/^h([1-6])$/i', trim($tag), $m) === 1) {
            return (int) $m[1];
        }
        $size = $content['size'] ?? null;
        if (is_string($size) && preg_match('/^h([1-6])$/i', trim($size), $m) === 1) {
            return (int) $m[1];
        }

        return 2;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function htmlTextPreview(array $node): string
    {
        $content = $node['data']['properties']['content']['content'] ?? [];
        $content = is_array($content) ? $content : [];

        $text = null;
        if (isset($content['text']) && is_string($content['text'])) {
            $text = wp_strip_all_tags($content['text']);
        } elseif (isset($content['link']['label']) && is_string($content['link']['label'])) {
            $text = $content['link']['label'];
        } elseif (isset($content['shortcode']['full_shortcode']) && is_string($content['shortcode']['full_shortcode'])) {
            $text = $content['shortcode']['full_shortcode'];
        } elseif (isset($content['icon']['name']) && is_string($content['icon']['name'])) {
            $text = 'icon:' . $content['icon']['name'];
        }

        if ($text === null) {
            return '';
        }

        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($this->stringLength($text) > 120) {
            $text = $this->stringSlice($text, 0, 117) . '...';
        }

        return $this->escText($text);
    }

    private function escAttr(string $value): string
    {
        return str_replace(['&', '"', '<', '>'], ['&amp;', '&quot;', '&lt;', '&gt;'], $value);
    }

    private function escText(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    // ------------------------------------------------------------------
    // #6 Find nodes (pure)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $documentTree
     * @param array<string, mixed> $filter type?, textContains?, class?, hasLink?
     * @return array<int, array<string, mixed>>
     */
    public function findNodesInTree(array $documentTree, array $filter): array
    {
        $tree = $this->normalizeDocumentTree($documentTree);
        $root = is_array($tree['root'] ?? null) ? $tree['root'] : [];

        $matches = [];
        $this->collectMatches($root, null, $filter, $matches);
        return $matches;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $filter
     * @param array<int, array<string, mixed>> $matches
     */
    private function collectMatches(array $node, ?int $parentId, array $filter, array &$matches): void
    {
        if ($this->nodeMatchesFilter($node, $filter)) {
            $matches[] = $this->summarizeNode($node, $parentId);
        }

        $id = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : null;
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectMatches($child, $id, $filter, $matches);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $filter
     */
    private function nodeMatchesFilter(array $node, array $filter): bool
    {
        $type = (string) ($node['data']['type'] ?? '');

        $wantType = isset($filter['type']) && is_string($filter['type']) ? trim($filter['type']) : '';
        if ($wantType !== '' && stripos($type, $wantType) === false) {
            return false;
        }

        $wantText = isset($filter['textContains']) && is_string($filter['textContains']) ? trim($filter['textContains']) : '';
        if ($wantText !== '') {
            $label = (string) ($this->nodeLabel($node) ?? '');
            if (stripos($label, $wantText) === false) {
                return false;
            }
        }

        $wantClass = isset($filter['class']) && is_string($filter['class']) ? trim($filter['class']) : '';
        if ($wantClass !== '') {
            $classes = $this->friendlyClasses($node);
            $refs = $node['data']['properties']['meta']['classes'] ?? [];
            $haystack = array_merge($classes, is_array($refs) ? $refs : []);
            if (!in_array($wantClass, $haystack, true)) {
                return false;
            }
        }

        if (array_key_exists('hasLink', $filter)) {
            $wantLink = filter_var($filter['hasLink'], FILTER_VALIDATE_BOOLEAN);
            $url = $node['data']['properties']['content']['content']['link']['url'] ?? null;
            $hasLink = is_string($url) && $url !== '';
            if ($wantLink !== $hasLink) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------
    // #2 / #10 Node operations (pure)
    // ------------------------------------------------------------------

    /**
     * Apply a sequence of node operations to a document tree in memory.
     *
     * Supported ops (each a map with an "op" key):
     *   update_node    {targetNodeId, set?:{path:value}, unset?:[path]}
     *   patch_node     {targetNodeId, data:{...}}  recursive deep-merge into node.data
     *   set_node_type  {targetNodeId, type, set?, unset?}
     *   delete_node    {targetNodeId}
     *   move_node      {nodeId, toParent, index?}
     *   insert_node    {parentId, node, index?}
     *   upsert_css     {key, css}
     *   remove_css     {key}
     *
     * @param array<string, mixed> $documentTree
     * @param array<int, array<string, mixed>> $ops
     * @param ElementWriteValidator|null $validator registry guardrail (#1/#3)
     * @return array<string, mixed>|WP_Error
     */
    public function applyNodeOperations(array $documentTree, array $ops, ?ElementWriteValidator $validator = null)
    {
        if ($ops === []) {
            return new WP_Error('oxyai_no_operations', __('No operations were provided.', 'oxyai-oxygen'), ['status' => 400]);
        }

        $tree = $this->normalizeDocumentTree($documentTree);
        $idMap = [];
        $changed = [];
        $changedPaths = [];
        $warnings = [];

        foreach ($ops as $index => $op) {
            if (!is_array($op)) {
                return new WP_Error('oxyai_invalid_operation', sprintf(/* translators */ __('Operation %d is not an object.', 'oxyai-oxygen'), (int) $index), ['status' => 400]);
            }

            $result = $this->applySingleNodeOperation($tree, $op, $idMap, $changed, (int) $index, $validator, $warnings, $changedPaths);
            if (is_wp_error($result)) {
                return $result;
            }
            $tree = $result;
        }

        return [
            'tree' => $this->normalizeDocumentTree($tree),
            'idMap' => $idMap,
            'changedNodeIds' => array_values(array_unique($changed)),
            'changedPaths' => array_values(array_unique($changedPaths)),
            'opsApplied' => count($ops),
            'mcpWarnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $op
     * @param array<int|string, int> $idMap
     * @param array<int, int> $changed
     * @param array<int, array<string, string>> $warnings
     * @param array<int, string> $changedPaths
     * @return array<string, mixed>|WP_Error
     */
    private function applySingleNodeOperation(array $tree, array $op, array &$idMap, array &$changed, int $index, ?ElementWriteValidator $validator = null, array &$warnings = [], array &$changedPaths = [])
    {
        $kind = strtolower(str_replace('-', '_', trim((string) ($op['op'] ?? $op['operation'] ?? ''))));

        switch ($kind) {
            case 'patch_node':
                $targetId = $this->intOrNull($op['targetNodeId'] ?? $op['nodeId'] ?? null);
                if ($targetId === null) {
                    return $this->opError($index, __('targetNodeId is required.', 'oxyai-oxygen'));
                }
                $patch = is_array($op['data'] ?? null) ? $op['data'] : (is_array($op['patch'] ?? null) ? $op['patch'] : null);
                if ($patch === null) {
                    return $this->opError($index, __('data (partial object) is required for patch_node.', 'oxyai-oxygen'));
                }
                $patchError = null;
                $found = $this->mutateNodeById($tree['root'], $targetId, function (array &$node) use ($patch, $validator, &$warnings, &$changedPaths, &$patchError, $index): void {
                    $before = is_array($node['data'] ?? null) ? $node['data'] : [];
                    $merged = $this->deepMerge($before, $patch);
                    // patch_node must never change the node id.
                    $candidate = $node;
                    $candidate['data'] = $merged;
                    $type = (string) ($merged['type'] ?? ($before['type'] ?? ''));
                    if ($validator !== null && $type !== '') {
                        $check = $validator->validateWrite($type, $this->dotPathsFor($patch), $candidate, false);
                        if (is_wp_error($check)) {
                            $patchError = $this->opError($index, $check->get_error_message(), (int) ($check->get_error_data()['status'] ?? 422));
                            return;
                        }
                        $warnings = array_merge($warnings, $check['warnings']);
                    }
                    $node['data'] = $merged;
                    foreach ($this->collectMergePaths($patch, 'data') as $p) {
                        $changedPaths[] = $p;
                    }
                });
                if ($patchError !== null) {
                    return $patchError;
                }
                if (!$found) {
                    return $this->opError($index, __('Target node was not found.', 'oxyai-oxygen'), 404);
                }
                $changed[] = $targetId;
                return $tree;

            case 'update_node':
            case 'set_node_type':
                $targetId = $this->intOrNull($op['targetNodeId'] ?? $op['nodeId'] ?? null);
                if ($targetId === null) {
                    return $this->opError($index, __('targetNodeId is required.', 'oxyai-oxygen'));
                }
                $type = $kind === 'set_node_type' ? (string) ($op['type'] ?? '') : null;
                if ($kind === 'set_node_type' && $type === '') {
                    return $this->opError($index, __('type is required for set_node_type.', 'oxyai-oxygen'));
                }
                $set = is_array($op['set'] ?? null) ? $op['set'] : [];
                $unset = is_array($op['unset'] ?? null) ? $op['unset'] : [];

                if ($validator !== null) {
                    // Build the post-write node to validate against (type + set applied).
                    $current = null;
                    $parent = null;
                    $current = $this->findNodeCopy($tree['root'] ?? [], $targetId, $parent);
                    if (is_array($current)) {
                        $after = $current;
                        if ($type !== null) {
                            $after['data']['type'] = $type;
                        }
                        foreach ($set as $path => $value) {
                            $this->setPath($after, (string) $path, $value);
                        }
                        $effectiveType = $type ?? (string) ($current['data']['type'] ?? '');
                        if ($effectiveType !== '') {
                            $check = $validator->validateWrite($effectiveType, $set, $after, false);
                            if (is_wp_error($check)) {
                                return $this->opError($index, $check->get_error_message(), (int) ($check->get_error_data()['status'] ?? 422));
                            }
                            $warnings = array_merge($warnings, $check['warnings']);
                        }
                    }
                }

                $found = $this->mutateNodeById($tree['root'], $targetId, function (array &$node) use ($type, $set, $unset, &$changedPaths): void {
                    if ($type !== null) {
                        if (!isset($node['data']) || !is_array($node['data'])) {
                            $node['data'] = [];
                        }
                        $node['data']['type'] = $type;
                    }
                    foreach ($set as $path => $value) {
                        $this->setPath($node, (string) $path, $value);
                        $changedPaths[] = (string) $path;
                    }
                    foreach ($unset as $path) {
                        if (is_string($path)) {
                            $this->unsetPath($node, $path);
                            $changedPaths[] = $path;
                        }
                    }
                });
                if (!$found) {
                    return $this->opError($index, __('Target node was not found.', 'oxyai-oxygen'), 404);
                }
                $changed[] = $targetId;
                return $tree;

            case 'delete_node':
                $targetId = $this->intOrNull($op['targetNodeId'] ?? $op['nodeId'] ?? null);
                if ($targetId === null) {
                    return $this->opError($index, __('targetNodeId is required.', 'oxyai-oxygen'));
                }
                if ((int) ($tree['root']['id'] ?? 0) === $targetId) {
                    return $this->opError($index, __('Cannot delete the root node.', 'oxyai-oxygen'));
                }
                $detached = null;
                if (!$this->detachNodeById($tree['root'], $targetId, $detached)) {
                    return $this->opError($index, __('Target node was not found.', 'oxyai-oxygen'), 404);
                }
                $changed[] = $targetId;
                return $tree;

            case 'move_node':
                $nodeId = $this->intOrNull($op['nodeId'] ?? $op['targetNodeId'] ?? null);
                $toParent = $this->intOrNull($op['toParent'] ?? $op['parentId'] ?? null);
                if ($nodeId === null || $toParent === null) {
                    return $this->opError($index, __('nodeId and toParent are required for move_node.', 'oxyai-oxygen'));
                }
                if ($nodeId === $toParent) {
                    return $this->opError($index, __('A node cannot be moved into itself.', 'oxyai-oxygen'));
                }
                $detached = null;
                $oldParentId = null;
                if (!$this->detachNodeById($tree['root'], $nodeId, $detached, $oldParentId) || !is_array($detached)) {
                    return $this->opError($index, __('Node to move was not found.', 'oxyai-oxygen'), 404);
                }
                $index2 = $this->intOrNull($op['index'] ?? null);
                if (!$this->insertChild($tree['root'], $toParent, $detached, $index2)) {
                    return $this->opError($index, __('Destination parent was not found.', 'oxyai-oxygen'), 404);
                }
                $changed[] = $nodeId;
                if ($oldParentId !== null) {
                    $changed[] = $oldParentId;
                }
                if ($toParent !== $oldParentId) {
                    $changed[] = $toParent;
                }
                return $tree;

            case 'insert_node':
                $parentId = $this->intOrNull($op['parentId'] ?? $op['toParent'] ?? null);
                $node = is_array($op['node'] ?? null) ? $op['node'] : null;
                if ($parentId === null || $node === null) {
                    return $this->opError($index, __('parentId and node are required for insert_node.', 'oxyai-oxygen'));
                }
                if ($validator !== null) {
                    $insertType = (string) ($node['data']['type'] ?? '');
                    if ($insertType !== '') {
                        $check = $validator->validateWrite($insertType, [], $node, true);
                        if (is_wp_error($check)) {
                            return $this->opError($index, $check->get_error_message(), (int) ($check->get_error_data()['status'] ?? 422));
                        }
                        $warnings = array_merge($warnings, $check['warnings']);
                    }
                }
                $providedId = $this->intOrNull($node['id'] ?? null);
                $nextId = $this->calculateNextNodeId($tree['root'] ?? []);
                $map = [];
                $this->reindexElementTree($node, $nextId, $map);
                $index2 = $this->intOrNull($op['index'] ?? null);
                if (!$this->insertChild($tree['root'], $parentId, $node, $index2)) {
                    return $this->opError($index, __('Destination parent was not found.', 'oxyai-oxygen'), 404);
                }
                foreach ($map as $incomingId => $assignedId) {
                    $idMap[(int) $incomingId] = $assignedId;
                }
                if ($providedId !== null && isset($node['id'])) {
                    $idMap[$providedId] = (int) $node['id'];
                }
                $insertedIds = [];
                $this->collectSubtreeNodeIds($node, $insertedIds);
                $changed = array_merge($changed, $insertedIds);
                return $tree;

            case 'upsert_css':
                $key = $this->sanitizeCssKey((string) ($op['key'] ?? ''));
                $css = (string) ($op['css'] ?? '');
                if ($key === '') {
                    return $this->opError($index, __('key is required for upsert_css.', 'oxyai-oxygen'));
                }
                $code = $this->cssBlockMarker($key) . "\n" . $css;
                $updated = $this->mutateCssBlock($tree['root'], $key, $code);
                if (!$updated) {
                    $nextId = $this->calculateNextNodeId($tree['root'] ?? []);
                    $node = $this->cssBlockNode($nextId, $code);
                    if (!isset($tree['root']['children']) || !is_array($tree['root']['children'])) {
                        $tree['root']['children'] = [];
                    }
                    $tree['root']['children'][] = $node;
                    $changed[] = $nextId;
                } else {
                    $changed[] = $updated;
                }
                return $tree;

            case 'remove_css':
                $key = $this->sanitizeCssKey((string) ($op['key'] ?? ''));
                if ($key === '') {
                    return $this->opError($index, __('key is required for remove_css.', 'oxyai-oxygen'));
                }
                $removedId = null;
                $this->removeCssBlockNode($tree['root'], $key, $removedId);
                if ($removedId !== null) {
                    $changed[] = $removedId;
                }
                return $tree;

            default:
                return $this->opError($index, sprintf(/* translators */ __('Unknown operation "%s".', 'oxyai-oxygen'), $kind));
        }
    }

    private function opError(int $index, string $message, int $status = 400): WP_Error
    {
        return new WP_Error(
            'oxyai_node_operation_failed',
            sprintf(/* translators: 1: op index 2: message */ __('Operation %1$d failed: %2$s', 'oxyai-oxygen'), $index, $message),
            ['status' => $status, 'operationIndex' => $index]
        );
    }

    private function intOrNull($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    // ------------------------------------------------------------------
    // By-reference tree mutators (pure)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $node
     */
    private function mutateNodeById(array &$node, int $targetId, callable $mutator): bool
    {
        if ((int) ($node['id'] ?? 0) === $targetId) {
            $mutator($node);
            return true;
        }

        if (!isset($node['children']) || !is_array($node['children'])) {
            return false;
        }

        foreach ($node['children'] as &$child) {
            if (is_array($child) && $this->mutateNodeById($child, $targetId, $mutator)) {
                return true;
            }
        }
        unset($child);

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $detached
     */
    private function detachNodeById(array &$node, int $targetId, &$detached, ?int &$parentId = null, ?int $currentParentId = null): bool
    {
        if (!isset($node['children']) || !is_array($node['children'])) {
            return false;
        }

        $nodeId = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : $currentParentId;
        foreach ($node['children'] as $i => $child) {
            if (is_array($child) && (int) ($child['id'] ?? 0) === $targetId) {
                $detached = $child;
                $parentId = $nodeId;
                array_splice($node['children'], $i, 1);
                return true;
            }
        }

        $nextParentId = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : null;
        foreach ($node['children'] as &$child) {
            if (is_array($child) && $this->detachNodeById($child, $targetId, $detached, $parentId, $nextParentId)) {
                return true;
            }
        }
        unset($child);

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, int> $ids
     */
    private function collectSubtreeNodeIds(array $node, array &$ids): void
    {
        if (isset($node['id']) && is_numeric($node['id'])) {
            $ids[] = (int) $node['id'];
        }

        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectSubtreeNodeIds($child, $ids);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $child
     */
    private function insertChild(array &$node, int $parentId, array $child, ?int $index): bool
    {
        if ((int) ($node['id'] ?? 0) === $parentId) {
            if (!isset($node['children']) || !is_array($node['children'])) {
                $node['children'] = [];
            }
            if ($index === null || $index < 0 || $index >= count($node['children'])) {
                $node['children'][] = $child;
            } else {
                array_splice($node['children'], $index, 0, [$child]);
            }
            return true;
        }

        if (!isset($node['children']) || !is_array($node['children'])) {
            return false;
        }

        foreach ($node['children'] as &$candidate) {
            if (is_array($candidate) && $this->insertChild($candidate, $parentId, $child, $index)) {
                return true;
            }
        }
        unset($candidate);

        return false;
    }

    /**
     * Set a dot-path value, creating intermediate arrays.
     *
     * @param array<string, mixed> $target
     * @param mixed $value
     */
    private function setPath(array &$target, string $path, $value): void
    {
        $segments = array_values(array_filter(explode('.', $path), static fn ($s): bool => $s !== ''));
        if ($segments === []) {
            return;
        }

        $ref = &$target;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
        unset($ref);
    }

    /**
     * Recursive deep-merge for patch_node.
     *
     * - Associative arrays merge key-by-key (recursively).
     * - Scalars and list (sequential numeric) arrays replace wholesale.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (
                is_array($value)
                && $this->isAssociative($value)
                && isset($base[$key])
                && is_array($base[$key])
                && $this->isAssociative($base[$key])
            ) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssociative(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * Flatten a patch object into dot-paths (relative to data) for validation,
     * keeping leaf values. Used so patch_node reuses the set-path validator.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed> dot-path => leaf value
     */
    private function dotPathsFor(array $patch, string $prefix = 'data'): array
    {
        $paths = [];
        foreach ($patch as $key => $value) {
            $path = $prefix . '.' . $key;
            if (is_array($value) && $this->isAssociative($value) && $value !== []) {
                $paths = array_merge($paths, $this->dotPathsFor($value, $path));
            } else {
                $paths[$path] = $value;
            }
        }

        return $paths;
    }

    /**
     * Collect changed dot-paths from a patch object for the compact response.
     *
     * @param array<string, mixed> $patch
     * @return array<int, string>
     */
    private function collectMergePaths(array $patch, string $prefix): array
    {
        $paths = [];
        foreach ($patch as $key => $value) {
            $path = $prefix . '.' . $key;
            if (is_array($value) && $this->isAssociative($value) && $value !== []) {
                $paths = array_merge($paths, $this->collectMergePaths($value, $path));
            } else {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $target
     */
    private function unsetPath(array &$target, string $path): void
    {
        $segments = array_values(array_filter(explode('.', $path), static fn ($s): bool => $s !== ''));
        if ($segments === []) {
            return;
        }

        $last = array_pop($segments);
        $ref = &$target;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                return;
            }
            $ref = &$ref[$segment];
        }
        unset($ref[$last]);
    }

    /**
     * Return a copy of the node with the given id (read-only), and set the
     * parent id by reference.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function findNodeCopy(array $node, int $targetId, ?int &$parentId, ?int $currentParent = null): ?array
    {
        if ((int) ($node['id'] ?? 0) === $targetId) {
            $parentId = $currentParent;
            return $node;
        }

        $id = isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : null;
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $found = $this->findNodeCopy($child, $targetId, $parentId, $id);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // #3 CSS block helpers (pure)
    // ------------------------------------------------------------------

    private function sanitizeCssKey(string $key): string
    {
        $key = preg_replace('/[^A-Za-z0-9_-]/', '', trim($key)) ?? '';
        return substr($key, 0, 64);
    }

    private function cssBlockMarker(string $key): string
    {
        return self::CSS_BLOCK_PREFIX . $key . ' */';
    }

    private function cssBlockKeyFromCode(string $code): ?string
    {
        if (preg_match('#/\* oxyai-css-block:([A-Za-z0-9_-]{1,64}) \*/#', $code, $matches) === 1) {
            return $matches[1];
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function cssBlockNode(int $id, string $code): array
    {
        return [
            'id' => $id,
            'data' => [
                'type' => self::CSS_CODE_TYPE,
                'properties' => ['content' => ['content' => ['css_code' => $code]]],
            ],
            'children' => [],
        ];
    }

    /**
     * Replace the css_code of an existing keyed block. Returns its node id or null.
     *
     * @param array<string, mixed> $node
     */
    private function mutateCssBlock(array &$node, string $key, string $code): ?int
    {
        $marker = $this->cssBlockMarker($key);
        $foundId = null;
        $this->mutateNodeMatching(
            $node,
            function (array $candidate) use ($marker): bool {
                $existing = (string) ($candidate['data']['properties']['content']['content']['css_code'] ?? '');
                return str_starts_with($existing, $marker);
            },
            function (array &$candidate) use ($code, &$foundId): void {
                $candidate['data']['properties']['content']['content']['css_code'] = $code;
                $foundId = (int) ($candidate['id'] ?? 0);
            }
        );

        return $foundId;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function removeCssBlockNode(array &$node, string $key, ?int &$removedId): void
    {
        $marker = $this->cssBlockMarker($key);
        if (!isset($node['children']) || !is_array($node['children'])) {
            return;
        }

        foreach ($node['children'] as $i => $child) {
            if (
                is_array($child)
                && str_starts_with((string) ($child['data']['properties']['content']['content']['css_code'] ?? ''), $marker)
            ) {
                $removedId = (int) ($child['id'] ?? 0);
                array_splice($node['children'], $i, 1);
                return;
            }
        }

        foreach ($node['children'] as &$child) {
            if (is_array($child)) {
                $this->removeCssBlockNode($child, $key, $removedId);
                if ($removedId !== null) {
                    return;
                }
            }
        }
        unset($child);
    }

    /**
     * @param array<string, mixed> $documentTree
     * @return array<int, array<string, mixed>>
     */
    public function listCssBlocksInTree(array $documentTree): array
    {
        $tree = $this->normalizeDocumentTree($documentTree);
        $blocks = [];
        $this->collectCssBlocks(is_array($tree['root'] ?? null) ? $tree['root'] : [], $blocks);
        return $blocks;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $blocks
     */
    private function collectCssBlocks(array $node, array &$blocks): void
    {
        $code = $node['data']['properties']['content']['content']['css_code'] ?? null;
        if (is_string($code)) {
            $key = $this->cssBlockKeyFromCode($code);
            if ($key !== null) {
                $blocks[] = [
                    'key' => $key,
                    'nodeId' => isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : null,
                    'length' => strlen($code),
                ];
            }
        }

        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectCssBlocks($child, $blocks);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function mutateNodeMatching(array &$node, callable $predicate, callable $mutator): bool
    {
        if ($predicate($node)) {
            $mutator($node);
            return true;
        }

        if (!isset($node['children']) || !is_array($node['children'])) {
            return false;
        }

        foreach ($node['children'] as &$child) {
            if (is_array($child) && $this->mutateNodeMatching($child, $predicate, $mutator)) {
                return true;
            }
        }
        unset($child);

        return false;
    }

    // ------------------------------------------------------------------
    // WordPress wrappers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>|WP_Error
     */
    public function findNodes(int $postId, array $filter)
    {
        if (!get_post($postId)) {
            return new WP_Error('oxyai_page_not_found', __('Page not found.', 'oxyai-oxygen'), ['status' => 404]);
        }

        $tree = $this->readTree($postId);
        if ($tree === null) {
            return ['success' => true, 'postId' => $postId, 'matches' => [], 'count' => 0];
        }

        $matches = $this->findNodesInTree($tree, $filter);
        return ['success' => true, 'postId' => $postId, 'matches' => $matches, 'count' => count($matches)];
    }

    /**
     * @param array<int, array<string, mixed>> $ops
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function applyOperations(int $postId, array $ops, array $options = [])
    {
        if (!get_post($postId)) {
            return new WP_Error('oxyai_page_not_found', __('Page not found.', 'oxyai-oxygen'), ['status' => 404]);
        }

        $existingTree = $this->readTree($postId);
        if ($existingTree === null) {
            return new WP_Error('oxyai_missing_existing_tree', __('The page has no existing Oxygen tree to operate on.', 'oxyai-oxygen'), ['status' => 400]);
        }

        $validator = $options['_validator'] ?? null;
        if (!$validator instanceof ElementWriteValidator) {
            $validator = new ElementWriteValidator();
        }

        $applied = $this->applyNodeOperations($existingTree, $ops, $validator);
        if (is_wp_error($applied)) {
            return $applied;
        }

        $newTree = $applied['tree'];
        $dryRun = !empty($options['dryRun']);
        $dryRunView = strtolower((string) ($options['dryRunView'] ?? 'outline'));

        $result = [
            'success' => true,
            'dryRun' => $dryRun,
            'postId' => $postId,
            'opsApplied' => $applied['opsApplied'],
            'idMap' => $applied['idMap'],
            'changedNodeIds' => $applied['changedNodeIds'],
            'changedPaths' => $applied['changedPaths'],
            'beforeNodeCount' => $this->countTreeNodes($existingTree),
            'afterNodeCount' => $this->countTreeNodes($newTree),
            'viewUrl' => get_permalink($postId),
            'editUrl' => get_edit_post_link($postId, 'raw'),
        ];

        if (!empty($applied['mcpWarnings'])) {
            $result['mcpWarnings'] = $applied['mcpWarnings'];
        }

        if ($dryRun) {
            $result['outline'] = $this->summarizeTree($newTree)['nodes'];
            if ($dryRunView === 'full') {
                $result['tree'] = $newTree;
            }
            return $result;
        }

        $backupId = $this->storeBackup($postId, $existingTree, ['operation' => 'node_operations']);
        $this->writeTree($postId, $newTree);
        $this->refreshCaches($postId);

        if (!empty($options['recompile']) || !empty($options['options']['recompile'])) {
            $result['recompile'] = $this->recompileCss($postId);
        }

        $result['backupId'] = $backupId;
        $result['message'] = __('Oxygen page tree updated via node operations. A restore backup was created.', 'oxyai-oxygen');

        return $result;
    }

    /**
     * Deep-merge a partial data object into a single node, preserving its id
     * and untouched properties. Returns a compact confirmation only.
     *
     * @param array<string, mixed> $data partial node.data object
     * @param array<string, mixed> $options dryRun?, recompile?
     * @return array<string, mixed>|WP_Error
     */
    public function patchNode(int $postId, int $nodeId, array $data, array $options = [])
    {
        $result = $this->applyOperations(
            $postId,
            [['op' => 'patch_node', 'targetNodeId' => $nodeId, 'data' => $data]],
            $options
        );
        if (is_wp_error($result)) {
            return $result;
        }

        // Compact confirmation: ids/paths only, never the tree.
        $compact = [
            'success' => true,
            'nodeId' => $nodeId,
            'changedPaths' => $result['changedPaths'] ?? [],
            'dryRun' => !empty($result['dryRun']),
        ];
        if (isset($result['backupId'])) {
            $compact['backupId'] = $result['backupId'];
        }
        if (!empty($result['mcpWarnings'])) {
            $compact['mcpWarnings'] = $result['mcpWarnings'];
        }
        if (isset($result['recompile'])) {
            $compact['recompile'] = $result['recompile'];
        }

        return $compact;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function upsertCssBlock(int $postId, string $css, string $key, array $options = [])
    {
        return $this->applyOperations($postId, [['op' => 'upsert_css', 'key' => $key, 'css' => $css]], $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|WP_Error
     */
    public function removeCssBlock(int $postId, string $key, array $options = [])
    {
        return $this->applyOperations($postId, [['op' => 'remove_css', 'key' => $key]], $options);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function listCssBlocks(int $postId)
    {
        if (!get_post($postId)) {
            return new WP_Error('oxyai_page_not_found', __('Page not found.', 'oxyai-oxygen'), ['status' => 404]);
        }

        $tree = $this->readTree($postId);
        $blocks = $tree === null ? [] : $this->listCssBlocksInTree($tree);
        return ['success' => true, 'postId' => $postId, 'cssBlocks' => $blocks, 'count' => count($blocks)];
    }
}
