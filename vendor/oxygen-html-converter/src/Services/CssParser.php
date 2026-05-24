<?php

namespace OxyHtmlConverter\Services;

/**
 * Parses CSS content into rules and selectors
 */
class CssParser
{
    /**
     * Parse CSS content into an array of rules
     *
     * @param string $css
     * @return array Array of [selector, declarations]
     */
    public function parse(string $css, ?string $breakpoint = null): array
    {
        $rules = [];

        // Remove comments
        $css = preg_replace('!/\*.*?\*/!s', '', $css);

        // Brace-depth-aware parser to handle nested @keyframes, @media, @property blocks
        $len = strlen($css);
        $depth = 0;
        $selector = '';
        $block = '';
        $inString = false;
        $stringChar = '';
        $isAtRule = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $css[$i];

            // Handle string literals (skip braces inside quotes)
            if ($inString) {
                if ($char === $stringChar && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inString = false;
                }
                if ($depth === 1 && !$isAtRule) {
                    $block .= $char;
                } elseif ($depth >= 1 && $isAtRule) {
                    $block .= $char;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
                if ($depth === 1 && !$isAtRule) {
                    $block .= $char;
                } elseif ($depth >= 1 && $isAtRule) {
                    $block .= $char;
                }
                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    // Starting a new top-level block
                    $isAtRule = (strpos(trim($selector), '@') === 0);
                }
                $depth++;
                if ($depth === 1 && !$isAtRule) {
                    // Opening brace for a normal rule — don't add to block
                    continue;
                }
                if ($depth > 1) {
                    $block .= $char;
                }
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    if (!$isAtRule) {
                        // Emit the normal rule
                        $selectors = explode(',', $selector);
                        $declarations = $this->parseDeclarations($block);

                        foreach ($selectors as $sel) {
                            $sel = trim($sel);
                            if ($sel) {
                                $rules[] = [
                                    'selector' => $sel,
                                    'declarations' => $declarations,
                                    'breakpoint' => $breakpoint ?? 'breakpoint_base',
                                ];
                            }
                        }
                    } elseif ($isAtRule) {
                        $mediaBreakpoint = $this->breakpointFromAtRule($selector);
                        if ($mediaBreakpoint !== null) {
                            array_push($rules, ...$this->parse($block, $mediaBreakpoint));
                        }
                    }
                    // Reset for next rule
                    $selector = '';
                    $block = '';
                    $isAtRule = false;
                    continue;
                }
                $block .= $char;
                continue;
            }

            // Accumulate characters
            if ($depth === 0) {
                $selector .= $char;
            } elseif ($depth >= 1) {
                $block .= $char;
            }
        }

        return $rules;
    }

    private function breakpointFromAtRule(string $selector): ?string
    {
        $selector = trim($selector);
        if (!preg_match('/^@media\b/i', $selector)) {
            return null;
        }

        if (!preg_match('/max-width\s*:\s*(\d+(?:\.\d+)?)px/i', $selector, $matches)) {
            return null;
        }

        $maxWidth = (float) $matches[1];
        if ($maxWidth <= 479) {
            return 'breakpoint_phone_portrait';
        }
        if ($maxWidth <= 767) {
            return 'breakpoint_phone_landscape';
        }
        if ($maxWidth <= 1023) {
            return 'breakpoint_tablet_portrait';
        }

        return 'breakpoint_tablet_landscape';
    }

    /**
     * Parse declaration block into key-value pairs
     */
    private function parseDeclarations(string $declarationsRaw): array
    {
        $declarations = [];
        $parts = explode(';', $declarationsRaw);

        foreach ($parts as $part) {
            $part = trim($part);
            if (!$part) {
                continue;
            }

            $bits = explode(':', $part, 2);
            if (count($bits) === 2) {
                $prop = trim($bits[0]);
                $val = trim($bits[1]);

                // Remove !important if present
                $val = trim(str_replace('!important', '', $val));

                if ($prop !== '' && $val !== '') {
                    $declarations[$prop] = $val;
                }
            }
        }

        return $declarations;
    }

    /**
     * Map CSS rules to specific elements based on ID or Class
     * This is a simplified mapping for Oxygen
     */
    public function mapRulesToElements(array $rules, array &$elements): void
    {
        foreach ($rules as $rule) {
            $selector = $rule['selector'];
            $declarations = $rule['declarations'];

            // Simple ID selector mapping: #id
            if (preg_match('/^#([a-zA-Z0-9_\-]+)$/', $selector, $matches)) {
                $id = $matches[1];
                if (isset($elements[$id])) {
                    $elements[$id]['css_rules'] = array_merge($elements[$id]['css_rules'] ?? [], $declarations);
                }
            }
            // Simple class selector mapping: .class
            // Note: In Oxygen, we usually prefer to map these to the element if it's the only one, 
            // or let the ClassStrategyService handle it. For now, we just store it.
        }
    }
}
