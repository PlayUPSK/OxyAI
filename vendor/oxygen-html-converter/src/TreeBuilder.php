<?php

namespace OxyHtmlConverter;

use OxyHtmlConverter\Report\ConversionReport;
use OxyHtmlConverter\Services\JavaScriptTransformer;
use OxyHtmlConverter\Services\EnvironmentService;
use OxyHtmlConverter\Services\ClassStrategyService;
use OxyHtmlConverter\Services\IconDetector;
use OxyHtmlConverter\Services\InteractionDetector;
use OxyHtmlConverter\Services\TailwindDetector;
use OxyHtmlConverter\Services\TailwindCssFallbackGenerator;
use OxyHtmlConverter\Services\TailwindPropertyMapper;
use OxyHtmlConverter\Services\FrameworkDetector;
use OxyHtmlConverter\Services\CssParser;
use OxyHtmlConverter\Services\AnimationDetector;
use OxyHtmlConverter\Services\ComponentDetector;
use OxyHtmlConverter\Services\DocumentCssExtractor;
use OxyHtmlConverter\Services\HeuristicsService;
use OxyHtmlConverter\Services\HeadAssetExtractor;
use OxyHtmlConverter\Services\HtmlCodeSanitizer;
use OxyHtmlConverter\Services\SelectorMatcher;
use OxyHtmlConverter\Validation\OutputValidator;
use OxyHtmlConverter\ElementTypes;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Builds Oxygen-compatible JSON tree from parsed HTML
 */
class TreeBuilder
{
    private HtmlParser $parser;
    private ElementMapper $mapper;
    private StyleExtractor $styleExtractor;
    private JavaScriptTransformer $jsTransformer;
    private EnvironmentService $environment;
    private ClassStrategyService $classStrategy;
    private IconDetector $iconDetector;
    private InteractionDetector $interactionDetector;
    private TailwindDetector $tailwindDetector;
    private TailwindCssFallbackGenerator $tailwindFallbackGenerator;
    private TailwindPropertyMapper $tailwindPropertyMapper;
    private FrameworkDetector $frameworkDetector;
    private CssParser $cssParser;
    private AnimationDetector $animationDetector;
    private ComponentDetector $componentDetector;
    private HeuristicsService $heuristics;
    private DocumentCssExtractor $documentCssExtractor;
    private HeadAssetExtractor $headAssetExtractor;
    private SelectorMatcher $selectorMatcher;
    private HtmlCodeSanitizer $htmlCodeSanitizer;
    private OutputValidator $validator;
    private ConversionReport $report;

    private bool $validateOutput = false;
    private bool $inlineStyles = true;  // NEW: Force all styles inline instead of CSS Code
    private bool $debugMode = false;     // NEW: Enable debug logging
    private bool $safeMode = false;
    /**
     * When true (the default), CSS rules that are mapped to native Oxygen
     * element properties are ALSO kept in the CssCode element so the
     * authoritative source CSS survives intact. When false, the legacy
     * behavior strips consumed rules from the CssCode element (only safe
     * when the consumer is sure the property mapping will compile to the
     * stylesheet — see Bug 5 in the 2026-05-22 MCP report).
     */
    private bool $preserveStyleBlockCss = true;
    private ?bool $preferEssentialElements = null;
    private int $nodeIdCounter = 1;
    private string $extractedCss = '';
    private array $customClasses = [];
    private array $detectedIconLibraries = [];
    private array $cssRules = [];
    private bool $firstBodyElementProcessed = false;
    private bool $fixedHeaderDetected = false;
    private array $jsPatterns = [];
    private array $consumedCssSelectors = [];
    private array $retainedCssSelectors = [];

    public function __construct()
    {
        $this->parser = new HtmlParser();
        $this->mapper = new ElementMapper();
        $this->styleExtractor = new StyleExtractor();
        $this->jsTransformer = new JavaScriptTransformer();
        $this->report = new ConversionReport();
        $this->environment = new EnvironmentService();
        $this->tailwindDetector = new TailwindDetector();
        $this->tailwindFallbackGenerator = new TailwindCssFallbackGenerator();
        $this->tailwindPropertyMapper = new TailwindPropertyMapper();
        $this->classStrategy = new ClassStrategyService(
            $this->environment,
            $this->report,
            $this->tailwindDetector,
            $this->tailwindPropertyMapper
        );
        $this->iconDetector = new IconDetector();
        $this->frameworkDetector = new FrameworkDetector($this->report);
        $this->interactionDetector = new InteractionDetector($this->frameworkDetector);
        $this->cssParser = new CssParser();
        $this->animationDetector = new AnimationDetector();
        $this->componentDetector = new ComponentDetector($this->report);
        $this->heuristics = new HeuristicsService();
        $this->documentCssExtractor = new DocumentCssExtractor(
            $this->heuristics,
            $this->tailwindDetector,
            $this->tailwindPropertyMapper,
            $this->tailwindFallbackGenerator
        );
        $this->headAssetExtractor = new HeadAssetExtractor(function (): int {
            return $this->generateNodeId();
        });
        $this->selectorMatcher = new SelectorMatcher();
        $this->htmlCodeSanitizer = new HtmlCodeSanitizer();
        $this->validator = new OutputValidator();
    }

