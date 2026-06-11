<?php

declare(strict_types=1);

use OxyAI\Oxygen\Oxygen\OxygenPageMutationService;

// Minimal WordPress stubs needed by the dry-run apply path.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public string $code;
        public string $message;
        /** @var array<string, mixed> */
        public array $data;

        /** @param array<string, mixed> $data */
        public function __construct(string $code = '', string $message = '', array $data = [])
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('get_post')) {
    function get_post(int $postId)
    {
        return (object) ['ID' => $postId];
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key, bool $single = false)
    {
        return ''; // No existing tree.
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(int $postId): string
    {
        return 'https://example.test/?p=' . $postId;
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(int $postId, string $context = 'display'): string
    {
        return 'https://example.test/wp-admin/post.php?post=' . $postId;
    }
}

require_once __DIR__ . '/../../src/Oxygen/OxygenTreeToolsTrait.php';
require_once __DIR__ . '/../../src/Oxygen/OxygenPageMutationService.php';

$service = new OxygenPageMutationService();

// A document tree where the namespace separator was stripped in transport, so
// "OxygenElements\Container" arrived as "OxygenElementsContainer" and an inner
// "EssentialElements\IconBox" as "EssentialElementsIconBox".
$oxygen = [
    'documentTree' => [
        'root' => [
            'id' => 1,
            'data' => ['type' => 'root', 'properties' => []],
            'children' => [
                [
                    'id' => 2,
                    'data' => ['type' => 'OxygenElementsContainer', 'properties' => []],
                    'children' => [
                        [
                            'id' => 3,
                            'data' => ['type' => 'EssentialElementsIconBox', 'properties' => []],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$result = $service->applyOxygen(7, $oxygen, [
    'operation' => 'replace',
    'dryRun' => true,
    // dryRunView default is now "outline" (token-efficient); this assertion
    // inspects the full proposed tree, so request it explicitly.
    'dryRunView' => 'full',
    'registerSelectors' => false,
]);

assert(is_array($result));
assert($result['success'] === true);
assert($result['dryRun'] === true);

// Both corrupted types were recorded as repairs.
assert(isset($result['elementTypeRepairs']));
$repairs = $result['elementTypeRepairs'];
assert(count($repairs) === 2);

$repairMap = [];
foreach ($repairs as $repair) {
    $repairMap[$repair['from']] = $repair['to'];
}
assert($repairMap['OxygenElementsContainer'] === 'OxygenElements\\Container');
assert($repairMap['EssentialElementsIconBox'] === 'EssentialElements\\IconBox');

// A client-visible warning was emitted (plural form for two repairs).
assert(isset($result['mcpWarnings'][0]['code']));
assert($result['mcpWarnings'][0]['code'] === 'element_type_namespace_repaired');
assert($result['mcpWarnings'][0]['severity'] === 'warning');
assert(str_contains($result['mcpWarnings'][0]['message'], 'namespace separators'));

// The applied (dry-run) tree carries the corrected, resolvable type names.
$types = [];
$collect = static function ($node) use (&$collect, &$types): void {
    if (!is_array($node)) {
        return;
    }
    if (isset($node['data']['type']) && is_string($node['data']['type'])) {
        $types[] = $node['data']['type'];
    }
    foreach ($node['children'] ?? [] as $child) {
        $collect($child);
    }
};
$collect($result['tree']['root'] ?? $result['tree']);

assert(in_array('OxygenElements\\Container', $types, true));
assert(in_array('EssentialElements\\IconBox', $types, true));
assert(!in_array('OxygenElementsContainer', $types, true));
assert(!in_array('EssentialElementsIconBox', $types, true));

// Healthy, already-namespaced types are left untouched (no repairs, no warning).
$healthy = [
    'documentTree' => [
        'root' => [
            'id' => 1,
            'data' => ['type' => 'root', 'properties' => []],
            'children' => [
                [
                    'id' => 2,
                    'data' => ['type' => 'OxygenElements\\Container', 'properties' => []],
                    'children' => [],
                ],
            ],
        ],
    ],
];

$healthyResult = $service->applyOxygen(7, $healthy, [
    'operation' => 'replace',
    'dryRun' => true,
    'registerSelectors' => false,
]);

assert(!isset($healthyResult['elementTypeRepairs']));
assert(!isset($healthyResult['mcpWarnings']));

echo "element-type-repair-ok\n";
