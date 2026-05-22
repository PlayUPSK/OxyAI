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
        $supportedDeclarationCount = 0;

        foreach ($styles as $cssProp => $value) {
            $cssProp = strtolower((string) $cssProp);

            if (strpos($cssProp, '_') === 0) {
                continue;
            }

            $supportedDeclarationCount++;
            if (!isset(self::SUPPORTED_PROPERTIES[$cssProp])) {
                return false;
            }
        }

        return $supportedDeclarationCount > 0;
    }

    private function applyCssProperty(array &$properties, string $boxCategory, string $cssProp, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        if ($cssProp === 'background' || $cssProp === 'background-color') {
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
                $this->setNestedValue(
                    $properties,
                    [$boxCategory, $type, self::BREAKPOINT, $side],
                    $this->normalizeLength($value)
                );
            }
            return;
        }

        if (in_array($cssProp, ['width', 'min-width', 'max-width', 'height', 'min-height', 'max-height'], true)) {
            $this->setBreakpointValue($properties, ['size', $this->oxygenKey($cssProp)], $this->normalizeLength($value));
            return;
        }

        if ($cssProp === 'border-radius') {
            $this->setBorderRadius($properties, $boxCategory, [
                'all' => $value,
                'topLeft' => $value,
                'topRight' => $value,
                'bottomLeft' => $value,
                'bottomRight' => $value,
            ]);
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
        }
    }

    private function setBreakpointValue(array &$properties, array $path, $value): void
    {
        $this->setNestedValue($properties, array_merge($path, [self::BREAKPOINT]), $value);
    }

    /**
     * @param array<string, string> $sides
     */
    private function setBoxSpacing(array &$properties, string $boxCategory, string $type, array $sides): void
    {
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (!isset($sides[$side])) {
                continue;
            }

            $this->setNestedValue(
                $properties,
                [$boxCategory, $type, self::BREAKPOINT, $side],
                $this->normalizeLength((string) $sides[$side])
            );
        }
    }

    /**
     * @param array<string, string> $corners
     */
    private function setBorderRadius(array &$properties, string $boxCategory, array $corners): void
    {
        $path = [$boxCategory, 'borders', 'radius', self::BREAKPOINT];
        $existing = $this->getNestedValue($properties, $path);
        $radius = is_array($existing) ? $existing : [];

        foreach ($corners as $corner => $value) {
            $radius[$corner] = $this->normalizeLength($value);
        }

        if (isset($corners['all'])) {
            $radius['editMode'] = 'all';
        } else {
            $radius['editMode'] = $radius['editMode'] ?? 'advanced';
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
        $parts = preg_split('/\s+/', trim($value));
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
