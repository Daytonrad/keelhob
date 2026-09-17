<?php
/**
 * The chat provider contract every LLM adapter implements.
 *
 * The runtime speaks one normalized shape; each adapter translates it to and
 * from that vendor's wire format. Three things differ between vendors, and the
 * base class below fixes the shape of all three:
 *
 *   1. Auth + URL       — header name, and whether the model goes in the path.
 *   2. Request body     — where the system prompt goes, how tools are declared.
 *   3. Response parsing — where the text, the tool calls, and the token counts
 *                         live, and what "the model wants to call a tool" looks
 *                         like.
 *
 * The conversation array itself stays in the provider's own native format for
 * the life of a run — the adapter owns it end to end via assistant_message()
 * and tool_result_message(). That keeps the runtime loop provider-agnostic
 * without a lossy neutral message format in the middle.
 *
 * This file also owns the registry side of the contract: providers(), the
 * settings resolution, the fetched-model cache, and the shared connection
 * test. Adding a vendor means one entry in providers() and one adapter file
 * next to this one.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalized response returned by every adapter's parse():
 *
 *   text        string  concatenated assistant prose for this turn
 *   tool_calls  array   [ ['id'=>string,'name'=>string,'args'=>array], ... ]
 *   wants_tools bool    true when the model is asking to call tools
 *   usage       array   ['input'=>int,'output'=>int]
 *   raw         array   the untouched decoded body (adapters need it to echo
 *                       the assistant turn back verbatim)
 */
abstract class Keelhob_Provider
{
    /** Transient prefix for a fetched model list — one entry per provider id. */
    private const MODELS_CACHE = 'keelhob_models_';

    /** How many models the dropdown offers before it stops being a dropdown. */
    public const MODELS_SHOWN = 10;

    /** Stable identifier stored in settings. */
    abstract public static function id(): string;

    /** Human-readable name for the settings dropdown. */
    abstract public static function label(): string;

    /** Where requests go when the owner has not overridden the endpoint. */
    abstract public static function default_endpoint(): string;

    /** Model used when the owner has not chosen one. */
    abstract public static function default_model(): string;

    /**
     * Known model IDs for the in-admin guide, newest/most capable first.
     *
     * @return array<int, array{id: string, note: string}>
     */
    abstract public static function models(): array;

    /** Where the owner gets an API key. */
    abstract public static function key_url(): string;

    /** Where the vendor publishes its current model IDs. */
    abstract public static function models_url(): string;

    /** What an API key for this vendor typically looks like. */
    abstract public static function key_hint(): string;

    /** Auth + content headers for an HTTP request. */
    abstract public static function headers(string $key): array;

    /** Final request URL. Most vendors ignore $model; Google puts it in the path. */
    public static function url(string $endpoint, string $model): string
    {
        return $endpoint;
    }

    /**
     * Build the request body.
     *
     * @param array $tools    registry tools as ['name','description','schema']
     * @param array $messages conversation in this provider's native format
     */
    abstract public static function body(string $model, string $system, array $tools, array $messages): array;

    /** A plain user turn, in this provider's native format. */
    abstract public static function user_message(string $text): array;

    /** Normalize a successful decoded response. See the class docblock. */
    abstract public static function parse(array $data): array;

    /** The assistant's turn, in native format, to append to the conversation. */
    abstract public static function assistant_message(array $parsed): array;

    /**
     * Tool results to append to the conversation.
     *
     * @param array $results [ ['id'=>string,'name'=>string,'result'=>array], ... ]
     * @return array one or more native messages
     */
    abstract public static function tool_result_message(array $results): array;

