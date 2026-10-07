<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What to paste, per AI app, to connect to this site.
 *
 * This exists because the Connect screen used to print one terminal command and
 * one JSON block labelled "Claude Desktop / Cursor / Codex / other clients".
 * That JSON works in none of them as written, and Claude Desktop in particular
 * cannot use it at all: it has no HTTP transport, so it needs a bridge program
 * and Node.js. A user followed that screen, got nothing, and had to be talked
 * through it by hand.
 *
 * Naming an app and then handing over a config it cannot read is worse than
 * saying nothing, because the reader has no reason to doubt it.
 *
 * Every shape below was read from the vendor's own source or docs, not from a
 * blog post:
 *
 *   Claude Code     `claude mcp add --transport http` — verified against the
 *                   CLI's own --help. Writes to ~/.claude.json. The terminal
 *                   and the Claude Code desktop app share that file.
 *   Claude Desktop  no HTTP transport. Bridged through `npx mcp-remote`, which
 *                   needs Node.js, and needs --allow-http for a plain-http site.
 *                   ${VAR} in args IS expanded from env by mcp-remote, which is
 *                   what keeps the token out of the process list.
 *   Cursor          mcpServers + url + headers, NO type key. Their docs show a
 *                   plain http://localhost example, so local sites are fine.
 *   VS Code         servers (not mcpServers) + type:"http" + url + headers.
 *                   Read from mcpConfiguration.ts in the VS Code repo: `url` is
 *                   required, `headers` is a string map, and the url pattern is
 *                   ^https?:// so http is accepted.
 *
 * Pure functions, no WordPress calls, so every shape is asserted in tests
 * without a site. The screen passes in the four facts it knows.
 */

final class Keelhob_Connect
{
    /**
     * Every supported app, in the order the screen shows them.
     *
     * @return array<string, array{label: string, needs_node: bool, file: string[]}>
     */
    public static function clients(): array
    {
        return [
            'claude-code' => [
                'label'      => 'Claude Code',
                'needs_node' => false,
                'file'       => [],
            ],
            'claude-desktop' => [
                'label'      => 'Claude Desktop',
                'needs_node' => true,
                'file'       => [
                    'Windows (Microsoft Store): %LOCALAPPDATA%\\Packages\\Claude_*\\LocalCache\\Roaming\\Claude\\claude_desktop_config.json',
                    'Windows (normal install): %APPDATA%\\Claude\\claude_desktop_config.json',
                    'macOS: ~/Library/Application Support/Claude/claude_desktop_config.json',
                ],
            ],
            'cursor' => [
                'label'      => 'Cursor',
                'needs_node' => false,
                'file'       => [
                    'All projects: ~/.cursor/mcp.json',
                    'One project: .cursor/mcp.json',
                ],
            ],
            'vscode' => [
                'label'      => 'VS Code',
                'needs_node' => false,
                'file'       => [
                    'In your project: .vscode/mcp.json',
                ],
            ],
        ];
    }

    /**
     * The thing to copy, for one app.
     *
     * @param string $client   key from clients()
     * @param string $name     MCP server name, e.g. "keelhob-my-shop"
     * @param string $endpoint full MCP URL
     * @param string $token    bearer token, or a placeholder when not flashed
     * @param bool   $is_http  true when the site is plain http (local dev)
     */
    public static function config(string $client, string $name, string $endpoint, string $token, bool $is_http): string
    {
        switch ($client) {
            case 'claude-code':
                // -s user, not the default local scope. Without it the server is
                // saved against the folder the terminal happened to be in, so
                // starting Claude anywhere else shows no site and no error.
                return sprintf(
                    'claude mcp add %s --transport http %s --header "Authorization: Bearer %s" -s user',
                    $name,
                    $endpoint,
                    $token
                );

            case 'claude-desktop':
                $args = ['-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${AUTH_HEADER}'];
                // Only on a plain-http site. Printing this for an https site
                // would be telling someone to weaken a connection that is
                // already fine.
                if ($is_http) {
                    $args[] = '--allow-http';
                }
                return self::json([
                    'mcpServers' => [
                        $name => [
                            'command' => 'npx',
                            'args'    => $args,
                            // The token goes in env, never in args: anything in
                            // args is visible to every other user on the machine
                            // in the process list. mcp-remote expands ${VAR}
                            // from the environment, which is what makes this work.
                            'env'     => ['AUTH_HEADER' => 'Bearer ' . $token],
                        ],
                    ],
                ]);

            case 'cursor':
                return self::json([
                    'mcpServers' => [
                        $name => [
                            'url'     => $endpoint,
                            'headers' => ['Authorization' => 'Bearer ' . $token],
                        ],
                    ],
                ]);

            case 'vscode':
                return self::json([
                    'servers' => [
                        $name => [
                            'type'    => 'http',
                            'url'     => $endpoint,
                            'headers' => ['Authorization' => 'Bearer ' . $token],
                        ],
                    ],
                ]);
        }

        return '';
    }

    /**
     * One line saying what the reader needs before they start.
     *
     * Short on purpose. The Connect screen is already long, and a requirement
     * nobody reads is the same as no requirement.
     */
    public static function requirement(string $client, bool $is_http): string
    {
        $notes = [];

        if (!empty(self::clients()[$client]['needs_node'])) {
            $notes[] = __('Needs Node.js installed. Claude Desktop cannot reach a web address on its own, so a small bridge runs it.', 'keelhob');
        }

        if ($is_http && $client === 'claude-desktop') {
            $notes[] = __('Your site is not on HTTPS, so --allow-http is included below. It is already there — do not remove it.', 'keelhob');
        }

        if ($client === 'claude-code') {
            $notes[] = __('Works in the terminal and in the Claude Code desktop app — they share one settings file. Quit and reopen the app afterwards.', 'keelhob');
        }

        return implode(' ', $notes);
    }

    /**
     * Warnings that apply to a site on your own computer.
     *
     * Returned rather than printed so a test can assert they appear for a local
     * site and stay out of the way for a real one. Nobody has to tick a box:
     * the plugin already knows its own address.
     *
     * @return string[]
     */
    public static function local_site_notes(bool $is_http, string $host): array
    {
        if (!$is_http && !self::looks_local($host)) {
            return [];
        }

        return [
            __('Leave SSL switched OFF in your local site tool. A local certificate is self-signed, and the connection is rejected before it starts.', 'keelhob'),
            __('Telegram, email alerts and scheduled tasks need a public address, so they will not work here.', 'keelhob'),
        ];
    }

    /** A hostname that is on this machine rather than the internet. */
    public static function looks_local(string $host): bool
    {
        $host = strtolower($host);
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }
        foreach (['.local', '.test', '.localhost', '.lndo.site', '.ddev.site'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /** Pretty JSON that stays readable when pasted: slashes unescaped. */
    private static function json(array $data): string
    {
        return (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
