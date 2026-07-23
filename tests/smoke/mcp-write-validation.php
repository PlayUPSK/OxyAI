<?php

declare(strict_types=1);

use OxyAI\Oxygen\Codex\ElementWriteValidator;
use OxyAI\Oxygen\Oxygen\OxygenPageMutationService;

// ---- minimal WordPress stubs (pure logic only) ----
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
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args)
    {
        return $value;
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

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data()
        {
            return $this->data;
        }
    }
}

require_once __DIR__ . '/../../vendor/oxygen-html-converter/src/ElementTypes.php';
require_once __DIR__ . '/../../vendor/oxygen-html-converter/src/Services/BuilderContractService.php';
require_once __DIR__ . '/../../vendor/oxygen-html-converter/src/Services/BuilderElementCatalogService.php';
require_once __DIR__ . '/../../vendor/oxygen-html-converter/src/Services/EnvironmentService.php';
require_once __DIR__ . '/../../src/Codex/OxygenElementCapabilityService.php';
require_once __DIR__ . '/../../src/Codex/ElementWriteValidator.php';
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
$strict = new ElementWriteValidator(null, ElementWriteValidator::MODE_STRICT);
$warn = new ElementWriteValidator(null, ElementWriteValidator::MODE_WARN);

// Fixture tree with a known Heading and a Container.
$tree = static fn (): array => [
    'root' => [
        'id' => 1,
        'data' => ['type' => 'root'],
        'children' => [
            ['id' => 2, 'data' => ['type' => 'EssentialElements\\Heading', 'properties' => ['content' => ['content' => ['text' => 'Hi']]]], 'children' => []],
            ['id' => 3, 'data' => ['type' => 'OxygenElements\\Container'], 'children' => []],
            ['id' => 4, 'data' => ['type' => 'OxygenElements\\Text', 'properties' => ['content' => ['content' => ['text' => 'body']]]], 'children' => []],
        ],
    ],
];

// ---- #1 valid write passes (structured spacing path on a container) ----
$ops = [['op' => 'update_node', 'targetNodeId' => 3, 'set' => [
    'data.design.spacing.padding.breakpoint_base.top' => ['number' => 10, 'unit' => 'px', 'style' => '10px'],
]]];
$res = $svc->applyNodeOperations($tree(), $ops, $strict);
$check(!is_wp_error($res), '#1 valid structured spacing write passes');

// ---- #1 invalid: flat design.padding shorthand on known element is rejected (422) ----
$ops = [['op' => 'update_node', 'targetNodeId' => 3, 'set' => ['data.design.padding' => '10px']]];
$res = $svc->applyNodeOperations($tree(), $ops, $strict);
$check(is_wp_error($res), '#1 flat design.padding rejected in strict mode');
$check(is_wp_error($res) && str_contains($res->get_error_message(), 'design.spacing.padding'), '#1 reject names the correct path');

// ---- #1 warn mode: same write passes but emits a warning ----
$res = $svc->applyNodeOperations($tree(), $ops, $warn);
$check(!is_wp_error($res), '#1 warn mode allows the write');
$check(!is_wp_error($res) && $res['mcpWarnings'] !== [], '#1 warn mode emits a warning');

// ---- #1 unknown element type: allowed + warned (even with odd design path) ----
$ops = [['op' => 'set_node_type', 'targetNodeId' => 3, 'type' => 'EssentialElements\\MysteryWidget', 'set' => ['data.design.padding' => 'x']]];
$res = $svc->applyNodeOperations($tree(), $ops, $strict);
$check(!is_wp_error($res), '#1 unknown element type is allowed');
$check(!is_wp_error($res) && $res['mcpWarnings'] !== [], '#1 unknown element type warns');
$hasUnknownWarn = false;
if (!is_wp_error($res)) {
    foreach ($res['mcpWarnings'] as $w) {
        if (($w['code'] ?? '') === 'unknown_element_type') {
            $hasUnknownWarn = true;
        }
    }
}
$check($hasUnknownWarn, '#1 unknown element warning code present');