    /**
     * Convert HTML string to Oxygen JSON structure
     */
    public function convert(string $html): array
    {
        // Reset state
        $this->nodeIdCounter = 1;
        $this->extractedCss = '';
        $this->customClasses = [];
        $this->detectedIconLibraries = [];
        $this->cssRules = [];
        $this->firstBodyElementProcessed = false;
        $this->fixedHeaderDetected = false;
        $this->jsPatterns = [];
        $this->consumedCssSelectors = [];
        $this->retainedCssSelectors = [];
        $this->report->reset();

        // Configure element mapping mode per conversion.
        // Manual override takes precedence; otherwise resolve from environment setting.
        $preferEssentialElements = $this->preferEssentialElements ?? $this->environment->shouldPreferEssentialElements();
        $this->mapper->setPreferEssentialElements($preferEssentialElements);
        $essentialContracts = $this->environment->getEssentialElementContractStatuses();
        $this->mapper->setEssentialElementCompatibility($essentialContracts);

        // Report compatibility decisions when mapping mode is environment-driven.
        if ($this->preferEssentialElements === null) {
            $mappingMode = $this->environment->getElementMappingMode();
            $essentialPluginActive = $this->environment->isBreakdanceElementsForOxygenActive();

            if ($mappingMode === 'essential' && !$preferEssentialElements) {
                $issues = $essentialPluginActive
                    ? $this->environment->getEssentialButtonContractIssues()
                    : ['Breakdance Elements for Oxygen plugin is not active'];
                $message = 'Essential button mapping was requested, but compatibility contract failed. Falling back to Oxygen mapping.';
                if (!empty($issues)) {
                    $message .= ' Issues: ' . implode('; ', $issues);
                }
                $this->report->addWarning($message);
            } elseif ($mappingMode === 'auto' && $essentialPluginActive && !$preferEssentialElements) {
                $issues = $this->environment->getEssentialButtonContractIssues();
                $message = 'Essential button contract check failed in auto mode. Using Oxygen button mapping.';
                if (!empty($issues)) {
                    $message .= ' Issues: ' . implode('; ', $issues);
                }
                $this->report->addWarning($message);
            } elseif ($preferEssentialElements) {
                $this->report->addInfo('Essential button mapping enabled (contract verified).');
            }
        }

        // Parse HTML
        $root = $this->parser->parse($html);
        if (!$root) {
            return [
                'success' => false,
                'error' => 'Failed to parse HTML',
                'errors' => $this->parser->getErrors(),
            ];
        }

        // Extract custom CSS from <style> tags
        $this->extractedCss = $this->extractStyleTags($this->parser->getDom());

        // Parse extracted CSS rules
        $this->cssRules = $this->cssParser->parse($this->extractedCss);

        // Pre-analyze CSS rules for animation detection
        $this->animationDetector->analyzeCssRules($this->cssRules, $this->extractedCss);

        // Pre-analyze JavaScript for toggle/scroll patterns
        $this->jsPatterns = $this->analyzeJavaScriptPatterns($this->parser->getDom());

        // Get body content
        $bodyNodes = $this->parser->extractBodyContent($root);

        // Analyze for repeated components
        foreach ($bodyNodes as $node) {
            $this->componentDetector->analyze($node);
        }
        $this->componentDetector->reportFindings();

        // Build element tree
        $children = [];
        foreach ($bodyNodes as $node) {
            $element = $this->convertNode($node);
            if ($element !== null) {
                $children[] = $element;
            }
        }

        // If single child, use it as root; otherwise wrap in container
        $rootElement = null;
        if (count($children) === 1) {
            $rootElement = $children[0];
        } elseif (count($children) > 1) {
            $rootElement = [
                'id' => $this->generateNodeId(),
                'data' => [
                    'type' => ElementTypes::CONTAINER,
                    'properties' => [],
                ],
                'children' => $children,
            ];
        }

        if ($rootElement === null) {
            return [
                'success' => false,
                'error' => 'No convertible content found in HTML',
            ];
        }

        // When preserveStyleBlockCss is true (the new default), keep the
        // source CSS intact in the CssCode element even when individual
        // rules were also materialized into native Oxygen properties. This
        // is a defense against Bug 5 in the 2026-05-22 MCP report: element
        // design properties are not always compiled to the generated
        // stylesheet, so stripping the source rule could leave the element
        // entirely unstyled. The selector list is still surfaced in the
        // result for audit (see "redistributedCssSelectors").
        if (!$this->preserveStyleBlockCss) {
            $this->extractedCss = $this->cleanupConsumedCssRules($this->extractedCss);
        }

        // Create CSS Code element if we have extracted CSS
        $cssElement = null;
        if (!empty(trim($this->extractedCss))) {
            $cssElement = $this->createCssCodeElement($this->extractedCss);
        }

        $headLinkElements = [];
        $headScriptElements = [];
        $iconScriptElements = [];

        if (!$this->safeMode) {
            // Detect icon libraries in the HTML
            $this->detectedIconLibraries = $this->iconDetector->detectIconLibraries($this->parser->getDom());

            // Extract <link> tags from <head> (Google Fonts, preconnect, etc.)
            $headLinkElements = $this->extractHeadLinks($this->parser->getDom());

            // Preserve non-icon <script> tags from <head> as raw HTML so execution order remains intact.
            $headScriptElements = $this->extractHeadScripts($this->parser->getDom(), $this->detectedIconLibraries);

            // Create script elements for detected icon libraries
            $iconScriptElements = $this->iconDetector->createIconLibraryElements(
                $this->detectedIconLibraries,
                function() { return $this->generateNodeId(); }
            );
        } else {
            $this->report->addInfo('Safe mode enabled: stripped scripts, event handlers, and external head assets.');
        }

        $result = [
            'success' => true,
            'element' => $rootElement,
            'cssElement' => $cssElement,
            'headLinkElements' => $headLinkElements,
            'headScriptElements' => $headScriptElements,
            'iconScriptElements' => $iconScriptElements,
            'detectedIconLibraries' => $this->detectedIconLibraries,
            'extractedCss' => $this->extractedCss,
            'redistributedCssSelectors' => array_keys($this->consumedCssSelectors),
            'retainedCssSelectors' => array_keys($this->retainedCssSelectors),
            'preserveStyleBlockCss' => $this->preserveStyleBlockCss,
            'customClasses' => array_unique($this->customClasses),
            'stats' => $this->report->toArray(),
        ];

        // Optionally validate output
        if ($this->validateOutput) {
            $this->validator->reset();
            if (!$this->validator->validateConversionResult($result)) {
                $result['validationErrors'] = $this->validator->getErrors();
                $this->report->addWarning('Output validation failed: ' . implode('; ', $this->validator->getErrors()));
            }
            if (!empty($this->validator->getWarnings())) {
                $result['validationWarnings'] = $this->validator->getWarnings();
            }
        }

        $result = apply_filters('oxy_html_converter_conversion_result', $result, $html, $this);
        return $result;
    }


    /**
     * Extract CSS from <style> tags in the entire document
     */
    private function extractStyleTags(\DOMDocument $doc): string
    {
        return $this->documentCssExtractor->extract($doc);
    }

