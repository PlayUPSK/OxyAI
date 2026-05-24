<?php

declare(strict_types=1);

$controllerPath = __DIR__ . '/../../src/Rest/McpController.php';
$source = file_get_contents($controllerPath);
assert(is_string($source));

assert(str_contains(
    $source,
    "if (\$method === 'notifications/initialized')"
));
assert(str_contains(
    $source,
    'return $this->ok($this->jsonRpcResult(null, new \stdClass()), 202);'
));
assert(!str_contains(
    $source,
    'return $this->ok(null, 202);'
));

echo "mcp-jsonrpc-notification-ok\n";
