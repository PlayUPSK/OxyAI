<?php

declare(strict_types=1);

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\CssParser;
use OxyHtmlConverter\StyleExtractor;

require_once __DIR__ . '/../../vendor/oxygen-html-converter/src/ElementTypes.php';
require_once __DIR__ . '/../../vendor/oxygen-html-converter/src/Services/CssParser.php';
require_once __DIR__ . '/../../vendor/oxygen-html-converter/src/StyleExtractor.php';

$parser = new CssParser();
$rules = $parser->parse(
    '.hero{display:flex;padding:90px 40px}' .
    '@media (max-width:1119px){.hero{flex-direction:column;padding:80px 28px}}' .
    '@media (max-width:767px){.hero{font-size:32px;padding:64px 20px}}'
);

assert(count($rules) === 3);
assert($rules[0]['breakpoint'] === 'breakpoint_base');
assert($rules[1]['breakpoint'] === 'breakpoint_tablet_landscape');
assert($rules[2]['breakpoint'] === 'breakpoint_phone_landscape');

$extractor = new StyleExtractor();
$tablet = $extractor->toOxygenProperties(
    $rules[1]['declarations'],
    ElementTypes::CONTAINER,
    $rules[1]['breakpoint']
);
$phone = $extractor->toOxygenProperties(
    $rules[2]['declarations'],
    ElementTypes::CONTAINER,
    $rules[2]['breakpoint']
);

assert($tablet['layout']['flex_direction']['breakpoint_tablet_landscape'] === 'column');
assert($tablet['container']['padding']['breakpoint_tablet_landscape']['top']['style'] === '80px');
assert($tablet['container']['padding']['breakpoint_tablet_landscape']['right']['style'] === '28px');
assert($phone['typography']['font_size']['breakpoint_phone_landscape']['style'] === '32px');
assert($phone['container']['padding']['breakpoint_phone_landscape']['left']['style'] === '20px');

echo "responsive-css-schema-ok\n";
