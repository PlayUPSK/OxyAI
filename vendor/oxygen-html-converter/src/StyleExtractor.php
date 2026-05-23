<?php

namespace OxyHtmlConverter;

use DOMElement;

/**
 * Extracts inline styles and maps them to Oxygen properties
 */
class StyleExtractor
{
    private const BREAKPOINT = 'breakpoint_base';

    /**
     * CSS properties this extractor can translate into Oxygen-readable design schema.
     */
    private const SUPPORTED_PROPERTIES = [
        'background' => true,
        'background-color' => true,
        'color' => true,
        'font-family' => true,
        'font-size' => true,
        'font-style' => true,
        'font-weight' => true,
        'height' => true,
        'letter-spacing' => true,
        'line-height' => true,
        'margin' => true,
        'margin-bottom' => true,
        'margin-left' => true,
        'margin-right' => true,
        'margin-top' => true,
        'max-height' => true,
        'max-width' => true,
        'min-height' => true,
        'min-width' => true,
        'padding' => true,
        'padding-bottom' => true,
        'padding-left' => true,
        'padding-right' => true,
        'padding-top' => true,
        'text-align' => true,
        'text-decoration' => true,
        'text-transform' => true,
        'width' => true,
        'border-radius' => true,
        'border-top-left-radius' => true,
        'border-top-right-radius' => true,
        'border-bottom-left-radius' => true,
        'border-bottom-right-radius' => true,
        'display' => true,
        'flex-direction' => true,
        'flex-wrap' => true,
        'justify-content' => true,
        'align-items' => true,
        'align-content' => true,
        'gap' => true,
        'row-gap' => true,
        'column-gap' => true,
        'flex-grow' => true,
        'flex-shrink' => true,
        'flex-basis' => true,
        'order' => true,
        'grid-template-columns' => true,
        'grid-template-rows' => true,
        'grid-auto-flow' => true,
        'grid-auto-columns' => true,
        'grid-auto-rows' => true,
        'position' => true,
        'top' => true,
        'right' => true,
        'bottom' => true,
        'left' => true,
        'z-index' => true,
        'overflow' => true,
        'overflow-x' => true,
        'overflow-y' => true,
        'opacity' => true,
        'box-shadow' => true,
        'transform' => true,
        'transition' => true,
        'filter' => true,
        'backdrop-filter' => true,
        'mix-blend-mode' => true,
        'object-fit' => true,
        'object-position' => true,
        'aspect-ratio' => true,
    ];

    private const UNSUPPORTED_NATIVE_ELEMENT_TYPES = [
        ElementTypes::HTML_CODE => true,
        ElementTypes::CSS_CODE => true,
        ElementTypes::JAVASCRIPT_CODE => true,
    ];

    /**
     * Extract styles from DOM element
     */
    public function extract(DOMElement $node): array
    {
        $styles = [];

        // Get inline style attribute
        $styleAttr = $node->getAttribute('style');
        if ($styleAttr) {
            $styles = array_merge($styles, $this->parseInlineStyles($styleAttr));
        }

        // Get class attribute for reference (stored but not converted)
        $classAttr = $node->getAttribute('class');
        if ($classAttr) {
            $styles['_original_classes'] = $classAttr;
        }

        return $styles;
    }

