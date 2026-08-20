<?php
/**
 * Licence state for the Claudpress Pro add-on.
 *
 * One question, one answer: may this site use the paid features today. Every
 * other Pro class asks this and nothing else, so the rules live here rather
 * than being re-derived at each call site where they would drift.
 *
 * Read the tiering note in the free core's class-capabilities.php before
 * touching any of this. The licence is a UX gate, not a security boundary — a
 * site owner who wants to flip it can, because the free plugin ships arbitrary
 * PHP execution. What actually separates the tiers is that this file is in a
 * zip a free user never receives, and that the features worth paying for are
 * answered by our server, where a local edit has no vote. So the job here is to
 * keep honest customers out of broken states and to be *generous* when the
 * answer is uncertain — not to catch thieves.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Pro_Licence
{
    /**
     * The stored licence record. It holds a licence key, so it is written with
     * autoload = false: there is no reason for a credential to sit in the
     * options cache of every front-end request where any other plugin can read
     * it back out.
     */
    const OPTION = 'claudpress_pro_licence';

    /**
     * Cached verdict. Stored as 'yes' / 'no' rather than a boolean because
     * get_transient() returns false for "missing", and a false verdict and an
     * absent one are different things we must not confuse.
     */
    const TRANSIENT = 'claudpress_pro_licence_valid';

    /** Long enough that the decision is cheap, short enough that a revocation lands the same day. */
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /**
     * How long a licence that was valid at its last successful check keeps
     * working while our licence server cannot be reached.
     *
     * This is deliberately generous, and it fails OPEN. The failure we are
     * insuring against is ours, not the customer's: our server goes down, their
     * host blocks outbound HTTP, their site is offline over a weekend, a DNS
     * change takes a day to settle. In every one of those cases a paying
     * customer would lose features they have paid for, at a moment they cannot
     * diagnose and we cannot see. Two weeks of grace costs us almost nothing —
     * a genuinely cancelled subscription still lapses, just a fortnight late —
     * and it removes an entire class of support ticket that would otherwise
     * arrive as "your plugin broke my site".
     *
     * An explicit "no" from the server is a different thing entirely and is
     * honoured immediately; see decide(). Grace covers silence, never refusal.
     */
    const GRACE_SECONDS = 14 * DAY_IN_SECONDS;

    /**
     * Whether the paid features may run right now.
     *
     * This is what hooks 'claudpress_is_pro'.
     */
    public static function is_valid(): bool
    {
        /**
         * Force the verdict, cache and all.
         *
         * null means "decide normally"; a boolean short-circuits everything.
         * The test suite and local development both need to sit on either side
         * of the gate without inventing a fake licence record, and a site owner
         * debugging a tier problem needs a one-line way to rule the licence in
         * or out.
         *
         * @param bool|null $override
         */
        $override = apply_filters('claudpress_pro_licence_override', null);
        if ($override !== null) {
            return (bool) $override;
        }

        $cached = get_transient(self::TRANSIENT);
        if ($cached === 'yes' || $cached === 'no') {
            return $cached === 'yes';
        }

        $valid = self::decide(self::record(), time());
        set_transient(self::TRANSIENT, $valid ? 'yes' : 'no', self::CACHE_TTL);

        return $valid;
    }

    /**
     * The whole licence rule, as a pure function.
     *
     * Kept free of options, transients, clocks and HTTP on purpose: this is the
     * part that decides whether someone keeps what they paid for, so it has to
     * be testable exactly, including the grace window, without a network or a
     * database anywhere near it.
     *
     * @param array{key:string,status:string,expires_at:int,last_verified_at:int} $record
     * @param int $now Unix timestamp.
     */
    public static function decide(array $record, int $now): bool
    {
        $key = (string) ($record['key'] ?? '');
        if ($key === '') {
            return false;
        }

        // A definite refusal from the server is honoured at once — no grace.
        // Grace exists to cover silence, not a "no".
        $status = (string) ($record['status'] ?? 'unknown');
        if ($status !== 'valid') {
            return false;
        }

        // Never successfully verified, so there is nothing to be generous about.
        $verified = (int) ($record['last_verified_at'] ?? 0);
        if ($verified <= 0) {
            return false;
        }

        // The subscription's own end date. This is a date the server already
        // told us, so it is honoured even offline: a licence that has run out
        // has run out, and letting the grace window extend it would turn an
        // outage into free Pro.
        $expires = (int) ($record['expires_at'] ?? 0);
        if ($expires > 0 && $now >= $expires) {
            return false;
        }

        // Silence. last_verified_at only moves forward on a *successful* check,
        // so this measures exactly how long we have been unable to confirm.
        if ($now - $verified > self::GRACE_SECONDS) {
            return false;
        }

        return true;
    }

    /**
     * The stored record, normalized so callers never have to guess at types.
     *
     * @return array{key:string,status:string,expires_at:int,last_verified_at:int}
     */
    public static function record(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return [
            'key'              => (string) ($stored['key'] ?? ''),
            'status'           => (string) ($stored['status'] ?? 'unknown'),
            'expires_at'       => (int) ($stored['expires_at'] ?? 0),
            'last_verified_at' => (int) ($stored['last_verified_at'] ?? 0),
        ];
    }

    /**
     * Persist a record and drop the cached verdict.
     *
     * @param array $record
     */
    public static function save(array $record): void
    {
        $clean = [
            'key'              => (string) ($record['key'] ?? ''),
            'status'           => (string) ($record['status'] ?? 'unknown'),
            'expires_at'       => (int) ($record['expires_at'] ?? 0),
            'last_verified_at' => (int) ($record['last_verified_at'] ?? 0),
        ];

        update_option(self::OPTION, $clean, false);
        delete_transient(self::TRANSIENT);
    }

    /**
     * Fold the outcome of a licence check into the stored record.
     *
     * Separated from the network call so the state machine can be tested
     * without one, and so the transport can be replaced later without touching
     * the rules.
     *
     * @param bool|null $valid      true = server said yes, false = server said no,
     *                              null = server could not be reached.
     * @param int       $expires_at Subscription end as a Unix timestamp; 0 when unknown.
     * @param int|null  $now
     */
    public static function apply_server_answer($valid, int $expires_at = 0, $now = null): void
    {
        $now    = $now === null ? time() : (int) $now;
        $record = self::record();

        if ($valid === null) {
            // Unreachable. Change nothing — leaving last_verified_at where it
            // is, is what makes the grace window count down instead of resetting.
            return;
        }

        $record['status']     = $valid ? 'valid' : 'invalid';
        $record['expires_at'] = $expires_at;

        if ($valid) {
            $record['last_verified_at'] = $now;
        }

        self::save($record);
    }

    /**
     * Human-readable state, for the add-on's own surfaces.
     *
     * @return array{valid:bool,status:string,expires_at:int,last_verified_at:int,grace_days:int}
     */
    public static function summary(): array
    {
        $record = self::record();

        return [
            'valid'            => self::is_valid(),
            'status'           => $record['status'],
            'expires_at'       => $record['expires_at'],
            'last_verified_at' => $record['last_verified_at'],
            'grace_days'       => (int) (self::GRACE_SECONDS / DAY_IN_SECONDS),
        ];
    }
}
