<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Ai;

use WP_Error;

final class TripleShotService
{
    public function __construct(private readonly AiGateway $aiGateway)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function generate(array $input)
    {
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            return new WP_Error('oxyai_empty_prompt', __('Prompt is required for Triple Shot.', 'oxyai-oxygen'), ['status' => 400]);
        }

        $directions = [
            [
                'slug' => 'conversion',
                'name' => 'Conversion Focus',
                'instruction' => 'Create the most conversion-focused version with a strong CTA, clear trust points, and direct copy.',
            ],
            [
                'slug' => 'editorial',
                'name' => 'Editorial Focus',
                'instruction' => 'Create a refined editorial version with stronger visual hierarchy, whitespace, and premium restraint.',
            ],
            [
                'slug' => 'product',
                'name' => 'Product Clarity',
                'instruction' => 'Create a product-clarity version with concrete benefits, scannable structure, and practical proof points.',
            ],
        ];

        $variants = [];
        foreach ($directions as $direction) {
            $variantInput = $input;
            $variantInput['prompt'] = $prompt . "\n\nVariant direction: " . $direction['instruction'];
            $variantInput['context'] = array_merge(
                is_array($input['context'] ?? null) ? $input['context'] : [],
                ['tripleShotVariant' => $direction['slug']]
            );

            $source = $this->aiGateway->generate($variantInput);
            if (is_wp_error($source)) {
                return $source;
            }

            $variants[] = [
                'slug' => $direction['slug'],
                'name' => $direction['name'],
                'source' => $source->toArray(),
            ];
        }

        return [
            'success' => true,
            'variants' => $variants,
        ];
    }
}
