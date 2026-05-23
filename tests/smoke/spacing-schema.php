<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require $root . '/vendor/oxygen-html-converter/src/ElementTypes.php';
require $root . '/vendor/oxygen-html-converter/src/StyleExtractor.php';

use OxyHtmlConverter\StyleExtractor;

$extractor = new StyleExtractor();

function elementWithStyle(string $style): DOMElement
{
    $dom = new DOMDocument();
    $dom->loadHTML('<div style="' . htmlspecialchars($style, ENT_QUOTES) . '"></div>');
    $element = $dom->getElementsByTagName('div')->item(0);
    assert($element instanceof DOMElement);

    return $element;
}

$design = $extractor->extractAndConvert(elementWithStyle(
    'padding: 24px; margin: 8px 12px 16px 20px; padding-top: 32px;'
));

$padding = $design['container']['padding']['breakpoint_base'] ?? null;
$margin = $design['container']['margin']['breakpoint_base'] ?? null;

assert(is_array($padding));
assert(($padding['editMode'] ?? null) === 'advanced');
assert(!array_key_exists('all', $padding));
assert(($padding['top']['style'] ?? null) === '32px');
assert(($padding['right']['style'] ?? null) === '24px');
assert(($padding['bottom']['style'] ?? null) === '24px');
assert(($padding['left']['style'] ?? null) === '24px');

assert(is_array($margin));
assert(($margin['editMode'] ?? null) === 'advanced');
assert(!array_key_exists('all', $margin));
assert(($margin['top']['style'] ?? null) === '8px');
assert(($margin['right']['style'] ?? null) === '12px');
assert(($margin['bottom']['style'] ?? null) === '16px');
assert(($margin['left']['style'] ?? null) === '20px');

$uniform = $extractor->extractAndConvert(elementWithStyle('padding: 48px; margin: 0;'));
$uniformPadding = $uniform['container']['padding']['breakpoint_base'] ?? null;
$uniformMargin = $uniform['container']['margin']['breakpoint_base'] ?? null;

assert(is_array($uniformPadding));
assert(($uniformPadding['editMode'] ?? null) === 'all');
assert(($uniformPadding['all']['style'] ?? null) === '48px');

assert(is_array($uniformMargin));
assert(($uniformMargin['editMode'] ?? null) === 'all');
assert(($uniformMargin['all']['style'] ?? null) === '0px');

echo "spacing-schema-ok\n";