    /**
     * Parse inline style string into array
     */
    public function parseInlineStyles(string $styleString): array
    {
        $styles = [];
        $declarations = array_filter(array_map('trim', explode(';', $styleString)));

        foreach ($declarations as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) === 2) {
                $property = trim($parts[0]);
                $value = trim($parts[1]);

                // Remove !important
                $value = str_replace('!important', '', $value);
                $value = trim($value);

                if ($property !== '' && $value !== '') {
                    $styles[$property] = $value;
                }
            }
        }

        return $styles;
    }

    /**
     * Convert extracted styles to Oxygen properties format
     */
    public function toOxygenProperties(array $styles, string $elementType = ElementTypes::CONTAINER): array
    {
        $properties = [];
        $boxCategory = $this->boxCategoryForElement($elementType);

        foreach ($styles as $cssProp => $value) {
            $cssProp = strtolower((string) $cssProp);

            // Skip internal properties
            if (strpos($cssProp, '_') === 0) {
                continue;
            }

            $this->applyCssProperty($properties, $boxCategory, $cssProp, (string) $value);
        }

        return $properties;
    }

    /**
     * Check whether every non-internal declaration can be represented
     * natively in the current Oxygen property map.
     */
    public function supportsDeclarationsFully(array $styles, string $elementType = ElementTypes::CONTAINER): bool
    {
        if (isset(self::UNSUPPORTED_NATIVE_ELEMENT_TYPES[$elementType])) {
            return false;
        }

        $supportedDeclarationCount = 0;

        foreach ($styles as $cssProp => $value) {
            $cssProp = strtolower((string) $cssProp);

            if (strpos($cssProp, '_') === 0) {
                continue;
            }

            $supportedDeclarationCount++;
            if (!isset(self::SUPPORTED_PROPERTIES[$cssProp])
                || !$this->canConvertCssPropertyValue($cssProp, (string) $value)
            ) {
                return false;
            }
        }

        return $supportedDeclarationCount > 0;
    }

    /**
     * Whether converted native design properties are known to render without
     * keeping the original class CSS as a frontend fallback.
     */
    public function canStripCssFallbackForElementType(string $elementType): bool
    {
        return in_array($elementType, [
            ElementTypes::ESSENTIAL_BUTTON,
        ], true);
    }

    private function applyCssProperty(array &$properties, string $boxCategory, string $cssProp, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        if ($cssProp === 'background') {
            if ($this->isPlainBackgroundColor($value)) {
                $this->setBreakpointValue($properties, [$boxCategory, 'background'], $this->normalizeColor($value));
            }
            return;
        }

        if ($cssProp === 'background-color') {
            $this->setBreakpointValue($properties, [$boxCategory, 'background'], $this->normalizeColor($value));
            return;
        }

        if ($cssProp === 'color') {
            $this->setBreakpointValue($properties, ['typography', 'color'], $this->normalizeColor($value));
            return;
        }

        if (in_array($cssProp, ['font-family', 'font-weight', 'font-style', 'text-align', 'text-decoration', 'text-transform'], true)) {
            $this->setBreakpointValue($properties, ['typography', $this->oxygenKey($cssProp)], $value);
            return;
        }

        if (in_array($cssProp, ['font-size', 'line-height', 'letter-spacing'], true)) {
            $this->setBreakpointValue($properties, ['typography', $this->oxygenKey($cssProp)], $this->normalizeLength($value));
            return;
        }

        if ($cssProp === 'padding' || $cssProp === 'margin') {
            $this->setBoxSpacing($properties, $boxCategory, $cssProp, $this->parseShorthandSpacing($value));
            return;
        }

        if (str_starts_with($cssProp, 'padding-') || str_starts_with($cssProp, 'margin-')) {
            [$type, $side] = explode('-', $cssProp, 2);
            if (in_array($side, ['top', 'right', 'bottom', 'left'], true)) {
                $this->setBoxSpacing($properties, $boxCategory, $type, [$side => $value], false);
            }
            return;
        }

        if (in_array($cssProp, ['width', 'min-width', 'max-width', 'height', 'min-height', 'max-height'], true)) {
            $this->setBreakpointValue($properties, ['size', $this->oxygenKey($cssProp)], $this->normalizeLength($value));
            return;
        }

        if ($cssProp === 'border-radius') {
            $corners = $this->parseRadiusShorthand($value);
            if ($corners !== null) {
                $this->setBorderRadius($properties, $boxCategory, $corners, true);
            }
            return;
        }

        $cornerMap = [
            'border-top-left-radius' => 'topLeft',
            'border-top-right-radius' => 'topRight',
            'border-bottom-left-radius' => 'bottomLeft',
            'border-bottom-right-radius' => 'bottomRight',
        ];

        if (isset($cornerMap[$cssProp])) {
            $this->setBorderRadius($properties, $boxCategory, [$cornerMap[$cssProp] => $value]);
            return;
        }

        if (in_array($cssProp, ['display', 'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content', 'grid-template-columns', 'grid-template-rows', 'grid-auto-flow', 'grid-auto-columns', 'grid-auto-rows'], true)) {
            $this->setBreakpointValue($properties, ['layout', $this->oxygenKey($cssProp)], $value);
            return;
        }

        if (in_array($cssProp, ['gap', 'row-gap', 'column-gap', 'flex-basis'], true)) {
            $this->setBreakpointValue($properties, ['layout', $this->oxygenKey($cssProp)], $this->normalizeLength($value));
            return;
        }

        if (in_array($cssProp, ['flex-grow', 'flex-shrink', 'order'], true)) {
            $this->setBreakpointValue($properties, ['layout', $this->oxygenKey($cssProp)], $this->normalizeNumber($value));
            return;
        }

        if ($cssProp === 'position') {
            $this->setBreakpointValue($properties, ['position', 'position'], $value);
            return;
        }

        if (in_array($cssProp, ['top', 'right', 'bottom', 'left'], true)) {
            $this->setBreakpointValue($properties, ['position', $cssProp], $this->normalizeLength($value));
            return;
        }

        if ($cssProp === 'z-index') {
            $this->setBreakpointValue($properties, ['position', 'z_index'], $this->normalizeNumber($value));
            return;
        }

        if (in_array($cssProp, ['overflow', 'overflow-x', 'overflow-y'], true)) {
            $this->setBreakpointValue($properties, ['overflow', $this->oxygenKey($cssProp)], $value);
            return;
        }

        if ($cssProp === 'opacity') {
            $this->setBreakpointValue($properties, ['effects', 'opacity'], $this->normalizeNumber($value));
            return;
        }

        if (in_array($cssProp, ['box-shadow', 'transform', 'transition', 'filter', 'backdrop-filter', 'mix-blend-mode'], true)) {
            $this->setBreakpointValue($properties, ['effects', $this->oxygenKey($cssProp)], $value);
            return;
        }

        if (in_array($cssProp, ['object-fit', 'object-position', 'aspect-ratio'], true)) {
            $this->setBreakpointValue($properties, ['size', $this->oxygenKey($cssProp)], $value);
        }
    }

    private function setBreakpointValue(array &$properties, array $path, $value): void
    {
        $this->setNestedValue($properties, array_merge($path, [self::BREAKPOINT]), $value);
    }

    /**
     * @param array<string, string> $sides
     */
    private function setBoxSpacing(array &$properties, string $boxCategory, string $type, array $sides, bool $fromShorthand = true): void
    {
        $path = [$boxCategory, $type, self::BREAKPOINT];
        $existing = $this->getNestedValue($properties, $path);
        $spacing = is_array($existing) ? $existing : [];

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (!isset($sides[$side])) {
                continue;
            }

            $spacing[$side] = $this->normalizeLength((string) $sides[$side]);
        }

        if ($fromShorthand) {
            $sideValues = [
                (string) ($sides['top'] ?? ''),
                (string) ($sides['right'] ?? ''),
                (string) ($sides['bottom'] ?? ''),
                (string) ($sides['left'] ?? ''),
            ];
            $allEqual = count(array_unique($sideValues)) === 1;
            if ($allEqual) {
                $spacing['all'] = $this->normalizeLength($sideValues[0]);
            } else {
                unset($spacing['all']);
            }
            $spacing['editMode'] = $allEqual ? 'all' : 'advanced';
        } else {
            unset($spacing['all']);
            $spacing['editMode'] = 'advanced';
        }

        $this->setNestedValue($properties, $path, $spacing);
    }

    /**
     * @param array<string, string> $corners
     */
    private function setBorderRadius(array &$properties, string $boxCategory, array $corners, bool $fromShorthand = false): void
    {
        $path = [$boxCategory, 'borders', 'radius', self::BREAKPOINT];
        $existing = $this->getNestedValue($properties, $path);
        $radius = is_array($existing) ? $existing : [];

        foreach ($corners as $corner => $value) {
            $radius[$corner] = $this->normalizeLength($value);
        }

        if ($fromShorthand) {
            $cornerValues = [
                (string) ($corners['topLeft'] ?? ''),
                (string) ($corners['topRight'] ?? ''),
                (string) ($corners['bottomRight'] ?? ''),
                (string) ($corners['bottomLeft'] ?? ''),
            ];
            $allEqual = count(array_unique($cornerValues)) === 1;
            if ($allEqual) {
                $radius['all'] = $this->normalizeLength($cornerValues[0]);
            } else {
                unset($radius['all']);
            }
            $radius['editMode'] = $allEqual ? 'all' : 'advanced';
        } else {
            unset($radius['all']);
            $radius['editMode'] = 'advanced';
        }

        $this->setNestedValue($properties, $path, $radius);
    }

    /**
     * Set a nested array value by path
     */
    private function setNestedValue(array &$array, array $path, $value): void
    {
        $current = &$array;
        foreach ($path as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        $current = $value;
    }

    private function getNestedValue(array $array, array $path)
    {
        $current = $array;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Extract and convert styles in one step
     */
    public function extractAndConvert(DOMElement $node, string $elementType = ElementTypes::CONTAINER): array
    {
        $styles = $this->extract($node);
        return $this->toOxygenProperties($styles, $elementType);
    }

    /**
     * Parse shorthand margin/padding values
     */
    public function parseShorthandSpacing(string $value): array
    {
        $parts = $this->splitCssTokens($value);
        $result = [];

        switch (count($parts)) {
            case 1:
                $result = [
                    'top' => $parts[0],
                    'right' => $parts[0],
                    'bottom' => $parts[0],
                    'left' => $parts[0],
                ];
                break;
            case 2:
                $result = [
                    'top' => $parts[0],
                    'right' => $parts[1],
                    'bottom' => $parts[0],
                    'left' => $parts[1],
                ];
                break;
            case 3:
                $result = [
                    'top' => $parts[0],
                    'right' => $parts[1],
                    'bottom' => $parts[2],
                    'left' => $parts[1],
                ];
                break;
            case 4:
                $result = [
                    'top' => $parts[0],
                    'right' => $parts[1],
                    'bottom' => $parts[2],
                    'left' => $parts[3],
                ];
                break;
        }

        return $result;
    }

    /**
     * @return array<string, string>|null
     */
    private function parseRadiusShorthand(string $value): ?array
    {
        if (str_contains($value, '/')) {
            return null;
        }

        $parts = $this->splitCssTokens($value);
        if (count($parts) < 1 || count($parts) > 4) {
            return null;
        }

        return match (count($parts)) {
            1 => [
                'topLeft' => $parts[0],
                'topRight' => $parts[0],
                'bottomRight' => $parts[0],
                'bottomLeft' => $parts[0],
            ],
            2 => [
                'topLeft' => $parts[0],
                'topRight' => $parts[1],
                'bottomRight' => $parts[0],
                'bottomLeft' => $parts[1],
            ],
            3 => [
                'topLeft' => $parts[0],
                'topRight' => $parts[1],
                'bottomRight' => $parts[2],
                'bottomLeft' => $parts[1],
            ],
            default => [
                'topLeft' => $parts[0],
                'topRight' => $parts[1],
                'bottomRight' => $parts[2],
                'bottomLeft' => $parts[3],
            ],
        };
    }

    /**
     * Split CSS shorthand tokens on whitespace outside functional values.
     *
     * @return array<int, string>
     */
    private function splitCssTokens(string $value): array
    {
        $tokens = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '(') {
                $depth++;
                $buffer .= $char;
                continue;
            }

            if ($char === ')' && $depth > 0) {
                $depth--;
                $buffer .= $char;
                continue;
            }

            if (ctype_space($char) && $depth === 0) {
                if ($buffer !== '') {
                    $tokens[] = $buffer;
                    $buffer = '';
                }
                continue;
            }

            $buffer .= $char;
        }

        if ($buffer !== '') {
            $tokens[] = $buffer;
        }

        return $tokens;
    }

    private function canConvertCssPropertyValue(string $cssProp, string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if ($cssProp === 'background') {
            return $this->isPlainBackgroundColor($value);
        }

        if ($cssProp === 'padding' || $cssProp === 'margin') {
            $parts = $this->splitCssTokens($value);
            return count($parts) >= 1 && count($parts) <= 4;
        }

        if ($cssProp === 'border-radius') {
            return $this->parseRadiusShorthand($value) !== null;
        }

        return true;
    }

    private function isPlainBackgroundColor(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/\b(?:url|image-set|linear-gradient|radial-gradient|conic-gradient|repeating-linear-gradient|repeating-radial-gradient)\s*\(/i', $value)) {
            return false;
        }

        return preg_match('/^(?:#[0-9a-f]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|var\([^)]+\)|[a-z]+)$/i', $value) === 1;
    }

    /**
     * Convert color value to standard format
     */
    public function normalizeColor(string $color): string
    {
        $color = trim($color);

        // Already hex or rgb/rgba
        if (preg_match('/^#|^rgb/i', $color)) {
            return $color;
        }

        // Named colors - return as-is (browser will handle)
        return $color;
    }

    /**
     * Convert a CSS length into Oxygen's structured scalar shape.
     *
     * @return array{number:int|float,unit:string,style:string}|string
     */
    private function normalizeLength(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        if (in_array(strtolower($value), ['auto', 'fit', 'none', 'inherit', 'initial', 'unset'], true)) {
            return strtolower($value);
        }

        if ($value === '0') {
            return [
                'number' => 0,
                'unit' => 'px',
                'style' => '0px',
            ];
        }

        if (preg_match('/^(-?\d+(?:\.\d+)?)(px|%|rem|em|vh|vw|vmin|vmax|ch|ex)$/i', $value, $matches)) {
            $number = (float) $matches[1];
            if (floor($number) === $number) {
                $number = (int) $number;
            }

            return [
                'number' => $number,
                'unit' => strtolower($matches[2]),
                'style' => $matches[1] . strtolower($matches[2]),
            ];
        }

        return $value;
    }

    /**
     * @return int|float|string
     */
    private function normalizeNumber(string $value)
    {
        $value = trim($value);
        if (is_numeric($value)) {
            $number = (float) $value;
            return floor($number) === $number ? (int) $number : $number;
        }

        return $value;
    }

    private function boxCategoryForElement(string $elementType): string
    {
        if ($elementType === ElementTypes::ESSENTIAL_BUTTON) {
            return 'button';
        }

        return 'container';
    }

    private function oxygenKey(string $cssProperty): string
    {
        return str_replace('-', '_', $cssProperty);
    }

    /**
     * Get original CSS classes from extracted styles
     */
    public function getOriginalClasses(array $styles): array
    {
        if (isset($styles['_original_classes'])) {
            return array_filter(array_map('trim', explode(' ', $styles['_original_classes'])));
        }
        return [];
    }
}