// ---- #1 insert_node missing required content path is rejected ----
$ops = [['op' => 'insert_node', 'parentId' => 1, 'node' => [
    'data' => ['type' => 'EssentialElements\\Heading', 'properties' => ['content' => ['content' => []]]],
    'children' => [],
]]];
$res = $svc->applyNodeOperations($tree(), $ops, $strict);
$check(is_wp_error($res), '#1 insert without required content.content.text rejected');
$check(is_wp_error($res) && str_contains($res->get_error_message(), 'content'), '#1 missing-content reject mentions content path');

// ---- #1 insert_node WITH required content passes ----
$ops = [['op' => 'insert_node', 'parentId' => 1, 'node' => [
    'data' => ['type' => 'EssentialElements\\Heading', 'properties' => ['content' => ['content' => ['text' => 'Valid']]]],
    'children' => [],
]]];
$res = $svc->applyNodeOperations($tree(), $ops, $strict);
$check(!is_wp_error($res), '#1 insert with required content passes');

// ---- #3 anti-pattern guard: raw <section> in text content rejected ----
$ops = [['op' => 'update_node', 'targetNodeId' => 4, 'set' => [
    'data.properties.content.content.text' => '<section>nope</section>',
]]];
$res = $svc->applyNodeOperations($tree(), $ops, $strict);
$check(is_wp_error($res), '#3 raw <section> in text rejected');
$check(is_wp_error($res) && str_contains($res->get_error_message(), 'apply_html_to_oxygen_page'), '#3 reject routes to apply_html_to_oxygen_page');

// ---- #3 <script> rejected, inline <strong>/style= allowed ----
$res = $svc->applyNodeOperations($tree(), [['op' => 'update_node', 'targetNodeId' => 4, 'set' => ['data.properties.content.content.text' => '<script>x</script>']]], $strict);
$check(is_wp_error($res), '#3 raw <script> in text rejected');

$res = $svc->applyNodeOperations($tree(), [['op' => 'update_node', 'targetNodeId' => 4, 'set' => ['data.properties.content.content.text' => 'Hello <strong style="color:red">there</strong> <a href="#">link</a>']]], $strict);
$check(!is_wp_error($res), '#3 inline formatting tags + inline style= allowed');

// ---- #3 CssCode exempt from anti-pattern guard ----
$cssTree = ['root' => ['id' => 1, 'data' => ['type' => 'root'], 'children' => [
    ['id' => 9, 'data' => ['type' => 'OxygenElements\\CssCode', 'properties' => ['content' => ['content' => ['text' => '<style>.x{}</style>']]]], 'children' => []],
]]];
$res = $svc->applyNodeOperations($cssTree, [['op' => 'update_node', 'targetNodeId' => 9, 'set' => ['data.properties.content.content.text' => '<style>.y{}</style>']]], $strict);
$check(!is_wp_error($res), '#3 CssCode element exempt from anti-pattern guard');

// ---- #2 patch_node deep-merge semantics ----
$pTree = ['root' => ['id' => 1, 'data' => ['type' => 'root'], 'children' => [
    ['id' => 5, 'data' => ['type' => 'OxygenElements\\Text', 'properties' => [
        'content' => ['content' => ['text' => 'old', 'keepme' => 'yes']],
        'settings' => ['advanced' => ['classes' => ['a', 'b']]],
    ]], 'children' => []],
]]];
$patch = ['properties' => ['content' => ['content' => ['text' => 'new']]]];
$res = $svc->applyNodeOperations($pTree, [['op' => 'patch_node', 'targetNodeId' => 5, 'data' => $patch]], $strict);
$check(!is_wp_error($res), '#2 patch_node succeeds');
if (!is_wp_error($res)) {
    $node = null;
    $walk = function ($n) use (&$walk, &$node): void {
        if (($n['id'] ?? null) === 5) {
            $node = $n;
        }
        foreach ($n['children'] ?? [] as $c) {
            $walk($c);
        }
    };
    $walk($res['tree']['root']);
    $check(($node['data']['properties']['content']['content']['text'] ?? '') === 'new', '#2 patch replaces scalar');
    $check(($node['data']['properties']['content']['content']['keepme'] ?? '') === 'yes', '#2 patch preserves sibling keys (deep-merge)');
    $check(($node['data']['properties']['settings']['advanced']['classes'] ?? []) === ['a', 'b'], '#2 patch preserves untouched branches');
    $check(($node['data']['type'] ?? '') === 'OxygenElements\\Text', '#2 patch preserves type');
    $check(($node['id'] ?? null) === 5, '#2 patch preserves id');
    $check(in_array('data.properties.content.content.text', $res['changedPaths'], true), '#2 patch reports changedPaths');
}

