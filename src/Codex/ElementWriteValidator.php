<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Codex;

use WP_Error;

/**
 * Guardrail for registry-aware write operations.
 *
 * The {@see OxygenElementCapabilityService} registry is descriptive: it
 * documents which design buckets, native CSS properties, value shapes, and
 * required content paths each known element type supports. This validator turns
 * that description into an *enforced* contract on the write path
 * (update_node / patch_node / set_node_type / insert_node).
 *
 * Design goals:
 *   - Pure logic (no WordPress calls beyond __()), so it is smoke-testable with
 *     an injected registry map and the same WP stubs used by the tree tools.
 *   - AI-directed error messages that name the correct path/tool, e.g.
 *     "Use design.spacing.padding (object with top/right/bottom/left), not design.padding".
 *   - Known element + clearly-contradictory write -> hard 422 reject (strict)
 *     or a warning (warn mode). Unknown / runtime-only types -> always allowed
 *     with an attached mcpWarning so the registry stays additive, not a wall.
 */
final class ElementWriteValidator
{
    public const MODE_STRICT = 'strict';
    public const MODE_WARN = 'warn';

    /**
     * Content properties that must never receive raw block-level / script HTML.
     * Used by the anti-pattern guard (deliverable #3).
     */
    private const TEXT_CONTENT_PATHS = [
        'data.properties.content.content.text',
        'properties.content.content.text',
        'content.content.text',
    ];

    /** Element types exempt from the raw-HTML anti-pattern guard. */
    private const RAW_HTML_EXEMPT_TYPES = [
        'OxygenElements\\CssCode',
        'OxygenElements\\HtmlCode',
        'OxygenElements\\JavaScriptCode',
        'OxygenElements\\CodeBlock',
        'EssentialElements\\CodeBlock',
    ];

    /** @var array<string, array<string, mixed>> registry keyed by full element type */
    private array $registry;

    private string $mode;

    /**
     * @param array<string, array<string, mixed>>|null $registry
     */
    public function __construct(?array $registry = null, ?string $mode = null)
    {
        $this->registry = $registry ?? (new OxygenElementCapabilityService())->registry();
        $this->mode = $this->resolveMode($mode);
    }

