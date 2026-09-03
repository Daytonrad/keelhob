<?php
/**
 * Encryption at rest for the credentials Claudpress has to store.
 *
 * Claudpress holds other people's API keys: an Anthropic or OpenAI key, a
 * Telegram bot token, a deploy token. Before 1.10.1 every one of those sat in
 * wp_options in clear text, and was additionally printed back into the admin
 * page source on every render.
 *
 * What this buys, and what it does not:
 *
 *   It DOES protect against a database dump - the commonest way credentials
 *   leak from WordPress - because the key lives in wp-config.php, not the
 *   database. A stolen SQL file alone is now useless.
 *
 *   It does NOT protect against an attacker who can already read wp-config.php
 *   or execute PHP on the site. Nothing stored on a machine can be hidden from
 *   someone who controls that machine. That is not a reason to skip it; it is a
 *   reason to be honest about what it is worth.
 *
 * Encryption uses AES-256-GCM, which authenticates as well as encrypts, so a
 * tampered value fails to decrypt rather than silently returning garbage.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Secrets
{
    const PREFIX = 'cpenc:v1:';
    const CIPHER = 'aes-256-gcm';

    /** Settings fields treated as credentials. */
    public static function fields(): array
    {
        return [
            'agent_api_key',
            'image_api_key',
            'voice_api_key',
            'notify_telegram_token',
            'telegram_secret',
            'deploy_target_token',
        ];
    }

    /**
     * Derived from the site's own salts, so it changes if those are rotated -
     * which is the correct behaviour: rotating salts should invalidate stored
     * secrets, not silently keep decrypting them.
     */
    private static function key(): string
    {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '')
            . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '')
            . (defined('AUTH_SALT') ? AUTH_SALT : '');

        if (trim($material) === '') {
            // A site with no salts defined is misconfigured, but refusing to
            // work would be worse than a weak-but-site-specific fallback.
            $material = 'claudpress-no-salts-' . (defined('ABSPATH') ? ABSPATH : __DIR__);
        }
        return hash('sha256', $material, true);
    }

    public static function available(): bool
    {
        return function_exists('openssl_encrypt')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    public static function is_encrypted($value): bool
    {
        return is_string($value) && strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '' || self::is_encrypted($plain) || !self::available()) {
            return $plain;
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            return $plain;
        }
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $stored): string
    {
        if (!self::is_encrypted($stored)) {
            return $stored; // Plain value from before the upgrade.
        }
        if (!self::available()) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv    = substr($raw, 0, 12);
        $tag   = substr($raw, 12, 16);
        $ct    = substr($raw, 28);
        $plain = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? '' : $plain;
    }

    /**
     * What the admin screen shows instead of the key itself.
     *
     * The last four characters survive so someone with several keys can tell
     * which one is installed without revealing anything useful.
     */
    public static function mask(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $dots = str_repeat("\u{2022}", 8);
        return strlen($plain) > 8 ? $dots . substr($plain, -4) : $dots;
    }

    /**
     * True when a posted field is the mask rather than a new value.
     *
     * The form renders the mask, so an unchanged form posts the mask straight
     * back. Without this check, saving any unrelated setting would overwrite
     * every stored key with a row of dots.
     */
    public static function is_mask(string $posted): bool
    {
        return $posted !== '' && strpos($posted, "\u{2022}") !== false;
    }
}