    /**
     * A user turn that carries an image alongside its text.
     *
     * Every vendor accepts inline base64, and every vendor spells it
     * differently. The default here is Anthropic's shape; the others override.
     *
     * @param string $mime e.g. image/jpeg — vendors reject an unknown type
     * @param string $b64  raw base64, no data: prefix
     */
    public static function user_image_message(string $text, string $mime, string $b64): array
    {
        return ['role' => 'user', 'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]],
            ['type' => 'text', 'text' => $text],
        ]];
    }

    /**
     * A prior assistant turn replayed from stored chat history.
     *
     * Distinct from assistant_message(), which echoes a live response including
     * its tool_use blocks. Replayed history carries text only: the tool_result
     * blocks that answered those calls are not stored, and a vendor that sees a
     * tool call with no matching result rejects the whole request.
     */
    public static function assistant_text_message(string $text): array
    {
        return ['role' => 'assistant', 'content' => $text];
    }

    /** Extract a human-readable error from a non-2xx decoded body. */
    public static function error_message(array $data): string
    {
        return (string) ($data['error']['message'] ?? '');
    }

    /**
     * Where to ask the vendor which models this key may actually use.
     *
     * Derived from the chat endpoint rather than hardcoded, so an owner pointing
     * Keelhob at a gateway gets that gateway's catalogue rather than the
     * vendor's — a list has to describe the host the requests actually go to.
     * '' means the vendor publishes no such endpoint at all.
     *
     * Not to be confused with models_url(), which is the human-readable docs
     * page linked from the admin guide.
     */
    public static function models_list_url(string $endpoint): string
    {
        return '';
    }

    /**
     * Flatten a vendor's model-list response into plain model IDs, newest first.
     *
     * IDs only, no labels: the dropdown offers exactly the strings a request may
     * carry, so nothing on screen can be mistaken for something typeable.
     *
     * @param array $data decoded 2xx body from models_list_url()
     * @return array<int, string>
     */
    public static function parse_models(array $data): array
    {
        return [];
    }

    // ---------------------------------------------------------------- registry

    /** @return array<string, class-string<Keelhob_Provider>> */
    public static function providers(): array
    {
        return (array) apply_filters('keelhob_llm_providers', [
            'anthropic' => Keelhob_Provider_Anthropic::class,
            'openai'    => Keelhob_Provider_OpenAI::class,
            'google'    => Keelhob_Provider_Google::class,
        ]);
    }

    /** Adapter for an id, falling back to Anthropic for unknown values. */
    public static function get(string $id): string
    {
        $all = self::providers();
        return $all[$id] ?? Keelhob_Provider_Anthropic::class;
    }

    /** The adapter selected in settings. */
    public static function current(): string
    {
        $s = Keelhob::instance()->settings();
        return self::get((string) ($s['agent_provider'] ?? 'anthropic'));
    }

    // ------------------------------------------------------- settings-screen IO

    /**
     * The provider, key, endpoint and model a settings-screen request should use.
     *
     * $override carries what is typed into the form but not yet saved. Testing
     * only the stored values meant that choosing OpenAI, pasting an OpenAI key
     * and pressing Test reported "connected to Anthropic" — a correct answer to
     * a question nobody asked. Blank override fields fall back to storage.
     *
     * @param array $override provider|api_key|endpoint|model, any subset
     * @return array{id: string, class: class-string<Keelhob_Provider>, key: string, endpoint: string, model: string}
     */
    /**
     * The currently configured provider, resolved.
     *
     * resolve() is protected because callers inside this file pass an override
     * from an unsaved form. Everyone else - the add-on, a third-party plugin -
     * just wants to know what this site is set up to talk to, so they get a
     * read-only view of that and no way to smuggle a different key in.
     *
     * @return array{id: string, class: class-string<Keelhob_Provider>, key: string, endpoint: string, model: string}
     */
    public static function configured(): array
    {
        return self::resolve([]);
    }

    protected static function resolve(array $override): array
    {
        $s    = Keelhob::instance()->settings();
        $pick = static function (string $field, $stored) use ($override): string {
            $typed = isset($override[$field]) ? trim((string) $override[$field]) : '';
            return $typed !== '' ? $typed : trim((string) $stored);
        };

        $id  = $pick('provider', $s['agent_provider'] ?? 'anthropic');
        $id  = isset(self::providers()[$id]) ? $id : 'anthropic';
        /** @var class-string<Keelhob_Provider> $cls */
        $cls = self::get($id);

        // Stored key, endpoint and model belong to the stored provider. Falling
        // back to them after the picker has been changed would send an Anthropic
        // credential to OpenAI and report the result as that provider's answer.
        $same = ($id === (string) ($s['agent_provider'] ?? 'anthropic'));

        return [
            'id'       => $id,
            'class'    => $cls,
            'key'      => $pick('api_key', $same ? ($s['agent_api_key'] ?? '') : ''),
            'endpoint' => $pick('endpoint', $same ? ($s['agent_api_endpoint'] ?? '') : '') ?: $cls::default_endpoint(),
            'model'    => $pick('model', $same ? ($s['agent_model'] ?? '') : '') ?: $cls::default_model(),
        ];
    }

    /**
     * Model IDs for the settings dropdown: the vendor's own list once it has been
     * fetched, the built-in fallback until then.
     *
     * Capped, because an account can list a hundred models — most of them years
     * retired — and a dropdown that long is worse than the text box it replaced.
     *
     * @param string $keep A model to keep on the list whatever the vendor says.
     * @return array<int, string>
     */
    public static function cached_models(string $id, string $keep = ''): array
    {
        /** @var class-string<Keelhob_Provider> $cls */
        $cls    = self::get($id);
        $cached = get_transient(self::MODELS_CACHE . $id);
        $out    = (is_array($cached) && $cached) ? array_map('strval', $cached) : [];

        if (!$out) {
            foreach ($cls::models() as $m) {
                $out[] = (string) $m['id'];
            }
        }
        $out = array_slice(array_values(array_unique(array_filter($out))), 0, self::MODELS_SHOWN);

        // Whatever is already saved stays on the list even when the vendor has
        // stopped advertising it. A model silently dropping out of the dropdown
        // would switch the site to a different one on the next save.
        if ($keep !== '' && !in_array($keep, $out, true)) {
            array_unshift($out, $keep);
        }
        return $out;
    }

    /** Has this provider's real list ever been fetched, or is it still the fallback? */
    public static function has_fetched_models(string $id): bool
    {
        return is_array(get_transient(self::MODELS_CACHE . $id));
    }

    /** Drop every cached list, so the next look asks the vendors again. */
    public static function forget_models(): void
    {
        foreach (array_keys(self::providers()) as $id) {
            delete_transient(self::MODELS_CACHE . $id);
        }
    }

    /**
     * Ask the vendor which models this key may actually use, and remember it.
     *
     * A list baked into a release is wrong within months, which is how someone
     * ends up picking a model their account has never had and reading a "model
     * not found" error they cannot act on. This asks the vendor instead.
     *
     * @param array $override see resolve() — lets an unsaved key be used.
     * @return array<int, string>|WP_Error
     */
    public static function fetch_models(array $override = [])
    {
        $r = self::resolve($override);
        /** @var class-string<Keelhob_Provider> $cls */
        $cls = $r['class'];

        if ($r['key'] === '') {
            return new WP_Error('keelhob_no_key', __('Add an API key first, then refresh the model list.', 'keelhob'));
        }

        $url = $cls::models_list_url($r['endpoint']);
        if ($url === '') {
            return new WP_Error(
                'keelhob_no_model_list',
                __('This provider does not publish a model list — type the model name your service uses.', 'keelhob')
            );
        }

        $resp = wp_remote_get($url, ['timeout' => 20, 'headers' => $cls::headers($r['key'])]);
        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);

        if ($code < 200 || $code >= 300) {
            return new WP_Error('keelhob_api', self::http_error($cls, $code, is_array($data) ? $data : []));
        }
        if (!is_array($data)) {
            return new WP_Error('keelhob_parse', __('The model list could not be read.', 'keelhob'));
        }

        $models = $cls::parse_models($data);
        if (!$models) {
            return new WP_Error('keelhob_no_models', __('That provider returned no models this plugin can use.', 'keelhob'));
        }

        // A week, not an hour: vendors add models occasionally, and the Refresh
        // button is there for anyone who cannot wait for the cache to lapse.
        set_transient(self::MODELS_CACHE . $r['id'], $models, WEEK_IN_SECONDS);

        return array_slice($models, 0, self::MODELS_SHOWN);
    }

    /**
     * One cheap round trip that answers "is this key going to work?".
     *
     * Nothing is written: it tests what is on screen, so a key can be checked
     * before it is committed. Without it a bad key surfaces days later, as a
     * scheduled run that quietly did nothing.
     *
     * @param array $override see resolve().
     * @return array{provider: string, label: string, model: string}|WP_Error
     */
    public static function test_connection(array $override = [])
    {
        $r = self::resolve($override);
        /** @var class-string<Keelhob_Provider> $cls */
        $cls = $r['class'];

        if ($r['key'] === '') {
            return new WP_Error('keelhob_no_key', __('Add an API key first, then test it.', 'keelhob'));
        }

        $body = $cls::body($r['model'], 'Reply with the single word OK.', [], [$cls::user_message('Say OK.')]);
        // A ping declares no tools, and both OpenAI and Gemini reject an empty
        // tools array outright — which would report a broken key that is fine.
        unset($body['tools']);

        $resp = wp_remote_post($cls::url($r['endpoint'], $r['model']), [
            'timeout' => 30,
            'headers' => $cls::headers($r['key']),
            'body'    => wp_json_encode($body),
        ]);
        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);

        if ($code < 200 || $code >= 300) {
            return new WP_Error('keelhob_api', self::http_error($cls, $code, is_array($data) ? $data : []));
        }
        if (!is_array($data)) {
            return new WP_Error('keelhob_parse', __('The provider answered with something this plugin could not read.', 'keelhob'));
        }

        return ['provider' => $r['id'], 'label' => $cls::label(), 'model' => $r['model']];
    }

    /**
     * A non-2xx turned into something the owner can act on.
     *
     * "HTTP 401" tells someone staring at a settings screen nothing; "your key
     * was rejected" tells them which box to go and look at. The vendor's own
     * message is preferred where there is one, because it is usually specific.
     *
     * @param class-string<Keelhob_Provider> $cls
     */
    private static function http_error(string $cls, int $code, array $data): string
    {
        if ($code === 401 || $code === 403) {
            return __('Your API key was rejected. Check it above.', 'keelhob');
        }
        $msg = $data ? $cls::error_message($data) : '';
        if ($msg !== '') {
            return $msg;
        }
        if ($code === 404) {
            return __('That address returned nothing. Check the endpoint under Advanced.', 'keelhob');
        }
        if ($code === 429) {
            return __('Your provider is rate-limiting this key. Wait a moment and try again.', 'keelhob');
        }
        /* translators: %d: HTTP status code. */
        return sprintf(__('The provider refused the request (HTTP %d).', 'keelhob'), $code);
    }

    /**
     * JSON Schema accepted by every vendor. Google's function-calling parser
     * rejects the schema vocabulary the others tolerate ($schema, additionalProperties,
     * const, oneOf...), so strip to the common subset rather than maintaining
     * two copies of every tool definition.
     */
    protected static function clean_schema(array $schema): array
    {
        $allowed = ['type', 'description', 'enum', 'items', 'properties', 'required', 'format', 'nullable'];
        $out     = [];

        foreach ($schema as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            if ($k === 'properties' && is_array($v)) {
                $props = [];
                foreach ($v as $name => $prop) {
                    $props[$name] = is_array($prop) ? self::clean_schema($prop) : $prop;
                }
                $out[$k] = $props ?: (object) [];
                continue;
            }
            if ($k === 'items' && is_array($v)) {
                $out[$k] = self::clean_schema($v);
                continue;
            }
            $out[$k] = $v;
        }

        if (($out['type'] ?? '') === 'object' && !isset($out['properties'])) {
            $out['properties'] = (object) [];
        }

        /*
         * GOOGLE ALLOWS enum ONLY ON A STRING.
         *
         * seo_redirect declares `type: integer, enum: [301, 302]`, which
         * Anthropic and OpenAI accept without comment. Google rejects the whole
         * REQUEST with:
         *
         *   Invalid value at 'tools[0].function_declarations[28]
         *   .parameters.properties[3].value.enum[0]' (TYPE_STRING), 301
         *
         * and the whole tool list goes with it — so on Gemini the agent could
         * not answer anything at all, in Telegram or anywhere else. One
         * integer enum in one tool killed every other tool on that provider.
         *
         * Casting the values to strings keeps the choice visible to the model.
         * The site is unaffected: the tool casts its own input with (int), so
         * "301" and 301 arrive at the same place.
         */
        // Scalars only. An enum on an array or object is already meaningless to
        // Google, and rewriting a container's type to "string" would turn one
        // rejected tool into a wrong one.
        if (isset($out['enum']) && is_array($out['enum'])
            && in_array($out['type'] ?? 'string', ['string', 'integer', 'number', 'boolean'], true)) {
            $out['enum'] = array_values(array_map(
                static fn($v): string => is_scalar($v) ? (string) $v : '',
                $out['enum']
            ));
            $out['type'] = 'string';
        }

        return $out;
    }
}
