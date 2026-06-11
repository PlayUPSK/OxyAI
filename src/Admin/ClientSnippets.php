<?php

declare(strict_types=1);

namespace OxyAI\Oxygen\Admin;

/**
 * Builds copy-paste MCP connection snippets for a range of AI clients.
 *
 * Templates live in one array so adding a client is a single entry. The token
 * is only interpolated when explicitly revealed; otherwise a placeholder is
 * used so secrets never render server-side.
 */
final class ClientSnippets
{
    public const TOKEN_PLACEHOLDER = '<your-token>';

    /**
     * @return list<array{id: string, label: string, language: string, note: string, template: string}>
     */
    public function templates(): array
    {
        return [
            [
                'id' => 'claude-code',
                'label' => 'Claude Code',
                'language' => 'bash',
                'note' => 'Run in your terminal.',
                'template' => 'claude mcp add --transport http oxyai {{URL}} --header "x-oxyai-token: {{TOKEN}}"',
            ],
            [
                'id' => 'claude-desktop',
                'label' => 'Claude Desktop',
                'language' => 'json',
                'note' => 'Add to claude_desktop_config.json (uses mcp-remote bridge).',
                'template' => <<<'JSON'
{
  "mcpServers": {
    "oxyai": {
      "command": "npx",
      "args": [
        "mcp-remote",
        "{{URL}}",
        "--header",
        "x-oxyai-token: {{TOKEN}}"
      ]
    }
  }
}
JSON,
            ],
            [
                'id' => 'cursor',
                'label' => 'Cursor',
                'language' => 'json',
                'note' => 'Add to ~/.cursor/mcp.json or .cursor/mcp.json in your project.',
                'template' => <<<'JSON'
{
  "mcpServers": {
    "oxyai": {
      "url": "{{URL}}",
      "headers": {
        "x-oxyai-token": "{{TOKEN}}"
      }
    }
  }
}
JSON,
            ],
            [
                'id' => 'vscode',
                'label' => 'VS Code',
                'language' => 'json',
                'note' => 'Add to .vscode/mcp.json.',
                'template' => <<<'JSON'
{
  "servers": {
    "oxyai": {
      "type": "http",
      "url": "{{URL}}",
      "headers": {
        "x-oxyai-token": "{{TOKEN}}"
      }
    }
  }
}
JSON,
            ],
            [
                'id' => 'windsurf',
                'label' => 'Windsurf',
                'language' => 'json',
                'note' => 'Add to ~/.codeium/windsurf/mcp_config.json.',
                'template' => <<<'JSON'
{
  "mcpServers": {
    "oxyai": {
      "serverUrl": "{{URL}}",
      "headers": {
        "x-oxyai-token": "{{TOKEN}}"
      }
    }
  }
}
JSON,
            ],
            [
                'id' => 'codex-cli',
                'label' => 'Codex CLI',
                'language' => 'toml',
                'note' => 'Add to ~/.codex/config.toml.',
                'template' => <<<'TOML'
[mcp_servers.oxyai]
url = "{{URL}}"
http_headers = { "x-oxyai-token" = "{{TOKEN}}" }
TOML,
            ],
            [
                'id' => 'gemini-cli',
                'label' => 'Gemini CLI',
                'language' => 'json',
                'note' => 'Add to ~/.gemini/settings.json.',
                'template' => <<<'JSON'
{
  "mcpServers": {
    "oxyai": {
      "httpUrl": "{{URL}}",
      "headers": {
        "x-oxyai-token": "{{TOKEN}}"
      }
    }
  }
}
JSON,
            ],
        ];
    }

    /**
     * Render every snippet with the URL interpolated and the token replaced by
     * the placeholder (when $token is empty/null) or the real token.
     *
     * @return list<array{id: string, label: string, language: string, note: string, snippet: string}>
     */
    public function render(string $url, ?string $token = null): array
    {
        $tokenValue = ($token === null || $token === '') ? self::TOKEN_PLACEHOLDER : $token;

        $rendered = [];
        foreach ($this->templates() as $template) {
            $snippet = strtr($template['template'], [
                '{{URL}}' => $url,
                '{{TOKEN}}' => $tokenValue,
            ]);
            $rendered[] = [
                'id' => $template['id'],
                'label' => $template['label'],
                'language' => $template['language'],
                'note' => $template['note'],
                'snippet' => $snippet,
            ];
        }

        return $rendered;
    }
}
