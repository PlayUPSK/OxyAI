<?php

declare(strict_types=1);

use OxyAI\Oxygen\Oxygen\SelectorRegistrationService;

require_once __DIR__ . '/../../src/Oxygen/SelectorRegistrationService.php';

if (!function_exists('get_option')) {
    function get_option(string $key, $default = [])
    {
        global $oxyaiIoTsSanitizationOptions;

        if (is_array($oxyaiIoTsSanitizationOptions) && array_key_exists($key, $oxyaiIoTsSanitizationOptions)) {
            return $oxyaiIoTsSanitizationOptions[$key];
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
        global $oxyaiIoTsSanitizationOptions;

        $oxyaiIoTsSanitizationOptions[$key] = $value;

        return true;
    }
}

// Simulate a registry that already contains the kind of IO-TS-incompatible
// strings the converter used to persist before normalizeLength learned to
// return null. The repair path must scrub these without touching neighbours.
$oxyaiIoTsSanitizationOptions = [
    'oxy_selectors_json_string' => [
        [
            'id' => 'container-id',
            'name' => 'mk-references-container',
            'type' => 'class',
            'properties' => [
                'breakpoint_base' => [
                    'size' => [
                        'width' => 'min(100% - 48px, 1280px)',
                        'max_width' => [
                            'number' => 1280,
                            'unit' => 'px',
                            'style' => '1280px',
                        ],
                    ],
                ],
                'breakpoint_phone_landscape' => [
                    'size' => [
                        'width' => 'fit-content',
                    ],
                ],
            ],
            'children' => [],
            'collection' => 'OxyAI',
            'locked' => false,
        ],
        [
            'id' => 'button-id',
            'name' => 'mk-button',
            'type' => 'class',
            'properties' => [
                'breakpoint_base' => [
                    'effects' => [
                        'transition' => '0.2s ease',
                        'transform' => 'scale(1.05)',
                    ],
                    'background' => [
                        'background_color' => '#e62b2b',
                    ],
                ],
            ],
            'children' => [],
            'collection' => 'OxyAI',
            'locked' => false,
        ],
        [
            'id' => 'text-id',
            'name' => 'mk-section-text',
            'type' => 'class',
            'properties' => [
                'breakpoint_base' => [
                    'typography' => [
                        'font_size' => 'clamp(34px, 4vw, 54px)',
                        'line_height' => '1.75',
                        'letter_spacing' => 'var(--track)',
                        'color' => '#333333',
                    ],
                ],
            ],
            'children' => [],
            'collection' => 'OxyAI',
            'locked' => false,
        ],
    ],
];

$readPersisted = static function (): array {
    global $oxyaiIoTsSanitizationOptions;
    $raw = $oxyaiIoTsSanitizationOptions['oxy_selectors_json_string'] ?? [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($raw) ? $raw : [];
};

$service = new SelectorRegistrationService();
$result = $service->repairPersistedSelectors();

assert($result['success'] === true);
assert($result['selectorsScanned'] === 3);
assert($result['selectorsChanged'] === 3);
assert($result['sizeStringsRemoved'] === 2);   // width(min), width(fit-content)
assert($result['effectStringsRemoved'] === 2); // transition, transform
assert($result['typographyStringsRemoved'] === 3); // font_size, line_height, letter_spacing

$repaired = $readPersisted();
assert(is_array($repaired));
$byName = [];
foreach ($repaired as $selector) {
    $byName[$selector['name']] = $selector;
}

// Container: width strings removed, valid max_width object preserved.
$container = $byName['mk-references-container'];
assert(!isset($container['properties']['breakpoint_base']['size']['width']));
assert($container['properties']['breakpoint_base']['size']['max_width']['style'] === '1280px');
assert(!isset($container['properties']['breakpoint_phone_landscape']['size']['width']));

// Button: effect strings removed, background preserved.
$button = $byName['mk-button'];
assert(!isset($button['properties']['breakpoint_base']['effects']['transition']));
assert(!isset($button['properties']['breakpoint_base']['effects']['transform']));
assert($button['properties']['breakpoint_base']['background']['background_color'] === '#e62b2b');

// Text: typography strings removed, color preserved.
$text = $byName['mk-section-text'];
assert(!isset($text['properties']['breakpoint_base']['typography']['font_size']));
assert(!isset($text['properties']['breakpoint_base']['typography']['line_height']));
assert(!isset($text['properties']['breakpoint_base']['typography']['letter_spacing']));
assert($text['properties']['breakpoint_base']['typography']['color'] === '#333333');

// Empty branches left behind after pruning must be removed too; IO-TS treats
// missing keys as valid, and pruning keeps the registry compact.
$oxyaiIoTsSanitizationOptions = [
    'oxy_selectors_json_string' => [
        [
            'id' => 'empty-branch-id',
            'name' => 'mk-empty-branch',
            'type' => 'class',
            'properties' => [
                'breakpoint_base' => [
                    'typography' => [
                        'font_size' => 'clamp(34px, 4vw, 54px)',
                    ],
                ],
            ],
            'children' => [],
            'collection' => 'OxyAI',
            'locked' => false,
        ],
    ],
];

$emptyResult = $service->repairPersistedSelectors();
assert($emptyResult['typographyStringsRemoved'] === 1);
assert(is_string($oxyaiIoTsSanitizationOptions['oxy_selectors_json_string']));
assert(str_contains($oxyaiIoTsSanitizationOptions['oxy_selectors_json_string'], '"properties":{}'));
$emptyRepaired = $readPersisted();
assert(!isset($emptyRepaired[0]['properties']['breakpoint_base']));

// Whitelisted size keywords (`auto`) survive — they're a legal schema variant
// for size.width so removing them would lose user intent.
$oxyaiIoTsSanitizationOptions = [
    'oxy_selectors_json_string' => [
        [
            'id' => 'auto-id',
            'name' => 'mk-auto-keep',
            'type' => 'class',
            'properties' => [
                'breakpoint_base' => [
                    'size' => [
                        'width' => 'auto',
                        'height' => 'inherit',
                    ],
                    'typography' => [
                        'line_height' => 'initial',
                        'letter_spacing' => 'unset',
                    ],
                ],
            ],
            'children' => [],
            'collection' => 'OxyAI',
            'locked' => false,
        ],
    ],
];

$keepResult = $service->repairPersistedSelectors();
assert($keepResult['sizeStringsRemoved'] === 0);
$keepRepaired = $readPersisted();
assert($keepRepaired[0]['properties']['breakpoint_base']['size']['width'] === 'auto');
assert($keepRepaired[0]['properties']['breakpoint_base']['size']['height'] === 'inherit');
assert($keepRepaired[0]['properties']['breakpoint_base']['typography']['line_height'] === 'initial');
assert($keepRepaired[0]['properties']['breakpoint_base']['typography']['letter_spacing'] === 'unset');

// Strict min/max size keys reject bare strings entirely. Even schema keywords
// like `none`/`auto` are invalid for `max_width`/`min_height` (Oxygen's IO-TS
// schema only accepts `{number, unit, style}` or null there), so they must be
// stripped — while a keyworded `width` in the same selector still survives.
// Mirrors the live failure: breakpoint_tablet_portrait.size.max_width = "none".
$oxyaiIoTsSanitizationOptions = [
    'oxy_selectors_json_string' => [
        [
            'id' => 'minmax-id',
            'name' => 'mk-minmax-strip',
            'type' => 'class',
            'properties' => [
                'breakpoint_tablet_portrait' => [
                    'size' => [
                        'max_width' => 'none',
                        'min_height' => 'auto',
                        'width' => 'auto',
                    ],
                ],
            ],
            'children' => [],
            'collection' => 'OxyAI',
            'locked' => false,
        ],
    ],
];

$strictResult = $service->repairPersistedSelectors();
assert($strictResult['sizeStringsRemoved'] === 2); // max_width:none, min_height:auto
$strictRepaired = $readPersisted();
assert(!isset($strictRepaired[0]['properties']['breakpoint_tablet_portrait']['size']['max_width']));
assert(!isset($strictRepaired[0]['properties']['breakpoint_tablet_portrait']['size']['min_height']));
assert($strictRepaired[0]['properties']['breakpoint_tablet_portrait']['size']['width'] === 'auto');

// Idempotent: re-running repair on already-clean data is a no-op.
$secondPass = $service->repairPersistedSelectors();
assert($secondPass['sizeStringsRemoved'] === 0);
assert($secondPass['effectStringsRemoved'] === 0);
assert($secondPass['typographyStringsRemoved'] === 0);

echo "iots-selector-sanitization-ok\n";
