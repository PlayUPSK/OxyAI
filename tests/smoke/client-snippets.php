<?php

declare(strict_types=1);

use OxyAI\Oxygen\Admin\ClientSnippets;

require_once __DIR__ . '/../../src/Admin/ClientSnippets.php';

$failures = 0;
$check = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$snippets = new ClientSnippets();
$url = 'https://example.test/wp-json/oxyai/v1/mcp';
$token = 'oxyai_abc123def456';

// ---- template catalogue covers the required clients ----
$ids = array_map(static fn (array $t): string => $t['id'], $snippets->templates());
foreach (['claude-code', 'claude-desktop', 'cursor', 'vscode', 'windsurf', 'codex-cli', 'gemini-cli'] as $expected) {
    $check(in_array($expected, $ids, true), "template catalogue includes {$expected}");
}
$check(count($ids) === count(array_unique($ids)), 'template ids are unique');

// ---- placeholder rendering never leaks a token ----
$placeholder = $snippets->render($url, null);
$claudeCodePlaceholder = '';
foreach ($placeholder as $entry) {
    $check(str_contains($entry['snippet'], $url), "{$entry['id']} interpolates the real URL");
    $check(str_contains($entry['snippet'], ClientSnippets::TOKEN_PLACEHOLDER), "{$entry['id']} keeps the token placeholder when unrevealed");
    if ($entry['id'] === 'claude-code') {
        $claudeCodePlaceholder = $entry['snippet'];
    }
}
$check(
    $claudeCodePlaceholder === 'claude mcp add --transport http oxyai ' . $url . ' --header "x-oxyai-token: <your-token>"',
    'claude-code placeholder snippet matches the documented command'
);

// ---- empty string token also falls back to the placeholder ----
$emptyToken = $snippets->render($url, '');
$check(str_contains($emptyToken[0]['snippet'], ClientSnippets::TOKEN_PLACEHOLDER), 'empty token string uses the placeholder');

// ---- revealed rendering interpolates the token and drops the placeholder ----
$revealed = $snippets->render($url, $token);
foreach ($revealed as $entry) {
    $check(str_contains($entry['snippet'], $token), "{$entry['id']} interpolates the revealed token");
    $check(!str_contains($entry['snippet'], ClientSnippets::TOKEN_PLACEHOLDER), "{$entry['id']} removes the placeholder when token is supplied");
}

// ---- header transport, never a query string token ----
$claudeCode = null;
foreach ($revealed as $entry) {
    if ($entry['id'] === 'claude-code') {
        $claudeCode = $entry;
    }
}
$check($claudeCode !== null, 'claude-code snippet present');
$check(str_contains((string) $claudeCode['snippet'], 'x-oxyai-token: ' . $token), 'claude-code sends token via header');
$check(!str_contains((string) $claudeCode['snippet'], '?'), 'claude-code does not place the token in a query string');

if ($failures > 0) {
    fwrite(STDERR, "client-snippets FAILED with {$failures} failure(s)\n");
    exit(1);
}

echo "client-snippets-ok\n";
