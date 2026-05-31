<?php
/**
 * Plugin Name: OxyAI Oxygen
 * Description: AI-assisted HTML, CSS, and JavaScript to native Oxygen 6 builder elements.
 * Version: 0.4.1
 * Author: Denis Uhrík
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: oxyai-oxygen
 * Requires at least: 7.0
 * Requires PHP: 8.4
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$oxyaiOxygenExpectedConstants = [
    'OXYAI_OXYGEN_VERSION' => '0.4.1',
    'OXYAI_OXYGEN_PATH' => plugin_dir_path(__FILE__),
    'OXYAI_OXYGEN_URL' => plugin_dir_url(__FILE__),
    'OXYAI_OXYGEN_OPTION' => 'oxyai_oxygen_settings',
    'OXYAI_OXYGEN_HISTORY_OPTION' => 'oxyai_oxygen_history',
    'OXYAI_OXYGEN_PRESETS_OPTION' => 'oxyai_oxygen_presets',
];

$oxyaiOxygenConstantMismatches = [];
$oxyaiOxygenCriticalMismatches = [];
foreach ($oxyaiOxygenExpectedConstants as $constant => $expected) {
    if (!defined($constant)) {
        define($constant, $expected);
        continue;
    }

    $actual = constant($constant);
    if (!is_string($actual) || $actual === '') {
        $oxyaiOxygenConstantMismatches[] = $constant;
        if (in_array($constant, ['OXYAI_OXYGEN_VERSION', 'OXYAI_OXYGEN_PATH', 'OXYAI_OXYGEN_URL'], true)) {
            $oxyaiOxygenCriticalMismatches[] = $constant;
        }
        continue;
    }

    if (in_array($constant, ['OXYAI_OXYGEN_VERSION', 'OXYAI_OXYGEN_PATH', 'OXYAI_OXYGEN_URL'], true) && $actual !== $expected) {
        $oxyaiOxygenConstantMismatches[] = $constant;
        $oxyaiOxygenCriticalMismatches[] = $constant;
    }
}

if ($oxyaiOxygenConstantMismatches !== []) {
    add_action('admin_notices', static function () use ($oxyaiOxygenConstantMismatches): void {
        echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(
            __('OxyAI Oxygen detected pre-defined constants with unexpected values: %s. Check for duplicate plugin loads or manual overrides.', 'oxyai-oxygen'),
            implode(', ', $oxyaiOxygenConstantMismatches)
        )) . '</p></div>';
    });
}

if ($oxyaiOxygenCriticalMismatches !== []) {
    return;
}

require_once OXYAI_OXYGEN_PATH . 'vendor/oxygen-html-converter/src/polyfills.php';

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'OxyAI\\Oxygen\\' => OXYAI_OXYGEN_PATH . 'src/',
        'OxyHtmlConverter\\' => OXYAI_OXYGEN_PATH . 'vendor/oxygen-html-converter/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $length = strlen($prefix);
        if (strncmp($class, $prefix, $length) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $length);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_readable($file)) {
            require $file;
        }
    }
});

add_action('plugins_loaded', static function (): void {
    if (version_compare(PHP_VERSION, '8.4.0', '<')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>' . esc_html__('OxyAI Oxygen requires PHP 8.4 or newer.', 'oxyai-oxygen') . '</p></div>';
        });
        return;
    }

    \OxyAI\Oxygen\Plugin::getInstance()->boot();
});
