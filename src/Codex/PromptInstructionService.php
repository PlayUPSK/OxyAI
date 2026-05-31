<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Codex;

use OxyAI\Oxygen\Inspirations\SiteInspirationStore;
use OxyAI\Oxygen\Presets\PresetStore;

final class PromptInstructionService
{
    public function __construct(
        private readonly PresetStore $presets,
        private readonly SiteInspirationStore $inspirations = new SiteInspirationStore(),
        private readonly OxygenElementCapabilityService $elementCapabilities = new OxygenElementCapabilityService()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getInstructions(): array
    {
        return [
            'name' => 'OxyAI Oxygen Codex Instructions',
            'goal' => 'Generate or adapt HTML, CSS, and JavaScript that converts cleanly into native Oxygen 6 builder elements.',
            'rules' => [
                'Return separate html, css, and js strings.',
                'Use one logical HTML root for sections and components.',
                'Prefer semantic HTML and readable class names.',
                'Keep CSS class-based; avoid framework-only directives unless the user asks for them.',
                'Keep JavaScript minimal and progressive.',
                'Do not include PHP, WordPress loops, shortcodes, dynamic bindings, forms, or server-side code unless explicitly supported by the target context.',
                'Preserve stable classes, links, content, and image intent when editing an existing target.',
                'Use direct single-class selectors for base and responsive rules when possible. OxyAI can map supported @media (max-width) direct class rules into Oxygen selector breakpoints.',
                'Use plan_generation when the prompt is short, ambiguous, or when the user asks for a planning/clarification step.',
                'Use triple_shot_generation when the user wants options, variants, or help choosing a creative direction.',
                'Use list_oxygen_element_capabilities before hand-authoring Oxygen JSON or deciding whether a CSS selector can be represented natively.',
                'Use dryRun=true before direct page writes when the user has not explicitly approved applying content.',
                'Prefer append for new sections, replace_node for selected static elements, and replace only when the user explicitly wants to overwrite the page tree.',
                'Always send non-ASCII characters as JSON unicode escapes (for example, \\u00E4; use surrogate pairs for non-BMP characters). Do not send raw UTF-8 bytes in html, css, js, or oxygen fields - downstream storage can double-encode them and corrupt diacritics.',
                'Use the css field as the authoritative fallback for component CSS. OxyAI registers direct class selector styles into the Oxygen selector library for editor visibility and compiled CSS, while complex CSS remains in CssCode.',
                'Only remove source CssCode for element types whose list_oxygen_element_capabilities response has cssFallbackCanBeStripped=true; keep complex selectors, responsive rules, and unverified declarations even when native selector properties are populated.',
                'Treat config/css-mapping/breakdance-coverage-manifest.json as the CSS coverage gate for Breakdance Elements/Forms. A design path is not native-only until it has an explicit manifest rule, JSON-shape test, compiled CSS/render proof, and clean conversion audit.',
                'Before claiming 100% CSS source coverage for Oxygen/Breakdance/Form properties, run or cite tools/css-mapping/real-source-coverage-gate.php and require Merge gate: PASS for both Breakdance Elements + Forms and Oxygen Core.',
                'Keep CSS Code for pseudo selectors, keyframes, complex selectors, unsupported media queries, responsive variants outside direct single-class max-width rules, and any unverified schema path.',
                'For native selector-only layouts, avoid relying on flex-wrap, flex-grow, flex-shrink, flex-basis, or CSS grid unless list_oxygen_element_capabilities reports them as verified for the current site; use percentage widths or keep a CSS fallback.',
                'For MCP page writes, leave registerSelectors enabled unless the user explicitly asks for raw runtime classes only. This registers semantic classes as Oxygen selectors, stores direct class styles on those selectors, and attaches their IDs in meta.classes so they can continue to be selected and managed from the Oxygen editor.',
                'When Breakdance Elements for Oxygen is available, safe static HTML may map to EssentialElements\\Button, EssentialElements\\Heading, EssentialElements\\TextLink, EssentialElements\\Image2, and EssentialElements\\BasicList. Check list_oxygen_element_capabilities for contract status, autoMapping rules, requiredContentPaths, and CSS fallback policy before hand-authoring Oxygen JSON.',
                'A link is auto-mapped to a native EssentialElements\\Button when its class contains btn/button/cta/action or it has a child div/img/svg/i element. The native Button renders its own button atom with the theme default design (e.g. a blue background) that overrides your class CSS, so a custom-styled red link can end up blue inside. To keep a link fully styled by your own class CSS, map it to EssentialElements\\TextLink instead: use a class name without those button keywords and put no element children inside the anchor (render icons via CSS ::before/::after or background-image, not an inline svg child).',
                'When hand-authoring Oxygen JSON, use OxygenElements\\Container as the outer element for top-level sections and page bands. Put EssentialElements\\Columns/Column inside that container for actual row/grid layouts; do not use EssentialElements\\Columns as the section root just to create a page band.',
                'For EssentialElements\\Column alignment, write design.layout.align_items, design.layout.align, and design.layout.vertical_align together. Partial alignment writes can persist in JSON but fail to compile in Breakdance.',
                'Do not rely on container.margin left/right "auto" or layout.justify_content on EssentialElements\\Columns/Column for centering. Use an OxygenElements\\Container wrapper or center the parent Column with the full alignment bundle.',
                'Breakdance Forms for Oxygen can be hand-authored only for explicit FormBuilder/LoginForm/RegisterForm contracts. Do not convert arbitrary form HTML into native forms unless list_oxygen_element_capabilities marks the target contract compatible and the fields/actions are explicit.',
                'Do not hand-author complex EssentialElements such as tabs, accordions, menus, sliders, icons, columns, WooCommerce widgets, or third-party form widgets unless list_oxygen_element_capabilities marks them as supported and you can satisfy their content contract exactly.',
                'After any apply_html_to_oxygen_page or apply_oxygen_json_to_page call, re-fetch get_oxygen_tree and, when possible, verify the page renders before reporting success. Direct writes create a restore backup unless dryRun is true; after applying, verify the new backup appears in list_oxygen_page_backups and capture its id.',
            ],
            'mcpWorkflow' => [
                'Inspect pages with list_oxygen_pages and get_page_context.',
                'Call list_oxygen_element_capabilities when you need to know which Oxygen/Breakdance element styles can be native versus class CSS. Use its breakdanceElementsForOxygen.autoMappedWhenPreferred, breakdanceFormsForOxygen.safeHandAuthoredTargets, cssMappingCoveragePolicy, selectorCompilerSupport, and elements[].requiredContentPaths fields as the source of truth for EssentialElements.',
                'Optionally call list_site_inspirations, plan_generation, or triple_shot_generation before generating.',
                'Generate HTML/CSS/JS, then call preview_conversion or convert_html_to_oxygen.',
                'For user-reviewed insertion, call convert_and_stage_page so the Oxygen sidebar can apply the handoff.',
                'For direct insertion, call apply_html_to_oxygen_page with operation append, replace_node, or replace. OxyAI creates a restore backup automatically.',
                'Use list_oxygen_page_backups and restore_oxygen_page_backup to undo a direct write.',
            ],
            'outputSchema' => [
                'html' => 'string',
                'css' => 'string',
                'js' => 'string',
                'meta' => [
                    'page_type' => 'section|page|subtree',
                    'root_selector' => 'string',
                    'notes' => ['string'],
                ],
            ],
            'presets' => $this->presets->all(),
            'siteInspirations' => $this->inspirations->all(),
            'oxygenElementCapabilities' => $this->elementCapabilities->all(),
        ];
    }
}
