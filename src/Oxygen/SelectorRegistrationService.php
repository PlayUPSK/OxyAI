<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Oxygen;

final class SelectorRegistrationService
{
    private const SELECTORS_OPTION = 'oxy_selectors_json_string';
    private const COLLECTIONS_OPTION = 'oxy_selectors_collections_json_string';
    private const COLLECTION_NAME = 'OxyAI';
    private const SELECTOR_DESIGN_META_KEY = '_oxyaiSelectorDesign';

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    public function registerTreeSelectors(array &$tree, bool $persist): array
    {
        $classes = [];
        $classDesigns = [];

        if (isset($tree['root']) && is_array($tree['root'])) {
            $this->collectRuntimeClassesAndDesigns($tree['root'], $classes, $classDesigns);
        } else {
            $this->collectRuntimeClassesAndDesigns($tree, $classes, $classDesigns);
        }

        $selectors = [];
        $existingSelectors = $this->readOxySelectors();
        $selectorsByClass = $this->indexSelectorsByClassName($existingSelectors);
        $created = 0;
        $selectorPropertiesAttached = 0;
        $unmappedSelectorPropertyPaths = [];

        foreach (array_keys($classes) as $className) {
            $selector = $selectorsByClass[$className] ?? null;
            if ($selector === null) {
                $selector = $this->createClassSelector($className);
                $created++;
            }

            $selectorProperties = $this->transformDesignPropertiesToSelectorProperties(
                $classDesigns[$className] ?? [],
                $unmappedSelectorPropertyPaths
            );
            if ($selectorProperties !== []) {
                $selector['properties'] = $this->mergeRecursive(
                    is_array($selector['properties'] ?? null) ? $selector['properties'] : [],
                    $selectorProperties
                );
                $selectorPropertiesAttached++;
            }

            $selectors[$className] = $this->normalizeSelectorShape($selector, $className);
        }

        $ioTsSanitization = ['sizeStringsRemoved' => 0, 'effectStringsRemoved' => 0, 'typographyStringsRemoved' => 0];
        foreach ($selectors as $className => &$selectorRef) {
            if (isset($selectorRef['properties']) && is_array($selectorRef['properties'])) {
                $this->sanitizePropertiesForIoTs($selectorRef['properties'], $ioTsSanitization);
            }
        }
        unset($selectorRef);

        if ($selectors === []) {
            return [
                'enabled' => true,
                'created' => 0,
                'matched' => 0,
                'attachedElements' => 0,
                'selectors' => [],
                'registryOption' => self::SELECTORS_OPTION,
                'collectionsOption' => self::COLLECTIONS_OPTION,
                'selectorPropertiesAttached' => 0,
                'unmappedSelectorPropertyPaths' => [],
                'ioTsSanitization' => $ioTsSanitization,
            ];
        }

        $attachedElements = 0;
        if (isset($tree['root']) && is_array($tree['root'])) {
            $this->attachSelectorIds($tree['root'], $selectors, $attachedElements);
        } else {
            $this->attachSelectorIds($tree, $selectors, $attachedElements);
        }

        if ($persist) {
            $this->persistSelectors(array_values($selectors));
        }

        return [
            'enabled' => true,
            'created' => $created,
            'matched' => count($selectors) - $created,
            'createdOrMatched' => count($selectors),
            'attachedElements' => $attachedElements,
            'selectorPropertiesAttached' => $selectorPropertiesAttached,
            'unmappedSelectorPropertyPaths' => array_values(array_unique($unmappedSelectorPropertyPaths)),
            'selectors' => array_values($selectors),
            'registryOption' => self::SELECTORS_OPTION,
            'collectionsOption' => self::COLLECTIONS_OPTION,
            'collection' => self::COLLECTION_NAME,
            'ioTsSanitization' => $ioTsSanitization,
            'note' => 'Runtime classes are promoted to Oxygen selector IDs in meta.classes; direct class styles are stored on matching selector properties for editor visibility and compiled selector CSS.',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     */
    public function persistSelectors(array $selectors): void
    {
        if ($selectors === []) {
            return;
        }

        $existing = $this->readOxySelectors();
        $byId = [];
        foreach ($existing as $selector) {
            $selector = $this->normalizeSelectorShape($selector);
            if (isset($selector['id']) && is_string($selector['id']) && $selector['id'] !== '') {
                $byId[$selector['id']] = $selector;
            }
        }

        foreach ($selectors as $selector) {
            $selector = $this->normalizeSelectorShape($selector);
            if (!isset($selector['id']) || !is_string($selector['id']) || $selector['id'] === '') {
                continue;
            }

            $byId[$selector['id']] = array_merge($byId[$selector['id']] ?? [], $selector);
        }

        $allSelectors = array_values(array_map(
            fn (array $selector): array => $this->normalizeSelectorShape($selector),
            $byId
        ));
        $collections = $this->readOxySelectorCollections();
        if (!in_array(self::COLLECTION_NAME, $collections, true)) {
            $collections[] = self::COLLECTION_NAME;
        }

        if (is_callable('\\Breakdance\\BreakdanceOxygen\\Selectors\\saveSelectors')) {
            $payload = wp_json_encode([
                'selectors' => $allSelectors,
                'collections' => array_values($collections),
            ]);
            if (is_string($payload)) {
                call_user_func('\\Breakdance\\BreakdanceOxygen\\Selectors\\saveSelectors', $payload);
                return;
            }
        }

        $this->setGlobalOption(self::SELECTORS_OPTION, $allSelectors, true);
        $this->setGlobalOption(self::COLLECTIONS_OPTION, array_values($collections), true);

        if (is_callable('\\Breakdance\\Render\\generateCacheForGlobalSettings')) {
            call_user_func('\\Breakdance\\Render\\generateCacheForGlobalSettings');
        }
    }

    /**
     * Repair previously persisted OxyAI selector records that may have been
     * written before the selector schema normalizer existed.
     *
     * @return array<string, mixed>
     */
    public function repairPersistedSelectors(): array
    {
        $existing = $this->readOxySelectors();
        $repaired = [];
        $changed = 0;
        $fontWeightsRepaired = 0;
        $lockedAdded = 0;
        $propertiesObjectsRepaired = 0;
        $classNamesRepaired = 0;
        $ioTsSanitization = ['sizeStringsRemoved' => 0, 'effectStringsRemoved' => 0, 'typographyStringsRemoved' => 0];

        foreach ($existing as $selector) {
            $before = $this->selectorRepairSignature($selector);
            $hadLocked = array_key_exists('locked', $selector);
            $hadEmptyPropertiesArray = isset($selector['properties']) && $selector['properties'] === [];
            $beforeName = $selector['name'] ?? null;
            $beforeType = $selector['type'] ?? null;

            $selector = $this->normalizeSelectorShape($selector);
            if (isset($selector['properties']) && is_array($selector['properties'])) {
                $this->normalizeSelectorPropertyValues($selector['properties'], $fontWeightsRepaired);
                $this->sanitizePropertiesForIoTs($selector['properties'], $ioTsSanitization);
                if ($selector['properties'] === []) {
                    $selector['properties'] = new \stdClass();
                }
            }

            if (!$hadLocked && array_key_exists('locked', $selector)) {
                $lockedAdded++;
            }

            if ($hadEmptyPropertiesArray && $selector['properties'] instanceof \stdClass) {
                $propertiesObjectsRepaired++;
            }

            if (($selector['name'] ?? null) !== $beforeName || ($selector['type'] ?? null) !== $beforeType) {
                $classNamesRepaired++;
            }

            if (!$hadLocked
                || $hadEmptyPropertiesArray
                || ($selector['name'] ?? null) !== $beforeName
                || ($selector['type'] ?? null) !== $beforeType
                || $this->selectorRepairSignature($selector) !== $before
            ) {
                $changed++;
            }

            $repaired[] = $selector;
        }

        if ($changed > 0) {
            $this->persistAllSelectors($repaired);
        }

        return [
            'success' => true,
            'selectorsScanned' => count($existing),
            'selectorsChanged' => $changed,
            'lockedAdded' => $lockedAdded,
            'propertiesObjectsRepaired' => $propertiesObjectsRepaired,
            'classNamesRepaired' => $classNamesRepaired,
            'fontWeightsRepaired' => $fontWeightsRepaired,
            'sizeStringsRemoved' => $ioTsSanitization['sizeStringsRemoved'],
            'effectStringsRemoved' => $ioTsSanitization['effectStringsRemoved'],
            'typographyStringsRemoved' => $ioTsSanitization['typographyStringsRemoved'],
            'registryOption' => self::SELECTORS_OPTION,
        ];
    }

    /**
     * @param array<string, mixed> $selector
     */
    private function selectorRepairSignature(array $selector): string
    {
        if (($selector['properties'] ?? null) instanceof \stdClass) {
            $selector['properties'] = [];
        }

        return (string) wp_json_encode($selector);
    }

    /**
     * @param mixed $node
     * @param array<string, true> $classes
     */
    private function collectRuntimeClassesAndDesigns(&$node, array &$classes, array &$classDesigns): void
    {
        if (!is_array($node)) {
            return;
        }

        $nodeClassSet = [];
        $rawNodeClasses = $node['data']['properties']['settings']['advanced']['classes'] ?? [];
        if (is_array($rawNodeClasses)) {
            foreach ($rawNodeClasses as $className) {
                if (!is_string($className)) {
                    continue;
                }

                $className = $this->normalizeClassName($className);
                if ($className !== null) {
                    $classes[$className] = true;
                    $nodeClassSet[$className] = true;
                }
            }
        }

        $selectorDesigns = $node['data']['properties']['meta'][self::SELECTOR_DESIGN_META_KEY] ?? [];
        if (is_array($selectorDesigns)) {
            foreach ($selectorDesigns as $className => $design) {
                if (!is_string($className) || !is_array($design)) {
                    continue;
                }

                $className = $this->normalizeClassName($className);
                if ($className === null || !isset($nodeClassSet[$className])) {
                    continue;
                }

                $classDesigns[$className] = $this->mergeRecursive($classDesigns[$className] ?? [], $design);
            }

            unset($node['data']['properties']['meta'][self::SELECTOR_DESIGN_META_KEY]);
        }

        if (!isset($node['children']) || !is_array($node['children'])) {
            return;
        }

        foreach ($node['children'] as &$child) {
            $this->collectRuntimeClassesAndDesigns($child, $classes, $classDesigns);
        }
        unset($child);
    }

    /**
     * @param mixed $node
     * @param array<string, array<string, mixed>> $selectors
     */
    private function attachSelectorIds(&$node, array $selectors, int &$attachedElements): void
    {
        if (!is_array($node)) {
            return;
        }

        $this->attachSelectorIdsToNode($node, $selectors, $attachedElements);

        if (!isset($node['children']) || !is_array($node['children'])) {
            return;
        }

        foreach ($node['children'] as &$child) {
            $this->attachSelectorIds($child, $selectors, $attachedElements);
        }
        unset($child);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $selectors
     */
    private function attachSelectorIdsToNode(array &$node, array $selectors, int &$attachedElements): void
    {
        $nodeClasses = $node['data']['properties']['settings']['advanced']['classes'] ?? [];
        if (!is_array($nodeClasses) || $nodeClasses === []) {
            return;
        }

        $selectorIds = [];
        $promotedClasses = [];
        foreach ($nodeClasses as $className) {
            if (!is_string($className)) {
                continue;
            }

            $normalized = $this->normalizeClassName($className);
            if ($normalized !== null && isset($selectors[$normalized]['id'])) {
                $selectorIds[] = (string) $selectors[$normalized]['id'];
                $promotedClasses[$normalized] = true;
            }
        }

        if ($selectorIds === []) {
            return;
        }

        $node['data']['properties']['meta'] = $node['data']['properties']['meta'] ?? [];
        $existing = $node['data']['properties']['meta']['classes'] ?? [];
        $existing = is_array($existing) ? array_values(array_filter($existing, 'is_string')) : [];
        $merged = array_values(array_unique(array_merge($existing, $selectorIds)));
        $node['data']['properties']['meta']['classes'] = $merged;
        $node['data']['properties']['meta']['classes_conditions'] = $node['data']['properties']['meta']['classes_conditions'] ?? [];
        $this->removePromotedRuntimeClasses($node, $promotedClasses);
        $attachedElements++;
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     * @return array<string, array<string, mixed>>
     */
    private function indexSelectorsByClassName(array $selectors): array
    {
        $indexed = [];

        foreach ($selectors as $selector) {
            if (!isset($selector['name']) || !is_string($selector['name'])) {
                continue;
            }

            $className = $this->classNameFromSelector($selector);
            if ($className !== null) {
                $indexed[$className] = $selector;
            }
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    private function createClassSelector(string $className): array
    {
        return [
            'id' => $this->uuidForClassName($className),
            'name' => $className,
            'type' => 'class',
            'properties' => new \stdClass(),
            'children' => [],
            'collection' => self::COLLECTION_NAME,
            'locked' => false,
        ];
    }

    /**
     * Oxygen's frontend selector validator requires properties to be an
     * object-map and locked to be present. Empty PHP arrays encode as JSON
     * arrays, so use stdClass for the empty object case.
     *
     * @param array<string, mixed> $selector
     * @return array<string, mixed>
     */
    private function normalizeSelectorShape(array $selector, ?string $className = null): array
    {
        $isOxyAiSelector = ($selector['collection'] ?? null) === self::COLLECTION_NAME;
        $className = $className ?? ($isOxyAiSelector ? $this->classNameFromSelector($selector) : null);
        if ($className !== null) {
            $selector['type'] = 'class';
            $selector['name'] = $className;
            if (!isset($selector['id']) || !is_string($selector['id']) || $selector['id'] === '') {
                $selector['id'] = $this->uuidForClassName($className);
            }
        }

        if (!array_key_exists('locked', $selector)) {
            $selector['locked'] = false;
        }

        if (!isset($selector['properties']) || $selector['properties'] === []) {
            $selector['properties'] = new \stdClass();
        }

        return $selector;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function normalizeSelectorPropertyValues(array &$properties, int &$fontWeightsRepaired): void
    {
        foreach ($properties as $key => &$value) {
            if ($key === 'font_weight' && is_string($value) && preg_match('/^\d+$/', $value) === 1) {
                $value = (int) $value;
                $fontWeightsRepaired++;
                continue;
            }

            if (is_array($value)) {
                $this->normalizeSelectorPropertyValues($value, $fontWeightsRepaired);
            }
        }
        unset($value);
    }

    /**
     * Strip raw string values from selector properties at paths where the
     * Oxygen builder's IO-TS schema only accepts structured shapes. The
     * upstream HTML-to-property converter falls back to raw strings for CSS
     * values it cannot parse (e.g. `min()`, `clamp()`, `fit-content`,
     * unitless line-height, transition shorthand) and persisting those
     * strings makes the builder refuse to decode `oxySelectors`.
     *
     * Removal is preferred over coercion: the schema treats missing keys as
     * undefined (valid) while raw strings hit a hard decode failure.
     *
     * @param array<string, mixed> $properties
     * @param array{sizeStringsRemoved:int,effectStringsRemoved:int,typographyStringsRemoved:int} $counters
     */
    private function sanitizePropertiesForIoTs(array &$properties, array &$counters): void
    {
        // `width`/`height` accept a keyword union (`auto`, `inherit`, ...) in the
        // schema, so a recognised keyword string is preserved. `min_width`,
        // `max_width`, `min_height` and `max_height` accept only a structured
        // `{number, unit, style}` object or `null` — every bare string there
        // (including keywords like `none`) is a hard IO-TS decode failure and
        // must be stripped.
        static $sizeKeywordKeys = ['width', 'height'];
        static $sizeStrictKeys = ['min_width', 'max_width', 'min_height', 'max_height'];
        static $sizeKeywords = ['auto', 'fit', 'none', 'inherit', 'initial', 'unset'];
        static $effectsKeys = ['transition', 'transform', 'box_shadow', 'filter', 'backdrop_filter'];
        static $typographyKeys = ['font_size', 'line_height', 'letter_spacing'];
        static $typographyKeywords = ['normal', 'inherit', 'initial', 'unset'];

        foreach (array_keys($properties) as $key) {
            $value = $properties[$key];

            if (is_string($value)) {
                if (in_array($key, $sizeStrictKeys, true)) {
                    unset($properties[$key]);
                    $counters['sizeStringsRemoved']++;
                    continue;
                }

                if (in_array($key, $sizeKeywordKeys, true)
                    && !in_array(strtolower($value), $sizeKeywords, true)
                ) {
                    unset($properties[$key]);
                    $counters['sizeStringsRemoved']++;
                    continue;
                }

                if (in_array($key, $effectsKeys, true)) {
                    unset($properties[$key]);
                    $counters['effectStringsRemoved']++;
                    continue;
                }

                if (in_array($key, $typographyKeys, true)
                    && !in_array(strtolower($value), $typographyKeywords, true)
                ) {
                    unset($properties[$key]);
                    $counters['typographyStringsRemoved']++;
                    continue;
                }
            }

            if (is_array($value)) {
                $this->sanitizePropertiesForIoTs($properties[$key], $counters);
                if ($properties[$key] === []) {
                    unset($properties[$key]);
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $selectors
     */
    private function persistAllSelectors(array $selectors): void
    {
        $collections = $this->readOxySelectorCollections();
        if (!in_array(self::COLLECTION_NAME, $collections, true)) {
            $collections[] = self::COLLECTION_NAME;
        }

        if (is_callable('\\Breakdance\\BreakdanceOxygen\\Selectors\\saveSelectors')) {
            $payload = wp_json_encode([
                'selectors' => $selectors,
                'collections' => array_values($collections),
            ]);
            if (is_string($payload)) {
                call_user_func('\\Breakdance\\BreakdanceOxygen\\Selectors\\saveSelectors', $payload);
                return;
            }
        }

        $this->setGlobalOption(self::SELECTORS_OPTION, $selectors, true);
        $this->setGlobalOption(self::COLLECTIONS_OPTION, array_values($collections), true);

        if (is_callable('\\Breakdance\\Render\\generateCacheForGlobalSettings')) {
            call_user_func('\\Breakdance\\Render\\generateCacheForGlobalSettings');
        }
    }

    /**
     * @param array<string, mixed> $selector
     */
    private function classNameFromSelector(array $selector): ?string
    {
        $name = $selector['name'] ?? null;
        if (!is_string($name)) {
            return null;
        }

        if (($selector['type'] ?? null) === 'class') {
            return $this->normalizeClassName($name);
        }

        if (preg_match('/^\.breakdance\s+\.(-?[_a-zA-Z]+[_a-zA-Z0-9-]*)$/', trim($name), $matches)) {
            return $this->normalizeClassName($matches[1]);
        }

        if (preg_match('/^\.(-?[_a-zA-Z]+[_a-zA-Z0-9-]*)$/', trim($name), $matches)) {
            return $this->normalizeClassName($matches[1]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true> $promotedClasses
     */
    private function removePromotedRuntimeClasses(array &$node, array $promotedClasses): void
    {
        $nodeClasses = $node['data']['properties']['settings']['advanced']['classes'] ?? [];
        if (!is_array($nodeClasses) || $nodeClasses === []) {
            return;
        }

        $remainingClasses = [];
        foreach ($nodeClasses as $className) {
            if (!is_string($className)) {
                $remainingClasses[] = $className;
                continue;
            }

            $normalized = $this->normalizeClassName($className);
            if ($normalized !== null && isset($promotedClasses[$normalized])) {
                continue;
            }

            $remainingClasses[] = $className;
        }

        $node['data']['properties']['settings']['advanced']['classes'] = array_values($remainingClasses);
    }

    /**
     * @param array<string, mixed> $design
     * @param array<int, string> $unmappedPaths
     * @return array<string, mixed>
     */
    private function transformDesignPropertiesToSelectorProperties(array $design, array &$unmappedPaths): array
    {
        $properties = [];
        $this->collectBreakpointProperties($design, [], $properties, $unmappedPaths);
        $this->normalizeCollectedSelectorProperties($properties);

        return $properties;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<int, string> $path
     * @param array<string, mixed> $properties
     * @param array<int, string> $unmappedPaths
     */
    private function collectBreakpointProperties(array $value, array $path, array &$properties, array &$unmappedPaths): void
    {
        foreach ($value as $key => $child) {
            if (!is_string($key)) {
                continue;
            }

            if (str_starts_with($key, 'breakpoint_')) {
                $this->setSelectorBreakpointValue($properties, $key, implode('.', $path), $child, $unmappedPaths);
                continue;
            }

            if (is_array($child)) {
                $this->collectBreakpointProperties($child, array_merge($path, [$key]), $properties, $unmappedPaths);
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $properties
     * @param array<int, string> $unmappedPaths
     */
    private function setSelectorBreakpointValue(
        array &$properties,
        string $breakpoint,
        string $sourcePath,
        $value,
        array &$unmappedPaths
    ): void {
        $targetPaths = $this->selectorPropertyPaths($sourcePath);
        if ($targetPaths === []) {
            if ($sourcePath !== '') {
                $unmappedPaths[] = $sourcePath;
            }
            return;
        }

        $properties[$breakpoint] = $properties[$breakpoint] ?? [];
        foreach ($targetPaths as $targetPath) {
            $this->setNestedValue(
                $properties[$breakpoint],
                explode('.', $targetPath),
                $this->normalizeSelectorMappedValue($targetPath, $value)
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function selectorPropertyPaths(string $sourcePath): array
    {
        $boxMap = [
            'container.padding' => ['spacing.spacing.padding'],
            'container.margin' => ['spacing.spacing.margin'],
            'container.background' => ['background.background_color'],
            'container.borders.radius' => ['borders.border_radius'],
            'button.padding' => ['spacing.spacing.padding'],
            'button.margin' => ['spacing.spacing.margin'],
            'button.background' => ['background.background_color'],
            'button.borders.radius' => ['borders.border_radius'],
            'spacing.padding' => ['spacing.spacing.padding'],
            'spacing.margin' => ['spacing.spacing.margin'],
            'background.color' => ['background.background_color'],
            'background.background_color' => ['background.background_color'],
            'background.layers' => ['background.backgrounds'],
            'borders.border' => ['borders.borders'],
            'borders.borders' => ['borders.borders'],
            'borders.radius' => ['borders.border_radius'],
            'borders.border_radius' => ['borders.border_radius'],
        ];

        if (isset($boxMap[$sourcePath])) {
            return $boxMap[$sourcePath];
        }

        $layoutMap = [
            'layout.align_items' => ['layout.flex_align.cross_axis'],
            'layout.justify_content' => ['layout.flex_align.primary_axis'],
            'layout.gap' => ['layout.gap.row', 'layout.gap.column'],
            'layout.row_gap' => ['layout.gap.row'],
            'layout.column_gap' => ['layout.gap.column'],
        ];

        if (isset($layoutMap[$sourcePath])) {
            return $layoutMap[$sourcePath];
        }

        if (str_starts_with($sourcePath, 'typography.')
            || str_starts_with($sourcePath, 'layout.')
            || str_starts_with($sourcePath, 'size.')
            || str_starts_with($sourcePath, 'position.')
            || str_starts_with($sourcePath, 'effects.')
            || str_starts_with($sourcePath, 'overflow.')
        ) {
            return [$sourcePath];
        }

        return [];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeSelectorMappedValue(string $targetPath, $value)
    {
        if ($targetPath === 'typography.font_family' && is_string($value)) {
            $trimmed = trim($value);
            if (strlen($trimmed) >= 2) {
                $quote = $trimmed[0];
                if (($quote === '"' || $quote === "'") && substr($trimmed, -1) === $quote) {
                    return substr($trimmed, 1, -1);
                }
            }
        }

        if ($targetPath === 'effects.opacity' && is_numeric($value)) {
            $number = $value + 0;
            if ($number >= 0 && $number <= 1) {
                return (int) round($number * 100);
            }

            return $number;
        }

        if ($targetPath === 'effects.custom_css' && is_string($value)) {
            return str_replace('%%SELECTOR%%', ':selector', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function normalizeCollectedSelectorProperties(array &$properties): void
    {
        foreach ($properties as &$breakpointProperties) {
            if (!is_array($breakpointProperties)) {
                continue;
            }

            $this->mergeFlexWrapIntoFlexDirection($breakpointProperties);
        }
        unset($breakpointProperties);
    }

    /**
     * Oxygen's selector schema emits flex wrapping through the flex direction
     * control, while converter data may carry `layout.flex_wrap` separately.
     *
     * @param array<string, mixed> $properties
     */
    private function mergeFlexWrapIntoFlexDirection(array &$properties): void
    {
        $layout = $properties['layout'] ?? null;
        if (!is_array($layout)) {
            return;
        }

        $rawWrap = $layout['flex_wrap'] ?? null;
        if (!is_string($rawWrap)) {
            return;
        }

        $wrap = trim($rawWrap);
        if ($wrap === '') {
            return;
        }

        $rawDirection = $layout['flex_direction'] ?? null;
        $direction = is_string($rawDirection) ? trim($rawDirection) : '';

        if ($direction === '') {
            $properties['layout']['flex_direction'] = $wrap;
            unset($properties['layout']['flex_wrap']);
            return;
        }

        $wrapTokens = ['wrap', 'nowrap', 'wrap-reverse'];
        $tokens = preg_split('/\s+/', $direction) ?: [];
        $kept = [];
        foreach ($tokens as $token) {
            if ($token === '' || in_array($token, $wrapTokens, true)) {
                continue;
            }
            $kept[] = $token;
        }
        $kept[] = $wrap;

        $properties['layout']['flex_direction'] = implode(' ', $kept);
        unset($properties['layout']['flex_wrap']);
    }

    /**
     * @param array<string, mixed> $array
     * @param array<int, string> $path
     * @param mixed $value
     */
    private function setNestedValue(array &$array, array $path, $value): void
    {
        $current = &$array;
        foreach ($path as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }

        $current = $value;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readOxySelectors(): array
    {
        $value = $this->getGlobalOption(self::SELECTORS_OPTION);
        if (is_string($value)) {
            $decoded = json_decode(wp_unslash($value), true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => is_array($item)));
    }

    /**
     * @return array<int, string>
     */
    private function readOxySelectorCollections(): array
    {
        $value = $this->getGlobalOption(self::COLLECTIONS_OPTION);
        if (is_string($value)) {
            $decoded = json_decode(wp_unslash($value), true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            $value = [];
        }

        $collections = array_values(array_filter($value, 'is_string'));
        if ($collections !== []) {
            return $collections;
        }

        $selectors = $this->readOxySelectors();
        $fromSelectors = [];
        foreach ($selectors as $selector) {
            if (isset($selector['collection']) && is_string($selector['collection']) && $selector['collection'] !== '') {
                $fromSelectors[] = $selector['collection'];
            }
        }

        return array_values(array_unique($fromSelectors));
    }

    /**
     * @return mixed
     */
    private function getGlobalOption(string $key)
    {
        if (is_callable('\\Breakdance\\Data\\get_global_option')) {
            return call_user_func('\\Breakdance\\Data\\get_global_option', $key);
        }

        return get_option($key, []);
    }

    /**
     * @param mixed $value
     */
    private function setGlobalOption(string $key, $value, bool $jsonEncodeRawOptionFallback = false): void
    {
        if (is_callable('\\Breakdance\\Data\\set_global_option')) {
            call_user_func('\\Breakdance\\Data\\set_global_option', $key, $value);
            return;
        }

        if ($jsonEncodeRawOptionFallback) {
            $encoded = wp_json_encode($value);
            if (is_string($encoded)) {
                update_option($key, $encoded, false);
                return;
            }
        }

        update_option($key, $value, false);
    }

    private function normalizeClassName(string $className): ?string
    {
        $className = ltrim(trim($className), '.');
        if ($className === '') {
            return null;
        }

        if (!preg_match('/^-?[_a-zA-Z]+[_a-zA-Z0-9-]*$/', $className)) {
            return null;
        }

        return $className;
    }

    private function uuidForClassName(string $className): string
    {
        $hash = md5('oxyai-oxygen-selector:' . $className);

        return sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec($hash[16]) & 0x3) | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }
}
