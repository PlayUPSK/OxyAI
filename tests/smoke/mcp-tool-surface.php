<?php

declare(strict_types=1);

// Source-level assertions that the new token-efficient MCP tools and the
// Streamable HTTP transport affordances are wired into the controller.

$controllerPath = __DIR__ . '/../../src/Rest/McpController.php';
$source = file_get_contents($controllerPath);
$failures = 0;
$check = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

$check(is_string($source), 'controller source loaded');
if (!is_string($source)) {
    exit(1);
}

// New tools are registered in tools/list ...
foreach ([
    'find_oxygen_nodes',
    'apply_oxygen_operations',
    'upsert_css_block',
    'remove_css_block',
    'list_css_blocks',
] as $tool) {
    $check(str_contains($source, "'{$tool}'"), "tool {$tool} is defined");
}

// ... and routed in callTool().
$check(str_contains($source, "'apply_oxygen_operations' => \$this->applyOxygenOperations("), 'apply_oxygen_operations routed');
$check(str_contains($source, "'find_oxygen_nodes' => \$this->pageMutations->findNodes("), 'find_oxygen_nodes routed');
$check(str_contains($source, "'upsert_css_block' => \$this->pageMutations->upsertCssBlock("), 'upsert_css_block routed');

// get_oxygen_tree forwards view options.
$check(str_contains($source, '$this->treeViewOptions($input)'), 'get_oxygen_tree forwards view options');

// recompile + dryRunView are documented on the apply tools.
$check(str_contains($source, "'recompile' => ['type' => 'boolean'"), 'recompile flag documented');
$check(str_contains($source, "'dryRunView'"), 'dryRunView documented');

// New write tools are covered by the non-ASCII live-write guard.
$check(str_contains($source, "'apply_oxygen_operations', 'upsert_css_block'"), 'new write tools guarded for non-ascii');

// Streamable HTTP transport affordances.
$check(str_contains($source, "negotiateProtocolVersion"), 'protocol version negotiation present');
$check(str_contains($source, "Mcp-Session-Id"), 'session id header present');
$check(str_contains($source, "'methods' => 'GET, DELETE'"), 'GET/DELETE route registered');
$check(str_contains($source, "handleUnsupportedTransportMethod"), '405 handler present');
$check(str_contains($source, "use WP_REST_Response;"), 'WP_REST_Response imported');

// Nested write options are forwarded for operation/css tools too.
$check(str_contains($source, "\$nestedOptions = is_array(\$input['options'] ?? null) ? \$input['options'] : [];"), 'writeOptions reads nested options');
$check(str_contains($source, "\$nestedOptions[\$flag]"), 'writeOptions forwards nested boolean flags');

if ($failures > 0) {
    fwrite(STDERR, "mcp-tool-surface FAILED with {$failures} failure(s)\n");
    exit(1);
}

echo "mcp-tool-surface-ok\n";
