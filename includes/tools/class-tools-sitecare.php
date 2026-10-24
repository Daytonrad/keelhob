<?php
/**
 * Site-care / Optimize tools — the READ-ONLY half.
 *
 * Keelhob could build a site and could not look after one. These five tools are
 * the looking-after half, and every one of them is a report: they read, they
 * count, they rank, and they change nothing at all. The tools that act on what
 * they find (`image_compress`, `db_cleanup`, `link_fix`, `media_set_alt`,
 * `plugin_install`) are deliberately NOT here — they write or destroy, and
 * `db_cleanup` cannot be undone at all, so they ship in a second pass once
 * Keelhob_Undo's `file` backup type (added alongside this file) has been proven.
 *
 * Two house rules show up everywhere below and both were learned the hard way:
 *
 *   1. EVERY result states its own count. A model miscounted 44 rows as 28 and
 *      told the owner so. A tool that hands back an array and leaves the
 *      counting to the reader is a tool that will be misquoted.
 *   2. EVERY finding names the real tool that fixes it. A report an agent
 *      cannot act on is a report that gets summarised and dropped. Where the
 *      fix is genuinely not a tool call — a PHP version is the host's, not
 *      ours — the finding says so in `fix` and sets `fix_tool` to null, and
 *      tests/unit/test-sitecare.php proves every other finding names a tool
 *      that actually ships.
 *
 * Structured the same way as class-tools-readiness.php, and for the same
 * reason: the ANALYZERS below are pure (arrays in, findings out, no WordPress
 * call inside them) so the unit suite can exercise the judgement without a
 * database, a network or a browser. The GATHERERS are the thin, boring,
 * WordPress-dependent half and hold no judgement of their own.
 *
 * Every outbound HTTP call has a timeout AND a cache. One tool that hangs
 * blocks the whole MCP request, and an un-cached per-plugin lookup would spend
 * thirty seconds re-learning what it learned a minute ago.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Tools_Sitecare
{
    // ---- speed_audit thresholds (from the tuned list, not invented) -------
    private const LOAD_HIGH_MS      = 1500;
    private const LOAD_MED_MS       = 800;
    private const MAX_SCRIPTS       = 25;
    private const MAX_STYLES        = 15;
    private const AUTOLOAD_MED_KB   = 800;
    private const AUTOLOAD_HIGH_KB  = 2000;
    private const MAX_REVISIONS     = 200;
    private const MAX_EXPIRED_TRANS = 100;
    private const MAX_DRAFTS_TRASH  = 50;
    private const MAX_SPAM          = 100;
    private const IMAGE_MAX_KB      = 300;
    private const IMAGE_MAX_WIDTH   = 2560;
    private const IMAGE_MANY        = 10;   // more than this many big images -> high
    private const MAX_PLUGINS       = 30;
    private const MIN_PHP           = '8.1';

    /** How many autoloaded options and large images a result carries. */
    private const TOP_OPTIONS = 8;
    private const MAX_IMAGES  = 40;

    // ---- broken_links budget ---------------------------------------------
    private const POSTS_PER_CALL   = 15;
    private const LINK_TIMEOUT     = 6;
    private const LINK_CACHE       = 6 * 3600;
    private const LINK_BUDGET_SECS = 22;

    // ---- plugin_security_check -------------------------------------------
    private const WPORG_CACHE   = 12 * 3600;
    private const WPSCAN_CACHE  = 24 * 3600;
    private const WPSCAN_MAX    = 25;          // per run, so a 60-plugin site cannot burn a day's quota
    private const ABANDONED_AGE = 2 * 365 * 86400;   // "not updated in over 2 years"
    private const YEAR_SECS     = 365 * 86400;

    /** Caching plugins, by slug. Any one of these present means "cached". */
    public const CACHE_SLUGS = [
        'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache',
        'wp-fastest-cache', 'sg-cachepress', 'breeze', 'hummingbird-performance',
        'autoptimize', 'perfmatters', 'flying-press', 'nitropack',
        'cache-enabler', 'wp-optimize', 'swift-performance-lite', 'comet-cache',
    ];

    /**
     * Plugins that do the same job. Two of these active at once is the single
     * most common cause of "the site went weird after I installed something".
     */
    public const OVERLAP_GROUPS = [
        'SEO'                => ['wordpress-seo', 'seo-by-rank-math', 'all-in-one-seo-pack', 'autodescription', 'wp-seopress', 'squirrly-seo'],
        'Caching / speed'    => self::CACHE_SLUGS,
        'Security / firewall' => ['wordfence', 'better-wp-security', 'sucuri-scanner', 'all-in-one-wp-security-and-firewall', 'ninjafirewall', 'defender-security', 'shield-security'],
        'Image optimization' => ['ewww-image-optimizer', 'imagify', 'shortpixel-image-optimiser', 'wp-smushit', 'optimole-wp', 'tiny-compress-images', 'robin-image-optimizer', 'webp-express'],
        'Page builder'       => ['elementor', 'beaver-builder-lite-version', 'divi-builder', 'brizy', 'oxygen', 'siteorigin-panels', 'kingcomposer', 'js_composer'],
        'Email (SMTP)'       => ['wp-mail-smtp', 'post-smtp', 'easy-wp-smtp', 'fluent-smtp', 'smtp-mailer'],
    ];

    /** Last slice of the debug log to read. The whole file can be gigabytes. */
    private const LOG_TAIL_BYTES = 400 * 1024;

    public static function register(Keelhob_Tool_Registry $r): void
    {
        $r->add(
            'speed_audit',
            'Read-only speed report with a 1-100 score: homepage load time and size, script/stylesheet counts, compression and caching headers, caching plugin, object cache, autoloaded option weight (with the 8 heaviest named), database junk, oversized images (up to 40, largest first), plugin count and PHP version. Every finding names the tool that fixes it. Changes nothing. Run it before and after optimizing to compare.',
            ['type' => 'object', 'properties' => (object) [], 'required' => []],
            [self::class, 'speed_audit'],
            ['readOnlyHint' => true]
        );

        $r->add(
            'db_cleanup_preview',
            'Count database junk without deleting anything: post revisions, auto-drafts, trashed posts, spam and trashed comments, expired transients and orphaned post meta. Deletes NOTHING — it is the number you show the owner before asking to clean up. Suggest a host backup before any cleanup.',
            ['type' => 'object', 'properties' => (object) [], 'required' => []],
            [self::class, 'db_cleanup_preview'],
            ['readOnlyHint' => true]
        );

        $r->add(
            'plugin_security_check',
            'Read-only security report for every installed plugin: removed from WordPress.org (often for a vulnerability), not updated in over two years, an update waiting, and — when a free WPScan token is configured — known vulnerabilities in the installed version. Riskiest first. Fixing usually means manage_updates, or replacing the plugin; ask the owner before either.',
            ['type' => 'object', 'properties' => (object) [], 'required' => []],
            [self::class, 'plugin_security_check'],
            ['readOnlyHint' => true]
        );

        $r->add(
            'plugin_conflicts',
            'Read-only conflict report: two or more active plugins doing the same job (SEO, caching, security, image optimization, page builders, SMTP), PHP errors in the debug log counted per plugin, plugins WordPress paused after a crash, and plugins that need a newer PHP or WordPress than this site runs. NEVER deactivates anything — report to the owner and let them decide.',
            ['type' => 'object', 'properties' => (object) [], 'required' => []],
            [self::class, 'plugin_conflicts'],
            ['readOnlyHint' => true]
        );

        $r->add(
            'broken_links',
            'Find broken links in published pages and posts, including Elementor layouts. Works in batches of 15 posts: pass the returned next_offset back as offset until done is true. Statuses are cached for 6 hours and each batch stops after about 22 seconds, so it never blocks the request. Report what it finds to the owner; it fixes nothing.',
            [
                'type' => 'object',
                'properties' => [
                    'offset'   => ['type' => 'integer', 'description' => 'Where to resume. Pass back the next_offset from the previous call. Starts at 0.'],
                    'external' => ['type' => 'boolean', 'description' => 'Check links to other sites too (default true). False checks only links on this site.'],
                ],
                'required' => [],
            ],
            [self::class, 'broken_links'],
            ['readOnlyHint' => true]
        );
    }

    // =========================================================================
    // ANALYZERS — pure. No WordPress function may be called below this line,
    // and none above the GATHERERS marker.
    // =========================================================================

    /**
     * One finding. `fix_tool` is the name of a tool that actually ships, or
     * null when the fix is not ours to make (a PHP version is the host's).
     *
     * @return array{severity:string,title:string,evidence:string,fix:string,fix_tool:?string}
     */
    private static function finding(string $severity, string $title, string $evidence, string $fix, ?string $fix_tool): array
    {
        return [
            'severity' => $severity,
            'title'    => $title,
            'evidence' => $evidence,
            'fix'      => $fix,
            'fix_tool' => $fix_tool,
        ];
    }

    /**
     * score = 100 − 15 per high − 7 per medium − 3 per low, floor 1.
     *
     * Floor 1 rather than 0 on purpose: 0 reads as "could not measure", and a
     * site that scores 1 was very much measured.
     *
     * @param array<int, array{severity:string}> $findings
     */
    public static function score_findings(array $findings): int
    {
        $score = 100;
        foreach ($findings as $f) {
            switch ($f['severity'] ?? '') {
                case 'high':
                    $score -= 15;
                    break;
                case 'medium':
                    $score -= 7;
                    break;
                case 'low':
                    $score -= 3;
                    break;
            }
        }
        return max(1, $score);
    }

    /**
     * Judge gathered facts. Everything numeric arrives already measured, which
     * is what makes the thresholds testable without a site.
     *
     * Severities follow the tuned list where it states one (no caching plugin
     * high, no object cache low, autoload high over 2000 KB, images high over
     * ten). Where it only named the threshold, the severity here is the
     * conservative reading and is recorded in BUILD-NOTES.md.
     *
     * @param array<string, mixed> $f
     * @return array<int, array{severity:string,title:string,evidence:string,fix:string,fix_tool:?string}>
     */
    public static function analyze_speed(array $f): array
    {
        $out = [];

        $load = (int) ($f['load_ms'] ?? 0);
        if ($load > self::LOAD_HIGH_MS) {
            $out[] = self::finding('high', 'The homepage takes ' . $load . ' ms to load', 'Over ' . self::LOAD_HIGH_MS . ' ms. Visitors leave.', 'Install and switch on a page-caching plugin, then re-run speed_audit to compare.', 'manage_plugin');
        } elseif ($load > self::LOAD_MED_MS) {
            $out[] = self::finding('medium', 'The homepage takes ' . $load . ' ms to load', 'Over ' . self::LOAD_MED_MS . ' ms.', 'A page-caching plugin is the single biggest win here.', 'manage_plugin');
        }

        $scripts = (int) ($f['scripts'] ?? 0);
        if ($scripts > self::MAX_SCRIPTS) {
            $out[] = self::finding('medium', $scripts . ' scripts on the homepage', 'More than ' . self::MAX_SCRIPTS . '. Each one is a request before the page can finish.', 'Find which plugins add them (plugin_conflicts lists overlapping ones) and drop what is not used.', 'plugin_conflicts');
        }

        $styles = (int) ($f['styles'] ?? 0);
        if ($styles > self::MAX_STYLES) {
            $out[] = self::finding('medium', $styles . ' stylesheets on the homepage', 'More than ' . self::MAX_STYLES . '.', 'Combine or remove them — an optimization plugin does this, or merge your own CSS into one snippet.', 'snippet_save');
        }

        if (empty($f['compressed'])) {
            $out[] = self::finding('medium', 'The homepage is served uncompressed', 'No gzip or brotli Content-Encoding on the response.', 'Switch compression on at the host or in a caching plugin — it typically cuts the HTML by 70%.', 'manage_plugin');
        }

        if ((string) ($f['cache_plugin'] ?? '') === '') {
            $out[] = self::finding('high', 'No caching plugin is active', 'None of the ' . count(self::CACHE_SLUGS) . ' known caching plugins is active.', 'Install one caching plugin — one, not two; two of them fight (see plugin_conflicts).', 'manage_plugin');
        }

        if (empty($f['object_cache'])) {
            $out[] = self::finding('low', 'No persistent object cache', 'No Redis or Memcached drop-in. Every page rebuilds the same queries.', 'Ask the host whether Redis is available, then install its drop-in plugin.', 'manage_plugin');
        }

        $akb = (int) round(((int) ($f['autoload_bytes'] ?? 0)) / 1024);
        if ($akb > self::AUTOLOAD_HIGH_KB) {
            $out[] = self::finding('high', 'Autoloaded options weigh ' . $akb . ' KB', 'Over ' . self::AUTOLOAD_HIGH_KB . ' KB, loaded on EVERY request including the front page.', 'Look at the heaviest options in this result and turn autoload off (or delete the leftovers of a removed plugin).', 'manage_option');
        } elseif ($akb > self::AUTOLOAD_MED_KB) {
            $out[] = self::finding('medium', 'Autoloaded options weigh ' . $akb . ' KB', 'Over ' . self::AUTOLOAD_MED_KB . ' KB on every request.', 'Check the heaviest options listed here before the site grows into a real problem.', 'manage_option');
        }

        $rev = (int) ($f['revisions'] ?? 0);
        if ($rev > self::MAX_REVISIONS) {
            $out[] = self::finding('low', $rev . ' post revisions', 'Over ' . self::MAX_REVISIONS . '. They bloat the posts table and every query against it.', 'Run db_cleanup_preview to see exactly what can go, show the owner the numbers, and keep the newest few.', 'db_cleanup_preview');
        }

        $trans = (int) ($f['expired_transients'] ?? 0);
        if ($trans > self::MAX_EXPIRED_TRANS) {
            $out[] = self::finding('low', $trans . ' expired transients still in the options table', 'Over ' . self::MAX_EXPIRED_TRANS . '. Nothing cleans these up on a site with no cron traffic.', 'Run db_cleanup_preview, then clean up with the owner\'s OK.', 'db_cleanup_preview');
        }

        $junk = (int) ($f['drafts_trash'] ?? 0);
        if ($junk > self::MAX_DRAFTS_TRASH) {
            $out[] = self::finding('low', $junk . ' auto-drafts and trashed posts', 'Over ' . self::MAX_DRAFTS_TRASH . '.', 'Run db_cleanup_preview and clear them once the owner has looked at the list.', 'db_cleanup_preview');
        }

        $spam = (int) ($f['spam'] ?? 0);
        if ($spam > self::MAX_SPAM) {
            $out[] = self::finding('low', $spam . ' spam comments', 'Over ' . self::MAX_SPAM . '.', 'Run db_cleanup_preview, then clear the spam queue.', 'db_cleanup_preview');
        }

        $images = (array) ($f['big_images'] ?? []);
        $n      = count($images);
        if ($n > 0) {
            $sev = $n > self::IMAGE_MANY ? 'high' : 'medium';
            $out[] = self::finding(
                $sev,
                $n . ' oversized image' . ($n === 1 ? '' : 's'),
                'Over ' . self::IMAGE_MAX_KB . ' KB or wider than ' . self::IMAGE_MAX_WIDTH . 'px. The largest are listed in this result.',
                'Re-upload each one resized, with upload_media, largest first — they are the heaviest thing on the page.',
                'upload_media'
            );
        }

        $plugins = (int) ($f['plugins'] ?? 0);
        if ($plugins > self::MAX_PLUGINS) {
            $out[] = self::finding('low', $plugins . ' plugins installed', 'Over ' . self::MAX_PLUGINS . '. Every one is code on every request, and more to keep updated.', 'Run plugin_conflicts and plugin_security_check, and remove what is not earning its place.', 'plugin_conflicts');
        }

        $php = (string) ($f['php'] ?? '');
        if ($php !== '' && version_compare($php, self::MIN_PHP, '<')) {
            // No tool fixes this. Saying "manage_updates" here would be a lie
            // the agent would then try.
            $out[] = self::finding('medium', 'PHP ' . $php . ' is older than ' . self::MIN_PHP, 'Older PHP is slower and stops getting security fixes.', 'This one is the host\'s, not a tool call: ask them to move the site to PHP ' . self::MIN_PHP . ' or newer, after checking the plugins support it.', null);
        }

        return $out;
    }

    /**
     * What the WordPress.org answer actually means.
     *
     * THIS DISTINCTION IS NOT COSMETIC, and the first real run against the rig
     * is what found it. The plugins API answers BOTH "this plugin was taken
     * down" and "no such plugin here" with HTTP 404, and the only difference is
     * in the body:
     *
     *   closed  -> {"error":"closed","closed":true,"description":"… Reason: Security Issue."}
     *   unknown -> {"error":"Plugin not found."}
     *
     * Treating the second as the first told the rig that Keelhob itself "has
     * been removed from WordPress.org, usually for a security problem" — three
     * false security alarms in one report, about three plugins that were never
     * in the directory to begin with. Keelhob ships from GitHub; so do a great
     * many paid plugins. Not being in the directory is not a defect, and a
     * report that says it is will be ignored wholesale, including the findings
     * that were true.
     *
     * @param array<string, mixed>|null $info
     * @return string 'closed' | 'unknown' | 'listed' | 'unreachable'
     */
    public static function wporg_state($info): string
    {
        if (!is_array($info) || $info === []) {
            return 'unreachable';
        }
        $error = strtolower(trim((string) ($info['error'] ?? '')));
        if (!empty($info['closed']) || $error === 'closed') {
            return 'closed';
        }
        if ($error !== '') {
            return 'unknown';
        }
        return isset($info['slug']) || isset($info['last_updated']) || isset($info['version']) ? 'listed' : 'unreachable';
    }

    /**
     * What WordPress.org says about one plugin.
     *
     * @param array<string, mixed>|null $info decoded plugins API body, or null when the lookup failed
     * @return array<int, array{severity:string,title:string,evidence:string,fix:string,fix_tool:?string}>
     */
    public static function analyze_wporg(string $slug, $info, int $now): array
    {
        if (self::wporg_state($info) === 'closed') {
            $reason = trim((string) ($info['closed_reason'] ?? $info['description'] ?? ''));
            return [self::finding(
                'high',
                $slug . ' has been removed from WordPress.org',
                'Removed' . ($reason !== '' ? ': ' . $reason : '') . '. Plugins are usually pulled for a security problem.',
                'Replace it with a maintained alternative, then remove this one. It will never get another update.',
                'manage_plugin'
            )];
        }

        if (self::wporg_state($info) !== 'listed') {
            return [];
        }

        $updated = (string) ($info['last_updated'] ?? '');
        if ($updated !== '') {
            $ts = strtotime($updated);
            if ($ts && ($now - $ts) > self::ABANDONED_AGE) {
                $years = round(($now - $ts) / self::YEAR_SECS, 1);
                return [self::finding(
                    'medium',
                    $slug . ' looks abandoned',
                    'Last updated ' . $years . ' years ago (' . substr($updated, 0, 10) . ').',
                    'Nobody is fixing its bugs. Plan a replacement before it breaks on a WordPress or PHP upgrade.',
                    'manage_plugin'
                )];
            }
        }

        return [];
    }

    /**
     * WPScan's answer for one plugin, against the installed version.
     *
     * A vulnerability counts when it is unfixed (`fixed_in` null) or fixed in a
     * version newer than the one installed. "There is a patch" is not the same
     * as "this site has it".
     *
     * @param array<string, mixed> $body decoded WPScan body for this slug
     * @return array<int, array{severity:string,title:string,evidence:string,fix:string,fix_tool:?string}>
     */
    public static function analyze_wpscan(string $slug, array $body, string $installed): array
    {
        $vulns = [];
        // The response is keyed by slug: {"akismet":{"vulnerabilities":[…]}}.
        foreach ($body as $key => $entry) {
            if (is_array($entry) && isset($entry['vulnerabilities']) && is_array($entry['vulnerabilities'])) {
                if ((string) $key === $slug || count($body) === 1) {
                    $vulns = $entry['vulnerabilities'];
                }
            }
        }

        $out = [];
        foreach ($vulns as $v) {
            if (!is_array($v)) {
                continue;
            }
            $fixed = $v['fixed_in'] ?? null;
            $open  = $fixed === null || $fixed === '' || ($installed !== '' && version_compare($installed, (string) $fixed, '<'));
            if (!$open) {
                continue;
            }
            $title = trim((string) ($v['title'] ?? 'Unnamed vulnerability'));
            $out[] = self::finding(
                'high',
                $slug . ' ' . ($installed !== '' ? $installed . ' ' : '') . 'has a known vulnerability',
                $title . ($fixed ? ' — fixed in ' . (string) $fixed . '.' : ' — no fixed version published yet.'),
                $fixed ? 'Update it to ' . (string) $fixed . ' or newer.' : 'There is no patch. Deactivate it and replace it, with the owner\'s OK.',
                $fixed ? 'manage_updates' : 'manage_plugin'
            );
        }
        return $out;
    }

    /** Rank for sorting riskiest first. */
    public static function severity_rank(string $severity): int
    {
        return ['high' => 0, 'medium' => 1, 'low' => 2][$severity] ?? 3;
    }

    /**
     * Active plugins that overlap. Returns one row per group with 2+ active.
     *
     * @param array<int, string> $active_slugs
     * @return array<int, array{group:string,plugins:array<int,string>}>
     */
    public static function find_overlaps(array $active_slugs): array
    {
        $out = [];
        foreach (self::OVERLAP_GROUPS as $group => $slugs) {
            $hits = array_values(array_intersect($slugs, $active_slugs));
            if (count($hits) >= 2) {
                $out[] = ['group' => (string) $group, 'plugins' => $hits];
            }
        }
        return $out;
    }

    /**
     * Count PHP errors per plugin in a slice of debug log, keeping one example.
     *
     * Deliberately attributes a line to a plugin only when the path says
     * `/plugins/{slug}/`: a fatal inside a theme or inside core is a real
     * problem and a real finding, but it is not a plugin conflict and saying so
     * would send the agent after the wrong thing.
     *
     * @return array<int, array{slug:string,errors:int,example:string}> busiest first
     */
    public static function parse_debug_log(string $tail): array
    {
        $counts   = [];
        $examples = [];
        $ranks    = [];
        foreach (preg_split('/\r?\n/', $tail) ?: [] as $line) {
            if (!preg_match('/PHP (Fatal error|Warning|Parse error|Notice|Deprecated)/i', $line, $kind)) {
                continue;
            }
            if (!preg_match('#[/\\\\]plugins[/\\\\]([a-z0-9][a-z0-9._-]*)[/\\\\]#i', $line, $m)) {
                continue;
            }
            $slug = strtolower($m[1]);
            $counts[$slug] = ($counts[$slug] ?? 0) + 1;

            // The example kept is the WORST line, not the first: a fatal is
            // what the agent needs to read, and a fatal is usually preceded by
            // the warnings that led up to it. Keeping the first line means
            // handing over a "Undefined array key" notice while the crash sits
            // three lines below it.
            $rank = stripos($kind[1], 'Fatal') !== false ? 3 : (stripos($kind[1], 'Parse') !== false ? 2 : 1);
            if (!isset($examples[$slug]) || $rank > ($ranks[$slug] ?? 0)) {
                // Trimmed: a single log line can be a 20 KB stack trace, and
                // the whole point of the tail limit is not to ship that.
                $examples[$slug] = substr(trim($line), 0, 300);
                $ranks[$slug]    = $rank;
            }
        }
        arsort($counts);
        $out = [];
        foreach ($counts as $slug => $n) {
            $out[] = ['slug' => (string) $slug, 'errors' => (int) $n, 'example' => (string) ($examples[$slug] ?? '')];
        }
        return $out;
    }

    /**
     * Plugins asking for a newer PHP or WordPress than this site runs.
     *
     * @param array<string, array<string, mixed>> $plugins get_plugins() shape
     * @return array<int, array{plugin:string,needs:string,has:string,what:string}>
     */
    public static function requires_mismatches(array $plugins, string $php, string $wp): array
    {
        $out = [];
        foreach ($plugins as $file => $data) {
            $name = (string) ($data['Name'] ?? $file);
            $rp   = trim((string) ($data['RequiresPHP'] ?? ''));
            if ($rp !== '' && version_compare($php, $rp, '<')) {
                $out[] = ['plugin' => $name, 'needs' => $rp, 'has' => $php, 'what' => 'PHP'];
            }
            $rw = trim((string) ($data['RequiresWP'] ?? ''));
            if ($rw !== '' && version_compare($wp, $rw, '<')) {
                $out[] = ['plugin' => $name, 'needs' => $rw, 'has' => $wp, 'what' => 'WordPress'];
            }
        }
        return $out;
    }

    /**
     * Every link worth checking in one post's content and Elementor data.
     *
     * Elementor stores its layout as JSON in `_elementor_data`, with every
     * slash escaped (`https:\/\/example.com`). A scanner that reads only
     * post_content reports a clean bill of health on an Elementor site, which
     * is the worst possible answer: confidently wrong.
     *
     * @return array<int, string> absolute URLs, de-duplicated, fragments stripped
     */
    public static function extract_links(string $content, string $elementor, string $base): array
    {
        $raw = [];

        if (preg_match_all('/href\s*=\s*([\'"])(.*?)\1/is', $content, $m)) {
            $raw = array_merge($raw, $m[2]);
        }
        if ($elementor !== '') {
            $json = str_replace('\\/', '/', $elementor);
            if (preg_match_all('/"url"\s*:\s*"([^"]*)"/i', $json, $m)) {
                $raw = array_merge($raw, $m[1]);
            }
            if (preg_match_all('/href\s*=\s*\\\\?[\'"]([^\'"\\\\]*)/i', $json, $m)) {
                $raw = array_merge($raw, $m[1]);
            }
        }

        $out = [];
        foreach ($raw as $url) {
            $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
            if ($url === '') {
                continue;
            }
            // Not links to a page: a mail client, a dialler, an on-page anchor,
            // or script. Fetching any of them is at best meaningless.
            if (preg_match('~^(mailto:|tel:|sms:|javascript:|data:|\#|\?)~i', $url)) {
                continue;
            }
            if (strpos($url, '//') === 0) {
                $url = 'https:' . $url;
            }
            if ($url[0] === '/') {
                $url = rtrim($base, '/') . $url;
            }
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }
            $url = strtok($url, '#');
            if (!is_string($url) || $url === '') {
                continue;
            }
            $out[$url] = true;
        }
        return array_keys($out);
    }

    /** Broken means "nothing answered" or "the server said no". */
    public static function is_broken_status(int $code): bool
    {
        return $code === 0 || $code >= 400;
    }

    /** A HEAD that returns one of these is a server being fussy, not a dead link. */
    public static function should_retry_with_get(int $code): bool
    {
        return in_array($code, [0, 403, 405, 501], true);
    }

    // =========================================================================
    // GATHERERS — WordPress-dependent, thin, no judgement of their own.
    // =========================================================================

    /** @return array<string, mixed> */
    public static function speed_audit(array $args): array
    {
        $home    = home_url('/');
        $started = microtime(true);
        $resp    = wp_remote_get($home, [
            'timeout'    => 20,
            'user-agent' => 'Keelhob/' . KEELHOB_VERSION . ' (speed_audit)',
            'headers'    => ['Accept-Encoding' => 'gzip, deflate, br'],
        ]);
        $load_ms = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => 'Could not load the homepage: ' . $resp->get_error_message()];
        }

        $body     = (string) wp_remote_retrieve_body($resp);
        $encoding = strtolower((string) wp_remote_retrieve_header($resp, 'content-encoding'));
        $scripts  = preg_match_all('/<script[^>]+src\s*=/i', $body);
        $styles   = preg_match_all('/<link[^>]+stylesheet/i', $body);

        $junk    = self::count_junk();
        $options = self::autoload_facts();
        $images  = self::big_images();

        $facts = [
            'load_ms'            => $load_ms,
            'bytes'              => strlen($body),
            'scripts'            => (int) $scripts,
            'styles'             => (int) $styles,
            'compressed'         => $encoding !== '' && preg_match('/gzip|br|deflate|zstd/', $encoding) === 1,
            'cache_plugin'       => self::active_cache_plugin(),
            'object_cache'       => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
            'autoload_bytes'     => $options['bytes'],
            'revisions'          => $junk['revisions'],
            'expired_transients' => $junk['expired_transients'],
            'drafts_trash'       => $junk['auto_drafts'] + $junk['trashed_posts'],
            'spam'               => $junk['spam_comments'],
            'big_images'         => $images,
            'plugins'            => count(self::installed_plugins()),
            'php'                => PHP_VERSION,
        ];

        $findings = self::analyze_speed($facts);
        $by_sev   = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            if (isset($by_sev[$f['severity']])) {
                $by_sev[$f['severity']]++;
            }
        }

        return [
            'success'        => true,
            'score'          => self::score_findings($findings),
            'scored_as'      => '100 - 15 per high - 7 per medium - 3 per low, minimum 1',
            'count'          => count($findings),
            'findings_by_severity' => $by_sev,
            'findings'       => $findings,
            'measured'       => [
                'url'                  => $home,
                'load_ms'              => $load_ms,
                'html_kb'              => (int) round(strlen($body) / 1024),
                'scripts'              => (int) $scripts,
                'stylesheets'          => (int) $styles,
                'compression'          => $encoding !== '' ? $encoding : 'none',
                'caching_plugin'       => $facts['cache_plugin'] !== '' ? $facts['cache_plugin'] : 'none',
                'persistent_object_cache' => (bool) $facts['object_cache'],
                'autoload_kb'          => (int) round($options['bytes'] / 1024),
                'plugins_installed'    => $facts['plugins'],
                'php'                  => PHP_VERSION,
                'database'             => $junk,
            ],
            'heaviest_autoloaded_options' => $options['top'],
            'heaviest_autoloaded_count'   => count($options['top']),
            'large_images'       => $images,
            'large_image_count'  => count($images),
            'note' => 'Read-only: nothing on the site was changed. Every finding names the tool that fixes it, except where the fix belongs to the host.',
        ];
    }

    /** @return array<string, mixed> */
    public static function db_cleanup_preview(array $args): array
    {
        $counts = self::count_junk();
        $total  = array_sum($counts);

        return [
            'success'  => true,
            'count'    => $total,
            'total'    => $total,
            'counts'   => $counts,
            'deleted'  => 0,
            'note'     => 'Nothing was deleted — this is a count only. Show these numbers to the owner and suggest a host backup before any cleanup. Cleaning up cannot be undone by Keelhob.',
        ];
    }

    /** @return array<string, mixed> */
    public static function plugin_security_check(array $args): array
    {
        $plugins  = self::installed_plugins();
        $updates  = self::pending_plugin_updates();
        $token    = self::wpscan_token();
        $now      = time();
        $findings = [];
        $checked  = 0;
        $unlisted = [];
        $unreachable = [];
        $wpscan_used = 0;
        $wpscan_stop = false;
        $wpscan_note = $token === '' ? 'No WPScan token configured, so known-vulnerability data was not checked. A free token (wpscan.com) raises this from "is it maintained" to "is it exploitable".' : '';

        foreach ($plugins as $file => $data) {
            $slug = self::plugin_slug((string) $file);
            if ($slug === '') {
                continue;
            }
            $checked++;
            $installed = (string) ($data['Version'] ?? '');

            $info  = self::wporg_info($slug);
            $state = self::wporg_state($info);
            if ($state === 'unknown') {
                // Not in the directory at all — a GitHub or paid plugin. There
                // is nothing to check and nothing wrong. Listed as a fact so
                // the agent knows the report does not cover it, rather than as
                // a finding that would read as an accusation.
                $unlisted[] = $slug;
            } elseif ($state === 'unreachable') {
                $unreachable[] = $slug;
            }

            foreach (self::analyze_wporg($slug, $info, $now) as $f) {
                $findings[] = $f;
            }

            if (isset($updates[$file])) {
                $findings[] = self::finding(
                    'medium',
                    $slug . ' has an update waiting',
                    'Installed ' . ($installed !== '' ? $installed : '?') . ', available ' . (string) $updates[$file] . '.',
                    'Update it with manage_updates — most plugin updates are security fixes. Tell the owner first; an update cannot be undone automatically.',
                    'manage_updates'
                );
            }

            if ($token !== '' && !$wpscan_stop && $wpscan_used < self::WPSCAN_MAX) {
                $scan = self::wpscan_info($slug, $token);
                if ($scan['counted']) {
                    $wpscan_used++;
                }
                if ($scan['error'] !== '') {
                    $wpscan_note = $scan['error'];
                    // A rejected token or an exhausted quota will not fix
                    // itself between two plugins. Asking sixty more times is
                    // sixty more HTTP calls for the same answer, and on a big
                    // site that is what times the MCP request out.
                    if ($scan['fatal']) {
                        $wpscan_stop = true;
                    }
                } elseif (is_array($scan['body'])) {
                    foreach (self::analyze_wpscan($slug, $scan['body'], $installed) as $f) {
                        $findings[] = $f;
                    }
                }
            }
        }

        if ($token !== '' && $wpscan_used >= self::WPSCAN_MAX && $checked > self::WPSCAN_MAX) {
            $wpscan_note = 'WPScan lookups stopped at ' . self::WPSCAN_MAX . ' this run (the free quota is 25 a day). Run it again tomorrow to cover the rest; answers already fetched are cached for 24 hours.';
        }

        usort($findings, static function (array $a, array $b): int {
            return self::severity_rank((string) $a['severity']) <=> self::severity_rank((string) $b['severity']);
        });

        $by_sev = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($findings as $f) {
            if (isset($by_sev[$f['severity']])) {
                $by_sev[$f['severity']]++;
            }
        }

        return [
            'success'          => true,
            'count'            => count($findings),
            'plugins_checked'  => $checked,
            'findings_by_severity' => $by_sev,
            'findings'         => $findings,
            'wpscan'           => [
                'configured' => $token !== '',
                'lookups'    => $wpscan_used,
                'note'       => $wpscan_note,
            ],
            'not_on_wordpress_org'       => $unlisted,
            'not_on_wordpress_org_count' => count($unlisted),
            'not_on_wordpress_org_note'  => $unlisted === []
                ? ''
                : 'These are not in the WordPress.org directory, so the maintenance checks above do not cover them. That is normal for a plugin that ships from GitHub or is paid for — it is NOT a sign of anything wrong. Check them with their own author.',
            'could_not_check'            => $unreachable,
            'could_not_check_count'      => count($unreachable),
            'note' => 'Read-only. Riskiest first. WordPress.org answers are cached 12 hours, WPScan 24 hours. Ask the owner before updating or removing anything.',
        ];
    }

    /** @return array<string, mixed> */
    public static function plugin_conflicts(array $args): array
    {
        $plugins = self::installed_plugins();
        $active  = self::active_plugin_slugs();

        $overlaps = self::find_overlaps($active);
        $log      = self::parse_debug_log(self::debug_log_tail());
        $paused   = function_exists('wp_paused_plugins') ? array_keys((array) wp_paused_plugins()->get_all()) : [];
        $mismatch = self::requires_mismatches($plugins, PHP_VERSION, self::wp_version());

        // Only plugins that are actually here get reported from the log: an old
        // line about a plugin that was removed last month is noise.
        $log = array_values(array_filter($log, static function (array $row) use ($plugins): bool {
            foreach (array_keys($plugins) as $file) {
                if (self::plugin_slug((string) $file) === $row['slug']) {
                    return true;
                }
            }
            return false;
        }));

        $issues = count($overlaps) + count($log) + count($paused) + count($mismatch);

        return [
            'success'        => true,
            'count'          => $issues,
            'overlaps'       => $overlaps,
            'overlap_count'  => count($overlaps),
            'log_errors'     => $log,
            'log_error_count' => count($log),
            'paused'         => array_values($paused),
            'paused_count'   => count($paused),
            'requires_mismatches' => $mismatch,
            'requires_mismatch_count' => count($mismatch),
            'active_plugins' => count($active),
            'fix'            => 'Report this to the owner and let them choose. NEVER deactivate a plugin on your own: on a live site the one you switch off may be the one taking the payments.',
            'fix_tool'       => 'notify_owner',
            'note'           => 'Read-only — nothing was deactivated. The debug log read is the last ' . (int) round(self::LOG_TAIL_BYTES / 1024) . ' KB only.',
        ];
    }

    /** @return array<string, mixed> */
    public static function broken_links(array $args): array
    {
        global $wpdb;

        $offset   = max(0, (int) ($args['offset'] ?? 0));
        $external = !isset($args['external']) || (bool) $args['external'];
        $base     = home_url('/');
        $host     = (string) wp_parse_url($base, PHP_URL_HOST);
        $started  = microtime(true);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_content FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('post','page')
                 ORDER BY ID ASC LIMIT %d OFFSET %d",
                self::POSTS_PER_CALL,
                $offset
            ),
            ARRAY_A
        );
        $rows = is_array($rows) ? $rows : [];

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')"
        );

        $broken    = [];
        $checked   = 0;
        $scanned   = 0;
        $links     = 0;
        $stopped   = false;

        foreach ($rows as $row) {
            if (microtime(true) - $started > self::LINK_BUDGET_SECS) {
                $stopped = true;
                break;
            }
            $scanned++;
            $id        = (int) $row['ID'];
            $elementor = (string) get_post_meta($id, '_elementor_data', true);
            $urls      = self::extract_links((string) $row['post_content'], $elementor, $base);

            foreach ($urls as $url) {
                $links++;
                $url_host = (string) wp_parse_url($url, PHP_URL_HOST);
                $is_ours  = $url_host !== '' && strcasecmp($url_host, $host) === 0;
                if (!$external && !$is_ours) {
                    continue;
                }
                if (microtime(true) - $started > self::LINK_BUDGET_SECS) {
                    $stopped = true;
                    break 2;
                }
                $status = self::url_status($url);
                $checked++;
                if (self::is_broken_status($status)) {
                    $broken[] = [
                        'post_id'  => $id,
                        'title'    => (string) $row['post_title'],
                        'url'      => $url,
                        'status'   => $status,
                        'reason'   => $status === 0 ? 'no response' : 'HTTP ' . $status,
                        'internal' => $is_ours,
                    ];
                }
            }
        }

        $next = $offset + $scanned;
        $done = !$stopped && count($rows) < self::POSTS_PER_CALL;

        return [
            'success'        => true,
            'count'          => count($broken),
            'broken'         => $broken,
            'links_found'    => $links,
            'links_checked'  => $checked,
            'posts_scanned'  => $scanned,
            'offset'         => $offset,
            'next_offset'    => $next,
            'done'           => $done,
            'progress'       => $total > 0 ? min(100, (int) round(($next / $total) * 100)) . '% (' . min($next, $total) . ' of ' . $total . ' published posts and pages)' : '100% (nothing published)',
            'stopped_early'  => $stopped,
            'note'           => $done
                ? 'Every published post and page has been scanned.'
                : 'Not finished: call broken_links again with offset=' . $next . '. Statuses are cached for 6 hours, so a repeat run is cheap.',
        ];
    }

    // ---- small gatherers --------------------------------------------------

    /**
     * The junk counts, shared by speed_audit and db_cleanup_preview so the two
     * can never disagree about the same site.
     *
     * `post_id NOT IN (SELECT ID …)` rather than a LEFT JOIN on purpose: it is
     * the one form that works on MySQL and on an SQLite-backed install, and
     * this rig is SQLite.
     *
     * @return array<string, int>
     */
    private static function count_junk(): array
    {
        global $wpdb;

        $one = static function (string $sql) use ($wpdb): int {
            $v = $wpdb->get_var($sql);
            return $v === null ? 0 : (int) $v;
        };

        return [
            'revisions'          => $one("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"),
            'auto_drafts'        => $one("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"),
            'trashed_posts'      => $one("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"),
            'spam_comments'      => $one("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"),
            'trashed_comments'   => $one("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"),
            'expired_transients' => $one($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
                $wpdb->esc_like('_transient_timeout_') . '%',
                time()
            )),
            'orphan_postmeta'    => $one("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})"),
        ];
    }

    /**
     * Autoloaded option weight, and the heaviest few.
     *
     * The `autoload` column has held 'yes', 'on', 'auto-on' and 'auto' across
     * WordPress versions, and counting only 'yes' on a 6.6+ install reports a
     * fraction of the real weight.
     *
     * @return array{bytes:int, top:array<int, array{option:string,kb:int}>}
     */
    private static function autoload_facts(): array
    {
        global $wpdb;

        $where = "autoload IN ('yes','on','auto-on','auto')";
        $bytes = $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE {$where}");

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, LENGTH(option_value) AS len FROM {$wpdb->options}
                 WHERE {$where} ORDER BY len DESC LIMIT %d",
                self::TOP_OPTIONS
            ),
            ARRAY_A
        );

        $top = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            $top[] = [
                'option' => (string) ($row['option_name'] ?? ''),
                'kb'     => (int) round(((int) ($row['len'] ?? 0)) / 1024),
            ];
        }

        return ['bytes' => $bytes === null ? 0 : (int) $bytes, 'top' => $top];
    }

    /**
     * Images over the size or width cap, largest first, capped at 40.
     *
     * Reads `_wp_attachment_metadata` rather than stat-ing every file: one
     * query instead of thousands of filesystem calls, and the metadata is what
     * WordPress itself believes. Where it carries no filesize (older uploads)
     * the file is measured, which is the only time this touches the disk.
     *
     * @return array<int, array{id:int,file:string,kb:int,width:int}>
     */
    private static function big_images(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_metadata'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
             LIMIT 5000",
            ARRAY_A
        );

        $uploads = wp_get_upload_dir();
        $basedir = (string) ($uploads['basedir'] ?? '');
        $big     = [];

        foreach ((is_array($rows) ? $rows : []) as $row) {
            $meta = maybe_unserialize((string) ($row['meta_value'] ?? ''));
            if (!is_array($meta)) {
                continue;
            }
            $file  = (string) ($meta['file'] ?? '');
            $width = (int) ($meta['width'] ?? 0);
            $bytes = (int) ($meta['filesize'] ?? 0);
            if ($bytes <= 0 && $file !== '' && $basedir !== '') {
                $path  = $basedir . '/' . ltrim($file, '/');
                $bytes = is_file($path) ? (int) filesize($path) : 0;
            }
            $kb = (int) round($bytes / 1024);
            if ($kb <= self::IMAGE_MAX_KB && $width <= self::IMAGE_MAX_WIDTH) {
                continue;
            }
            $big[] = ['id' => (int) ($row['ID'] ?? 0), 'file' => $file, 'kb' => $kb, 'width' => $width];
        }

        usort($big, static function (array $a, array $b): int {
            return $b['kb'] <=> $a['kb'];
        });

        return array_slice($big, 0, self::MAX_IMAGES);
    }

    /** @return array<string, array<string, mixed>> get_plugins() */
    private static function installed_plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        return is_array($plugins) ? $plugins : [];
    }

    /** @return array<int, string> slugs of active plugins */
    private static function active_plugin_slugs(): array
    {
        $out = [];
        foreach ((array) get_option('active_plugins', []) as $file) {
            $slug = self::plugin_slug((string) $file);
            if ($slug !== '') {
                $out[] = $slug;
            }
        }
        if (is_multisite()) {
            foreach (array_keys((array) get_site_option('active_sitewide_plugins', [])) as $file) {
                $slug = self::plugin_slug((string) $file);
                if ($slug !== '') {
                    $out[] = $slug;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** First active caching plugin, or ''. */
    private static function active_cache_plugin(): string
    {
        foreach (self::active_plugin_slugs() as $slug) {
            if (in_array($slug, self::CACHE_SLUGS, true)) {
                return $slug;
            }
        }
        return '';
    }

    /**
     * The directory name of a plugin file, validated.
     *
     * A single-file plugin (hello.php) has no directory and therefore no
     * WordPress.org slug we can trust, so it returns '' rather than a guess.
     * Nothing from a model reaches here, but nothing from the filesystem is
     * trusted into a URL either.
     */
    public static function plugin_slug(string $file): string
    {
        $parts = explode('/', str_replace('\\', '/', $file));
        if (count($parts) < 2) {
            return '';
        }
        $slug = strtolower($parts[0]);
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $slug) === 1 ? $slug : '';
    }

    /** @return array<string, string> plugin file => new version */
    private static function pending_plugin_updates(): array
    {
        $out = [];
        $t   = get_site_transient('update_plugins');
        if (!is_object($t) || !isset($t->response) || !is_array($t->response)) {
            return $out;
        }
        foreach ($t->response as $file => $info) {
            $new = (string) ($info->new_version ?? '');
            if ($new !== '') {
                $out[(string) $file] = $new;
            }
        }
        return $out;
    }

    /**
     * WordPress.org's record for one plugin, cached 12 hours.
     *
     * Cached whether the answer was useful or not: a site with sixty plugins
     * would otherwise make sixty HTTP calls every single time the agent asked,
     * and the most likely outcome of that is the MCP request timing out.
     *
     * @return array<string, mixed>|null
     */
    private static function wporg_info(string $slug)
    {
        $key    = 'keelhob_sc_org_' . md5($slug);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        $url  = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode($slug);
        $resp = wp_remote_get($url, ['timeout' => 10, 'user-agent' => 'Keelhob/' . KEELHOB_VERSION]);
        if (is_wp_error($resp)) {
            // Short negative cache: the network being down should not make the
            // next four tool calls wait ten seconds each as well.
            set_transient($key, ['error' => ''], 300);
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) {
            set_transient($key, ['error' => ''], 300);
            return null;
        }
        set_transient($key, $body, self::WPORG_CACHE);
        return $body;
    }

    /** The owner's WPScan token, if they have set one. */
    private static function wpscan_token(): string
    {
        if (defined('KEELHOB_WPSCAN_TOKEN') && is_string(constant('KEELHOB_WPSCAN_TOKEN'))) {
            return trim((string) constant('KEELHOB_WPSCAN_TOKEN'));
        }
        return trim((string) Keelhob::instance()->get('wpscan_token', ''));
    }

    /**
     * WPScan's record for one plugin, cached 24 hours.
     *
     * The quota is 25 a day on the free tier, so a cache miss is expensive in a
     * way an ordinary HTTP call is not — which is why a 404 (no known
     * vulnerabilities) is cached just as long as a hit.
     *
     * @return array{body:array<string,mixed>|null, error:string, counted:bool, fatal:bool} fatal = do not ask again this run
     */
    private static function wpscan_info(string $slug, string $token): array
    {
        $key    = 'keelhob_sc_wpscan_' . md5($slug);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return ['body' => $cached, 'error' => '', 'counted' => false, 'fatal' => false];
        }

        $resp = wp_remote_get('https://wpscan.com/api/v3/plugins/' . rawurlencode($slug), [
            'timeout'    => 10,
            'user-agent' => 'Keelhob/' . KEELHOB_VERSION,
            'headers'    => ['Authorization' => 'Token token=' . $token],
        ]);
        if (is_wp_error($resp)) {
            return ['body' => null, 'error' => 'WPScan could not be reached: ' . $resp->get_error_message(), 'counted' => false, 'fatal' => true];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code === 404) {
            set_transient($key, [], self::WPSCAN_CACHE);
            return ['body' => [], 'error' => '', 'counted' => true, 'fatal' => false];
        }
        if ($code === 429) {
            return ['body' => null, 'error' => 'WPScan daily limit reached (25 lookups a day on the free token). Answers already fetched are cached for 24 hours; run this again tomorrow.', 'counted' => true, 'fatal' => true];
        }
        if ($code === 401 || $code === 403) {
            return ['body' => null, 'error' => 'WPScan rejected the token (HTTP ' . $code . '). Check it in Keelhob settings. No further WPScan lookups were made this run.', 'counted' => true, 'fatal' => true];
        }
        if ($code !== 200) {
            return ['body' => null, 'error' => 'WPScan returned HTTP ' . $code . '.', 'counted' => true, 'fatal' => true];
        }

        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) {
            return ['body' => null, 'error' => 'WPScan returned something that was not JSON.', 'counted' => true, 'fatal' => true];
        }
        set_transient($key, $body, self::WPSCAN_CACHE);
        return ['body' => $body, 'error' => '', 'counted' => true, 'fatal' => false];
    }

    /** The last slice of the debug log, or '' when there is none to read. */
    private static function debug_log_tail(): string
    {
        $candidates = [];
        if (defined('WP_DEBUG_LOG') && is_string(constant('WP_DEBUG_LOG')) && constant('WP_DEBUG_LOG') !== '') {
            $candidates[] = (string) constant('WP_DEBUG_LOG');
        }
        $candidates[] = WP_CONTENT_DIR . '/debug.log';
        $ini = (string) ini_get('error_log');
        if ($ini !== '' && $ini !== 'syslog') {
            $candidates[] = $ini;
        }

        foreach ($candidates as $path) {
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $size = (int) filesize($path);
            $fh   = fopen($path, 'rb');
            if (!$fh) {
                continue;
            }
            if ($size > self::LOG_TAIL_BYTES) {
                fseek($fh, -self::LOG_TAIL_BYTES, SEEK_END);
            }
            $tail = (string) stream_get_contents($fh);
            fclose($fh);
            return $tail;
        }
        return '';
    }

    private static function wp_version(): string
    {
        global $wp_version;
        return (string) ($wp_version ?? '');
    }

    /**
     * One URL's status: HEAD first, GET only when HEAD was refused, cached 6h.
     *
     * The URL is fetched to read its status code and nothing else. Nothing in
     * the response body is parsed, followed, or returned.
     */
    private static function url_status(string $url): int
    {
        $key    = 'keelhob_sc_link_' . md5($url);
        $cached = get_transient($key);
        if ($cached !== false && $cached !== null) {
            return (int) $cached;
        }

        $args = [
            'timeout'     => self::LINK_TIMEOUT,
            'redirection' => 3,
            'user-agent'  => 'Keelhob/' . KEELHOB_VERSION . ' (broken_links)',
        ];

        $resp = wp_remote_head($url, $args);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);

        if (self::should_retry_with_get($code)) {
            $resp = wp_remote_get($url, $args + ['limit_response_size' => 2048]);
            $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        }

        set_transient($key, $code, self::LINK_CACHE);
        return $code;
    }
}