    /**
     * Extract <link> tags from <head> (stylesheets, preconnect, etc.)
     */
    private function extractHeadLinks(\DOMDocument $doc): array
    {
        return $this->headAssetExtractor->extractLinks($doc);
    }

    /**
     * Extract non-icon <script> tags from <head> in source order.
     *
     * These stay as raw HTML Code blocks instead of JavaScript Code so inline
     * setup like tailwind.config is not delayed by Oxygen's DOMContentLoaded wrapper.
     */
    private function extractHeadScripts(\DOMDocument $doc, array $detectedIconLibraries = []): array
    {
        return $this->headAssetExtractor->extractScripts($doc, $detectedIconLibraries);
    }

    /**
     * Create a CSS Code element for extracted styles
     * Now always creates the element (inline styles mode doesn't disable it)
     */
    private function createCssCodeElement(string $css): ?array
    {
        if (empty(trim($css))) {
            return null;
        }

        return [
            'id' => $this->generateNodeId(),
            'data' => [
                'type' => ElementTypes::CSS_CODE,
                'properties' => [
                    'content' => [
                        'content' => [
                            'css_code' => $css,
                        ],
                    ],
                ],
            ],
            'children' => [],
        ];
    }

    /**
     * Convert a DOM node to Oxygen element structure
     */
    private function convertNode(DOMNode $node): ?array
    {
        // Handle text nodes
        if ($node instanceof DOMText) {
            $text = trim($node->textContent);
            if ($text === '') {
                return null;
            }

            $this->report->incrementElementCount();

            return [
                'id' => $this->generateNodeId(),
                'data' => [
                    'type' => ElementTypes::TEXT,
                    'properties' => [
                        'content' => [
                            'content' => [
                                'text' => $text,
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ];
        }

        // Handle element nodes
        if (!($node instanceof DOMElement)) {
            return null;
        }

        // Skip certain elements
        if ($this->parser->shouldSkipNode($node)) {
            return null;
        }

        $tag = strtolower($node->tagName);

        // Handle Script Tags
        if ($tag === 'script') {
            if ($this->safeMode) {
                return null;
            }

            $src = $node->getAttribute('src');
            $scriptContent = $node->textContent;

            // External script -> Use HTML Code to preserve the tag
            if ($src) {
                $element = [
                    'id' => $this->generateNodeId(),
                    'data' => [
                        'type' => ElementTypes::HTML_CODE,
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'html_code' => $node->ownerDocument->saveHTML($node),
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ];
                $this->processClasses($node, $element);
                $this->processId($node, $element);
                $this->interactionDetector->processCustomAttributes($node, $element);
                return $element;
            }

            // Inline script -> Use JavaScript Code
            if (!empty(trim($scriptContent))) {
                $this->report->incrementElementCount();

                // Transform JavaScript to make functions available on window object
                // This is required for Oxygen's interaction system to call them
                $transformedJs = $this->jsTransformer->transformJavaScriptForOxygen($scriptContent);

                // Strip JS patterns that were converted to native Oxygen features
                $hasObserver = strpos($scriptContent, 'IntersectionObserver') !== false
                    && strpos($scriptContent, 'animate-on-scroll') !== false;
                $hasSmoothScroll = $this->jsPatterns['smoothScroll']
                    && strpos($scriptContent, 'scrollIntoView') !== false;
                $transformedJs = $this->jsTransformer->stripConvertedPatterns(
                    $transformedJs,
                    $hasObserver,
                    $hasSmoothScroll,
                    []
                );

                // If JS is empty after cleanup, skip creating the element
                if (empty(trim($transformedJs))) {
                    return null;
                }

                $element = [
                    'id' => $this->generateNodeId(),
                    'data' => [
                        'type' => ElementTypes::JAVASCRIPT_CODE,
                        'properties' => [
                            'content' => [
                                'content' => [
                                    'javascript_code' => $transformedJs,
                                ],
                            ],
                        ],
                    ],
                    'children' => [],
                ];
                $this->processClasses($node, $element);
                $this->processId($node, $element);
                $this->interactionDetector->processCustomAttributes($node, $element);
                return $element;
            }
            return null;
        }

        // Skip <style> tags — all styles are already captured by extractStyleTags()
        // which creates a single combined CSS Code element to avoid duplication
        if ($tag === 'style') {
            return null;
        }

        // Handle Link Tags (External CSS)
        if ($tag === 'link') {
            if ($this->safeMode) {
                return null;
            }

            $element = [
                'id' => $this->generateNodeId(),
                'data' => [
                    'type' => ElementTypes::HTML_CODE,
                    'properties' => [
                        'content' => [
                            'content' => [
                                'html_code' => $node->ownerDocument->saveHTML($node),
                            ],
                        ],
                    ],
                ],
                'children' => [],
            ];
            $this->processClasses($node, $element);
            $this->processId($node, $element);
            $this->interactionDetector->processCustomAttributes($node, $element);
            $this->preserveUnsupportedInlineStyle($node, $element, ElementTypes::HTML_CODE);
            return $element;
        }

        $elementType = $this->mapper->getElementType($tag, $node);

        $this->report->incrementElementCount();

        // Build base element
        $element = [
            'id' => $this->generateNodeId(),
            'data' => [
                'type' => $elementType,
                'properties' => [],
            ],
            'children' => [],
        ];

        // Get element properties from mapper
        $contentProperties = $this->mapper->buildProperties($node);

        // Extract and convert inline style attributes only when inline style mode is enabled.
        $convertedInlineStyles = $this->inlineStyles
            ? $this->styleExtractor->extractAndConvert($node, $elementType)
            : [];
        $styleProperties = $convertedInlineStyles !== []
            ? ['design' => $convertedInlineStyles]
            : [];

        // Merge properties
        $element['data']['properties'] = $this->mergeProperties($contentProperties, $styleProperties);

        if ($this->safeMode && $elementType === ElementTypes::HTML_CODE) {
            if (!$this->sanitizeHtmlCodeElement($element)) {
                return null;
            }
        }

        // Handle tag option
        $tagOption = $this->mapper->getTagOption($tag);
        if ($tagOption) {
            $element['data']['properties']['design'] = $element['data']['properties']['design'] ?? [];
            $element['data']['properties']['design']['tag'] = $tagOption;
            // Oxygen element implementations vary: some read tag from design.tag,
            // others from settings.advanced.tag.
            $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
            $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];
            $element['data']['properties']['settings']['advanced']['tag'] = $tagOption;
        }

        // Apply heuristics (optional template-specific optimizations)
        $this->heuristics->applyStickyNavbar($node, $element);
        $this->heuristics->applyNavLinkWhite($node, $element);
        $this->heuristics->applyRoundedFullCentering($node, $element);
        $this->heuristics->applyButtonCentering($tag, $elementType, $element);

        // Sanitize URLs for Images and Links
        if ($tag === 'img' && isset($element['data']['properties']['content']['image']['url'])) {
            $element['data']['properties']['content']['image']['url'] = $this->sanitizeUrl(
                $element['data']['properties']['content']['image']['url'],
                ['http', 'https', 'data']
            );
        }
        if ($tag === 'a' && isset($element['data']['properties']['content']['content']['url'])) {
            $element['data']['properties']['content']['content']['url'] = $this->sanitizeUrl(
                $element['data']['properties']['content']['content']['url'],
                ['http', 'https', 'mailto', 'tel']
            );
        }
        if (($tag === 'a' || $tag === 'button') && isset($element['data']['properties']['content']['content']['link']['url'])) {
            $element['data']['properties']['content']['content']['link']['url'] = $this->sanitizeUrl(
                $element['data']['properties']['content']['content']['link']['url'],
                ['http', 'https', 'mailto', 'tel']
            );
        }
        if ($tag === 'video' && isset($element['data']['properties']['content']['content']['video_file_url'])) {
            $element['data']['properties']['content']['content']['video_file_url'] = $this->sanitizeUrl(
                $element['data']['properties']['content']['content']['video_file_url'],
                ['http', 'https', 'data']
            );
        }
        if (($element['data']['type'] ?? '') === ElementTypes::ESSENTIAL_BASIC_LIST) {
            $this->sanitizeEssentialBasicListUrls($element);
        }

        // Apply fixed header spacing heuristic (optional)
        $this->heuristics->applyFixedHeaderSpacing(
            $node,
            $element,
            $this->fixedHeaderDetected,
            $this->firstBodyElementProcessed
        );

        // Process CSS classes (settings.advanced.classes)
        $this->processClasses($node, $element);

        // Process HTML ID attribute (settings.advanced.id)
        $this->processId($node, $element);

        // Detect and apply native entrance animations
        $classAttr = $node->getAttribute('class');
        $classNames = $classAttr ? array_filter(array_map('trim', explode(' ', $classAttr))) : [];
        $animationSettings = $this->animationDetector->detectAnimations($node, $classNames, $this->cssRules);
        if ($animationSettings) {
            $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
            $element['data']['properties']['settings']['animations'] = $element['data']['properties']['settings']['animations'] ?? [];
            $element['data']['properties']['settings']['animations']['entrance_animation'] = $animationSettings;

            // Remove consumed animation classes from element
            $consumedClasses = $this->animationDetector->getRemovableConsumedClasses();
            if (!empty($consumedClasses) && isset($element['data']['properties']['settings']['advanced']['classes'])) {
                $element['data']['properties']['settings']['advanced']['classes'] = array_values(
                    array_diff($element['data']['properties']['settings']['advanced']['classes'], $consumedClasses)
                );
            }
        }

        // Apply smooth scroll to anchor links
        if ($this->jsPatterns['smoothScroll'] && $tag === 'a') {
            $href = $node->getAttribute('href');
            if ($href && strpos($href, '#') === 0 && strlen($href) > 1) {
                $scrollInteraction = [
                    'trigger' => 'click',
                    'target' => 'this_element',
                    'actions' => [[
                        'name' => 'scroll_to',
                        'target' => $href,
                        'scroll_behavior' => 'smooth',
                    ]],
                ];
                $this->interactionDetector->applyDetectedInteraction('', $scrollInteraction, $element);
            }
        }

        // Process custom attributes (data-*, aria-*, onclick, etc.)
        $this->interactionDetector->processCustomAttributes($node, $element);
        $this->preserveUnsupportedInlineStyle($node, $element, $elementType);

        // Detect frameworks and add warnings
        $this->frameworkDetector->detect($node);

        // Check for interactive elements and add warnings
        $this->checkForWarnings($node);

        // Special handling for buttons and button-like links: create child Text element
        if ($this->mapper->needsChildTextElement($node)) {
            $textChild = $this->mapper->buildChildTextElement($node, $this->generateNodeId());
            if ($textChild !== null) {
                // Ensure text inside button is centered in Oxygen
                $textChild['data']['properties']['design']['typography'] = $textChild['data']['properties']['design']['typography'] ?? [];
                $textChild['data']['properties']['design']['typography']['text_align'] = [
                    'breakpoint_base' => 'center',
                ];
                $element['children'][] = $textChild;
            }

            // Buttons return early, so CSS rules must be applied before exiting.
            if ($this->inlineStyles) {
                $this->applyCssRules($element, $this->cssRules, $node);
            }

            // Don't process other children for buttons - they're handled as text
            return $element;
        }

        // Convert text-only containers directly to Text to avoid wrapper elements
        // that distort layout for decorative or typographic blocks.
        if ($this->mapper->isContainer($tag, $node)
            && !$this->mapper->shouldKeepInnerHtml($tag)
            && $this->mapper->shouldConvertToText($node)
        ) {
            $element['data']['type'] = ElementTypes::TEXT;
            $element['data']['properties']['content'] = $element['data']['properties']['content'] ?? [];
            $element['data']['properties']['content']['content'] = [
                'text' => $this->mapper->getInnerHtml($node),
            ];
            $element['children'] = [];
        } elseif ($this->mapper->isContainer($tag, $node) && !$this->mapper->shouldKeepInnerHtml($tag)) {
            $children = [];
            foreach ($node->childNodes as $childNode) {
                $childElement = $this->convertNode($childNode);
                if ($childElement !== null) {
                    $children[] = $childElement;
                }
            }
            $element['children'] = $children;

            // If container has no children but has text, convert text content
            if (empty($children) && trim($node->textContent) !== '') {
                // Check if it should be converted to text element
                if ($this->mapper->shouldConvertToText($node)) {
                    $element['data']['type'] = ElementTypes::TEXT;
                    // IMPORTANT: Preserve existing properties (like settings.advanced.classes)
                    // by only setting the content, not replacing the entire properties array
                    if (!isset($element['data']['properties']['content'])) {
                        $element['data']['properties']['content'] = [];
                    }
                    $element['data']['properties']['content']['content'] = [
                        'text' => $this->mapper->getInnerHtml($node),
                    ];
                }
            }
        }



        // Apply CSS rules from style tags if they match this element's ID
        if ($this->inlineStyles) {
            $this->applyCssRules($element, $this->cssRules, $node);
        }

        return $element;
    }

    /**
     * Pre-analyze all JavaScript in the document for toggle/scroll patterns.
     *
     * @return array ['toggles' => [...], 'smoothScroll' => bool]
     */
    private function analyzeJavaScriptPatterns(\DOMDocument $doc): array
    {
        $allJs = '';
        $scriptTags = $doc->getElementsByTagName('script');

        foreach ($scriptTags as $script) {
            if (!$script->getAttribute('src')) {
                $allJs .= $script->textContent . "\n";
            }
        }

        $toggles = $this->interactionDetector->detectTogglePatterns($allJs);
        $smoothScroll = $this->interactionDetector->detectSmoothScrollPattern($allJs);

        if (!empty($toggles)) {
            $this->report->addInfo('Detected ' . count($toggles) . ' toggle interaction(s) from JavaScript — preserving original handlers for frontend parity.');
        }
        if ($smoothScroll) {
            $this->report->addInfo('Detected smooth scroll pattern — converted to native Oxygen scroll_to interactions on anchor links.');
        }

        return [
            'toggles' => $toggles,
            'smoothScroll' => $smoothScroll,
        ];
    }

    /**
     * Apply CSS rules from style tags to an element
     */
    private function applyCssRules(array &$element, array $cssRules, DOMElement $node): void
    {
        if (empty($cssRules)) {
            $this->logDebug('No CSS rules to apply');
            return;
        }

        $elementId = $element['data']['properties']['settings']['advanced']['id'] ?? null;
        $elementClasses = $element['data']['properties']['settings']['advanced']['classes'] ?? [];
        $elementType = $element['data']['type'] ?? 'unknown';

        $this->logDebug(sprintf(
            'Applying CSS rules to element type=%s, id=%s, classes=%s',
            $elementType,
            $elementId ?? 'none',
            implode(',', $elementClasses) ?: 'none'
        ));

        $matchedRules = [];
        $sourceOrder = 0;

        foreach ($cssRules as $rule) {
            $selector = trim($rule['selector']);
            if ($selector === '') {
                continue;
            }

            // Keep state/pseudo selectors in the fallback CSS block.
            // Converting them to native properties merges hover/::before styles
            // into base styles and causes rendering regressions.
            if ($this->selectorContainsPseudo($selector)) {
                continue;
            }

            if (!$this->selectorMatchesElement($selector, $elementClasses, $elementId, $node, $element)) {
                continue;
            }

            $expandedDeclarations = $this->expandShorthandProperties($rule['declarations']);
            $materializedDeclarations = $this->filterNeutralFallbackDeclarations($expandedDeclarations);
            $convertedStyles = $this->styleExtractor->toOxygenProperties(
                $materializedDeclarations,
                $elementType,
                (string) ($rule['breakpoint'] ?? 'breakpoint_base')
            );

            if ($convertedStyles === []) {
                continue;
            }

            $matchedRules[] = [
                'selector' => $selector,
                'specificity' => $this->computeSelectorSpecificity($selector),
                'sourceOrder' => $sourceOrder++,
                'declarations' => $materializedDeclarations,
                'convertedStyles' => $convertedStyles,
            ];
        }

        // CSS cascade: higher specificity wins; ties broken by source order.
        // Sorting ascending so the last applied (highest specificity, latest in
        // source) is the one whose values survive the merges.
        usort($matchedRules, static function (array $a, array $b): int {
            $specificityComparison = self::compareSpecificity($a['specificity'], $b['specificity']);
            if ($specificityComparison !== 0) {
                return $specificityComparison;
            }
            return $a['sourceOrder'] <=> $b['sourceOrder'];
        });

        // Track per-property origin so we can surface conflict warnings to the
        // audit when multiple non-equivalent rules touched the same property.
        $propertyOrigins = [];

        foreach ($matchedRules as $rule) {
            $specificity = self::formatSpecificity($rule['specificity']);

            $this->logDebug(sprintf(
                'Applying styles (specificity=%s, selector=%s): %s',
                $specificity,
                $rule['selector'],
                json_encode($rule['convertedStyles'])
            ));

            $element['data']['properties'] = $this->mergeProperties(
                $element['data']['properties'],
                ['design' => $rule['convertedStyles']]
            );
            $this->attachSelectorDesignMetadata($element, $rule['selector'], $elementClasses, $rule['convertedStyles']);

            if ($this->styleExtractor->supportsDeclarationsFully($rule['declarations'], $elementType)
                && $this->styleExtractor->canStripCssFallbackForElementType($elementType)
            ) {
                $this->consumedCssSelectors[$rule['selector']] = true;
            } elseif ($this->styleExtractor->supportsDeclarationsFully($rule['declarations'], $elementType)) {
                $this->retainedCssSelectors[$rule['selector']] = true;
                unset($this->consumedCssSelectors[$rule['selector']]);
            }

            foreach ($rule['convertedStyles'] as $property => $value) {
                $serializedValue = is_scalar($value) ? (string) $value : json_encode($value);
                $existing = $propertyOrigins[$property] ?? null;
                if ($existing !== null && $existing['value'] !== $serializedValue) {
                    $this->report->addWarning(sprintf(
                        'CSS specificity conflict on element %s for property "%s": "%s" from %s (specificity %s) overrode "%s" from %s (specificity %s). Verify the resolved value matches your intent.',
                        $elementType,
                        $property,
                        $serializedValue,
                        $rule['selector'],
                        $specificity,
                        $existing['value'],
                        $existing['selector'],
                        $existing['specificity']
                    ));
                }
                $propertyOrigins[$property] = [
                    'selector' => $rule['selector'],
                    'specificity' => $specificity,
                    'value' => $serializedValue,
                ];
            }
        }

        $this->logDebug('Total rules matched: ' . count($matchedRules));
    }

    /**
     * Carry direct class selector styles forward to the OxyAI selector
     * registration pass. Oxygen 6 compiles selector-library properties for
     * plain Oxygen elements; it does not reliably compile element-local
     * data.properties.design for those elements.
     *
     * @param array<int, string> $elementClasses
     * @param array<string, mixed> $convertedStyles
     */
    private function attachSelectorDesignMetadata(
        array &$element,
        string $selector,
        array $elementClasses,
        array $convertedStyles
    ): void {
        $className = $this->directClassSelectorName($selector);
        if ($className === null || $convertedStyles === []) {
            return;
        }

        $normalizedElementClasses = array_values(array_filter(array_map(
            static fn ($item): string => is_string($item) ? ltrim(trim($item), '.') : '',
            $elementClasses
        )));

        if (!in_array($className, $normalizedElementClasses, true)) {
            return;
        }

        if (!isset($element['data']['properties']['meta']) || !is_array($element['data']['properties']['meta'])) {
            $element['data']['properties']['meta'] = [];
        }

        $meta = $element['data']['properties']['meta']['_oxyaiSelectorDesign'][$className] ?? [];
        $element['data']['properties']['meta']['_oxyaiSelectorDesign'][$className] = $this->mergeProperties(
            is_array($meta) ? $meta : [],
            $convertedStyles
        );
    }

    private function directClassSelectorName(string $selector): ?string
    {
        $selector = trim($selector);
        if (!preg_match('/^\.(-?[_a-zA-Z]+[_a-zA-Z0-9-]*)$/', $selector, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * CSS specificity per https://www.w3.org/TR/selectors-4/#specificity
     *
     * @return array{a:int,b:int,c:int}
     */
    private function computeSelectorSpecificity(string $selector): array
    {
        $normalized = (string) preg_replace('/\s*[>+~,]\s*/', ' ', $selector);
        $identifier = '(?:-?[_a-zA-Z]|\\\\[0-9a-fA-F]{1,6}\s?|\\\\.)[_a-zA-Z0-9-]*';

        $attributes = preg_match_all('/\[[^\]]+\]/', $normalized);
        $withoutAttributes = (string) preg_replace('/\[[^\]]+\]/', ' ', $normalized);

        $ids = preg_match_all('/#' . $identifier . '/', $withoutAttributes);
        $classes = preg_match_all('/\.' . $identifier . '/', $withoutAttributes);
        $pseudoClasses = preg_match_all('/(?<!:):' . $identifier . '(?:\([^)]*\))?/', $withoutAttributes);
        $pseudoElements = preg_match_all('/::' . $identifier . '/', $withoutAttributes);

        $stripped = $withoutAttributes;
        $stripped = (string) preg_replace('/#' . $identifier . '/', ' ', $stripped);
        $stripped = (string) preg_replace('/\.' . $identifier . '/', ' ', $stripped);
        $stripped = (string) preg_replace('/::?' . $identifier . '(?:\([^)]*\))?/', ' ', $stripped);
        $elements = preg_match_all('/' . $identifier . '/', $stripped);

        $a = (int) $ids;
        $b = (int) ($classes + $attributes + $pseudoClasses);
        $c = (int) ($elements + $pseudoElements);

        return ['a' => $a, 'b' => $b, 'c' => $c];
    }

    /**
     * @param array{a:int,b:int,c:int} $left
     * @param array{a:int,b:int,c:int} $right
     */
    private static function compareSpecificity(array $left, array $right): int
    {
        foreach (['a', 'b', 'c'] as $part) {
            if ($left[$part] !== $right[$part]) {
                return $left[$part] <=> $right[$part];
            }
        }

        return 0;
    }

    /**
     * @param array{a:int,b:int,c:int} $specificity
     */
    private static function formatSpecificity(array $specificity): string
    {
        return sprintf('%d,%d,%d', $specificity['a'], $specificity['b'], $specificity['c']);
    }

    /**
     * Check if a CSS selector matches the current DOM element.
     *
     * Supports simple selectors used by imported templates:
     * - #id, .class, tag, tag.class
     * - descendant selectors (e.g. .nav-links a, footer .footer-col a)
     * - basic attribute selectors (e.g. [data-animate], [id="navbar"])
     */
    private function selectorMatchesElement(
        string $selector,
        array $elementClasses,
        ?string $elementId,
        DOMElement $node,
        array $element
    ): bool
    {
        return $this->selectorMatcher->matchesElement($selector, $elementClasses, $elementId, $node, $element);
    }

    /**
     * Expand shorthand CSS properties into longhand equivalents
     */
    private function expandShorthandProperties(array $declarations): array
    {
        $expanded = [];

        foreach ($declarations as $property => $value) {
            if ($property === 'margin' || $property === 'padding') {
                $sides = $this->styleExtractor->parseShorthandSpacing($value);
                if (!empty($sides)) {
                    $expanded[$property . '-top'] = $sides['top'];
                    $expanded[$property . '-right'] = $sides['right'];
                    $expanded[$property . '-bottom'] = $sides['bottom'];
                    $expanded[$property . '-left'] = $sides['left'];
                } else {
                    $expanded[$property] = $value;
                }
            } elseif ($property === 'border' && preg_match('/^(\S+)\s+(\S+)\s+(.+)$/', $value, $m)) {
                $expanded['border-width'] = $m[1];
                $expanded['border-style'] = $m[2];
                $expanded['border-color'] = $m[3];
            } elseif ($property === 'background' && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|[a-zA-Z]+)$/', trim($value))) {
                $expanded['background-color'] = trim($value);
            } else {
                $expanded[$property] = $value;
            }
        }

        return $expanded;
    }

    /**
     * Remove consumed CSS rules from the raw CSS string
     */
    private function cleanupConsumedCssRules(string $css): string
    {
        if (empty($this->consumedCssSelectors)) {
            return $css;
        }

        foreach (array_keys($this->consumedCssSelectors) as $selector) {
            if (isset($this->retainedCssSelectors[$selector])) {
                continue;
            }

            $css = $this->removeTopLevelCssRule($css, $selector);
        }

        return $css;
    }

    private function removeTopLevelCssRule(string $css, string $selector): string
    {
        $output = '';
        $length = strlen($css);
        $position = 0;
        $depth = 0;

        while ($position < $length) {
            $open = strpos($css, '{', $position);
            if ($open === false) {
                $output .= substr($css, $position);
                break;
            }

            $prefix = substr($css, $position, $open - $position);
            $trimmedSelector = trim($prefix);

            if ($depth === 0 && $trimmedSelector === $selector) {
                $close = $this->findMatchingCssBrace($css, $open);
                if ($close === null) {
                    $output .= substr($css, $position);
                    break;
                }

                $position = $close + 1;
                while ($position < $length && ctype_space($css[$position])) {
                    $position++;
                }
                continue;
            }

            $output .= $prefix . '{';
            $depth++;
            $position = $open + 1;

            while ($position < $length && $depth > 0) {
                $nextOpen = strpos($css, '{', $position);
                $nextClose = strpos($css, '}', $position);

                if ($nextClose === false) {
                    $output .= substr($css, $position);
                    return $output;
                }

                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    $output .= substr($css, $position, $nextOpen - $position + 1);
                    $depth++;
                    $position = $nextOpen + 1;
                    continue;
                }

                $output .= substr($css, $position, $nextClose - $position + 1);
                $depth--;
                $position = $nextClose + 1;
            }
        }

        return $output;
    }

    private function findMatchingCssBrace(string $css, int $openPosition): ?int
    {
        $depth = 0;
        $length = strlen($css);

        for ($i = $openPosition; $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
                continue;
            }

            if ($css[$i] !== '}') {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Skip neutral fallback declarations that are useful in CSS utility rules
     * but should not be materialized into native Oxygen properties.
     */
    private function filterNeutralFallbackDeclarations(array $declarations): array
    {
        $filtered = [];

        foreach ($declarations as $property => $value) {
            if ($property === 'color' && trim((string) $value) === 'inherit') {
                continue;
            }

            $filtered[$property] = $value;
        }

        return $filtered;
    }

    /**
     * Process CSS classes - uses settings.advanced.classes for Oxygen rendering
     *
     * Oxygen reads classes from: node['data']['properties']['settings']['advanced']['classes']
     * Data type must be: string[] (array of class name strings)
     * See: plugin/render/renderer.php getAppliedClassNames()
     */
    private function processClasses(DOMElement $node, array &$element): void
    {
        $classAttr = $node->getAttribute('class');
        if (!$classAttr) {
            return;
        }

        $classNames = array_filter(array_map('trim', explode(' ', $classAttr)));

        if (empty($classNames)) {
            return;
        }

        // Track custom classes for the report and final response
        foreach ($classNames as $className) {
            if (!$this->tailwindDetector->isTailwindClass($className)) {
                $this->customClasses[] = $className;
            }
        }

        // Use strategy service to process classes based on mode
        $this->classStrategy->processClasses($classNames, $element);


    }

    /**
     * Process HTML ID attribute - stores in settings.advanced.id
     *
     * Oxygen reads ID from: node['data']['properties']['settings']['advanced']['id']
     * See: plugin/render/renderer.php getHtmlId()
     */
    private function processId(DOMElement $node, array &$element): void
    {
        $id = $node->getAttribute('id');
        if (!$id) {
            return;
        }

        // Store in settings.advanced.id - this is where Oxygen's renderer reads it
        $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
        $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];
        $element['data']['properties']['settings']['advanced']['id'] = $id;
    }

    /**
     * Check for elements that may need warnings
     */
    private function checkForWarnings(DOMElement $node): void
    {
        // Check for icons
        if ($node->hasAttribute('data-lucide') || $node->hasAttribute('data-feather')) {
            $iconName = $node->getAttribute('data-lucide') ?: $node->getAttribute('data-feather');
            $this->addWarning("Icon element (data-lucide=\"{$iconName}\") detected. Scripts are automatically included, but you may need to adjust the icon size or color manually in Oxygen.");
        }
    }

    /**
     * Add a warning to the conversion stats (deduped)
     */
    private function addWarning(string $warning): void
    {
        $this->report->addWarning($warning);
    }

    /**
     * Merge content and style properties
     */
    private function mergeProperties(array $content, array $styles): array
    {
        return $this->mergeAssociativeProperties($content, $styles);
    }

    private function preserveUnsupportedInlineStyle(\DOMElement $node, array &$element, string $elementType): void
    {
        if (!$this->inlineStyles || !$node->hasAttribute('style')) {
            return;
        }

        $style = trim($node->getAttribute('style'));
        if ($style === '') {
            return;
        }

        $styles = $this->styleExtractor->parseInlineStyles($style);
        if ($this->styleExtractor->supportsDeclarationsFully($styles, $elementType)) {
            return;
        }

        $element['data']['properties']['settings'] = $element['data']['properties']['settings'] ?? [];
        $element['data']['properties']['settings']['advanced'] = $element['data']['properties']['settings']['advanced'] ?? [];
        $element['data']['properties']['settings']['advanced']['attributes'] = $element['data']['properties']['settings']['advanced']['attributes'] ?? [];

        foreach ($element['data']['properties']['settings']['advanced']['attributes'] as $attribute) {
            if (($attribute['name'] ?? null) === 'style') {
                return;
            }
        }

        $element['data']['properties']['settings']['advanced']['attributes'][] = [
            'name' => 'style',
            'value' => $style,
        ];
    }

    /**
     * Merge arrays recursively with override semantics.
     *
     * array_merge_recursive() turns duplicate scalar keys into arrays, which
     * breaks Oxygen properties (e.g. color/background/position become arrays).
     */
    private function mergeAssociativeProperties(array $base, array $override): array
    {
        $merged = $base;

        foreach ($override as $key => $value) {
            if (
                array_key_exists($key, $merged)
                && is_array($merged[$key])
                && is_array($value)
                && $this->isAssocArray($merged[$key])
                && $this->isAssocArray($value)
            ) {
                $merged[$key] = $this->mergeAssociativeProperties($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * True for associative arrays, false for indexed arrays.
     */
    private function isAssocArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Detect pseudo-class / pseudo-element selectors.
     */
    private function selectorContainsPseudo(string $selector): bool
    {
        return $this->selectorMatcher->containsPseudo($selector);
    }

    /**
     * Generate unique node ID
     */
    private function generateNodeId(): int
    {
        return $this->nodeIdCounter++;
    }

    /**
     * Get the parser instance
     */
    public function getParser(): HtmlParser
    {
        return $this->parser;
    }

    /**
     * Get the mapper instance
     */
    public function getMapper(): ElementMapper
    {
        return $this->mapper;
    }

    /**
     * Get the style extractor instance
     */
    public function getStyleExtractor(): StyleExtractor
    {
        return $this->styleExtractor;
    }

    /**
     * Set starting node ID (useful when adding to existing document)
     */
    public function setStartingNodeId(int $id): void
    {
        $this->nodeIdCounter = $id;
    }

    /**
     * Get conversion statistics
     */
    public function getStats(): array
    {
        return $this->report->toArray();
    }

    /**
     * Get the heuristics service for configuration
     */
    public function getHeuristics(): HeuristicsService
    {
        return $this->heuristics;
    }

    /**
     * Enable all heuristics for template-specific conversion
     */
    public function enableAllHeuristics(): void
    {
        $this->heuristics->enableAll();
    }

    /**
     * Disable all heuristics for general-purpose conversion
     */
    public function disableAllHeuristics(): void
    {
        $this->heuristics->disableAll();
    }

    /**
     * Enable output validation
     */
    public function enableValidation(): void
    {
        $this->validateOutput = true;
    }

    /**
     * Disable output validation
     */
    public function disableValidation(): void
    {
        $this->validateOutput = false;
    }

    /**
     * Get the validator instance
     */
    public function getValidator(): OutputValidator
    {
        return $this->validator;
    }

    /**
     * Sanitize HtmlCode payloads in safe mode.
     */
    private function sanitizeHtmlCodeElement(array &$element): bool
    {
        if (!$this->htmlCodeSanitizer->sanitizeElement($element)) {
            $this->report->addWarning('Safe mode removed an HtmlCode block because no safe markup remained.');
            return false;
        }

        return true;
    }

    /**
     * Sanitize local URLs
     */
    private function sanitizeUrl(string $url, array $allowedSchemes = ['http', 'https']): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (strpos($url, 'file://') === 0) {
            // Extract filename or relative path
            $parts = explode('/', str_replace('\\', '/', $url));
            $filename = end($parts);
            return $filename; // Minimal fix, just keep filename
        }

        // Allow local anchors, root-relative paths, and query-relative paths.
        if (preg_match('/^(#|\/|\.\.?\/|\?)/', $url)) {
            return $url;
        }

        // No explicit scheme -> keep as relative URL.
        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $matches)) {
            return $url;
        }

        $scheme = strtolower($matches[1]);
        if (!in_array($scheme, $allowedSchemes, true)) {
            return '#';
        }

        if ($scheme === 'data') {
            // Restrict data URLs to image/video payloads.
            if (preg_match('/^data:(image|video)\/[a-z0-9.+-]+;base64,[a-z0-9+\/=\s]+$/i', $url)) {
                $dataUrl = preg_replace('/\s+/', '', $url);
                return is_string($dataUrl) ? $dataUrl : '#';
            }
            return '#';
        }

        if ($scheme === 'http' || $scheme === 'https') {
            $sanitized = esc_url_raw($url);
            return is_string($sanitized) && $sanitized !== '' ? $sanitized : '#';
        }

        // mailto/tel: strip CRLF to prevent header injection.
        $safeUrl = preg_replace('/[\r\n]+/', '', $url);
        return is_string($safeUrl) ? $safeUrl : '#';
    }

    /**
     * @param array<string, mixed> $element
     */
    private function sanitizeEssentialBasicListUrls(array &$element): void
    {
        if (!isset($element['data']['properties']['content']['content']['items'])
            || !is_array($element['data']['properties']['content']['content']['items'])
        ) {
            return;
        }

        foreach ($element['data']['properties']['content']['content']['items'] as &$item) {
            if (!is_array($item) || !isset($item['link']['url']) || !is_string($item['link']['url'])) {
                continue;
            }

            $item['link']['url'] = $this->sanitizeUrl($item['link']['url'], ['http', 'https', 'mailto', 'tel']);
        }
        unset($item);
    }

    /**
     * Enable inline styles mode - all CSS is applied directly to elements
     * instead of creating CSS Code elements
     */
    public function setInlineStyles(bool $enabled): void
    {
        $this->inlineStyles = $enabled;
    }

    /**
     * Enable safe mode conversion.
     *
     * Safe mode strips script tags, event handlers, and external head/link assets.
     */
    public function setSafeMode(bool $enabled): void
    {
        $this->safeMode = $enabled;
        $this->interactionDetector->setStripEventHandlers($enabled);
    }

    /**
     * Enable debug mode - logs additional information during conversion
     */
    public function setDebugMode(bool $enabled): void
    {
        $this->debugMode = $enabled;
    }

    /**
     * Control whether CSS rules consumed by element-property mapping are also
     * kept in the CssCode element. Default true (preserve). Set false to
     * restore the legacy strip-on-consume behavior.
     */
    public function setPreserveStyleBlockCss(bool $enabled): void
    {
        $this->preserveStyleBlockCss = $enabled;
    }

    /**
     * Override auto element mapping to prefer EssentialElements button output.
     */
    public function setPreferEssentialElements(bool $enabled): void
    {
        $this->preferEssentialElements = $enabled;
        $this->mapper->setPreferEssentialElements($enabled);
    }

    /**
     * Log debug message (if debug mode is enabled)
     */
    private function logDebug(string $message): void
    {
        if ($this->debugMode) {
            $this->report->addInfo('[DEBUG] ' . $message);
        }
    }
}