// ---- #2 patch_node list replaces wholesale (not merged) ----
$res = $svc->applyNodeOperations($pTree, [['op' => 'patch_node', 'targetNodeId' => 5, 'data' => ['properties' => ['settings' => ['advanced' => ['classes' => ['c']]]]]]], $strict);
$check(!is_wp_error($res), '#2 patch list succeeds');
if (!is_wp_error($res)) {
    $node = null;
    $walk = function ($n) use (&$walk, &$node): void {
        if (($n['id'] ?? null) === 5) {
            $node = $n;
        }
        foreach ($n['children'] ?? [] as $c) {
            $walk($c);
        }
    };
    $walk($res['tree']['root']);
    $check(($node['data']['properties']['settings']['advanced']['classes'] ?? []) === ['c'], '#2 list value replaces wholesale');
}

// ---- #4 html view reconstruction ----
$htmlTree = ['root' => ['id' => 1, 'data' => ['type' => 'OxygenElements\\Container'], 'children' => [
    ['id' => 2, 'data' => ['type' => 'OxygenElements\\Section'], 'children' => [
        ['id' => 3, 'data' => ['type' => 'EssentialElements\\Heading', 'properties' => ['content' => ['content' => ['text' => 'Title here', 'tag' => 'h1']]]], 'children' => []],
        ['id' => 4, 'data' => ['type' => 'OxygenElements\\Text', 'properties' => ['content' => ['content' => ['text' => 'Some paragraph']]]], 'children' => []],
        ['id' => 5, 'data' => ['type' => 'OxygenElements\\Image', 'properties' => ['content' => ['image' => ['url' => 'https://x/y.jpg', 'alt' => 'Alt']]]], 'children' => []],
        ['id' => 6, 'data' => ['type' => 'EssentialElements\\Button', 'properties' => ['content' => ['content' => ['text' => 'Go', 'link' => ['url' => 'https://x']]]]], 'children' => []],
    ]],
]]];
$html = $svc->renderTreeHtml($htmlTree);
$check($html['found'] === true, '#4 html view found');
$h = $html['html'];
$check(str_contains($h, '<section data-node-id="2"'), '#4 Section -> <section> with node id');
$check(str_contains($h, '<h1 data-node-id="3"') && str_contains($h, 'Title here'), '#4 Heading -> <h1> from tag prop');
$check(str_contains($h, '<p data-node-id="4"'), '#4 Text -> <p>');
$check(str_contains($h, '<img data-node-id="5"') && str_contains($h, 'src="https://x/y.jpg"') && str_contains($h, 'alt="Alt"'), '#4 Image -> <img src alt>');
$check(str_contains($h, '<a data-node-id="6"') && str_contains($h, 'href="https://x"'), '#4 Button -> <a href>');

// unknown type -> div with data-type
$unkTree = ['root' => ['id' => 1, 'data' => ['type' => 'EssentialElements\\Mystery'], 'children' => []]];
$uh = $svc->renderTreeHtml($unkTree)['html'];
$check(str_contains($uh, 'data-type="Mystery"'), '#4 unknown type annotated data-type');

// text preview capped ~120 chars
$longTree = ['root' => ['id' => 1, 'data' => ['type' => 'OxygenElements\\Text', 'properties' => ['content' => ['content' => ['text' => str_repeat('a', 300)]]]], 'children' => []]];
$lh = $svc->renderTreeHtml($longTree)['html'];
$check(str_contains($lh, '...'), '#4 long text preview truncated');

if ($failures > 0) {
    fwrite(STDERR, "mcp-write-validation FAILED with {$failures} failure(s)\n");
    exit(1);
}

echo "mcp-write-validation-ok\n";
