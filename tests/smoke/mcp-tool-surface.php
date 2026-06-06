<?php

declare(strict_types=1);

// Source-level assertions that the new token-efficient MCP tools and the
// Streamable HTTP transport affordances are wired into the controller.

$controllerPath = __DIR__ . '/../../src/Rest/McpController.php';
$source = file_get_contents($controllerPath);
assert(is_string($source));

// New tools are registered in tools/list ...
foreach ([
    'find_oxygen_nodes',
    'apply_oxygen_operations',
    'upsert_css_block',
    'remove_css_block',
    'list_css_blocks',
] as $tool) {
    assert(str_contains($source, "'{$tool}'"), "tool {$tool} is defined");
}

// ... and routed in callTool().
assert(str_contains($source, "'apply_oxygen_operations' => \$this->applyOxygenOperations("), 'apply_oxygen_operations routed');
assert(str_contains($source, "'find_oxygen_nodes' => \$this->pageMutations->findNodes("), 'find_oxygen_nodes routed');
assert(str_contains($source, "'upsert_css_block' => \$this->pageMutations->upsertCssBlock("), 'upsert_css_block routed');

// get_oxygen_tree forwards view options.
assert(str_contains($source, '$this->treeViewOptions($input)'), 'get_oxygen_tree forwards view options');

// recompile + dryRunView are documented on the apply tools.
assert(str_contains($source, "'recompile' => ['type' => 'boolean'"), 'recompile flag documented');
assert(str_contains($source, "'dryRunView'"), 'dryRunView documented');

// New write tools are covered by the non-ASCII live-write guard.
assert(str_contains($source, "'apply_oxygen_operations', 'upsert_css_block'"), 'new write tools guarded for non-ascii');

// Streamable HTTP transport affordances.
assert(str_contains($source, "negotiateProtocolVersion"), 'protocol version negotiation present');
assert(str_contains($source, "Mcp-Session-Id"), 'session id header present');
assert(str_contains($source, "'methods' => 'GET, DELETE'"), 'GET/DELETE route registered');
assert(str_contains($source, "handleUnsupportedTransportMethod"), '405 handler present');

echo "mcp-tool-surface-ok\n";
