<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Oxygen;

final class SelectorRegistrationService
{
    private const SELECTORS_OPTION = 'oxy_selectors_json_string';
    private const COLLECTIONS_OPTION = 'oxy_selectors_collections_json_string';
    private const COLLECTION_NAME = 'OxyAI';

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    public function registerTreeSelectors(array &$tree, bool $persist): array
    {
        $classes = [];
        $this->collectRuntimeClasses($tree['root'] ?? $tree, $classes);

        $selectors = [];
        $existingSelectors = $this->readOxySelectors();
        $selectorsByClass = $this->indexSelectorsByClassName($existingSelectors);
        $created = 0;

        foreach (array_keys($classes) as $className) {
            $selector = $selectorsByClass[$className] ?? null;
            if ($selector === null) {
                $selector = $this->createClassSelector($className);
                $created++;
            }

            $selectors[$className] = $selector;
        }

        if ($selectors === []) {
            return [
                'enabled' => true,
                'created' => 0,
                'matched' => 0,
                'attachedElements' => 0,
                'selectors' => [],
                'registryOption' => self::SELECTORS_OPTION,
                'collectionsOption' => self::COLLECTIONS_OPTION,
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
            'selectors' => array_values($selectors),
            'registryOption' => self::SELECTORS_OPTION,
            'collectionsOption' => self::COLLECTIONS_OPTION,
            'collection' => self::COLLECTION_NAME,
            'note' => 'Runtime classes remain in settings.advanced.classes for rendering; matching Oxygen selector IDs are attached in meta.classes for editor class visibility.',
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
            if (isset($selector['id']) && is_string($selector['id']) && $selector['id'] !== '') {
                $byId[$selector['id']] = $selector;
            }
        }

        foreach ($selectors as $selector) {
            if (!isset($selector['id']) || !is_string($selector['id']) || $selector['id'] === '') {
                continue;
            }

            $byId[$selector['id']] = array_merge($byId[$selector['id']] ?? [], $selector);
        }

        $allSelectors = array_values($byId);
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

        $this->setGlobalOption(self::SELECTORS_OPTION, $allSelectors);
        $this->setGlobalOption(self::COLLECTIONS_OPTION, array_values($collections));

        if (is_callable('\\Breakdance\\Render\\generateCacheForGlobalSettings')) {
            call_user_func('\\Breakdance\\Render\\generateCacheForGlobalSettings');
        }
    }

    /**
     * @param mixed $node
     * @param array<string, true> $classes
     */
    private function collectRuntimeClasses($node, array &$classes): void
    {
        if (!is_array($node)) {
            return;
        }

        $nodeClasses = $node['data']['properties']['settings']['advanced']['classes'] ?? [];
        if (is_array($nodeClasses)) {
            foreach ($nodeClasses as $className) {
                if (!is_string($className)) {
                    continue;
                }

                $className = $this->normalizeClassName($className);
                if ($className !== null) {
                    $classes[$className] = true;
                }
            }
        }

        $children = $node['children'] ?? [];
        if (!is_array($children)) {
            return;
        }

        foreach ($children as $child) {
            $this->collectRuntimeClasses($child, $classes);
        }
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
        foreach ($nodeClasses as $className) {
            if (!is_string($className)) {
                continue;
            }

            $normalized = $this->normalizeClassName($className);
            if ($normalized !== null && isset($selectors[$normalized]['id'])) {
                $selectorIds[] = (string) $selectors[$normalized]['id'];
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
            if (($selector['type'] ?? null) !== 'class' || !isset($selector['name']) || !is_string($selector['name'])) {
                continue;
            }

            $className = $this->normalizeClassName($selector['name']);
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
            'properties' => [],
            'children' => [],
            'collection' => self::COLLECTION_NAME,
        ];
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
    private function setGlobalOption(string $key, $value): void
    {
        if (is_callable('\\Breakdance\\Data\\set_global_option')) {
            call_user_func('\\Breakdance\\Data\\set_global_option', $key, $value);
            return;
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
