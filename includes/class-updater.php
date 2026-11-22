<?php
/**
 * Updates from GitHub releases, and the one number the project runs on.
 *
 * WordPress.org may never list this plugin — it executes arbitrary PHP and SQL,
 * which is the whole point of it and also exactly what the directory declines.
 * So updates come from the project's own releases, through the normal WordPress
 * update screen, with no third-party library involved.
 *
 * The same request carries a check-in, because a site asking whether there is a
 * newer version is by definition a live site. That is how wordpress.org derives
 * its own "active installs" figure, and it is the only honest way to count
 * without asking every owner to opt into telemetry — an opt-in counter reports
 * a fraction of reality and then gets quoted as if it were the whole.
 *
 * WHAT IS SENT, in full:
 *
 *   site_id  a random UUID made once at activation. NOT derived from the URL,
 *            the domain, the admin email or anything else, so it cannot be
 *            turned back into a site. Deleting and reinstalling the plugin
 *            makes a new one; an undercount is the correct trade for that.
 *   version  which release is running
 *   wp, php  which versions still need supporting
 *
 * There is no site URL, no domain, no email, no user data, no tool usage and no
 * content. The receiving endpoint does not log IP addresses.
 *
 * And the opt-out costs nothing: switch it off and the updater talks straight
 * to GitHub instead, so you still get updates. An opt-out that quietly stops
 * your security updates is not an opt-out, it is a punishment.
 *
 * The same reasoning applies when the counting endpoint itself is down: it
 * falls through to GitHub rather than reporting no update. A counter must
 * never be able to leave a site unpatched.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Updater
{
    /** Answers with the latest release, and records the check-in. */
    const CHECKIN_ENDPOINT = 'https://api.keelhob.com/v1/version';

    /** The same answer, without the check-in, for anyone who opted out. */
    const DIRECT_ENDPOINT = 'https://api.github.com/repos/calflint/keelhob/releases/latest';

    const SLUG      = 'keelhob';
    const TRANSIENT = 'keelhob_update_check';

    /** How long an answer is cached. Twice a day is what WordPress asks anyway. */
    const TTL = 43200;

    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject']);
        add_filter('plugins_api', [__CLASS__, 'details'], 20, 3);
    }

    /**
     * This install's anonymous id.
     *
     * Made once, stored locally, never derived from anything identifying. It
     * exists so two check-ins from the same site are not counted twice, and for
     * nothing else.
     */
    public static function site_id(): string
    {
        $id = get_option('keelhob_site_id', '');
        if (!is_string($id) || strlen($id) !== 36) {
            $id = wp_generate_uuid4();
            update_option('keelhob_site_id', $id, false);
        }
        return $id;
    }

    /**
     * What the check-in carries.
     *
     * The rule every field here obeys: it must be true of a THOUSAND sites, or
     * it does not go. Nothing here names a site, a person, a domain, a key, a
     * post, a product or a price. Read the whole list out loud to a stranger
     * and none of it tells them whose install it is.
     *
     * Why widen it at all. The endpoint already counted installs and nothing
     * else, which answers "how many" and no other question — not whether anyone
     * comes back after week one, not which of the eight risky capability groups
     * people actually turn on, not whether the PHP floor can move. A free
     * project with no analytics still has to decide what to build next, and the
     * alternative to a handful of anonymous integers is guessing.
     *
     * Why not an analytics vendor instead. PRIVACY.md promises there is no
     * analytics service, no session recording and no third-party script in the
     * admin, and this plugin already has more access to a site than almost
     * anything else installed on it. That promise is worth more than a funnel
     * chart. This request already exists, is already documented, and already
     * has an off switch — widening it costs the owner nothing new.
     *
     * Deliberately absent: cost and token totals (that is the owner's spend,
     * and none of our business), anything from the content of the site, and
     * anything a support conversation would need — because the moment a field
     * is useful for identifying one install it stops being a count.
     *
     * Split out from latest() so it can be asserted on directly. What leaves
     * somebody else's server is exactly the kind of thing that should be
     * readable in one place and pinned by a test.
     *
     * @return array<string, string|int>
     */
    public static function checkin_fields(): array
    {
        $fields = [
            'site_id' => self::site_id(),
            'version' => KEELHOB_VERSION,
            'wp'      => get_bloginfo('version'),
            'php'     => PHP_VERSION,
        ];

        // The stack, for deciding what the plugin still has to support. The PHP
        // floor is 8.0 and moving it is guesswork without knowing who is on it.
        $fields['locale']    = (string) get_locale();
        $fields['multisite'] = is_multisite() ? 1 : 0;
        $fields['woo']       = class_exists('WooCommerce') ? 1 : 0;

        if (class_exists('Keelhob')) {
            $builder = (string) Keelhob::instance()->get('builder', 'auto');
            $fields['builder'] = $builder !== '' ? $builder : 'auto';
            $fields['ai']      = Keelhob::instance()->abilities_enabled() ? 1 : 0;
        }

        // Which capability groups are switched ON. Eight of them ship off by
        // default because they are risky, and how many owners deliberately
        // enable each one is the difference between "nobody wanted this" and
        // "the switch is too hard to find". Group keys only — never the tools
        // inside them, and never whether a provider key is present.
        if (class_exists('Keelhob_Capabilities')) {
            // is_group_enabled() rather than diffing against disabled_groups():
            // that method returns rows describing each disabled group for the
            // agent to read, not a list of keys, so diffing keys against it
            // compared strings with arrays and quietly produced the wrong list.
            $on = [];
            foreach (array_keys(Keelhob_Capabilities::groups()) as $key) {
                if (Keelhob_Capabilities::is_group_enabled((string) $key)) {
                    $on[] = (string) $key;
                }
            }
            sort($on);
            $fields['caps'] = implode(',', $on);
        }

        // Is anyone actually using it. A count of unattended runs in the last
        // day, already stored on the site for the owner's own reporting, so
        // this adds no new record of anything. Never the cost or the tokens.
        if (class_exists('Keelhob_Agent_Runs')) {
            $usage = Keelhob_Agent_Runs::usage_since(24);
            $fields['runs'] = (int) ($usage['runs'] ?? 0);
        }

        return $fields;
    }

    /** Whether this site joins the public count. On unless the owner says no. */
    public static function counts_itself(): bool
    {
        $settings = class_exists('Keelhob') ? (array) Keelhob::instance()->settings() : [];
        return empty($settings['skip_checkin']);
    }

    /**
     * Ask what the latest release is.
     *
     * Failure is always silent. A site that cannot reach the internet must not
     * get a broken update screen over it, and it must certainly not get one
     * over a counter.
     *
     * @return array{version:string, url:string, package:string}|null
     */
    public static function latest(bool $force = false): ?array
    {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT);
            if (is_array($cached)) {
                return $cached ?: null;
            }
        }

        $out = null;

        if (self::counts_itself()) {
            $out = self::ask(add_query_arg(self::checkin_fields(), self::CHECKIN_ENDPOINT));
        }

        // If the counting endpoint is unreachable, not yet built, or answers
        // with something unreadable, fall through to GitHub.
        //
        // The count is a convenience for the project. The update is what the
        // site owner is owed, and it may include a security fix. Letting the
        // first quietly cost the second would be the worst bug in this file:
        // silent by design, and invisible until somebody is unpatched.
        if ($out === null) {
            $out = self::ask(self::DIRECT_ENDPOINT);
        }

        if ($out === null) {
            // Cache the failure briefly so a dead network does not mean a
            // request on every single admin page load.
            set_transient(self::TRANSIENT, [], 900);
            return null;
        }

        set_transient(self::TRANSIENT, $out, self::TTL);
        return $out;
    }

    /**
     * One request, read into our shape, or null if it did not work.
     *
     * Null covers every failure the same way — no network, a non-200, an
     * unparseable body, a 200 that carries no release. The caller only needs
     * to know whether to try somewhere else.
     *
     * @return array{version:string, url:string, package:string}|null
     */
    private static function ask(string $url): ?array
    {
        $response = wp_remote_get($url, [
            'timeout'     => 10,
            'redirection' => 5,
            'headers'     => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        return self::parse(is_array($body) ? $body : []);
    }

    /**
     * Read either answer shape into one.
     *
     * Our endpoint answers {version, package, url}. GitHub answers
     * {tag_name, assets:[{name, browser_download_url}], html_url}. Both are
     * handled here so the opt-out path is not a second, less-tested code path.
     *
     * @param array<string,mixed> $body
     * @return array{version:string, url:string, package:string}|null
     */
    public static function parse(array $body): ?array
    {
        $version = (string) ($body['version'] ?? $body['tag_name'] ?? '');
        $version = ltrim($version, 'vV');
        if ($version === '') {
            return null;
        }

        $package = (string) ($body['package'] ?? '');
        if ($package === '' && !empty($body['assets']) && is_array($body['assets'])) {
            foreach ($body['assets'] as $asset) {
                if (substr((string) ($asset['name'] ?? ''), -4) === '.zip') {
                    $package = (string) ($asset['browser_download_url'] ?? '');
                    break;
                }
            }
        }
        if ($package === '') {
            return null;
        }

        return [
            'version' => $version,
            'url'     => (string) ($body['url'] ?? $body['html_url'] ?? 'https://github.com/calflint/keelhob'),
            'package' => $package,
        ];
    }

    /** Put the update on the normal Plugins screen. */
    public static function inject($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $latest = self::latest();

        // Unreachable is not the same as up to date. A site with no network
        // knows nothing about its update state and must be told nothing, in
        // either direction.
        if (!$latest) {
            return $transient;
        }

        $newer = version_compare($latest['version'], KEELHOB_VERSION, '>');

        $row = (object) [
            'slug'        => self::SLUG,
            'plugin'      => KEELHOB_BASENAME,
            'new_version' => $newer ? $latest['version'] : KEELHOB_VERSION,
            'url'         => $latest['url'],
            'package'     => $latest['package'],
            // Without these the Updates screen shows the grey puzzle-piece
            // placeholder, which on a plugin that updates itself from GitHub
            // rather than wordpress.org reads as "source unknown".
            'icons'       => Keelhob_Brand::icons(),
        ];

        if ($newer) {
            $transient->response[KEELHOB_BASENAME] = $row;
            return $transient;
        }

        // Nothing to install — and core needs to hear that out loud.
        //
        // Returning the transient untouched here looks like the harmless
        // choice and is not. class-wp-plugins-list-table.php sets
        // 'update-supported' => true only for plugins found in response[] or
        // no_update[], false otherwise, and gates the "Enable auto-updates"
        // link on it. A plugin in neither list is read as one that cannot be
        // updated at all, so the toggle is never drawn.
        //
        // Found on keelhob.com running 2.1.1 with 2.1.1 available: Keelhob was
        // the only plugin on the site without that toggle while two others had
        // it. Updates were never broken, because a newer version still filled
        // response[]. What was impossible was UNATTENDED updating, on a plugin
        // whose whole job is handing an AI full control of a site.
        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }
        $transient->no_update[KEELHOB_BASENAME] = $row;
        return $transient;
    }

    /** The "View details" panel, so the update is not an unexplained button. */
    public static function details($result, $action, $args)
    {
        if ($action !== 'plugin_information' || (string) (is_object($args) ? ($args->slug ?? '') : '') !== self::SLUG) {
            return $result;
        }
        $latest = self::latest();
        if (!$latest) {
            return $result;
        }
        return (object) [
            'name'          => 'Keelhob',
            'slug'          => self::SLUG,
            'version'       => $latest['version'],
            'homepage'      => $latest['url'],
            'download_link' => $latest['package'],
            'icons'         => Keelhob_Brand::icons(),
            'sections'      => [
                'description' => 'Self-contained MCP server that lets an AI agent run your WordPress and WooCommerce site. Free, GPL, funded by donations.',
            ],
        ];
    }
}
