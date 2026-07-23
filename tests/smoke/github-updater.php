<?php

declare(strict_types=1);

use OxyAI\Oxygen\Updates\GitHubUpdater;

global $githubUpdaterTestFilters;
global $githubUpdaterDeletedTransients;
global $githubUpdaterRemoteResponse;
global $githubUpdaterSiteTransients;
global $githubUpdaterSetTransientCalls;

$githubUpdaterTestFilters = [];
$githubUpdaterDeletedTransients = [];
$githubUpdaterRemoteResponse = null;
$githubUpdaterSiteTransients = [];
$githubUpdaterSetTransientCalls = [];

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, $value, ...$args)
    {
        global $githubUpdaterTestFilters;

        if ($hook === 'oxyai_oxygen_github_token' && isset($githubUpdaterTestFilters['token'])) {
            return $githubUpdaterTestFilters['token'];
        }
        if ($hook === 'oxyai_oxygen_enable_auto_updates' && array_key_exists('auto_update', $githubUpdaterTestFilters)) {
            return $githubUpdaterTestFilters['auto_update'];
        }

        return $value;
    }
}

if (!function_exists('delete_site_transient')) {
    function delete_site_transient(string $key): bool
    {
        global $githubUpdaterDeletedTransients;
        $githubUpdaterDeletedTransients[] = $key;
        return true;
    }
}

if (!function_exists('get_file_data')) {
    function get_file_data(string $file, array $headers): array
    {
        return [
            'Name' => 'OxyAI Oxygen',
            'Author' => 'Denis Uhrik',
            'PluginURI' => 'https://github.com/PlayUPSK/OxyAI',
            'RequiresWP' => '7.0',
            'RequiresPHP' => '8.4',
        ];
    }
}

if (!function_exists('get_site_transient')) {
    function get_site_transient(string $key)
    {
        global $githubUpdaterSiteTransients;
        return $githubUpdaterSiteTransients[$key] ?? false;
    }
}

