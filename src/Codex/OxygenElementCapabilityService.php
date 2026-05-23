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
                'notes' => [
                    'When Breakdance Elements for Oxygen is active and compatible, button-like HTML can map to EssentialElements\\Button.',
                    'EssentialElements\\Button uses a different element namespace and may use button-specific design buckets.',
                ],
            ],
            'nativeDesignPolicy' => [
                'Use native design properties only for supported element/property pairs listed here.',
                'Every native style value must be written under breakpoint_base unless a real responsive mapping is implemented.',
                'Length values must use Oxygen structured values: {number, unit, style}.',
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
                ['requiresBreakdanceElementsForOxygen' => true],
                'button'
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
            'nativeValueShapes' => [
                'breakpoint' => 'breakpoint_base',
                'length' => ['number' => 'int|float', 'unit' => 'px|%|rem|em|vh|vw|...', 'style' => 'original CSS length'],
                'color' => 'string',
                'radius' => $boxCategory . '.borders.radius.breakpoint_base.{all,topLeft,topRight,bottomLeft,bottomRight,editMode}',
                'spacing' => $boxCategory . '.padding|margin.breakpoint_base.{top,right,bottom,left}',
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
