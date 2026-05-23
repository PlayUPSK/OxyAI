<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Codex;

final class PageContextService
{
    private const HANDOFF_META_KEY = '_oxyai_codex_handoff';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPages(string $search = ''): array
    {
        $query = new \WP_Query([
            'post_type' => ['page', 'post', 'ct_template'],
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 20,
            's' => $search,
            'fields' => 'ids',
        ]);

        return array_map(fn (int $postId): array => $this->pageSummary($postId), $query->posts);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(int $postId): array
    {
        $post = get_post($postId);
        if (!$post) {
            return [
                'found' => false,
                'message' => 'Page not found.',
            ];
        }

        return [
            'found' => true,
            'page' => $this->pageSummary($postId),
            'contentExcerpt' => wp_strip_all_tags((string) $post->post_content),
            'oxygenMetaKeys' => $this->oxygenMeta($postId),
            'handoff' => $this->getHandoff($postId),
            'instructions' => [
                'Use convert_and_stage_page when the user wants to review/apply from the Oxygen sidebar.',
                'Use apply_html_to_oxygen_page with dryRun=true before direct writes unless the user explicitly approved applying content.',
                'OxyAI creates restore backups for direct writes; use restore_oxygen_page_backup to undo.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function stagePayload(int $postId, array $payload): array
    {
        $post = get_post($postId);
        if (!$post) {
            return [
                'success' => false,
                'message' => 'Page not found.',
            ];
        }

        $record = [
            'id' => wp_generate_uuid4(),
            'createdAt' => gmdate('c'),
            'postId' => $postId,
            'source' => [
                'html' => is_scalar($payload['html'] ?? null) ? (string) $payload['html'] : '',
                'css' => is_scalar($payload['css'] ?? null) ? (string) $payload['css'] : '',
                'js' => is_scalar($payload['js'] ?? null) ? (string) $payload['js'] : '',
            ],
            'prompt' => is_scalar($payload['prompt'] ?? null) ? (string) $payload['prompt'] : '',
            'notes' => is_scalar($payload['notes'] ?? null) ? (string) $payload['notes'] : '',
            'oxygen' => is_array($payload['oxygen'] ?? null) ? $payload['oxygen'] : null,
            'insert' => is_array($payload['insert'] ?? null) ? $payload['insert'] : null,
            'status' => 'pending',
        ];

        update_post_meta($postId, self::HANDOFF_META_KEY, $record);

        return [
            'success' => true,
            'handoff' => $record,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getHandoff(int $postId): ?array
    {
        $handoff = get_post_meta($postId, self::HANDOFF_META_KEY, true);
        return is_array($handoff) ? $handoff : null;
    }

    public function clearHandoff(int $postId): void
    {
        delete_post_meta($postId, self::HANDOFF_META_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageSummary(int $postId): array
    {
        $post = get_post($postId);

        return [
            'id' => $postId,
            'title' => $post ? get_the_title($postId) : '',
            'type' => $post ? $post->post_type : '',
            'status' => $post ? $post->post_status : '',
            'editUrl' => get_edit_post_link($postId, 'raw'),
            'viewUrl' => get_permalink($postId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function oxygenMeta(int $postId): array
    {
        $all = get_post_meta($postId);
        $oxygen = [];
        foreach ($all as $key => $values) {
            if (str_contains((string) $key, 'oxygen') || str_contains((string) $key, 'ct_') || str_contains((string) $key, 'breakdance')) {
                $oxygen[$key] = is_array($values) ? array_map('maybe_unserialize', $values) : $values;
            }
        }

        return $oxygen;
    }
}
