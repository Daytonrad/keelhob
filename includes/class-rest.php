<?php
/**
 * Registers the MCP endpoint on the WordPress REST API and bridges HTTP <-> the
 * JSON-RPC MCP server. Endpoint: /wp-json/claudpress/v1/mcp
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Rest
{
    public function hooks(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(CLAUDPRESS_REST_NAMESPACE, '/mcp', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle_post'],
                'permission_callback' => [$this, 'permission'],
            ],
            [
                // MCP clients may open a GET for a server->client SSE stream. We
                // do not push server-initiated messages, so advertise that.
                'methods'             => 'GET',
                'callback'            => [$this, 'handle_get'],
                'permission_callback' => [$this, 'permission'],
            ],
        ]);

        // Lightweight unauthenticated health check for connection debugging.
        register_rest_route(CLAUDPRESS_REST_NAMESPACE, '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'status'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** @return true|WP_Error */
    public function permission()
    {
        return Claudpress_Auth::authenticate();
    }

    public function status(): WP_REST_Response
    {
        return new WP_REST_Response([
            'name'              => 'Claudpress',
            'version'           => CLAUDPRESS_VERSION,
            'abilities_enabled' => Claudpress::instance()->abilities_enabled(),
            'endpoint'          => rest_url(CLAUDPRESS_REST_NAMESPACE . '/mcp'),
            'tool_count'        => count(Claudpress::instance()->registry->names()),
        ], 200);
    }

    public function handle_get(): WP_REST_Response
    {
        $res = new WP_REST_Response(null, 405);
        $res->header('Allow', 'POST');
        return $res;
    }

    public function handle_post(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_body();
        $data = json_decode($body, true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return new WP_REST_Response([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32700, 'message' => 'Parse error'],
            ], 200);
        }

        $server   = new Claudpress_MCP_Server(Claudpress::instance()->registry);
        $is_batch = is_array($data) && !empty($data) && array_keys($data) === range(0, count($data) - 1);
        $messages = $is_batch ? $data : [$data];

        $responses = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $r = $server->handle($msg);
            if ($r !== null) {
                $responses[] = $r;
            }
        }

        // All-notification batch → 202 Accepted, no body.
        if (empty($responses)) {
            return new WP_REST_Response(null, 202);
        }

        $payload  = $is_batch ? $responses : $responses[0];
        $response = new WP_REST_Response($payload, 200);
        $response->header('Content-Type', 'application/json');
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
