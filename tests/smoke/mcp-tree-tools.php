<?php

declare(strict_types=1);

use OxyAI\Oxygen\Oxygen\OxygenPageMutationService;

// ---- minimal WordPress stubs (pure tree helpers only) ----
if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false)
    {
        return trim(strip_tags((string) $text));
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public string $code;
        public string $message;
        /** @var mixed */
        public $data;

        public function __construct($code = '', $message = '', $data = null)
        {
            $this->code = (string) $code;
            $this->message = (string) $message;
            $this->data = $data;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

require_once __DIR__ . '/../../src/Oxygen/OxygenTreeToolsTrait.php';
require_once __DIR__ . '/../../src/Oxygen/OxygenPageMutationService.php';

$failures = 0;
$check = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$svc = new OxygenPageMutationService();

// ---- fixture tree: header menu with email + phone custom areas ----
$tree = [
    'root' => [
        'id' => 1,
        'data' => ['type' => 'root'],
        'children' => [[
            'id' => 104,
            'data' => ['type' => 'EssentialElements\\MenuBuilder'],
            'children' => [
                ['id' => 105, 'data' => ['type' => 'EssentialElements\\MenuLink', 'properties' => ['content' => ['content' => ['text' => 'Startseite']]]], 'children' => []],
                ['id' => 128, 'data' => ['type' => 'EssentialElements\\MenuCustomArea', 'properties' => ['content' => ['content' => ['link' => ['type' => 'url', 'url' => 'mailto:info@mk-mat.com']]]]], 'children' => [
                    ['id' => 121, 'data' => ['type' => 'OxygenElements\\Container'], 'children' => [
                        ['id' => 122, 'data' => ['type' => 'EssentialElements\\Icon', 'properties' => ['content' => ['content' => ['icon' => ['name' => 'envelope', 'svgCode' => '<svg>HUGE-PAYLOAD</svg>']]]]], 'children' => []],
                        ['id' => 123, 'data' => ['type' => 'OxygenElements\\Text', 'properties' => ['content' => ['content' => ['text' => 'info@mk-mat.com']]]], 'children' => []],
                    ]],
                ]],
                ['id' => 129, 'data' => ['type' => 'EssentialElements\\MenuCustomArea', 'properties' => ['content' => ['content' => ['link' => ['type' => 'url', 'url' => 'tel:+421914553777']]]]], 'children' => [
                    ['id' => 120, 'data' => ['type' => 'OxygenElements\\Container'], 'children' => [
                        ['id' => 117, 'data' => ['type' => 'EssentialElements\\Icon', 'properties' => ['content' => ['content' => ['icon' => ['name' => 'phone', 'svgCode' => '<svg>HUGE-PAYLOAD-2</svg>']]]]], 'children' => []],
                        ['id' => 119, 'data' => ['type' => 'OxygenElements\\Text', 'properties' => ['content' => ['content' => ['text' => '+421 914 553 777']]]], 'children' => []],
                    ]],
                ]],
                ['id' => 130, 'data' => ['type' => 'EssentialElements\\MenuCustomArea'], 'children' => [
                    ['id' => 131, 'data' => ['type' => 'OxygenElements\\Shortcode', 'properties' => ['content' => ['content' => ['shortcode' => ['full_shortcode' => '[language-switcher]']]]]], 'children' => []],
                ]],
            ],
        ]],
    ],
];

// ---- #1 outline strips svg + keeps labels/childIds ----
$outline = $svc->summarizeTree($tree);
$check($outline['found'] === true, '#1 outline found');
$json = json_encode($outline);
$check(is_string($json) && !str_contains($json, 'HUGE-PAYLOAD'), '#1 outline strips inline svg/design');
$check(is_string($json) && str_contains($json, 'info@mk-mat.com'), '#1 outline keeps text label');
$menuOutline = null;
foreach ($outline['nodes'] as $n) {
    if (($n['id'] ?? null) === 104) {
        $menuOutline = $n;
    }
}
$check($menuOutline !== null && $menuOutline['type'] === 'MenuBuilder', '#1 short type');
$check($menuOutline !== null && $menuOutline['childIds'] === [105, 128, 129, 130], '#1 childIds');

// ---- #1 subtree focus by nodeId ----
$sub = $svc->summarizeTree($tree, ['nodeId' => 121]);
$check($sub['found'] === true && count($sub['nodes']) === 3, '#1 nodeId focus returns subtree');

// ---- #6 find by type / hasLink ----
$links = $svc->findNodesInTree($tree, ['type' => 'MenuCustomArea']);
$check(count($links) === 3, '#6 find by type');

// ---- #2/#10 + #3 batch ops: stack email+phone, drop phone area, add css ----
$ops = [
    ['op' => 'set_node_type', 'targetNodeId' => 123, 'type' => 'EssentialElements\\TextLink', 'set' => ['data.properties.content.content.link' => ['type' => 'url', 'url' => 'mailto:info@mk-mat.com']]],
    ['op' => 'set_node_type', 'targetNodeId' => 119, 'type' => 'EssentialElements\\TextLink', 'set' => ['data.properties.content.content.link' => ['type' => 'url', 'url' => 'tel:+421914553777']]],
    ['op' => 'move_node', 'nodeId' => 117, 'toParent' => 121],
    ['op' => 'move_node', 'nodeId' => 119, 'toParent' => 121],
    ['op' => 'update_node', 'targetNodeId' => 128, 'unset' => ['data.properties.content.content.link']],
    ['op' => 'delete_node', 'targetNodeId' => 129],
    ['op' => 'upsert_css', 'key' => 'contact', 'css' => '.x{display:grid}'],
];
$res = $svc->applyNodeOperations($tree, $ops);
$check(!is_wp_error($res), '#2 batch ops succeed');

$after = $res['tree'];
// locate container 121 in result
$find121 = null;
$walk = function ($node) use (&$walk, &$find121): void {
    if (($node['id'] ?? null) === 121) {
        $find121 = $node;
    }
    foreach ($node['children'] ?? [] as $c) {
        $walk($c);
    }
};
$walk($after['root']);
$check($find121 !== null, '#2 container 121 present');
$childIds = array_map(static fn ($c) => $c['id'], $find121['children'] ?? []);
$check($childIds === [122, 123, 117, 119], '#2 email+phone icon/link stacked into one container');
$check(($find121['children'][1]['data']['type'] ?? '') === 'EssentialElements\\TextLink', '#2 set_node_type applied');
$check(($find121['children'][1]['data']['properties']['content']['content']['link']['url'] ?? '') === 'mailto:info@mk-mat.com', '#2 set path applied');

// 129 deleted
$has129 = false;
$walk2 = function ($node) use (&$walk2, &$has129): void {
    if (($node['id'] ?? null) === 129) {
        $has129 = true;
    }
    foreach ($node['children'] ?? [] as $c) {
        $walk2($c);
    }
};
$walk2($after['root']);
$check($has129 === false, '#2 delete_node removed phone area');
$check(in_array(129, $res['changedNodeIds'], true), '#2 changedNodeIds includes deleted node');

// 128 link unset
$find128 = null;
$walk3 = function ($node) use (&$walk3, &$find128): void {
    if (($node['id'] ?? null) === 128) {
        $find128 = $node;
    }
    foreach ($node['children'] ?? [] as $c) {
        $walk3($c);
    }
};
$walk3($after['root']);
$check(!isset($find128['data']['properties']['content']['content']['link']), '#2 unset removed outer link');

// ---- #3 css block idempotency ----
$blocks = $svc->listCssBlocksInTree($after);
$check(count($blocks) === 1 && $blocks[0]['key'] === 'contact', '#3 css block created with key');

$res2 = $svc->applyNodeOperations($after, [['op' => 'upsert_css', 'key' => 'contact', 'css' => '.x{display:flex}']]);
$blocks2 = $svc->listCssBlocksInTree($res2['tree']);
$check(count($blocks2) === 1, '#3 upsert is idempotent (no duplicate block)');

$res3 = $svc->applyNodeOperations($res2['tree'], [['op' => 'remove_css', 'key' => 'contact']]);
$blocks3 = $svc->listCssBlocksInTree($res3['tree']);
$check(count($blocks3) === 0, '#3 remove_css deletes block');

// ---- #2 insert_node assigns a fresh id + idMap ----
$res4 = $svc->applyNodeOperations($tree, [['op' => 'insert_node', 'parentId' => 104, 'node' => ['id' => 999, 'data' => ['type' => 'EssentialElements\\MenuLink', 'properties' => ['content' => ['content' => ['text' => 'New']]]], 'children' => []]]]);
$check(isset($res4['idMap'][999]) && $res4['idMap'][999] !== 999, '#2 insert_node remaps temp id via idMap');

// ---- error path ----
$err = $svc->applyNodeOperations($tree, [['op' => 'delete_node', 'targetNodeId' => 1]]);
$check(is_wp_error($err), 'delete root is rejected');

if ($failures > 0) {
    fwrite(STDERR, "mcp-tree-tools FAILED with {$failures} failure(s)\n");
    exit(1);
}

echo "mcp-tree-tools-ok\n";
