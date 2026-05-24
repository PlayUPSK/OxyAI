<?php

declare(strict_types=1);

use OxyAI\Oxygen\Oxygen\SelectorRegistrationService;

require_once __DIR__ . '/../../src/Oxygen/SelectorRegistrationService.php';

if (!function_exists('get_option')) {
    function get_option(string $key, $default = [])
    {
        global $oxyaiSelectorPropertiesOptions;

        if (is_array($oxyaiSelectorPropertiesOptions) && array_key_exists($key, $oxyaiSelectorPropertiesOptions)) {
            return $oxyaiSelectorPropertiesOptions[$key];
        }

        return $default;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value)
    {
        return json_encode($value);
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, $value, bool $autoload = false): bool
    {
        global $oxyaiSelectorPropertiesOptions;

        $oxyaiSelectorPropertiesOptions[$key] = $value;

        return true;
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
                                'classes' => ['first-card', 'missing-id-card'],
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

$oxyaiSelectorPropertiesOptions = [
    'oxy_selectors_json_string' => [
        [
            'id' => 'legacy-hero-id',
            'name' => '.breakdance .oxyai-test-hero',
            'type' => 'custom',
            'properties' => [],
            'children' => [],
            'collection' => 'OxyAI',
        ],
        [
            'name' => '.missing-id-card',
            'type' => 'class',
            'properties' => [],
            'children' => [],
            'collection' => 'OxyAI',
        ],
    ],
];

$service = new SelectorRegistrationService();
$result = $service->registerTreeSelectors($tree, false);

assert($result['created'] === 2);
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
assert($selector['locked'] === false);
assert($selector['id'] === 'legacy-hero-id');

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
assert($firstCard['locked'] === false);
assert(($firstCard['properties'] ?? null) instanceof stdClass);
assert(str_contains(json_encode($firstCard), '"properties":{}'));

assert(!isset($tree['root']['data']['properties']['meta']['_oxyaiSelectorDesign']));
assert(!isset($tree['root']['children'][1]['data']['properties']['meta']['_oxyaiSelectorDesign']));
assert(in_array($selector['id'], $tree['root']['data']['properties']['meta']['classes'] ?? [], true));
assert(($tree['root']['data']['properties']['settings']['advanced']['classes'] ?? null) === []);
assert(($tree['root']['children'][0]['data']['properties']['settings']['advanced']['classes'] ?? null) === []);
assert(($tree['root']['children'][1]['data']['properties']['settings']['advanced']['classes'] ?? null) === []);

foreach ($result['selectors'] as $registeredSelector) {
    assert(!str_starts_with((string) $registeredSelector['name'], '.breakdance'));
}

$missingIdCard = $selectorsByName['missing-id-card'];
assert(isset($missingIdCard['id']) && is_string($missingIdCard['id']) && $missingIdCard['id'] !== '');
assert(in_array($missingIdCard['id'], $tree['root']['children'][0]['data']['properties']['meta']['classes'] ?? [], true));

$service->persistSelectors($result['selectors']);

$persistedSelectors = json_decode((string) ($oxyaiSelectorPropertiesOptions['oxy_selectors_json_string'] ?? ''), true);
assert(is_array($persistedSelectors));

$persistedByName = [];
foreach ($persistedSelectors as $persistedSelector) {
    assert(is_array($persistedSelector));
    $persistedByName[$persistedSelector['name'] ?? ''] = $persistedSelector;
}

assert(isset($persistedByName['missing-id-card']));
assert(($persistedByName['missing-id-card']['id'] ?? '') === $missingIdCard['id']);
assert(($persistedByName['missing-id-card']['type'] ?? '') === 'class');
assert(!str_starts_with((string) ($persistedByName['missing-id-card']['name'] ?? ''), '.'));

$oxyaiSelectorPropertiesOptions = [
    'oxy_selectors_json_string' => [
        [
            'id' => 'empty-properties-id',
            'name' => 'empty-properties',
            'type' => 'class',
            'properties' => [],
            'children' => [],
            'collection' => 'OxyAI',
        ],
    ],
];

$repairResult = $service->repairPersistedSelectors();
assert($repairResult['selectorsChanged'] === 1);
assert($repairResult['propertiesObjectsRepaired'] === 1);

$repairedSelectors = json_decode((string) ($oxyaiSelectorPropertiesOptions['oxy_selectors_json_string'] ?? ''), true);
assert(is_array($repairedSelectors));
assert(str_contains((string) ($oxyaiSelectorPropertiesOptions['oxy_selectors_json_string'] ?? ''), '"properties":{}'));
assert(($repairedSelectors[0]['locked'] ?? null) === false);

echo "selector-properties-ok\n";
