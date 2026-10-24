<?php
/**
 * Registry of MCP tools. Each tool = name, description, JSON-Schema input, and a
 * PHP callable. The MCP server reads this to answer tools/list and tools/call.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Tool_Registry
{
    /** @var array<string, array{description:string,schema:array,callback:callable,annotations:array}> */
    private $tools = [];

    /**
     * Skills loaded through skill_get during this PHP request, in order.
     *
     * Static rather than per-instance because the thing being measured is the
     * request, not the registry: an unattended run builds its own registry and
     * the answer to "what did that run load" must not depend on how many were
     * built along the way.
     *
     * @var string[]
     */
    private static $skills_loaded = [];

    /**
     * Where the calls in this request are coming from: 'chat' for an
     * interactive MCP client, or the agent run's own context ('task',
     * 'telegram', 'run_agent', 'manual') while an unattended run is in flight.
     *
     * Static and request-scoped, which is right for both paths: over MCP every
     * tool call is its own HTTP request so the default is always correct, and
     * an agent run holds one request for its whole loop.
     *
     * @var string
     */
    private static $source = 'chat';

    /** @var int the agent run row these calls belong to, 0 when interactive */
    private static $agent_run_id = 0;

    /**
     * Declare that the calls that follow belong to an unattended run.
     *
     * Keelhob_Agent_Runtime calls this before its loop. Nothing else should:
     * the whole point of recording a source is that "the owner asked for this
     * in a chat" and "the site did this to itself at 4am" are different facts
     * about the same tool call.
     */
    public static function set_source(string $source, int $agent_run_id = 0): void
    {
        self::$source       = substr($source, 0, 32) ?: 'chat';
        self::$agent_run_id = max(0, $agent_run_id);
    }

    public static function source(): string
    {
        return self::$source;
    }

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
            error_log(sprintf('[Keelhob] Tool "%s" not registered: its callback is not callable.', $name));
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
                'description' => self::decorate_description($name, $t['description']),
                'inputSchema' => $t['schema'],
            ] + (empty($t['annotations']) ? [] : ['annotations' => $t['annotations']]);
        }
        return $out;
    }

    /**
     * The tool's own description, plus what this site has learned about it.
     *
     * This is the point of the whole ratings/lessons track. Keelhob has
     * memory_save/memory_get, but memory is a separate store the agent has to
     * remember to read; a lesson stapled here is read on every tools/list
     * whether the agent went looking or not.
     *
     * Order is deliberate: the base description first, so a client that
     * truncates long descriptions keeps the part that says what the tool does;
     * then the lessons; then the rating last, because it is the least
     * actionable of the three.
     *
     * Both halves return '' when there is nothing to add, so an untouched site
     * — and the unit suite, whose $wpdb returns no rows — sees descriptions
     * byte-for-byte as they were written.
     */
    public static function decorate_description(string $name, string $base): string
    {
        $parts = [$base];

        $lessons = Keelhob_Lessons::description_block($name);
        if ($lessons !== '') {
            $parts[] = $lessons;
        }

        $rating = Keelhob_Tool_Stats::description_line($name);
        if ($rating !== '') {
            $parts[] = $rating;
        }

        return implode("\n\n", $parts);
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
            // A tool that was never registered is almost always a capability
            // the owner switched off, or one the plugin simply does not have.
            // That is the single most useful thing this project could know and
            // it is free to observe here, so it is not thrown away with the
            // exception.
            Keelhob_Feedback::observe_unknown_tool($name);
            throw new Exception(sprintf('Unknown tool: %s', $name));
        }

        // A receipt, taken here because this is the one place every tool call
        // passes through. Rule 13 asks the agent to name the skills it used,
        // and an agent's own account of what it did is the least reliable
        // evidence there is — it can honestly believe it followed a playbook it
        // never loaded. This is the record that does not depend on it: the run
        // report can say "loaded web-design-master, declared verifier
        // pre-launch-audit never ran", which is the difference between knowing
        // why a page shipped badly and guessing.
        if ($name === 'skill_get' && !empty($args['name'])) {
            self::$skills_loaded[] = (string) $args['name'];

            // And durably, because the static above does not survive the
            // request. Over MCP every tool call is its own HTTP request, so
            // $skills_loaded only ever holds the one skill loaded in the same
            // request, and only Agent_Runs::finish() ever reads it - which
            // runs for run_agent and scheduled tasks and nothing else.
            //
            // keelhob.com was built entirely over interactive MCP: 0 agent
            // runs, 10 snippets, and no record anywhere of a single skill
            // being loaded. Rule 13 asks the agent to name the skills it used,
            // and for the way most work actually happens there was nothing to
            // check that answer against.
            Keelhob_Skills::record_load((string) $args['name']);
        }

        $started = microtime(true);
        $result  = call_user_func($this->tools[$name]['callback'], $args);
        $elapsed = round((microtime(true) - $started) * 1000, 1);

        if (!is_array($result)) {
            $result = ['result' => $result];
        }
        $result['_meta'] = array_merge($result['_meta'] ?? [], ['execution_time_ms' => $elapsed]);

        $ok    = !(isset($result['success']) && $result['success'] === false);
        $error = (string) ($result['error'] ?? '');

        if (Keelhob::instance()->get('log_calls')) {
            $run_id = Keelhob::instance()->logger->record($name, $args, $result, [
                'source'       => self::$source,
                'duration_ms'  => (int) round($elapsed),
                'agent_run_id' => self::$agent_run_id,
            ]);
            // Handed back so the owner can thumb THIS call rather than the
            // tool in general, and so a client that shows the run id can link
            // straight to the row on the Activity screen.
            if ($run_id > 0) {
                $result['_meta']['run_id'] = $run_id;
            }
        }

        /*
         * The rating tally, kept separately from the log on purpose.
         *
         * It is updated even when log_calls is off: the log is a record of
         * arguments, which an owner may well not want kept, whereas this is
         * four integers with no payload at all and it is what the agent reads
         * to know whether a tool works here. Switching off the audit trail
         * should not also blind the agent.
         */
        Keelhob_Tool_Stats::observe($name, $ok, $error);

        // Friction, captured from the same choke point and for the same reason
        // the receipt above is: an agent's own account of what went badly is
        // the least reliable evidence there is. This costs no tokens, carries
        // no conversation content, and cannot be skipped by an agent that does
        // not feel like cooperating. It records nothing at all when the owner
        // has the setting off.
        Keelhob_Feedback::observe_call($name, $args, $result);

        return $result;
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Which skills were loaded, de-duplicated, in the order first seen.
     *
     * @return string[]
     */
    public static function skills_loaded(): array
    {
        return array_values(array_unique(self::$skills_loaded));
    }

    /** Start a fresh receipt. An unattended run calls this before it begins. */
    public static function reset_skills_loaded(): void
    {
        self::$skills_loaded = [];
        Keelhob_Feedback::reset_repeats();
    }
}
