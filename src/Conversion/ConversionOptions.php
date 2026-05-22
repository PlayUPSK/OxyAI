<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Conversion;

final class ConversionOptions
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $startingNodeId = isset($input['startingNodeId']) ? (int) $input['startingNodeId'] : 1;
        $mapCssToProperties = $this->bool($input['mapCssToProperties'] ?? false, false);
        $preserveStyleBlockCss = array_key_exists('preserveStyleBlockCss', $input)
            ? $this->bool($input['preserveStyleBlockCss'], true)
            : !$mapCssToProperties;

        return [
            'startingNodeId' => max(1, $startingNodeId),
            'wrapInContainer' => $this->bool($input['wrapInContainer'] ?? true, true),
            'includeCssElement' => $this->bool($input['includeCssElement'] ?? true, true),
            'inlineStyles' => $this->bool($input['inlineStyles'] ?? true, true),
            'safeMode' => $this->bool($input['safeMode'] ?? false, false),
            'debugMode' => $this->bool($input['debugMode'] ?? false, false),
            'useSelectors' => $this->bool($input['useSelectors'] ?? false, false),
            'preserveStyleBlockCss' => $preserveStyleBlockCss,
            'mapCssToProperties' => $mapCssToProperties,
        ];
    }

    /**
     * @param mixed $value
     */
    private function bool($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return is_bool($parsed) ? $parsed : $default;
    }
}
