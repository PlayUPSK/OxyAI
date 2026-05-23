<?php

namespace OxyHtmlConverter\Contracts;

use OxyHtmlConverter\ElementTypes;

/**
 * Canonical property-path contracts for generated element payloads.
 */
final class ElementContractRegistry
{
    /**
     * @return array<string, array<int, string>>
     */
    private static function contracts(): array
    {
        return [
            ElementTypes::TEXT_LINK => [
                'content.content.url',
            ],
            ElementTypes::CONTAINER_LINK => [
                'content.content.url',
            ],
            ElementTypes::IMAGE => [
                'content.image.url',
            ],
            ElementTypes::HTML5_VIDEO => [
                'content.content.video_file_url',
            ],
            ElementTypes::ESSENTIAL_BUTTON => [
                'content.content.text',
                'content.content.link.url',
            ],
            ElementTypes::ESSENTIAL_HEADING => [
                'content.content.text',
            ],
            ElementTypes::ESSENTIAL_TEXT => [
                'content.content.text',
            ],
            ElementTypes::ESSENTIAL_TEXT_LINK => [
                'content.content.text',
                'content.content.link.url',
            ],
            ElementTypes::ESSENTIAL_IMAGE => [
                'content.image.from',
                'content.image.url',
            ],
            ElementTypes::ESSENTIAL_BASIC_LIST => [
                'content.content.items',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getRequiredPropertyPaths(string $elementType): array
    {
        $contracts = self::contracts();
        return $contracts[$elementType] ?? [];
    }
}
