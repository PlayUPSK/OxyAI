<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Inspirations;

final class SiteInspirationStore
{
    /**
     * @return array<int, array<string, string>>
     */
    public function all(): array
    {
        return [
            [
                'slug' => 'editorial-luxury',
                'name' => 'Editorial Luxury',
                'description' => 'High-end editorial layouts with generous whitespace, refined contrast, and restrained accents.',
                'instructions' => 'Use spacious sections, elegant type scale, narrow accent rules, deliberate asymmetry, and premium restraint. Keep CTAs simple and confident.',
            ],
            [
                'slug' => 'technical-saas',
                'name' => 'Technical SaaS',
                'description' => 'Dense but readable SaaS pages for technical products and developer tools.',
                'instructions' => 'Use crisp grids, code-adjacent details, compact feature cards, clear product hierarchy, and high-contrast documentation-inspired surfaces.',
            ],
            [
                'slug' => 'warm-marketplace',
                'name' => 'Warm Marketplace',
                'description' => 'Human marketplace design with welcoming cards, social proof, and clear conversion paths.',
                'instructions' => 'Use warm neutrals, rounded cards, trust signals, friendly microcopy, strong search/filter affordances, and simple conversion-focused CTAs.',
            ],
            [
                'slug' => 'bold-launch',
                'name' => 'Bold Launch',
                'description' => 'High-energy launch-page treatment with large type, punchy sections, and memorable contrast.',
                'instructions' => 'Use oversized headlines, strong section rhythm, bold CTA blocks, selective gradients or color fields, and concise feature storytelling.',
            ],
            [
                'slug' => 'minimal-product',
                'name' => 'Minimal Product',
                'description' => 'Quiet product pages with precise spacing, simple hierarchy, and polished product framing.',
                'instructions' => 'Use minimal decoration, careful typographic hierarchy, soft surfaces, precise alignment, and clear product screenshots or media placeholders.',
            ],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    public function find(string $slug): ?array
    {
        foreach ($this->all() as $inspiration) {
            if (($inspiration['slug'] ?? '') === $slug) {
                return $inspiration;
            }
        }

        return null;
    }
}
