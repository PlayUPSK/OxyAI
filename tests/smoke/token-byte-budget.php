<?php

declare(strict_types=1);

use OxyAI\Oxygen\Oxygen\OxygenPageMutationService;

// ---- minimal WP stubs (pure tree helpers only) ----
if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false)
    {
        return trim(strip_tags((string) $text));
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0, $depth = 512)
    {
        return json_encode($data, $flags, $depth);
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct($code = '', $message = '', $data = null)
        {
        }

        public function get_error_message(): string
        {
            return '';
        }
    }
}

require_once __DIR__ . '/../../src/Oxygen/OxygenTreeToolsTrait.php';
require_once __DIR__ . '/../../src/Oxygen/OxygenPageMutationService.php';

$failures = 0;
$check = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: {$msg}\n");
    }
};

// Build a representative ~40-node tree: 5 sections, each with a heading, a
// paragraph (with realistic design data + inline SVG that the outline strips),
// an image, and a button. Plus the root. = 1 + 5 + 5*7 = 41 nodes.
$nodeId = 1;
$next = static function () use (&$nodeId): int {
    return $nodeId++;
};

// Representative Oxygen design block: real nodes carry spacing, typography,
// layout, borders, effects, and background across multiple breakpoints. This
// is the bulk that the outline/html views legitimately omit.
$len = static fn (int $n): array => ['number' => $n, 'unit' => 'px', 'style' => $n . 'px'];
$box4 = static fn (): array => ['breakpoint_base' => ['top' => $len(40), 'right' => $len(24), 'bottom' => $len(40), 'left' => $len(24), 'editMode' => 'all']];
$bigDesign = [
    'spacing' => ['padding' => $box4(), 'margin' => $box4()],
    'typography' => [
        'color' => ['breakpoint_base' => '#112233', 'breakpoint_phone_portrait' => '#223344'],
        'font_family' => ['breakpoint_base' => 'Inter, system-ui, sans-serif'],
        'font_size' => ['breakpoint_base' => $len(18), 'breakpoint_tablet_portrait' => $len(16), 'breakpoint_phone_portrait' => $len(14)],
        'font_weight' => ['breakpoint_base' => 600],
        'line_height' => ['breakpoint_base' => ['number' => 1.5, 'unit' => '', 'style' => '1.5']],
        'letter_spacing' => ['breakpoint_base' => $len(0)],
        'text_align' => ['breakpoint_base' => 'center', 'breakpoint_phone_portrait' => 'left'],
        'text_transform' => ['breakpoint_base' => 'none'],
    ],
    'layout' => [
        'display' => ['breakpoint_base' => 'flex'],
        'flex_direction' => ['breakpoint_base' => 'column', 'breakpoint_phone_portrait' => 'row'],
        'flex_align' => ['primary_axis' => ['breakpoint_base' => 'center'], 'cross_axis' => ['breakpoint_base' => 'center']],
        'gap' => ['row' => ['breakpoint_base' => $len(16)], 'column' => ['breakpoint_base' => $len(16)]],
    ],
    'borders' => [
        'radius' => ['breakpoint_base' => ['all' => $len(12), 'topLeft' => $len(12), 'topRight' => $len(12), 'bottomLeft' => $len(12), 'bottomRight' => $len(12), 'editMode' => 'all']],
        'borders' => ['breakpoint_base' => ['top' => ['width' => $len(1), 'style' => 'solid', 'color' => '#e5e7eb']]],
    ],
    'effects' => ['box_shadow' => ['breakpoint_base' => '0 4px 12px rgba(0,0,0,0.1)'], 'opacity' => ['breakpoint_base' => 100]],
    'background' => ['background_color' => ['breakpoint_base' => '#ffffff', 'breakpoint_phone_portrait' => '#fafafa']],
];
$svgBlob = '<svg viewBox="0 0 24 24">' . str_repeat('<path d="M10 10 L20 20 Z"/>', 8) . '</svg>';

