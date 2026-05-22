<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Ai;

use OxyAI\Oxygen\Inspirations\SiteInspirationStore;
use OxyAI\Oxygen\Presets\PresetStore;
use WP_Error;

final class PlanModeService
{
    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PresetStore $presets,
        private readonly SiteInspirationStore $inspirations
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function plan(array $input)
    {
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            return new WP_Error('oxyai_empty_prompt', __('Prompt is required for Plan Mode.', 'oxyai-oxygen'), ['status' => 400]);
        }

        $providerInput = $input;
        $providerInput['prompt'] = $this->plannerPrompt($prompt, $input);
        $providerInput['context'] = [
            'mode' => 'plan',
            'originalPrompt' => $prompt,
            'expectedJson' => [
                'status' => 'needs_clarification|ready',
                'summary' => 'string',
                'questions' => [
                    [
                        'id' => 'string',
                        'label' => 'string',
                        'why' => 'string',
                        'type' => 'single_choice|multi_choice|text|color',
                        'options' => ['string'],
                        'allowCustom' => true,
                    ],
                ],
                'readyPrompt' => 'string',
            ],
        ];

        $source = $this->aiGateway->generate($providerInput);
        if (is_wp_error($source)) {
            return $source;
        }

        $decoded = json_decode($source->html, true);
        if (!is_array($decoded)) {
            $decoded = $this->fallbackPlan($prompt, $input);
        }

        return [
            'success' => true,
            'plan' => $this->normalizePlan($decoded, $prompt),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function plannerPrompt(string $prompt, array $input): string
    {
        $preset = '';
        $presetSlug = is_string($input['preset'] ?? null) ? (string) $input['preset'] : '';
        if ($presetSlug !== '') {
            $found = $this->presets->find($presetSlug);
            $preset = is_array($found) ? (string) ($found['name'] ?? $presetSlug) : $presetSlug;
        }

        $inspiration = '';
        $inspirationSlug = is_string($input['siteInspiration'] ?? null) ? (string) $input['siteInspiration'] : '';
        if ($inspirationSlug !== '') {
            $found = $this->inspirations->find($inspirationSlug);
            $inspiration = is_array($found) ? (string) ($found['name'] ?? $inspirationSlug) : $inspirationSlug;
        }

        return implode("\n\n", array_filter([
            'You are OxyAI Plan Mode. Ask only the few questions that would materially improve an Oxygen Builder section/page generation.',
            'Return JSON only inside the html field. The JSON must contain status, summary, questions, and readyPrompt.',
            'If the prompt is already detailed, return status "ready", no questions, and a readyPrompt that improves the original prompt.',
            'If details are missing, return status "needs_clarification" and 1-4 questions. Prefer single_choice or multi_choice with 3-5 concrete options. Do not ask implementation questions about HTML/CSS.',
            'Do not ask about colors, fonts, or overall vibe when a preset or site inspiration already provides that direction.',
            $preset !== '' ? 'Active design preset: ' . $preset : '',
            $inspiration !== '' ? 'Active site inspiration: ' . $inspiration : '',
            'Original prompt: ' . $prompt,
        ]));
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function normalizePlan(array $plan, string $prompt): array
    {
        $status = (string) ($plan['status'] ?? 'needs_clarification');
        $status = $status === 'ready' ? 'ready' : 'needs_clarification';

        $questions = [];
        foreach ((array) ($plan['questions'] ?? []) as $question) {
            if (!is_array($question)) {
                continue;
            }

            $label = trim((string) ($question['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $type = (string) ($question['type'] ?? 'single_choice');
            if (!in_array($type, ['single_choice', 'multi_choice', 'text', 'color'], true)) {
                $type = 'single_choice';
            }

            $options = array_values(array_filter(array_map(
                static fn ($option): string => is_scalar($option) ? trim((string) $option) : '',
                (array) ($question['options'] ?? [])
            )));

            if (in_array($type, ['single_choice', 'multi_choice'], true) && count($options) < 2) {
                continue;
            }

            $questions[] = [
                'id' => sanitize_key((string) ($question['id'] ?? 'q_' . (count($questions) + 1))),
                'label' => $label,
                'why' => trim((string) ($question['why'] ?? '')),
                'type' => $type,
                'options' => array_slice($options, 0, 5),
                'allowCustom' => !isset($question['allowCustom']) || (bool) $question['allowCustom'],
            ];
        }

        if ($status === 'needs_clarification' && $questions === []) {
            $questions = $this->fallbackPlan($prompt, [])['questions'];
        }

        return [
            'status' => $status,
            'summary' => trim((string) ($plan['summary'] ?? 'Clarify the design direction before generating.')),
            'questions' => $status === 'ready' ? [] : array_slice($questions, 0, 4),
            'readyPrompt' => trim((string) ($plan['readyPrompt'] ?? $prompt)),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function fallbackPlan(string $prompt, array $input): array
    {
        return [
            'status' => 'needs_clarification',
            'summary' => 'A few choices will make the generated section more specific.',
            'readyPrompt' => $prompt,
            'questions' => [
                [
                    'id' => 'primary_goal',
                    'label' => 'What should this section mainly achieve?',
                    'why' => 'Goal changes layout, copy, and CTA priority.',
                    'type' => 'single_choice',
                    'options' => ['Generate leads', 'Explain a product', 'Build trust', 'Drive a purchase'],
                    'allowCustom' => true,
                ],
                [
                    'id' => 'visual_direction',
                    'label' => 'Which visual direction should it lean toward?',
                    'why' => 'This shapes spacing, typography, and surface treatment.',
                    'type' => 'single_choice',
                    'options' => ['Clean and minimal', 'Premium and editorial', 'Bold and high contrast', 'Warm and approachable'],
                    'allowCustom' => true,
                ],
            ],
        ];
    }
}
