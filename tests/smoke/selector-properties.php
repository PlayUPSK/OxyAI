<?php

declare(strict_types=1);

use OxyAI\Oxygen\Oxygen\SelectorRegistrationService;

require_once __DIR__ . '/../../src/Oxygen/SelectorRegistrationService.php';

if (!function_exists('get_option')) {
    function get_option(string $key, $default = [])
    {
        return $default;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

$length = static fn (int $number): array => [
    'number' => $number,
    'unit' => 'px',
    'style' => $number . 'px',
];

$tree = [
    'root' => [
        'data' => [
            'type' => 'OxygenElements\\Container',
            'properties' => [
                'settings' => [
                    'advanced' => [
                        'classes' => ['oxyai-test-hero'],
                    ],
                ],
                'meta' => [
                    '_oxyaiSelectorDesign' => [
                        'oxyai-test-hero' => [
                            'container' => [
                                'padding' => [
                                    'breakpoint_base' => [
                                        'top' => $length(96),
                                        'right' => $length(24),
                                        'bottom' => $length(96),
                                        'left' => $length(24),
                                        'editMode' => 'advanced',
                                    ],
                                ],
                                'background' => [
                                    'breakpoint_base' => '#f8fafc',
                                ],
                                'borders' => [
                                    'radius' => [
                                        'breakpoint_base' => [
                                            'all' => $length(16),
                                            'topLeft' => $length(16),
                                            'topRight' => $length(16),
                                            'bottomRight' => $length(16),
                                            'bottomLeft' => $length(16),
                                            'editMode' => 'all',
                                        ],
                                    ],
                                ],
                            ],
                            'layout' => [
                                'display' => [
                                    'breakpoint_base' => 'flex',
                                ],
                                'gap' => [
                                    'breakpoint_base' => $length(24),
                                ],
                                'align_items' => [
                                    'breakpoint_base' => 'center',
                                ],
                                'justify_content' => [
                                    'breakpoint_base' => 'space-between',
                                ],
                            ],
                            'typography' => [
                                'color' => [
                                    'breakpoint_base' => '#0f172a',
                                ],
                            ],
                            'unsupported_bucket' => [
                                'example' => [
                                    'breakpoint_base' => 'not-mapped',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'children' => [
            [
                'data' => [
                    'type' => 'OxygenElements\\Text',
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'classes' => ['first-card'],
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ],
            [
                'data' => [
                    'type' => 'OxygenElements\\Text',
                    'properties' => [
                        'settings' => [
                            'advanced' => [
                                'classes' => ['second-card'],
                            ],
                        ],
                        'meta' => [
                            '_oxyaiSelectorDesign' => [
                                'first-card' => [
                                    'typography' => [
                                        'color' => [
                                            'breakpoint_base' => '#ff0000',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ],
        ],
    ],
];

$service = new SelectorRegistrationService();
$result = $service->registerTreeSelectors($tree, false);

assert($result['created'] === 3);
assert($result['selectorPropertiesAttached'] === 1);
assert($result['attachedElements'] === 3);
assert($result['unmappedSelectorPropertyPaths'] === ['unsupported_bucket.example']);

$selectorsByName = [];
foreach ($result['selectors'] as $registeredSelector) {
    $selectorsByName[$registeredSelector['name']] = $registeredSelector;
}

$selector = $selectorsByName['oxyai-test-hero'];
assert($selector['type'] === 'class');
assert($selector['name'] === 'oxyai-test-hero');

$props = $selector['properties']['breakpoint_base'] ?? [];
assert(($props['spacing']['spacing']['padding']['editMode'] ?? null) === 'advanced');
assert(($props['spacing']['spacing']['padding']['top']['style'] ?? null) === '96px');
assert(($props['background']['background_color'] ?? null) === '#f8fafc');
assert(($props['borders']['border_radius']['editMode'] ?? null) === 'all');
assert(($props['layout']['display'] ?? null) === 'flex');
assert(($props['layout']['gap']['row']['style'] ?? null) === '24px');
assert(($props['layout']['gap']['column']['style'] ?? null) === '24px');
assert(($props['layout']['flex_align']['cross_axis'] ?? null) === 'center');
assert(($props['layout']['flex_align']['primary_axis'] ?? null) === 'space-between');
assert(($props['typography']['color'] ?? null) === '#0f172a');
assert(!isset($props['unsupported_bucket']));

$firstCard = $selectorsByName['first-card'];
assert(($firstCard['properties'] ?? []) === []);

assert(!isset($tree['root']['data']['properties']['meta']['_oxyaiSelectorDesign']));
assert(!isset($tree['root']['children'][1]['data']['properties']['meta']['_oxyaiSelectorDesign']));
assert(in_array($selector['id'], $tree['root']['data']['properties']['meta']['classes'] ?? [], true));
assert(($tree['root']['data']['properties']['settings']['advanced']['classes'] ?? null) === []);
assert(($tree['root']['children'][0]['data']['properties']['settings']['advanced']['classes'] ?? null) === []);
assert(($tree['root']['children'][1]['data']['properties']['settings']['advanced']['classes'] ?? null) === []);

echo "selector-properties-ok\n";
