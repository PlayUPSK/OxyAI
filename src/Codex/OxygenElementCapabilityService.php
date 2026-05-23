<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Codex;

use OxyHtmlConverter\ElementTypes;
use OxyHtmlConverter\Services\EnvironmentService;

final class OxygenElementCapabilityService
{
    /**
     * @return array<string, mixed>
     */
    public function all(?string $elementType = null): array
    {
        $environment = new EnvironmentService();
        $essentialContracts = $environment->getEssentialElementContractStatuses();
        $builderElementCatalog = $environment->getBuilderElementCatalog();
        $elements = $this->elements();

        if ($elementType !== null && $elementType !== '') {
            $elements = array_intersect_key($elements, [$elementType => true]);
        }

        return [
            'success' => true,
            'oxygenVersionTarget' => 'Oxygen 6 / Breakdance Oxygen',
            'breakdanceElementsForOxygen' => [
                'detected' => $environment->isBreakdanceElementsForOxygenActive(),
                'essentialButtonContractCompatible' => $environment->isEssentialButtonContractCompatible(),
                'preferredButtonMapping' => $environment->shouldPreferEssentialElements()
                    ? ElementTypes::ESSENTIAL_BUTTON
                    : ElementTypes::CONTAINER,
                'contractStatuses' => $essentialContracts,
                'runtimeCatalog' => $builderElementCatalog,
                'autoMappedWhenPreferred' => [
                    ElementTypes::ESSENTIAL_BUTTON => 'button tags and button-like links with text-only content',
                    ElementTypes::ESSENTIAL_HEADING => 'h1-h6 tags with text-only or inline-formatted content',
                    ElementTypes::ESSENTIAL_TEXT_LINK => 'plain text links that are not button-like',
                    ElementTypes::ESSENTIAL_IMAGE => 'img tags with a usable src attribute',
                    ElementTypes::ESSENTIAL_BASIC_LIST => 'ul/ol lists whose li children contain only text or a single plain link',
                ],
                'notes' => [
                    'When Breakdance Elements for Oxygen is active and compatible, safe static HTML can map to supported EssentialElements.',
                    'Complex widgets such as tabs, accordions, menus, forms, and WooCommerce elements require dedicated content contracts and are reported as available only after explicit support is added.',
                ],
            ],
            'nativeDesignPolicy' => [
                'Use native design properties only for supported element/property pairs listed here.',
                'Every native style value must be written under breakpoint_base unless a real responsive mapping is implemented.',
                'Length values must use Oxygen structured values: {number, unit, style}.',
                'For plain Oxygen Container/Text/Image elements, native design properties improve builder editability but class CssCode remains the reliable frontend render fallback in Oxygen 6 / Breakdance Oxygen.',
                'Only strip class CSS for element types explicitly marked cssFallbackCanBeStripped=true.',
                'Keep class CSS in CssCode for pseudo selectors, media queries, keyframes, complex selectors, responsive variants, and any unverified property.',
                'Do not strip CssCode fallback unless conversion audit proves every declaration in the selector was consumed natively.',
            ],
            'classStylingPolicy' => [
                'Always preserve stable semantic classes on generated elements.',
                'Class CSS is the authoritative fallback for visual fidelity.',
                'Native properties are for Oxygen editability and compiled CSS, not a reason to drop unsupported CSS.',
                'Prefer simple selectors scoped to the generated root class so future CSS stripping can safely reason about ownership.',
            ],
            'elements' => array_values($elements),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function elements(): array
    {
        $sharedBoxStyles = [
            'background',
            'background-color',
            'padding',
            'padding-top',
            'padding-right',
            'padding-bottom',
            'padding-left',
            'margin',
            'margin-top',
            'margin-right',
            'margin-bottom',
            'margin-left',
            'border-radius',
            'border-top-left-radius',
            'border-top-right-radius',
            'border-bottom-left-radius',
            'border-bottom-right-radius',
        ];

        $typographyStyles = [
            'color',
            'font-family',
            'font-size',
            'font-style',
            'font-weight',
            'letter-spacing',
            'line-height',
            'text-align',
            'text-decoration',
            'text-transform',
        ];

        $sizeStyles = [
            'width',
            'min-width',
            'max-width',
            'height',
            'min-height',
            'max-height',
            'object-fit',
            'object-position',
            'aspect-ratio',
        ];

        $layoutStyles = [
            'display',
            'flex-direction',
            'flex-wrap',
            'justify-content',
            'align-items',
            'align-content',
            'gap',
            'row-gap',
            'column-gap',
            'flex-grow',
            'flex-shrink',
            'flex-basis',
            'order',
            'grid-template-columns',
            'grid-template-rows',
            'grid-auto-flow',
            'grid-auto-columns',
            'grid-auto-rows',
        ];

        $positionStyles = [
            'position',
            'top',
            'right',
            'bottom',
            'left',
            'z-index',
        ];

        $effectStyles = [
            'opacity',
            'box-shadow',
            'transform',
            'transition',
            'filter',
            'backdrop-filter',
            'mix-blend-mode',
        ];

        $overflowStyles = [
            'overflow',
            'overflow-x',
            'overflow-y',
        ];

        return [
            ElementTypes::CONTAINER => $this->element(
                ElementTypes::CONTAINER,
                'General layout wrapper for div, section, article, nav, lists, and similar block HTML.',
                ['container', 'size', 'typography', 'layout', 'position', 'effects', 'overflow'],
                array_merge($sharedBoxStyles, $typographyStyles, $sizeStyles, $layoutStyles, $positionStyles, $effectStyles, $overflowStyles)
            ),
            ElementTypes::CONTAINER_LINK => $this->element(
                ElementTypes::CONTAINER_LINK,
                'Clickable wrapper for button-like links or links with child elements.',
                ['container', 'size', 'typography', 'layout', 'position', 'effects', 'overflow'],
                array_merge($sharedBoxStyles, $typographyStyles, $sizeStyles, $layoutStyles, $positionStyles, $effectStyles, $overflowStyles)
            ),
            ElementTypes::TEXT => $this->element(
                ElementTypes::TEXT,
                'Plain text, headings, spans, labels, and simple paragraphs.',
                ['container', 'size', 'typography', 'layout', 'position', 'effects', 'overflow'],
                array_merge($sharedBoxStyles, $typographyStyles, $sizeStyles, $layoutStyles, $positionStyles, $effectStyles, $overflowStyles)
            ),
            ElementTypes::TEXT_LINK => $this->element(
                ElementTypes::TEXT_LINK,
                'Inline text link.',
                ['container', 'size', 'typography', 'layout', 'position', 'effects', 'overflow'],
                array_merge($sharedBoxStyles, $typographyStyles, $sizeStyles, $layoutStyles, $positionStyles, $effectStyles, $overflowStyles)
            ),
            ElementTypes::RICH_TEXT => $this->element(
                ElementTypes::RICH_TEXT,
                'Rich text wrapper for preserved table or rich HTML content.',
                ['container', 'size', 'typography', 'layout', 'position', 'effects', 'overflow'],
                array_merge($sharedBoxStyles, $typographyStyles, $sizeStyles, $layoutStyles, $positionStyles, $effectStyles, $overflowStyles)
            ),
            ElementTypes::IMAGE => $this->element(
                ElementTypes::IMAGE,
                'Image element. Native support covers wrapper, size, object fit/position, effects, overflow, and positioning.',
                ['container', 'size', 'position', 'effects', 'overflow'],
                array_merge($sharedBoxStyles, $sizeStyles, $positionStyles, $effectStyles, $overflowStyles)
            ),
            ElementTypes::HTML5_VIDEO => $this->element(
                ElementTypes::HTML5_VIDEO,
                'Video element. Native support covers wrapper, size, object fit/position, effects, overflow, and positioning.',
                ['container', 'size', 'position', 'effects', 'overflow'],
                array_merge($sharedBoxStyles, $sizeStyles, $positionStyles, $effectStyles, $overflowStyles)
            ),
            ElementTypes::ESSENTIAL_BUTTON => $this->element(
                ElementTypes::ESSENTIAL_BUTTON,
                'Breakdance Elements for Oxygen button. Use only when the plugin and contract are available.',
                ['button', 'size', 'typography', 'layout', 'position', 'effects', 'overflow'],
                array_merge($sharedBoxStyles, $typographyStyles, $sizeStyles, $layoutStyles, $positionStyles, $effectStyles, $overflowStyles),
                [
                    'requiresBreakdanceElementsForOxygen' => true,
                    'autoMapping' => 'button tags and button-like text links',
                    'requiredContentPaths' => ['content.content.text', 'content.content.link.url'],
                ],
                'button'
            ),
            ElementTypes::ESSENTIAL_HEADING => $this->element(
                ElementTypes::ESSENTIAL_HEADING,
                'Breakdance Elements for Oxygen heading. Auto-mapped for h1-h6 with simple text/inline content.',
                ['typography', 'size', 'spacing'],
                array_merge($typographyStyles, $sizeStyles, ['margin-top', 'margin-bottom']),
                [
                    'requiresBreakdanceElementsForOxygen' => true,
                    'autoMapping' => 'h1-h6 tags with text-only or inline-formatted content',
                    'requiredContentPaths' => ['content.content.text'],
                    'cssFallbackCanBeStripped' => false,
                ]
            ),
            ElementTypes::ESSENTIAL_TEXT => $this->element(
                ElementTypes::ESSENTIAL_TEXT,
                'Breakdance Elements for Oxygen text. Supported as a hand-authored target; not auto-mapped from p/span yet because tag semantics need more verification.',
                ['typography', 'size', 'spacing'],
                array_merge($typographyStyles, $sizeStyles, ['margin-top', 'margin-bottom']),
                [
                    'requiresBreakdanceElementsForOxygen' => true,
                    'autoMapping' => false,
                    'requiredContentPaths' => ['content.content.text'],
                    'cssFallbackCanBeStripped' => false,
                ]
            ),
            ElementTypes::ESSENTIAL_TEXT_LINK => $this->element(
                ElementTypes::ESSENTIAL_TEXT_LINK,
                'Breakdance Elements for Oxygen text link. Auto-mapped for simple non-button links.',
                ['typography', 'spacing'],
                array_merge($typographyStyles, ['margin-top', 'margin-bottom']),
                [
                    'requiresBreakdanceElementsForOxygen' => true,
                    'autoMapping' => 'a tags with text-only content that do not look like buttons',
                    'requiredContentPaths' => ['content.content.text', 'content.content.link.url'],
                    'cssFallbackCanBeStripped' => false,
                ]
            ),
            ElementTypes::ESSENTIAL_IMAGE => $this->element(
                ElementTypes::ESSENTIAL_IMAGE,
                'Breakdance Elements for Oxygen image. Auto-mapped for img tags with a usable src.',
                ['image', 'effects', 'borders', 'spacing'],
                array_merge($sharedBoxStyles, $sizeStyles, $effectStyles),
                [
                    'requiresBreakdanceElementsForOxygen' => true,
                    'autoMapping' => 'img tags with src',
                    'requiredContentPaths' => ['content.image.from', 'content.image.url'],
                    'cssFallbackCanBeStripped' => false,
                ]
            ),
            ElementTypes::ESSENTIAL_BASIC_LIST => $this->element(
                ElementTypes::ESSENTIAL_BASIC_LIST,
                'Breakdance Elements for Oxygen basic list. Auto-mapped for simple text/link ul/ol lists.',
                ['list', 'typography', 'spacing', 'size'],
                array_merge($typographyStyles, $sizeStyles, ['margin-top', 'margin-bottom']),
                [
                    'requiresBreakdanceElementsForOxygen' => true,
                    'autoMapping' => 'ul/ol where every li is plain text or a single text link',
                    'requiredContentPaths' => ['content.content.items'],
                    'cssFallbackCanBeStripped' => false,
                ]
            ),
            ElementTypes::ESSENTIAL_COLUMNS => $this->element(
                ElementTypes::ESSENTIAL_COLUMNS,
                'Breakdance Elements for Oxygen columns. Contract is exposed for agents, but auto-mapping is not enabled until column detection/rebalancing is implemented.',
                ['layout', 'size', 'spacing'],
                array_merge($layoutStyles, $sizeStyles, ['gap', 'column-gap', 'padding']),
                ['requiresBreakdanceElementsForOxygen' => true, 'autoMapping' => false, 'cssFallbackCanBeStripped' => false]
            ),
            ElementTypes::ESSENTIAL_COLUMN => $this->element(
                ElementTypes::ESSENTIAL_COLUMN,
                'Breakdance Elements for Oxygen column. Contract is exposed for agents, but auto-mapping is not enabled until paired Columns output is implemented.',
                ['layout', 'layout_v2', 'background', 'size', 'order', 'text_colors'],
                array_merge($sharedBoxStyles, $layoutStyles, $sizeStyles),
                ['requiresBreakdanceElementsForOxygen' => true, 'autoMapping' => false, 'cssFallbackCanBeStripped' => false]
            ),
            ElementTypes::ESSENTIAL_ICON => $this->element(
                ElementTypes::ESSENTIAL_ICON,
                'Breakdance Elements for Oxygen icon. Contract is exposed for agents, but auto-mapping is not enabled until icon-library normalization is implemented.',
                ['icon', 'spacing'],
                ['color', 'font-size', 'width', 'height', 'margin-top', 'margin-bottom'],
                ['requiresBreakdanceElementsForOxygen' => true, 'autoMapping' => false, 'cssFallbackCanBeStripped' => false]
            ),
        ];
    }

    /**
     * @param array<int, string> $designBuckets
     * @param array<int, string> $nativeCssProperties
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function element(
        string $type,
        string $description,
        array $designBuckets,
        array $nativeCssProperties,
        array $extra = [],
        string $boxCategory = 'container'
    ): array {
        return array_merge([
            'type' => $type,
            'description' => $description,
            'nativeDesignBuckets' => $designBuckets,
            'nativeCssProperties' => array_values(array_unique($nativeCssProperties)),
            'cssFallbackCanBeStripped' => $boxCategory === 'button',
            'nativeValueShapes' => [
                'breakpoint' => 'breakpoint_base',
                'length' => ['number' => 'int|float', 'unit' => 'px|%|rem|em|vh|vw|...', 'style' => 'original CSS length'],
                'color' => 'string',
                'radius' => $boxCategory . '.borders.radius.breakpoint_base.{all,topLeft,topRight,bottomLeft,bottomRight,editMode}',
                'spacing' => $boxCategory . '.padding|margin.breakpoint_base.{all,top,right,bottom,left,editMode}',
                'layout' => 'layout.{property}.breakpoint_base',
                'position' => 'position.{property}.breakpoint_base',
                'effects' => 'effects.{property}.breakpoint_base',
                'overflow' => 'overflow.{property}.breakpoint_base',
            ],
            'mustRemainClassCss' => [
                'media queries',
                'pseudo selectors',
                'keyframes and animations',
                'complex selectors',
                'responsive variants until media queries map to Oxygen breakpoints',
                'unknown or unverified Oxygen schema paths',
            ],
        ], $extra);
    }
}
