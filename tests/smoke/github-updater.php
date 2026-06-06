<?php

declare(strict_types=1);

use OxyAI\Oxygen\Updates\GitHubUpdater;

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

if ($failures > 0) {
    fwrite(STDERR, "github-updater FAILED with {$failures} failure(s)\n");
    exit(1);
}

echo "github-updater-ok\n";
