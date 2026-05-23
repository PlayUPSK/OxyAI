<?php

namespace OxyHtmlConverter\Services;

/**
 * Runtime catalog for builder element classes loaded by Oxygen/Breakdance.
 *
 * This does not claim every element can be auto-generated. It exposes the
 * contracts agents need before hand-authoring element JSON.
 */
class BuilderElementCatalogService
{
    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $essential = $this->catalogNamespace('EssentialElements\\');
        $oxygen = $this->catalogNamespace('OxygenElements\\');

        return [
            'coverage' => [
                'essentialElementsLoaded' => count($essential),
                'oxygenElementsLoaded' => count($oxygen),
                'mode' => 'runtime_declared_classes',
                'note' => 'Coverage reflects builder element classes loaded in the current WordPress request. Missing plugins or lazily unloaded classes cannot be inspected until WordPress/Oxygen loads them.',
            ],
            'essentialElements' => $essential,
            'oxygenElements' => $oxygen,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogNamespace(string $namespace): array
    {
        $elements = [];

        foreach (get_declared_classes() as $className) {
            if (!str_starts_with($className, $namespace)) {
                continue;
            }

            $elements[] = $this->catalogClass($className);
        }

        usort(
            $elements,
            static fn (array $a, array $b): int => [(string) ($a['name'] ?? ''), (string) ($a['class'] ?? '')]
                <=> [(string) ($b['name'] ?? ''), (string) ($b['class'] ?? '')]
        );

        return $elements;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogClass(string $className): array
    {
        return [
            'class' => '\\' . ltrim($className, '\\'),
            'shortName' => $this->shortName($className),
            'name' => $this->safeStatic($className, 'name'),
            'slug' => $this->safeStatic($className, 'slug'),
            'className' => $this->safeStatic($className, 'className'),
            'category' => $this->safeStatic($className, 'category'),
            'availableIn' => $this->safeStatic($className, 'availableIn'),
            'tag' => $this->safeStatic($className, 'tag'),
            'tagOptions' => $this->safeStatic($className, 'tagOptions'),
            'tagControlPath' => $this->safeStatic($className, 'tagControlPath'),
            'nestingRule' => $this->safeStatic($className, 'nestingRule'),
            'dynamicPropertyPaths' => $this->safeStatic($className, 'dynamicPropertyPaths'),
            'propertyPathsToWhitelistInFlatProps' => $this->safeStatic($className, 'propertyPathsToWhitelistInFlatProps'),
            'propertyPathsToSsrElementWhenValueChanges' => $this->safeStatic($className, 'propertyPathsToSsrElementWhenValueChanges'),
            'defaultPropertiesShape' => $this->shape($this->safeStatic($className, 'defaultProperties')),
            'autoGeneration' => [
                'status' => 'manual_contract_required',
                'note' => 'Use dynamicPropertyPaths, defaultPropertiesShape, and whitelisted property paths to satisfy the element contract before writing this element via MCP.',
            ],
        ];
    }

    /**
     * @return mixed
     */
    private function safeStatic(string $className, string $method)
    {
        if (!is_callable([$className, $method])) {
            return null;
        }

        try {
            return call_user_func([$className, $method]);
        } catch (\Throwable $exception) {
            return [
                'error' => 'static_call_failed',
                'class' => '\\' . ltrim($className, '\\'),
                'method' => $method,
            ];
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function shape($value, int $depth = 0)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($depth >= 4) {
            return 'array';
        }

        $shape = [];
        foreach ($value as $key => $child) {
            $shape[$key] = $this->shape($child, $depth + 1);
        }

        return $shape;
    }

    private function shortName(string $className): string
    {
        $position = strrpos($className, '\\');
        return $position === false ? $className : substr($className, $position + 1);
    }
}
