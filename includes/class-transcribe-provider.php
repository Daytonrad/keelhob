<?php
/**
 * The speech-to-text provider contract, for voice notes sent to the Telegram bot.
 *
 * Separate from the chat adapters for the same reason the image ones are: one
 * request in, a transcript out, no tool loop. This file also holds the registry,
 * the key lookup, and the multipart body builder both adapters share.
 *
 * Anthropic has no audio endpoint, so an owner running Claude as their brain
 * still needs an OpenAI or Google key for voice — which the settings UI says
 * out loud.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Speech-to-text providers, for voice notes sent to the Telegram bot.
 *
 * Separate from the chat adapters for the same reason the image ones are: one
 * request in, a transcript out, no tool loop. Note that Anthropic has no audio
 * endpoint — an owner running Claude as their brain still needs an OpenAI or
 * Google key for voice, which the settings UI says out loud.
 */
abstract class Claudpress_Transcribe_Provider
{
    abstract public static function id(): string;
    abstract public static function label(): string;
    abstract public static function default_endpoint(): string;
    abstract public static function default_model(): string;
    abstract public static function key_url(): string;
    abstract public static function models_url(): string;
    abstract public static function key_hint(): string;

    /**
     * The transcription models this vendor offers, newest and best first.
     *
     * A short curated list, not the vendor's whole catalogue: a chat model that
     * happens to accept audio is not the same as one that transcribes well, and
     * the /models endpoint cannot tell the two apart. Offering the wrong one
     * produces a confident, wrong transcript rather than an error.
     *
     * @return array<int, array{id: string, note: string}>
     */
    abstract public static function models(): array;

    /** Auth headers only, for a plain GET. No Content-Type: there is no body. */
    abstract public static function auth_headers(string $key): array;

    /**
     * A URL that proves the key works WITHOUT transcribing anything.
     *
     * Testing by sending audio would need audio to send and would bill the
     * owner for every press of Test. Asking the vendor to describe the chosen
     * model costs nothing and catches both failures worth catching: a rejected
     * key, and a model this account cannot reach.
     */
    abstract public static function verify_url(string $endpoint, string $model): string;

    /** As on the image family: the vendor's catalogue, for the Refresh button. */
    public static function models_list_url(string $endpoint): string
    {
        return '';
    }

    /**
     * As on the image family: a name match, which is a guess, which is why
     * Claudpress_Media_Check::models() adds to the curated list rather than
     * trusting this to replace it.
     *
     * @return array<int, string>
     */
    public static function parse_models(array $data): array
    {
        return [];
    }

    /**
     * Perform the transcription.
     *
     * @param string $bytes    raw audio
     * @param string $mime     e.g. audio/ogg — Telegram voice notes are OGG/Opus
     * @param string $filename a name with the right extension; some APIs sniff it
     * @return array{success: bool, text?: string, error?: string}
     */
    abstract public static function transcribe(string $key, string $model, string $endpoint, string $bytes, string $mime, string $filename): array;

    /** @return array<string, class-string<Claudpress_Transcribe_Provider>> */
    public static function providers(): array
    {
        return (array) apply_filters('claudpress_transcribe_providers', [
            'openai' => Claudpress_Transcribe_Provider_OpenAI::class,
            'google' => Claudpress_Transcribe_Provider_Google::class,
        ]);
    }

    public static function get(string $id): string
    {
        $all = self::providers();
        return $all[$id] ?? Claudpress_Transcribe_Provider_OpenAI::class;
    }

    public static function current(): string
    {
        $s = Claudpress::instance()->settings();
        return self::get((string) ($s['voice_provider'] ?? 'openai'));
    }

    /**
     * Which key to transcribe with.
     *
     * An owner who already pasted an OpenAI key for the brain, or for image
     * generation, should not have to paste the same key a third time — so a
     * blank voice key falls back to any other key belonging to the same vendor.
     * It only asks for a new one when there genuinely isn't one.
     */
    public static function key(): string
    {
        $s   = Claudpress::instance()->settings();
        $own = trim((string) ($s['voice_api_key'] ?? ''));
        if ($own !== '') {
            return $own;
        }
        $want = (string) ($s['voice_provider'] ?? 'openai');
        if ((string) ($s['agent_provider'] ?? '') === $want && trim((string) ($s['agent_api_key'] ?? '')) !== '') {
            return trim((string) $s['agent_api_key']);
        }
        if ((string) ($s['image_provider'] ?? '') === $want && trim((string) ($s['image_api_key'] ?? '')) !== '') {
            return trim((string) $s['image_api_key']);
        }
        return '';
    }

    /** Build a multipart/form-data body by hand — no curl file handles needed. */
    protected static function multipart(array $fields, string $file_field, string $filename, string $mime, string $bytes): array
    {
        $boundary = '----claudpress' . bin2hex(random_bytes(8));
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
            $body .= $value . "\r\n";
        }
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $file_field . '"; filename="' . $filename . "\"\r\n";
        $body .= 'Content-Type: ' . $mime . "\r\n\r\n";
        $body .= $bytes . "\r\n";
        $body .= "--{$boundary}--\r\n";

        return ['content_type' => 'multipart/form-data; boundary=' . $boundary, 'body' => $body];
    }
}