    private function resolveMode(?string $mode): string
    {
        if ($mode === null && function_exists('apply_filters')) {
            $mode = (string) apply_filters('oxyai_oxygen_write_validation_mode', self::MODE_STRICT);
        }
        $mode = strtolower((string) ($mode ?? self::MODE_STRICT));

        return $mode === self::MODE_WARN ? self::MODE_WARN : self::MODE_STRICT;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Validate a write against a known/unknown element type.
     *
     * @param string $elementType full element type the node will have after the write
     * @param array<string, mixed> $set       dot-path => value writes (may be empty)
     * @param array<string, mixed> $nodeAfter the node as it will look after the write (for required-path checks)
     * @param bool $isInsert true for insert_node (enforce required content paths)
     * @return array{warnings: array<int, array<string, string>>}|WP_Error
     *         WP_Error in strict mode on contradiction; otherwise warnings (possibly empty).
     */
    public function validateWrite(string $elementType, array $set, array $nodeAfter, bool $isInsert = false)
    {
        $type = ltrim($elementType, '\\');
        $warnings = [];

        // Anti-pattern guard runs for ALL types (known + unknown), since raw
        // landmark/script HTML in a text property corrupts any element.
        $antiPattern = $this->checkRawHtmlAntiPattern($type, $set, $nodeAfter);
        if ($antiPattern !== null) {
            return $antiPattern;
        }

        $entry = $this->registry[$type] ?? null;
        if ($entry === null) {
            // Unknown / runtime-only element: allow, but flag it so the agent
            // knows the registry could not vet the write.
            $warnings[] = [
                'code' => 'unknown_element_type',
                'severity' => 'warning',
                'message' => sprintf(
                    /* translators: %s: element type */
                    __('"%s" is not in the OxyAI capability registry, so its design/content paths could not be validated. Call list_oxygen_element_capabilities(elementType) to confirm supported paths before relying on this write.', 'oxyai-oxygen'),
                    $type
                ),
            ];

            return ['warnings' => $warnings];
        }

        // Known element: enforce the contract.
        $error = $this->checkDesignPaths($type, $entry, $set);
        if ($error !== null) {
            return $this->fail($error, $warnings);
        }

        if ($isInsert) {
            $error = $this->checkRequiredContentPaths($type, $entry, $nodeAfter);
            if ($error !== null) {
                return $this->fail($error, $warnings);
            }
        }

        return ['warnings' => $warnings];
    }

    /**
     * @param array<int, array<string, string>> $warnings
     * @return array{warnings: array<int, array<string, string>>}|WP_Error
     */
    private function fail(WP_Error $error, array $warnings)
    {
        if ($this->mode === self::MODE_WARN) {
            $warnings[] = [
                'code' => $error->get_error_code(),
                'severity' => 'warning',
                'message' => $error->get_error_message(),
            ];

            return ['warnings' => $warnings];
        }

        return $error;
    }

    /**
     * Reject design.* writes that contradict the element's native capability:
     *   - flat spacing/radius shorthand instead of the structured object path,
     *   - design buckets the element does not support.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $set
     */
    private function checkDesignPaths(string $type, array $entry, array $set): ?WP_Error
    {
        $buckets = is_array($entry['nativeDesignBuckets'] ?? null) ? $entry['nativeDesignBuckets'] : [];

        foreach ($set as $path => $_value) {
            $path = (string) $path;
            $designSeg = $this->designSegments($path);
            if ($designSeg === null) {
                continue; // not a design.* write; content/settings writes are unrestricted
            }

            // Flat spacing/radius shorthand: design.padding / design.margin / design.radius
            $firstFlat = $designSeg[0] ?? '';
            $shorthandFix = $this->shorthandSpacingFix($firstFlat, $type);
            if ($shorthandFix !== null && count($designSeg) <= 2) {
                return new WP_Error('oxyai_write_value_shape_mismatch', $shorthandFix, [
                    'status' => 422,
                    'elementType' => $type,
                    'path' => $path,
                ]);
            }

            // Bucket support: the leading design segment must be a supported
            // bucket OR map to one. Only reject when buckets are declared and
            // the segment is clearly outside them.
            if ($buckets !== [] && !$this->bucketSupported($firstFlat, $buckets)) {
                return new WP_Error('oxyai_write_unsupported_design_bucket', sprintf(
                    /* translators: 1: bucket 2: element type 3: supported buckets */
                    __('Element %2$s does not natively support the "%1$s" design bucket. Supported buckets: %3$s. Keep "%1$s" styling in a class CSS rule or upsert_css_block instead of inline native design.', 'oxyai-oxygen'),
                    $firstFlat,
                    $type,
                    implode(', ', array_map('strval', $buckets))
                ), [
                    'status' => 422,
                    'elementType' => $type,
                    'path' => $path,
                    'supportedBuckets' => array_values($buckets),
                ]);
            }
        }

        return null;
    }

    /**
     * Return the design.* segments after the design root, or null if not a
     * design write. Accepts both "design.x" and "data.design.x" / dot-paths
     * rooted under properties.
     *
     * @return array<int, string>|null
     */
    private function designSegments(string $path): ?array
    {
        $segments = array_values(array_filter(explode('.', $path), static fn ($s): bool => $s !== ''));
        $idx = array_search('design', $segments, true);
        if ($idx === false) {
            return null;
        }

        return array_values(array_slice($segments, $idx + 1));
    }

    private function shorthandSpacingFix(string $segment, string $type): ?string
    {
        $box = $this->boxCategory($type);
        return match ($segment) {
            'padding' => sprintf(
                __('Use design.spacing.padding.breakpoint_base (object with top/right/bottom/left, each {number,unit,style}), not design.padding. For %s, padding lives under %s.spacing.padding.breakpoint_base.', 'oxyai-oxygen'),
                $type,
                $box
            ),
            'margin' => sprintf(
                __('Use design.spacing.margin.breakpoint_base (object with top/right/bottom/left, each {number,unit,style}), not design.margin. For %s, margin lives under %s.spacing.margin.breakpoint_base.', 'oxyai-oxygen'),
                $type,
                $box
            ),
            'radius', 'borderRadius', 'border_radius' => sprintf(
                __('Use design.borders.radius.breakpoint_base (object with all/topLeft/topRight/bottomLeft/bottomRight), not design.%s. For %s, radius lives under %s.borders.radius.breakpoint_base.', 'oxyai-oxygen'),
                $segment,
                $type,
                $box
            ),
            default => null,
        };
    }

    private function boxCategory(string $type): string
    {
        // Buttons are styled under the "button" box category in the registry's
        // nativeValueShapes; everything else uses "container".
        return $type === 'EssentialElements\\Button' ? 'button' : 'container';
    }

    /**
     * @param array<int, string> $buckets
     */
    private function bucketSupported(string $segment, array $buckets): bool
    {
        // Map common design roots onto bucket names.
        $aliases = [
            'spacing' => ['spacing', 'container', 'button', 'image'],
            'borders' => ['borders', 'container', 'button', 'image', 'effects'],
            'typography' => ['typography'],
            'layout' => ['layout', 'layout_v2'],
            'size' => ['size'],
            'position' => ['position'],
            'effects' => ['effects'],
            'overflow' => ['overflow'],
            'background' => ['background', 'container', 'button'],
            'container' => ['container'],
            'button' => ['button'],
            'image' => ['image'],
            'icon' => ['icon'],
            'list' => ['list'],
            'form' => ['form'],
            'text_colors' => ['text_colors', 'typography'],
            'order' => ['order', 'layout'],
        ];

        if (in_array($segment, $buckets, true)) {
            return true;
        }

        foreach ($aliases[$segment] ?? [] as $candidate) {
            if (in_array($candidate, $buckets, true)) {
                return true;
            }
        }

        // Be permissive about design roots we do not specifically model, to
        // avoid false rejects on legitimate-but-unmapped paths.
        return !array_key_exists($segment, $aliases);
    }

    /**
     * On insert, every required content path declared by the registry must be
     * present (non-empty) on the node.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $nodeAfter
     */
    private function checkRequiredContentPaths(string $type, array $entry, array $nodeAfter): ?WP_Error
    {
        $required = is_array($entry['requiredContentPaths'] ?? null) ? $entry['requiredContentPaths'] : [];
        if ($required === []) {
            return null;
        }

        $properties = $nodeAfter['data']['properties'] ?? ($nodeAfter['properties'] ?? null);
        $missing = [];
        foreach ($required as $contentPath) {
            if (!is_string($contentPath) || $contentPath === '') {
                continue;
            }
            // List/array shapes like "content.form.fields[].type" only require
            // the collection root to exist; check the segment before "[]".
            $lookup = $contentPath;
            if (str_contains($contentPath, '[]')) {
                $lookup = substr($contentPath, 0, strpos($contentPath, '[]'));
                $lookup = rtrim($lookup, '.');
            }
            if (!$this->pathHasValue(is_array($properties) ? $properties : [], $lookup)) {
                $missing[] = $contentPath;
            }
        }

        if ($missing === []) {
            return null;
        }

        return new WP_Error('oxyai_write_missing_required_content', sprintf(
            /* translators: 1: element type 2: missing paths */
            __('Cannot insert %1$s: required content path(s) missing or empty: %2$s. Call list_oxygen_element_capabilities("%1$s") and fill content.* before inserting (its exampleNode shows the shape).', 'oxyai-oxygen'),
            $type,
            implode(', ', $missing)
        ), [
            'status' => 422,
            'elementType' => $type,
            'missingPaths' => $missing,
        ]);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function pathHasValue(array $properties, string $path): bool
    {
        $segments = array_values(array_filter(explode('.', $path), static fn ($s): bool => $s !== ''));
        $ref = $properties;
        foreach ($segments as $segment) {
            if (!is_array($ref) || !array_key_exists($segment, $ref)) {
                return false;
            }
            $ref = $ref[$segment];
        }

        if ($ref === null || $ref === '' || $ref === []) {
            return false;
        }

        return true;
    }

    /**
     * Anti-pattern guard (#3): reject raw <style>/<script>/landmark tags placed
     * in a text/heading content property. Routes the agent to
     * apply_html_to_oxygen_page. Inline formatting tags and inline style= are
     * allowed; CssCode/CodeBlock-style elements are exempt.
     *
     * @param array<string, mixed> $set
     * @param array<string, mixed> $nodeAfter
     */
    private function checkRawHtmlAntiPattern(string $type, array $set, array $nodeAfter): ?WP_Error
    {
        if (in_array($type, self::RAW_HTML_EXEMPT_TYPES, true)) {
            return null;
        }

        // Gather candidate text values from both the set map and the resulting node.
        $candidates = [];
        foreach ($set as $path => $value) {
            $normalized = str_replace(['data.', 'properties.'], '', (string) $path);
            if (is_string($value) && ($normalized === 'content.content.text' || str_ends_with($normalized, 'content.content.text'))) {
                $candidates[] = $value;
            }
        }
        $textAfter = $nodeAfter['data']['properties']['content']['content']['text']
            ?? ($nodeAfter['properties']['content']['content']['text'] ?? null);
        if (is_string($textAfter)) {
            $candidates[] = $textAfter;
        }

        foreach ($candidates as $text) {
            $offending = $this->offendingTag($text);
            if ($offending !== null) {
                return new WP_Error('oxyai_write_raw_html_in_text', sprintf(
                    /* translators: 1: tag 2: element type */
                    __('Refusing to put a raw <%1$s> tag inside the text content of %2$s. Block-level, <style>, and <script> markup must be converted, not stored as text. Use apply_html_to_oxygen_page to convert the HTML into proper Oxygen elements. Inline tags (span/a/strong/em/b/i/br) and inline style= are allowed.', 'oxyai-oxygen'),
                    $offending,
                    $type
                ), [
                    'status' => 422,
                    'elementType' => $type,
                    'offendingTag' => $offending,
                    'route' => 'apply_html_to_oxygen_page',
                ]);
            }
        }

        return null;
    }

    /**
     * Return the first forbidden tag name found in a text value, or null.
     */
    private function offendingTag(string $text): ?string
    {
        if (preg_match('/<\s*(style|script|section|header|footer|main|article|nav|aside)\b/i', $text, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }
}
