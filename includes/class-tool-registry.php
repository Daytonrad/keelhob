<?php
/**
 * Registry of MCP tools. Each tool = name, description, JSON-Schema input, and a
 * PHP callable. The MCP server reads this to answer tools/list and tools/call.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tool_Registry
{
    /** @var array<string, array{description:string,schema:array,callback:callable,annotations:array}> */
    private $tools = [];

    /**
     * @param array $input_schema  JSON Schema for the tool's arguments.
     * @param mixed $callback      fn(array $args): array — return value is the tool result.
     *                             Not type-hinted `callable` on purpose — see below.
     */
    public function add(string $name, string $description, array $input_schema, $callback, array $annotations = []): void
    {
        // A PHP `callable` type hint is validated the instant this method is
        // called — e.g. a `[self::class, 'method']` array is only "callable"
        // if that method already exists. register() runs on every request
        // (plugins_loaded), so one tool whose target method is missing or
        // misspelled would throw an uncaught TypeError on every page load,
        // taking down the entire site rather than just that one tool. This
        // happened in production (2026-08-17): a tool was registered pointing
        // at a callback added in a second, separate deploy step, and the site
        // 500'd on every request in between — including the very requests
        // needed to apply the fix, since fixing it also requires loading this
        // plugin. Failing soft here means a bad registration degrades to "one
        // missing tool", which is recoverable, instead of "the whole site is
        // down and the only way to reach it is a broken plugin".
        if (!is_callable($callback)) {
            error_log(sprintf('[Claudpress] Tool "%s" not registered: its callback is not callable.', $name));
            return;
        }
        if (empty($input_schema['type'])) {
            $input_schema = array_merge(['type' => 'object', 'properties' => (object) [], 'additionalProperties' => true], $input_schema);
        }
        $this->tools[$name] = [
            'description' => $description,
            'schema'      => $input_schema,
            'callback'    => $callback,
            'annotations' => $annotations,
        ];
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * List tool definitions for tools/list. When abilities are disabled we still
     * advertise a single informational tool so the connection verifies cleanly.
     *
     * @return array<int, array>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->tools as $name => $t) {
            $out[] = [
                'name'        => $name,
                'description' => $t['description'],
                'inputSchema' => $t['schema'],
            ] + (empty($t['annotations']) ? [] : ['annotations' => $t['annotations']]);
        }
        return $out;
    }

    /**
     * Execute a tool by name. Returns a normalized array the MCP layer wraps into
     * a CallToolResult. Any thrown error becomes an error result upstream.
     *
     * @throws Exception
     */
    public function call(string $name, array $args): array
    {
        if (!isset($this->tools[$name])) {
            throw new Exception(sprintf('Unknown tool: %s', $name));
        }

        $started = microtime(true);
        $result  = call_user_func($this->tools[$name]['callback'], $args);
        $elapsed = round((microtime(true) - $started) * 1000, 1);

        if (!is_array($result)) {
            $result = ['result' => $result];
        }
        $result['_meta'] = array_merge($result['_meta'] ?? [], ['execution_time_ms' => $elapsed]);

        if (Claudpress::instance()->get('log_calls')) {
            Claudpress::instance()->logger->record($name, $args, $result);
        }

        return $result;
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->tools);
    }
}
