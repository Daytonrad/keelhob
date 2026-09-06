<?php
/**
 * The image-generation provider contract.
 *
 * Kept apart from the chat adapters because the job is a different shape: one
 * request in, raw image bytes out, no tool loop and no conversation to own.
 * This file fixes that shape and holds the small registry that turns the
 * owner's `image_provider` setting into an adapter class.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Image-generation providers.
 *
 * Kept separate from the chat adapters above because the job is different: one
 * request in, raw image bytes out. Each adapter builds its vendor's request and
 * digs the bytes out of whatever envelope comes back.
 */
abstract class Claudpress_Image_Provider
{
    abstract public static function id(): string;
    abstract public static function label(): string;
    abstract public static function default_endpoint(): string;
    abstract public static function default_model(): string;
    abstract public static function key_url(): string;
    abstract public static function models_url(): string;
    abstract public static function key_hint(): string;

    /** @return array<int, array{id: string, note: string}> */
    abstract public static function models(): array;

    abstract public static function headers(string $key): array;

    /** Auth headers only, for a plain GET. No Content-Type: there is no body. */
    abstract public static function auth_headers(string $key): array;

    /**
     * A URL that proves the key works WITHOUT generating an image.
     *
     * Generating one to test the connection would bill the owner every time
     * they pressed Test, which is exactly the surprise the budget caps exist to
     * prevent. Asking the vendor to describe the chosen model costs nothing and
     * answers two questions at once: is the key good, and does this model exist
     * for this account.
     */
    abstract public static function verify_url(string $endpoint, string $model): string;

    /**
     * The vendor's own model catalogue, so Refresh can find models newer than
     * this release. Empty string means the vendor publishes no such list.
     */
    public static function models_list_url(string $endpoint): string
    {
        return '';
    }

    /**
     * Pull the models THIS provider can actually use out of that catalogue.
     *
     * Vendors return one flat list of everything the account can touch, with
     * nothing in it that marks a model as an image model. So this matches on
     * names, and a name match is a guess — which is exactly why a refresh only
     * ever adds to the curated list in models() and never replaces it.
     *
     * @return array<int, string>
     */
    public static function parse_models(array $data): array
    {
        return [];
    }

    public static function url(string $endpoint, string $model): string
    {
        return $endpoint;
    }

    abstract public static function body(string $model, string $prompt, string $size): array;

    /**
     * Pull raw image bytes out of a decoded response.
     *
     * @return string|null null when the response carried no image
     */
    abstract public static function extract_bytes(array $data): ?string;

    /** A direct image URL to download instead, when the vendor returns one. */
    public static function extract_url(array $data): ?string
    {
        return null;
    }

    /** @return array<string, class-string<Claudpress_Image_Provider>> */
    public static function providers(): array
    {
        return (array) apply_filters('claudpress_image_providers', [
            'openai' => Claudpress_Image_Provider_OpenAI::class,
            'google' => Claudpress_Image_Provider_Google::class,
        ]);
    }

    public static function get(string $id): string
    {
        $all = self::providers();
        return $all[$id] ?? Claudpress_Image_Provider_OpenAI::class;
    }

    public static function current(): string
    {
        $s = Claudpress::instance()->settings();
        return self::get((string) ($s['image_provider'] ?? 'openai'));
    }
}