$sections = [];
for ($s = 0; $s < 5; $s++) {
    $children = [];
    $children[] = ['id' => $next(), 'data' => ['type' => 'EssentialElements\\Heading', 'design' => $bigDesign, 'properties' => ['content' => ['content' => ['text' => "Section {$s} heading", 'tag' => 'h2']]]], 'children' => []];
    $children[] = ['id' => $next(), 'data' => ['type' => 'OxygenElements\\Text', 'design' => $bigDesign, 'properties' => ['content' => ['content' => ['text' => "Paragraph copy for section {$s} with several words of body text to make the full tree heavy."]]]], 'children' => []];
    $children[] = ['id' => $next(), 'data' => ['type' => 'EssentialElements\\Icon', 'design' => $bigDesign, 'properties' => ['content' => ['content' => ['icon' => ['name' => 'star', 'svgCode' => $svgBlob]]]]], 'children' => []];
    $children[] = ['id' => $next(), 'data' => ['type' => 'OxygenElements\\Image', 'design' => $bigDesign, 'properties' => ['content' => ['image' => ['url' => "https://example.com/img-{$s}.jpg", 'alt' => "Image {$s}"]]]], 'children' => []];
    $children[] = ['id' => $next(), 'data' => ['type' => 'EssentialElements\\Button', 'design' => $bigDesign, 'properties' => ['content' => ['content' => ['text' => "CTA {$s}", 'link' => ['url' => "https://example.com/cta-{$s}"]]]]], 'children' => []];
    $children[] = ['id' => $next(), 'data' => ['type' => 'OxygenElements\\Container', 'design' => $bigDesign], 'children' => []];
    $children[] = ['id' => $next(), 'data' => ['type' => 'OxygenElements\\Text', 'design' => $bigDesign, 'properties' => ['content' => ['content' => ['text' => "Footnote {$s}"]]]], 'children' => []];

    $sections[] = ['id' => $next(), 'data' => ['type' => 'OxygenElements\\Section', 'design' => $bigDesign], 'children' => $children];
}

$tree = ['root' => ['id' => 1, 'data' => ['type' => 'root'], 'children' => $sections]];
// fix root id to 1 (was consumed first); renormalize via service
$svc = new OxygenPageMutationService();

$nodeCount = 0;
$countWalk = function ($n) use (&$countWalk, &$nodeCount): void {
    $nodeCount++;
    foreach ($n['children'] ?? [] as $c) {
        $countWalk($c);
    }
};
$countWalk($tree['root']);
$check($nodeCount >= 35, "representative tree has ~40 nodes (got {$nodeCount})");

$fullJson = json_encode($tree);
$fullBytes = strlen($fullJson);

$outline = $svc->summarizeTree($tree);
$outlineBytes = strlen((string) json_encode($outline['nodes']));

$html = $svc->renderTreeHtml($tree);
$htmlBytes = strlen($html['html']);

$outlinePct = $outlineBytes / $fullBytes * 100;
$htmlPct = $htmlBytes / $fullBytes * 100;

fwrite(STDOUT, sprintf("  full=%dB outline=%dB (%.1f%%) html=%dB (%.1f%%)\n", $fullBytes, $outlineBytes, $outlinePct, $htmlBytes, $htmlPct));

$check($outlineBytes < $fullBytes * 0.10, sprintf('outline view < 10%% of full (%.1f%%)', $outlinePct));
$check($htmlBytes < $fullBytes * 0.10, sprintf('html view < 10%% of full (%.1f%%)', $htmlPct));

// Inline SVG must NOT leak into either cheap view.
$check(!str_contains((string) json_encode($outline['nodes']), 'M10 10'), 'outline strips inline svg');
$check(!str_contains($html['html'], 'M10 10'), 'html view strips inline svg');

if ($failures > 0) {
    fwrite(STDERR, "token-byte-budget FAILED with {$failures} failure(s)\n");
    exit(1);
}

echo "token-byte-budget-ok\n";