if (!function_exists('set_site_transient')) {
    function set_site_transient(string $key, $value, int $expiration): bool
    {
        global $githubUpdaterSetTransientCalls;
        global $githubUpdaterSiteTransients;

        $githubUpdaterSetTransientCalls[] = [
            'key' => $key,
            'value' => $value,
            'expiration' => $expiration,
        ];
        $githubUpdaterSiteTransients[$key] = $value;

        return true;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args)
    {
        global $githubUpdaterRemoteResponse;
        return $githubUpdaterRemoteResponse;
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int
    {
        return (int) ($response['response']['code'] ?? 0);
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        return (string) ($response['body'] ?? '');
    }
}

require_once __DIR__ . '/../../src/Updates/GitHubUpdater.php';

$failures = 0;
$check = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$updater = new GitHubUpdater(
    '/var/www/wp-content/plugins/oxyai/oxyai-oxygen.php',
    'PlayUPSK/OxyAI',
    '0.4.2',
    '/^oxyai-[0-9][0-9A-Za-z.\-]*\.zip$/'
);

// ---- version normalisation ----
$check($updater->normalizeVersion('v0.5.0') === '0.5.0', 'strips leading v');
$check($updater->normalizeVersion('0.5.0') === '0.5.0', 'leaves bare version');

// ---- asset selection prefers the built zip ----
$release = [
    'tag_name' => 'v0.5.0',
    'html_url' => 'https://github.com/PlayUPSK/OxyAI/releases/tag/v0.5.0',
    'zipball_url' => 'https://api.github.com/repos/PlayUPSK/OxyAI/zipball/v0.5.0',
    'assets' => [
        ['name' => 'something-else.txt', 'browser_download_url' => 'https://example.com/x.txt'],
        ['name' => 'oxyai-0.5.0.zip', 'browser_download_url' => 'https://github.com/PlayUPSK/OxyAI/releases/download/v0.5.0/oxyai-0.5.0.zip'],
    ],
];
$check(
    $updater->selectPackageUrl($release) === 'https://github.com/PlayUPSK/OxyAI/releases/download/v0.5.0/oxyai-0.5.0.zip',
    'selects the oxyai-*.zip asset'
);

// ---- falls back to zipball when no asset matches ----
$noAsset = ['tag_name' => 'v0.5.0', 'assets' => [['name' => 'readme.txt', 'browser_download_url' => 'https://x/readme.txt']], 'zipball_url' => 'https://api.github.com/zip'];
$check($updater->selectPackageUrl($noAsset) === 'https://api.github.com/zip', 'falls back to zipball');

// ---- version comparison ----
$check($updater->isNewer(['tag_name' => 'v0.5.0']) === true, '0.5.0 > 0.4.2 is newer');
$check($updater->isNewer(['tag_name' => 'v0.4.2']) === false, 'equal is not newer');
$check($updater->isNewer(['tag_name' => 'v0.4.1']) === false, 'older is not newer');
$check($updater->isNewer(['tag_name' => '']) === false, 'missing tag is not newer');

// ---- update object shape ----
$item = $updater->buildUpdateObject($release, $updater->selectPackageUrl($release));
$check($item->plugin === 'oxyai/oxyai-oxygen.php', 'plugin basename derived from path');
$check($item->slug === 'oxyai', 'slug derived from folder');
$check($item->new_version === '0.5.0', 'new_version from tag');
$check(str_ends_with($item->package, 'oxyai-0.5.0.zip'), 'package points at the asset');
$check($item->url === 'https://github.com/PlayUPSK/OxyAI/releases/tag/v0.5.0', 'url is the release page');
$check($item->requires === '7.0', 'preserves WordPress requirement in update offers');
$check($item->requires_php === '8.4', 'preserves PHP requirement in update offers');

// ---- private repo downloads use authenticated asset URLs ----
$githubUpdaterTestFilters['token'] = 'test-token';
$privateRelease = $release;
$privateRelease['assets'][1]['url'] = 'https://api.github.com/repos/PlayUPSK/OxyAI/releases/assets/123';
$check(
    $updater->selectPackageUrl($privateRelease) === 'https://api.github.com/repos/PlayUPSK/OxyAI/releases/assets/123',
    'prefers authenticated asset API URL when a token is available'
);
$authorizedArgs = $updater->authorizePackageDownloads(['headers' => []], 'https://api.github.com/repos/PlayUPSK/OxyAI/releases/assets/123');
$check(($authorizedArgs['headers']['Authorization'] ?? '') === 'Bearer test-token', 'adds auth header to GitHub package downloads');
$check(($authorizedArgs['headers']['Accept'] ?? '') === 'application/octet-stream', 'requests octet-stream for GitHub asset API downloads');
unset($githubUpdaterTestFilters['token']);

// ---- auto-update hook respects the existing plugin choice ----
$pluginItem = (object) ['plugin' => 'oxyai/oxyai-oxygen.php'];
$check($updater->enableAutoUpdate(false, $pluginItem) === false, 'keeps disabled auto-update choice');
$check($updater->enableAutoUpdate(true, $pluginItem) === true, 'keeps enabled auto-update choice');

// ---- cache is cleared after both single and bulk plugin updates ----
$githubUpdaterDeletedTransients = [];
$updater->clearCacheAfterUpdate(null, ['type' => 'plugin', 'action' => 'update', 'plugin' => 'oxyai/oxyai-oxygen.php']);
$check($githubUpdaterDeletedTransients === ['oxyai_oxygen_github_release'], 'clears cache after single-plugin update');
$githubUpdaterDeletedTransients = [];
$updater->clearCacheAfterUpdate(null, ['type' => 'plugin', 'action' => 'update', 'plugins' => ['akismet/akismet.php', 'oxyai/oxyai-oxygen.php']]);
$check($githubUpdaterDeletedTransients === ['oxyai_oxygen_github_release'], 'clears cache after bulk plugin update');

// ---- failed lookups recover faster than successful ones ----
$fetchRelease = new ReflectionMethod($updater, 'fetchRelease');
$fetchRelease->setAccessible(true);
$githubUpdaterSiteTransients = [];
$githubUpdaterSetTransientCalls = [];
$githubUpdaterRemoteResponse = ['response' => ['code' => 500], 'body' => ''];
$check($fetchRelease->invoke($updater) === null, 'failed release lookup returns null');
$check(($githubUpdaterSetTransientCalls[0]['expiration'] ?? 0) === 300, 'failed release lookup uses short cache TTL');
$githubUpdaterSiteTransients = [];
$githubUpdaterSetTransientCalls = [];
$githubUpdaterRemoteResponse = ['response' => ['code' => 200], 'body' => '{"tag_name":"v0.5.0"}'];
$fetchedRelease = $fetchRelease->invoke($updater);
$check(($fetchedRelease['tag_name'] ?? '') === 'v0.5.0', 'successful release lookup returns payload');
$check(($githubUpdaterSetTransientCalls[0]['expiration'] ?? 0) === 21600, 'successful release lookup keeps six-hour cache TTL');

// ---- checksum verification: pure helpers ----
$tmp = tempnam(sys_get_temp_dir(), 'oxyai_zip_');
file_put_contents($tmp, 'pretend-zip-bytes');
$realDigest = hash('sha256', 'pretend-zip-bytes');
$check($updater->verifyChecksum($tmp, $realDigest) === true, 'verifyChecksum passes for a matching sha256');
$check($updater->verifyChecksum($tmp, 'sha256:' . $realDigest) === true, 'verifyChecksum accepts the sha256: prefixed form');
$check($updater->verifyChecksum($tmp, strtoupper($realDigest)) === true, 'verifyChecksum is case-insensitive');
$check($updater->verifyChecksum($tmp, str_repeat('0', 64)) === false, 'verifyChecksum fails for a mismatched digest');
$check($updater->verifyChecksum($tmp, '') === false, 'verifyChecksum fails for an absent digest');
$check($updater->verifyChecksum('/no/such/file', $realDigest) === false, 'verifyChecksum fails for a missing file');
@unlink($tmp);

// ---- digest resolution from a release payload ----
$assetUrl = 'https://github.com/PlayUPSK/OxyAI/releases/download/v0.5.0/oxyai-0.5.0.zip';
$releaseWithDigest = [
    'tag_name' => 'v0.5.0',
    'assets' => [
        ['name' => 'oxyai-0.5.0.zip', 'browser_download_url' => $assetUrl, 'digest' => 'sha256:' . $realDigest],
    ],
];
$check($updater->digestForPackage($releaseWithDigest, $assetUrl) === 'sha256:' . $realDigest, 'digestForPackage returns the asset digest');

$releaseNoDigest = [
    'tag_name' => 'v0.5.0',
    'assets' => [
        ['name' => 'oxyai-0.5.0.zip', 'browser_download_url' => $assetUrl],
    ],
];
$check($updater->digestForPackage($releaseNoDigest, $assetUrl) === '', 'digestForPackage returns empty when no digest and no sidecar');

$releaseSidecar = [
    'tag_name' => 'v0.5.0',
    'assets' => [
        ['name' => 'oxyai-0.5.0.zip', 'browser_download_url' => $assetUrl],
        ['name' => 'oxyai-0.5.0.zip.sha256', 'browser_download_url' => 'https://example.test/oxyai-0.5.0.zip.sha256'],
    ],
];
// fetchSidecarDigest uses wp_remote_get, stubbed to return $githubUpdaterRemoteResponse.
$githubUpdaterRemoteResponse = ['response' => ['code' => 200], 'body' => $realDigest . '  oxyai-0.5.0.zip'];
$check($updater->digestForPackage($releaseSidecar, $assetUrl) === $realDigest, 'digestForPackage reads a sidecar .sha256 asset');

// ---- token + auto-update read from settings with constant/filter precedence ----
$GLOBALS['oxyaiSmokeUpdaterOptions'] = [];
if (!function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['oxyaiSmokeUpdaterOptions'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool
    {
        $GLOBALS['oxyaiSmokeUpdaterOptions'][$name] = $value;
        return true;
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim($value);
    }
}
if (!defined('OXYAI_OXYGEN_OPTION')) {
    define('OXYAI_OXYGEN_OPTION', 'oxyai_oxygen_settings');
}

require_once __DIR__ . '/../../src/Settings/SettingsRepository.php';

$settings = new OxyAI\Oxygen\Settings\SettingsRepository();
$settings->set('github_token', 'settings-token-xyz');
$settings->set('auto_update_enabled', true);

$settingsUpdater = new GitHubUpdater(
    '/var/www/wp-content/plugins/oxyai/oxyai-oxygen.php',
    'PlayUPSK/OxyAI',
    '0.4.2',
    '/^oxyai-[0-9][0-9A-Za-z.\-]*\.zip$/',
    $settings
);

$tokenMethod = new ReflectionMethod($settingsUpdater, 'gitHubToken');
$tokenMethod->setAccessible(true);
$check($tokenMethod->invoke($settingsUpdater) === 'settings-token-xyz', 'updater reads github token from settings when no constant/filter');

// filter overrides the stored setting
$githubUpdaterTestFilters['token'] = 'filter-token-overrides';
$check($tokenMethod->invoke($settingsUpdater) === 'filter-token-overrides', 'filter token overrides the settings token');
unset($githubUpdaterTestFilters['token']);

// auto-update default comes from settings when no constant/filter
$check($settingsUpdater->enableAutoUpdate(false, $pluginItem) === true, 'auto-update defaults to the stored setting (enabled)');
$settings->set('auto_update_enabled', false);
$check($settingsUpdater->enableAutoUpdate(true, $pluginItem) === false, 'auto-update default follows the stored setting (disabled)');
// explicit filter still wins
$githubUpdaterTestFilters['auto_update'] = true;
$check($settingsUpdater->enableAutoUpdate(false, $pluginItem) === true, 'auto-update filter overrides the stored setting');
unset($githubUpdaterTestFilters['auto_update']);

if ($failures > 0) {
    fwrite(STDERR, "github-updater FAILED with {$failures} failure(s)\n");
    exit(1);
}

echo "github-updater-ok\n";
