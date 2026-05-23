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
                'If styles use media queries, pseudo selectors, keyframes, or complex selectors, keep them in CSS so the converter can preserve them in CSS Code.',
                'Use plan_generation when the prompt is short, ambiguous, or when the user asks for a planning/clarification step.',
                'Use triple_shot_generation when the user wants options, variants, or help choosing a creative direction.',
                'Use list_oxygen_element_capabilities before hand-authoring Oxygen JSON or deciding whether a CSS selector can be represented natively.',
                'Use dryRun=true before direct page writes when the user has not explicitly approved applying content.',
                'Prefer append for new sections, replace_node for selected static elements, and replace only when the user explicitly wants to overwrite the page tree.',
                'Always send non-ASCII characters as JSON unicode escapes (for example, \\u00E4; use surrogate pairs for non-BMP characters). Do not send raw UTF-8 bytes in html, css, js, or oxygen fields - downstream storage can double-encode them and corrupt diacritics.',
                'Use the css field as the authoritative fallback for component CSS. Native Oxygen design properties improve editability only for supported properties. Keep CSS Code for media queries, pseudo selectors, keyframes, complex selectors, responsive variants, and any unverified schema path.',
                'When Breakdance Elements for Oxygen is available, button-like elements may map to EssentialElements\\Button. Check list_oxygen_element_capabilities for current availability and supported styling buckets.',
                'After any apply_html_to_oxygen_page or apply_oxygen_json_to_page call, re-fetch get_oxygen_tree and, when possible, verify the page renders before reporting success. Direct writes create a restore backup unless dryRun is true; after applying, verify the new backup appears in list_oxygen_page_backups and capture its id.',
            ],
            'mcpWorkflow' => [
                'Inspect pages with list_oxygen_pages and get_page_context.',
                'Call list_oxygen_element_capabilities when you need to know which Oxygen/Breakdance element styles can be native versus class CSS.',
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
