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

        $requestedElementType = $elementType !== null ? ltrim($elementType, '\\') : null;
        if ($requestedElementType !== null && $requestedElementType !== '') {
            $elements = array_intersect_key($elements, [$requestedElementType => true]);
            if ($elements === []) {
                $runtimeElement = $this->runtimeElement($requestedElementType, $builderElementCatalog);
                if ($runtimeElement !== null) {
                    $elements = [$requestedElementType => $runtimeElement];
                }
            }

            // Focused, token-efficient view: return only this element, its
            // contract, and a concrete example node - NOT the multi-hundred-KB
            // runtime catalog and full coverage policies.
            return $this->elementFocus($requestedElementType, $elements, $essentialContracts);
        }

        return [
            'success' => true,
            'oxygenVersionTarget' => 'Oxygen 6 / Breakdance Oxygen',
            'breakdanceElementsForOxygen' => [
                'detected' => $environment->isBreakdanceElementsForOxygenActive(),
                'formsDetected' => $environment->isBreakdanceFormsForOxygenActive(),
                'essentialButtonContractCompatible' => $environment->isEssentialButtonContractCompatible(),
                'preferredButtonMapping' => $environment->shouldPreferEssentialElements()
                    ? ElementTypes::ESSENTIAL_BUTTON
                    : ElementTypes::CONTAINER,
                'contractStatuses' => $essentialContracts,
                'runtimeCatalog' => $builderElementCatalog,
                'coverageSummary' => [
                    'essentialElementsLoaded' => $builderElementCatalog['coverage']['essentialElementsLoaded'] ?? 0,
                    'breakdanceElementsForOxygenLoaded' => $builderElementCatalog['coverage']['breakdanceElementsForOxygenLoaded'] ?? 0,
                    'breakdanceFormsForOxygenLoaded' => $builderElementCatalog['coverage']['breakdanceFormsForOxygenLoaded'] ?? 0,
                ],
                'autoMappedWhenPreferred' => [
                    ElementTypes::ESSENTIAL_BUTTON => 'button tags and button-like links with text-only content',
                    ElementTypes::ESSENTIAL_HEADING => 'h1-h6 tags with text-only or inline-formatted content',
                    ElementTypes::ESSENTIAL_TEXT => 'p/span tags with simple text-only or inline-formatted content',
                    ElementTypes::ESSENTIAL_TEXT_LINK => 'plain text links that are not button-like',
                    ElementTypes::ESSENTIAL_IMAGE => 'img tags with a usable src attribute',
                    ElementTypes::ESSENTIAL_BASIC_LIST => 'ul/ol lists whose li children contain only text or a single plain link',
                ],
                'notes' => [
                    'When Breakdance Elements for Oxygen is active and compatible, safe static HTML can map to supported EssentialElements.',
                    'Complex widgets such as tabs, accordions, menus, forms, and WooCommerce elements require dedicated content contracts and are reported as available only after explicit support is added.',
                ],
            ],
            'breakdanceFormsForOxygen' => [
                'detected' => $environment->isBreakdanceFormsForOxygenActive(),
                'runtimeCatalog' => $builderElementCatalog['breakdanceFormsForOxygen'] ?? [],
                'contractStatuses' => array_intersect_key($essentialContracts, array_flip(['formBuilder', 'loginForm', 'registerForm'])),
                'safeHandAuthoredTargets' => [
                    ElementTypes::ESSENTIAL_FORM_BUILDER => 'Contact/newsletter-style forms when fields, submit text, success/error messages, and actions are intentionally provided.',
                    ElementTypes::ESSENTIAL_LOGIN_FORM => 'Login forms when submit/success labels and optional lost-password/register settings are intentionally provided.',
                    ElementTypes::ESSENTIAL_REGISTER_FORM => 'Registration forms when submit/success labels and redirect URL are intentionally provided.',
                ],
                'autoMapping' => false,
                'notes' => [
                    'Do not turn arbitrary <form> HTML into a Breakdance form unless the field/action contract is explicit; preserve unknown forms as HtmlCode.',
                    'FormBuilder defaultProperties depends on WordPress user/options at runtime, so agents should send complete content.form fields and actions when hand-authoring.',
                ],
            ],
            'nativeDesignPolicy' => [
                'Use native design properties only for supported element/property pairs listed here.',
                'Every native style value must be written under breakpoint_base unless a real responsive mapping is implemented.',
                'Length values must use Oxygen structured values: {number, unit, style}.',
                'For plain Oxygen Container/Text/Image elements, OxyAI stores direct class selector styles in the Oxygen selector library so the editor and compiler can see them.',
                'Only strip class CSS for element types explicitly marked cssFallbackCanBeStripped=true.',
                'Direct single-class @media (max-width) rules can map to Oxygen breakpoint selector properties when every declaration is supported. Keep other media queries in CssCode.',
                'Do not strip CssCode fallback unless conversion audit proves every declaration in the selector was consumed natively.',
            ],
            'cssMappingCoveragePolicy' => [
                'sourceOfTruth' => 'config/css-mapping/breakdance-coverage-manifest.json plus live list_oxygen_element_capabilities output for the current site.',
                'coverageHarness' => [
                    'inventory' => 'tools/css-mapping/extract-breakdance-contracts.php reads Breakdance Elements/Forms element.php, css.twig, html.twig, and default.css without booting WordPress.',
                    'oxygenInventory' => 'tools/css-mapping/extract-oxygen-core-contracts.php reads Oxygen 6 core element sources and universal spacing controls.',
                    'manifestValidation' => 'tools/css-mapping/validate-breakdance-coverage.php verifies every css.twig/universal design path has an explicit manifest rule and no stripSafe rule lacks proof metadata.',
                    'realSourceSmoke' => 'tests/smoke/real-source-css-coverage.php asserts the current downloaded Oxygen, Breakdance Elements, and Breakdance Forms sources have zero uncovered paths, zero needs-element-specific-mapper paths, and zero unknown CSS properties/macros when those sources are present.',
                    'realSourceReviewGate' => 'tools/css-mapping/real-source-coverage-gate.php prints the reviewer-facing PASS/FAIL table for Oxygen core plus Breakdance Elements/Forms together.',
                ],
                'statuses' => [
                    'native-shared-mapper' => 'Shared mapper can represent the property family, but CSS fallback stays unless compile proof exists for the exact element/property pair.',
                    'native-with-guardrails' => 'Native mapping is allowed only with documented element-specific guardrails.',
                    'element-specific-contract' => 'A reviewed element-specific mapper/fallback contract exists for this element and path family; CSS fallback remains unless stripSafe proof is attached.',
                    'content-or-render-runtime' => 'Path affects content/runtime rendering rather than a direct CSS declaration mapping.',
                    'requires-css-fallback' => 'Keep CssCode unless a dedicated mapper and compile proof are added.',
                    'needs-element-specific-mapper' => 'Known source path without a reviewed contract; the real-source gate must fail until this is eliminated.',
                    'uncovered' => 'No manifest rule exists yet; do not hand-author as native-only.',
                ],
                'stripSafeRequires' => [
                    'explicit manifest rule',
                    'documented CSS declaration to design/content path mapping',
                    'JSON-shape smoke test',
                    'compiled CSS or rendered page proof',
                    'conversion audit without retained/dead-write declarations',
                ],
            ],
            'selectorCompilerSupport' => [
                'nativeResponsiveMapping' => [
                    '@media (max-width:1119px)' => 'breakpoint_tablet_landscape',
                    '@media (max-width:1023px)' => 'breakpoint_tablet_portrait',
                    '@media (max-width:767px)' => 'breakpoint_phone_landscape',
                    '@media (max-width:479px)' => 'breakpoint_phone_portrait',
                ],
                'nativeResponsiveSelectorScope' => 'Only direct single-class selectors such as .hero-title are mapped into Oxygen selector breakpoints. Descendant/grouped/pseudo/keyframe/container-query rules remain CSS fallback.',
                'verifiedNativeSelectorCss' => [
                    'display:flex',
                    'flex-direction via Oxygen flex-flow output',
                    'justify-content',
                    'align-items',
                    'gap',
                    'width/max-width/height',
                    'position/top/right/bottom/left/z-index',
                    'background-color',
                    'padding/margin side values',
                    'border-radius',
                    'typography color/size/weight/line-height/letter-spacing/text-transform/text-align',
                ],
                'selectorPropertyNormalizer' => [
                    'Selector registration remaps converter/Breakdance schema paths before persisting oxy_selectors_json_string.',
                    'Known remaps include layout.align_items -> layout.flex_align.cross_axis, layout.justify_content -> layout.flex_align.primary_axis, layout.gap -> layout.gap.row/column, spacing.padding/margin -> spacing.spacing.*, background.color -> background.background_color, borders.border -> borders.borders, and borders.radius -> borders.border_radius.',
                    'Known value normalizers include quoted font-family cleanup, opacity 0..1 -> 0..100, %%SELECTOR%% custom CSS token rewrite, and flex_wrap merge into flex_direction.',
                ],
                'knownNativeSelectorGaps' => [
                    'grid-template-columns and grid-template-rows are captured in design data but not emitted by the Oxygen selector compiler on the verified Oxygen 6 site.',
                    'flex-wrap, flex-grow, flex-shrink, and flex-basis are captured in design data but were not emitted by the selector compiler on the verified Oxygen 6 site.',
                    'Use percentage widths or keep a CSS fallback for layouts that require wrapping, CSS grid, complex media queries, or flex item growth/shrink behavior.',
                ],
            ],
            'classStylingPolicy' => [
                'Always preserve stable semantic classes on generated elements.',
                'Class CSS is the authoritative fallback for visual fidelity.',
                'Direct single-class selectors such as .hero-title are registered as Oxygen selector properties when registerSelectors is enabled.',
                'Prefer simple selectors scoped to the generated root class so OxyAI can map editable selector properties safely.',
                'Keep descendant selectors, grouped selectors, pseudo states, unsupported media queries, and animations in CssCode.',
            ],
            'elements' => array_values($elements),
        ];
    }

    /**
     * Focused single-element capability view.
     *
     * @param array<string, array<string, mixed>> $elements
     * @param array<string, mixed> $essentialContracts
     * @return array<string, mixed>
     */
    private function elementFocus(string $elementType, array $elements, array $essentialContracts): array
    {
        $element = $elements === [] ? null : array_values($elements)[0];

        return [
            'success' => true,
            'oxygenVersionTarget' => 'Oxygen 6 / Breakdance Oxygen',
            'view' => 'element',
            'elementType' => $elementType,
            'element' => $element,
            // Kept (plural) for backward compatibility with existing consumers.
            'elements' => array_values($elements),
            'contractStatuses' => $essentialContracts,
            'exampleNode' => $this->exampleNode($elementType),
            'nativeDesignPolicy' => [
                'Every native style value must be written under breakpoint_base unless a real responsive mapping is implemented.',
                'Length values must use Oxygen structured values: {number, unit, style}.',
                'Direct single-class @media (max-width) rules can map to Oxygen breakpoint selector properties when every declaration is supported.',
            ],
            'knownNativeSelectorGaps' => [
                'grid-template-columns/rows are captured in design data but NOT emitted by the Oxygen selector compiler on verified Oxygen 6.',
                'flex-wrap, flex-grow, flex-shrink, flex-basis are captured but were not emitted either.',
                'For CSS grid, flex wrapping, or flex item growth, prefer a class CSS rule or upsert_css_block over inline native design.',
            ],
            'note' => 'Focused single-element view. Omit elementType to get the full catalog (large).',
        ];
    }

    /**
     * A minimal, valid example node for the requested element type. Content
     * paths are filled; layout that the selector compiler cannot emit is left
     * to a CssCode block / class CSS rather than inline design.
     *
     * @return array<string, mixed>
     */
    private function exampleNode(string $elementType): array
    {
        $type = ltrim($elementType, '\\');
        $node = static fn (array $content): array => [
            'data' => ['type' => $type, 'properties' => ['content' => ['content' => $content]]],
            'children' => [],
        ];

        return match ($type) {
            ElementTypes::ESSENTIAL_TEXT_LINK, ElementTypes::TEXT_LINK, ElementTypes::ESSENTIAL_BUTTON => $node([
                'text' => 'Example link',
                'link' => ['type' => 'url', 'url' => 'https://example.com', 'openInNewTab' => false],
            ]),
            ElementTypes::TEXT, ElementTypes::ESSENTIAL_TEXT, ElementTypes::ESSENTIAL_HEADING => $node([
                'text' => 'Example text',
            ]),
            ElementTypes::IMAGE, ElementTypes::ESSENTIAL_IMAGE => [
                'data' => ['type' => $type, 'properties' => ['content' => ['image' => [
                    'from' => 'url',
                    'url' => 'https://example.com/image.jpg',
                    'alt' => 'Example image',
                ]]]],
                'children' => [],
            ],
            ElementTypes::CONTAINER, ElementTypes::CONTAINER_LINK => [
                'data' => [
                    'type' => $type,
                    'properties' => ['settings' => ['advanced' => ['classes' => ['example-wrapper']]]],
                ],
                'children' => [],
                '_note' => 'Plain container. For flex-column/grid layout, attach a class CSS rule or an upsert_css_block targeting .example-wrapper - the Oxygen selector compiler does not emit grid/flex-wrap from inline native design.',
            ],
            default => [
                'data' => ['type' => $type, 'properties' => ['content' => ['content' => []]]],
                'children' => [],
                '_note' => 'Generic node skeleton. Check requiredContentPaths in "element" above and fill content.content accordingly.',
            ],
        };
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
                'Breakdance Elements for Oxygen text. Auto-mapped for p/span with simple text-only or inline-formatted content when the plugin contract is compatible.',
                ['typography', 'size', 'spacing'],
                array_merge($typographyStyles, $sizeStyles, ['margin-top', 'margin-bottom']),
                [
                    'requiresBreakdanceElementsForOxygen' => true,
                    'autoMapping' => 'p/span tags with text-only or inline-formatted content',
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
            ElementTypes::ESSENTIAL_FORM_BUILDER => $this->element(
                ElementTypes::ESSENTIAL_FORM_BUILDER,
                'Breakdance Forms for Oxygen Form Builder. Hand-author only when fields/actions are explicit; arbitrary HTML forms should remain HtmlCode.',
                ['container', 'form', 'layout', 'spacing'],
                array_merge($sharedBoxStyles, $typographyStyles, $sizeStyles, ['gap', 'margin-top', 'margin-bottom']),
                [
                    'requiresBreakdanceFormsForOxygen' => true,
                    'autoMapping' => false,
                    'requiredContentPaths' => [
                        'content.form.form_name',
                        'content.form.fields[].type',
                        'content.form.fields[].label',
                        'content.form.fields[].advanced.id',
                        'content.form.submit_text',
                        'content.form.success_message',
                        'content.actions.actions',
                    ],
                    'cssFallbackCanBeStripped' => false,
                ]
            ),
            ElementTypes::ESSENTIAL_LOGIN_FORM => $this->element(
                ElementTypes::ESSENTIAL_LOGIN_FORM,
                'Breakdance Forms for Oxygen Login Form. Hand-author only when the login behavior and labels are intended.',
                ['form', 'layout', 'spacing'],
                array_merge($sharedBoxStyles, $typographyStyles, $sizeStyles, ['margin-top', 'margin-bottom']),
                [
                    'requiresBreakdanceFormsForOxygen' => true,
                    'autoMapping' => false,
                    'requiredContentPaths' => ['content.form.submit_text', 'content.form.success_message'],
                    'cssFallbackCanBeStripped' => false,
                ]
            ),
            ElementTypes::ESSENTIAL_REGISTER_FORM => $this->element(
                ElementTypes::ESSENTIAL_REGISTER_FORM,
                'Breakdance Forms for Oxygen Register Form. Hand-author only when registration is intentionally desired.',
                ['form', 'layout', 'spacing'],
                array_merge($sharedBoxStyles, $typographyStyles, $sizeStyles, ['margin-top', 'margin-bottom']),
                [
                    'requiresBreakdanceFormsForOxygen' => true,
                    'autoMapping' => false,
                    'requiredContentPaths' => ['content.form.submit_text', 'content.form.success_message', 'content.form.redirect_url'],
                    'cssFallbackCanBeStripped' => false,
                ]
            ),
        ];
    }

    /**
     * @param array<string, mixed> $builderElementCatalog
     * @return array<string, mixed>|null
     */
    private function runtimeElement(string $elementType, array $builderElementCatalog): ?array
    {
        $normalized = '\\' . ltrim($elementType, '\\');
        $catalogs = [
            $builderElementCatalog['essentialElements'] ?? [],
            $builderElementCatalog['oxygenElements'] ?? [],
        ];

        foreach ($catalogs as $catalog) {
            if (!is_array($catalog)) {
                continue;
            }

            foreach ($catalog as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $class = '\\' . ltrim((string) ($entry['class'] ?? ''), '\\');
                if ($class !== $normalized) {
                    continue;
                }

                return [
                    'type' => ltrim($normalized, '\\'),
                    'description' => 'Runtime builder element loaded by Oxygen/Breakdance. OxyAI exposes its contract for hand-authored MCP JSON, but does not claim automatic conversion unless autoMapping is explicitly enabled.',
                    'runtime' => $entry,
                    'nativeDesignBuckets' => [],
                    'nativeCssProperties' => [],
                    'cssFallbackCanBeStripped' => false,
                    'autoMapping' => false,
                    'requiredContentPaths' => $this->dynamicPropertyPaths($entry),
                    'mustRemainClassCss' => [
                        'unknown element-specific design schema',
                        'unsupported media queries',
                        'pseudo selectors',
                        'complex selectors',
                        'unverified property paths',
                    ],
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $runtimeEntry
     * @return array<int, string>
     */
    private function dynamicPropertyPaths(array $runtimeEntry): array
    {
        $paths = [];
        $dynamicPaths = $runtimeEntry['dynamicPropertyPaths'] ?? [];
        if (!is_array($dynamicPaths)) {
            return [];
        }

        foreach ($dynamicPaths as $dynamicPath) {
            if (!is_array($dynamicPath)) {
                continue;
            }

            $path = $dynamicPath['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
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
                'unsupported media queries',
                'pseudo selectors',
                'keyframes and animations',
                'complex selectors',
                'responsive variants outside direct single-class max-width media rules',
                'unknown or unverified Oxygen schema paths',
            ],
        ], $extra);
    }
}
