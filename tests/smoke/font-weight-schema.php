<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require $root . '/vendor/oxygen-html-converter/src/ElementTypes.php';
require $root . '/vendor/oxygen-html-converter/src/StyleExtractor.php';

use OxyHtmlConverter\StyleExtractor;

$extractor = new StyleExtractor();

$numeric = $extractor->toOxygenProperties(['font-weight' => '700']);
$numericWeight = $numeric['typography']['font_weight']['breakpoint_base'] ?? null;
$numericJson = json_encode($numeric);

assert($numericWeight === 700);
assert(is_string($numericJson));
assert(str_contains($numericJson, '"breakpoint_base":700'));
assert(!str_contains($numericJson, '"breakpoint_base":"700"'));
assert($extractor->supportsDeclarationsFully(['font-weight' => '700']) === true);

$keywordCases = [
    'normal' => 400,
    'bold' => 700,
];

foreach ($keywordCases as $keyword => $expected) {
    $design = $extractor->toOxygenProperties(['font-weight' => $keyword]);
    assert(($design['typography']['font_weight']['breakpoint_base'] ?? null) === $expected);
}

$relative = $extractor->toOxygenProperties(['font-weight' => 'lighter']);
assert(!isset($relative['typography']['font_weight']));
assert($extractor->supportsDeclarationsFully(['font-weight' => 'lighter']) === false);

$cssWide = $extractor->toOxygenProperties(['font-weight' => 'revert-layer']);
assert(!isset($cssWide['typography']['font_weight']));
assert($extractor->supportsDeclarationsFully(['font-weight' => 'revert-layer']) === false);

$variable = $extractor->toOxygenProperties(['font-weight' => 'var(--BrandWeight)']);
$variableJson = json_encode($variable);
assert(is_string($variableJson));
assert(!isset($variable['typography']['font_weight']));
assert(!str_contains($variableJson, 'BrandWeight'));
assert(!str_contains($variableJson, 'brandweight'));
assert($extractor->supportsDeclarationsFully(['font-weight' => 'var(--BrandWeight)']) === false);

echo "font-weight-schema-ok\n";
