<?php
/**
 * `site_readiness` — the exit code for the `build-standard` skill.
 *
 * Read-only MCP tool that returns structured pass/fail for the objective half
 * of the standard: alt coverage, meta/OG per page, JSON-LD validity,
 * `href="#"` count, heading order, image weight, security headers, tax
 * sanity, stock config, legal wiring, snippet errors, llms.txt status, and
 * sitemap/robots. It exists so "done" is an exit code, not an argument.
 *
 * Structured in two halves on purpose:
 *   - The analyzers (below the "ANALYZERS" marker) are pure static functions:
 *     plain arrays/strings/ints in, findings out, no WordPress call inside
 *     them. That is what makes them testable — tests/bootstrap.php stubs only
 *     a handful of simple WordPress functions and has no WP_Query, get_posts,
 *     or wp_remote_get, so anything that calls those cannot run in the suite.
 *     Same precedent as Claudpress_Skills::bundled_action().
 *   - The gatherers (below "GATHERERS") are the boring WordPress-dependent
 *     part: fetch data, hand it to an analyzer, collect the findings. They
 *     are deliberately thin and hold no judgement of their own.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Readiness
{
    // Threshold constants, each traceable to skills/build-standard.md.
    private const MAX_IMAGE_BYTES = 300 * 1024; // "No image over 300 KB"
    private const MAX_LCP_BYTES   = 200 * 1024; // "the LCP/hero image under 200 KB"

    // Crawl budget. Every page and every image costs one HTTP round trip back
    // to this same site, so an uncapped run on a large site dies on
    // max_execution_time and returns nothing at all. Anything the budget does
    // not reach is reported in `skipped`, never counted as checked.
    private const MAX_PAGES  = 25;
    private const MAX_IMAGES = 60;

    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add(
            'site_readiness',
            'Read-only exit code for the `build-standard` standard: runs every objective, machine-checkable part of a launch readiness pass and returns structured pass/fail findings shape-compatible with the pre-launch-audit skill\'s machine-readable block. Checks: heading order, alt-text coverage, title/meta description/Open Graph presence, JSON-LD validity (and aggregateRating/review misuse), href="#" count, placeholder text, image weight (300KB cap, 200KB on the LCP image), security headers (HSTS/nosniff/frame-ancestors/referrer-policy — deliberately does NOT require an enforcing CSP), llms.txt/sitemap/robots.txt status (including a 404 page served with a 200, a real bug this plugin shipped), 404 handling, snippet errors, legal-page wiring, and — only when WooCommerce is active — tax-rate sanity, stock policy, and registration/role defaults. Changes nothing on the site. A check that could not run (WooCommerce inactive, no HTTPS, a capability the owner disabled) is reported in `skipped` and is NEVER counted as a pass — always check `skipped` and `not_verified` before trusting `pass`. Some checks (viewport sweeps, console errors, rendered contrast, animation smoothness) are structurally impossible without a browser and are listed in `not_verified` with what a human should click instead.',
            [
                'type' => 'object',
                'properties' => [
                    'scope'   => ['type' => 'string', 'enum' => ['site', 'page'], 'default' => 'site', 'description' => 'site: crawl published pages/posts plus the site-level checks. page: check only post_id.'],
                    'post_id' => ['type' => 'integer', 'description' => 'Required when scope=page: the post/page to check.'],
                    'urls'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional extra absolute URLs to include in the page-level checks (e.g. a landing page not in the post table).'],
                ],
                'required' => [],
            ],
            [self::class, 'site_readiness'],
            ['readOnlyHint' => true]
        );
    }

    // =========================================================================
    // ANALYZERS — pure. No WordPress function may be called below this line.
    // =========================================================================

    /**
     * Heading order, image alt coverage, dead-anchor links, title/meta/OG
     * presence, placeholder text, and JSON-LD validity for one rendered page.
     */
    public static function analyze_page_html(string $html, string $location): array
    {
        $findings = [];

        // ---- headings: exactly one h1, no skipped levels -------------------
        $levels = [];
        if (preg_match_all('/<h([1-6])[^>]{0,300}>/i', $html, $hm)) {
            $levels = array_map('intval', $hm[1]);
        }
        $h1_count = count(array_filter($levels, fn($l) => $l === 1));
        if ($h1_count === 0) {
            $findings[] = self::finding('missing-h1', 'high', 'accessibility', 'Page has no <h1>', 'No <h1> element found in the rendered HTML', $location);
        } elseif ($h1_count > 1) {
            $findings[] = self::finding('multiple-h1', 'medium', 'accessibility', 'Page has more than one <h1>', $h1_count . ' <h1> elements found', $location);
        }
        $prev = null;
        foreach ($levels as $level) {
            if ($prev !== null && $level > $prev + 1) {
                $findings[] = self::finding('skipped-heading-level', 'medium', 'accessibility', 'Heading level skipped', 'h' . $prev . ' followed by h' . $level, $location);
                break; // one finding per page is enough signal; evidence names the first offender.
            }
            $prev = $level;
        }

        // ---- content images: missing alt (alt="" is a deliberate pass) -----
        if (preg_match_all('/<img\b[^>]{0,500}>/i', $html, $im)) {
            $missing = 0;
            foreach ($im[0] as $tag) {
                if (!preg_match('/\balt\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag)) {
                    $missing++;
                }
            }
            if ($missing > 0) {
                $findings[] = self::finding('images-missing-alt', 'high', 'accessibility', 'Content image(s) missing alt attribute', $missing . ' of ' . count($im[0]) . ' <img> tags have no alt attribute', $location);
            }
        }

        // ---- href="#" ---------------------------------------------------
        $hash_count = preg_match_all('/\bhref\s*=\s*("#"|\'#\')/i', $html);
        if ($hash_count > 0) {
            $findings[] = self::finding('dead-hash-links', 'high', 'functionality', 'Link(s) point to href="#"', $hash_count . ' anchor(s) with href="#"', $location);
        }

        // ---- title / meta description / OG ---------------------------------
        if (!preg_match('/<title>\s*[^<\s][^<]*<\/title>/i', $html)) {
            $findings[] = self::finding('missing-title', 'high', 'trust-and-conversion', '<title> missing or empty', 'No non-empty <title> element found', $location);
        }
        if (!preg_match('/<meta\s+name=["\']description["\'][^>]*content=["\'][^"\']+["\']/i', $html)) {
            $findings[] = self::finding('missing-meta-description', 'medium', 'trust-and-conversion', 'Meta description missing or empty', 'No <meta name="description" content="..."> with non-empty content found', $location);
        }
        foreach (['og:title' => 'og-title', 'og:description' => 'og-description', 'og:url' => 'og-url'] as $prop => $slug) {
            $pattern = '/<meta\s+property=["\']' . preg_quote($prop, '/') . '["\'][^>]*content=["\'][^"\']+["\']/i';
            if (!preg_match($pattern, $html)) {
                $findings[] = self::finding('missing-' . $slug, 'medium', 'trust-and-conversion', 'Open Graph ' . $prop . ' missing or empty', 'No <meta property="' . $prop . '" content="..."> with non-empty content found', $location);
            }
        }

        // ---- placeholder text -----------------------------------------------
        // Matched with word boundaries, and TODO case-sensitively: a bare
        // stripos('todo') fires on Spanish copy and on class names like
        // "todo-list", and a false Blocker costs more trust than it buys.
        $placeholders = [
            '/\blorem\s+ipsum\b/i' => 'lorem ipsum',
            '/\bcoming\s+soon\b/i' => 'coming soon',
            '/\bTODO\b/'           => 'TODO',
        ];
        foreach ($placeholders as $pattern => $label) {
            if (preg_match($pattern, $html)) {
                $findings[] = self::finding('placeholder-text', 'high', 'trust-and-conversion', 'Placeholder text found on page', 'Matched "' . $label . '"', $location);
                break; // one finding names the page; no need to enumerate every match.
            }
        }

        // ---- JSON-LD validity ------------------------------------------------
        if (preg_match_all('/<script[^>]{0,200}type=["\']application\/ld\+json["\'][^>]{0,200}>(.*?)<\/script>/is', $html, $lm)) {
            foreach ($lm[1] as $i => $block) {
                if (trim($block) === '') {
                    continue;
                }
                json_decode($block);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $findings[] = self::finding('invalid-json-ld', 'high', 'code-quality-and-production-readiness', 'Invalid JSON-LD on page', 'Block #' . ($i + 1) . ' failed json_decode: ' . json_last_error_msg(), $location);
                }
            }
        }

        return $findings;
    }

    /**
     * Detect a coming-soon gate, maintenance mode, or catch-all redirect by
     * content, not by looking for known snippets: if two or more DISTINCT
     * URLs come back with a byte-identical body, the crawl is looking at one
     * page dressed up as many. That is a general signal — it works for any
     * coming-soon plugin, any maintenance mode, any host-level holding page,
     * not just this plugin's own gate snippet.
     *
     * This only detects the condition and names the affected URLs; it does
     * not remove anything. The caller is expected to exclude the named
     * `gated_locations` from analyze_page_html/JSON-LD/image-weight analysis
     * and report them in `skipped` instead of `findings` — every page-level
     * finding derived from a gated body would be describing a page nobody
     * asked for, which is worse than no finding at all.
     *
     * Severity is a deliberate split. When EVERY fetched URL shares one
     * identical body, nothing about the real site was observed at all — the
     * audit did not run, so this is a `blocker`, on the same footing as
     * "tax is on with no rates configured". When only some URLs match, the
     * rest of the crawl is still real signal, so this is `high`: serious and
     * worth a human's attention, but it does not void the whole run. Either
     * way the wording states only what was actually observed — byte-identical
     * bodies — never an assumed cause, because a site can legitimately serve
     * two identical pages and that is worth knowing without being told
     * "you have a gate" when a gate was not, in fact, confirmed.
     *
     * @param array $pages [ ['location'=>string, 'html'=>string], ... ] one entry per fetched URL
     * @return array{finding: array|null, gated_locations: string[]}
     */
    public static function analyze_duplicate_body_gate(array $pages): array
    {
        $by_hash = [];
        foreach ($pages as $page) {
            $location = (string) ($page['location'] ?? '');
            if ($location === '') {
                continue;
            }
            $hash = md5((string) ($page['html'] ?? ''));
            $by_hash[$hash][$location] = true; // dedupe repeats of the same URL within one hash bucket
        }

        $gated_locations = [];
        $largest_group = [];
        foreach ($by_hash as $locations_for_hash) {
            $locations_for_hash = array_keys($locations_for_hash);
            if (count($locations_for_hash) < 2) {
                continue; // a body seen at only one URL is not a duplicate
            }
            $gated_locations = array_merge($gated_locations, $locations_for_hash);
            if (count($locations_for_hash) > count($largest_group)) {
                $largest_group = $locations_for_hash;
            }
        }

        if (!$gated_locations) {
            return ['finding' => null, 'gated_locations' => []];
        }

        $gated_locations = array_values(array_unique($gated_locations));
        $total_distinct_urls = count(array_unique(array_column($pages, 'location')));
        $all_identical = count($by_hash) === 1 && count($largest_group) === $total_distinct_urls;

        if ($all_identical) {
            $finding = self::finding(
                'duplicate-body-gate',
                'blocker',
                'code-quality-and-production-readiness',
                'Every fetched page returned byte-identical content',
                'All ' . $total_distinct_urls . ' fetched URL(s) returned the exact same response body. This is not a defect the audit found on the site — it is the audit reporting that it could not see the site: something (a coming-soon gate, maintenance mode, or a catch-all redirect) is intercepting every request and serving one holding page instead of the real pages. Re-run site_readiness once that is off, or take it off temporarily, before trusting any page-level finding from this run.',
                'crawl-wide'
            );
        } else {
            $finding = self::finding(
                'duplicate-body-gate',
                'high',
                'code-quality-and-production-readiness',
                count($largest_group) . ' of ' . $total_distinct_urls . ' fetched pages returned byte-identical content',
                'These URLs returned a byte-identical response body: ' . implode(', ', $largest_group) . '. This may be a legitimate duplicate page, or a gate/redirect catching only some requests — verify manually. Either way, the individual page-level findings for these specific URLs from this run should not be trusted.',
                'crawl-wide'
            );
        }

        return ['finding' => $finding, 'gated_locations' => $gated_locations];
    }

    /**
     * Flags schema.org review/rating markup with no real reviews behind it —
     * "a store with no orders has no reviews to schema" (build-standard).
     *
     * @param array $json_ld_blocks decoded JSON-LD nodes (each already valid JSON)
     */
    public static function analyze_schema(array $json_ld_blocks, bool $has_real_reviews): array
    {
        if ($has_real_reviews) {
            return [];
        }
        $findings = [];
        $flat = [];
        self::flatten_schema_nodes($json_ld_blocks, $flat);
        foreach ($flat as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['@type'] ?? '';
            $types = is_array($type) ? $type : [$type];
            if (isset($node['aggregateRating']) || in_array('AggregateRating', $types, true)) {
                $findings[] = self::finding('schema-aggregate-rating-no-reviews', 'high', 'trust-and-conversion', 'aggregateRating schema present with no real reviews', 'JSON-LD node carries aggregateRating but the caller reports zero real reviews', 'site-wide schema');
            }
            if (isset($node['review']) || in_array('Review', $types, true)) {
                $findings[] = self::finding('schema-review-no-reviews', 'high', 'trust-and-conversion', 'review schema present with no real reviews', 'JSON-LD node carries review markup but the caller reports zero real reviews', 'site-wide schema');
            }
        }
        return $findings;
    }

    /** Recursively walk decoded JSON-LD (objects, @graph arrays) into a flat list of nodes. */
    private static function flatten_schema_nodes(array $blocks, array &$out): void
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (isset($block['@graph']) && is_array($block['@graph'])) {
                self::flatten_schema_nodes($block['@graph'], $out);
                continue;
            }
            $out[] = $block;
        }
    }

    /**
     * Image weight budget. $images: [['url'=>string,'bytes'=>int,'is_lcp'=>bool], ...]
     */
    public static function analyze_image_weights(array $images): array
    {
        $findings = [];
        foreach ($images as $img) {
            $url    = (string) ($img['url'] ?? 'unknown');
            $bytes  = (int) ($img['bytes'] ?? 0);
            $is_lcp = (bool) ($img['is_lcp'] ?? false);

            if ($is_lcp && $bytes > self::MAX_LCP_BYTES) {
                $findings[] = self::finding('lcp-image-over-budget', 'high', 'performance', 'LCP/hero image exceeds 200KB', round($bytes / 1024) . 'KB', $url);
            } elseif ($bytes > self::MAX_IMAGE_BYTES) {
                $findings[] = self::finding('image-over-budget', 'high', 'performance', 'Image exceeds 300KB', round($bytes / 1024) . 'KB', $url);
            }
        }
        return $findings;
    }

    /**
     * Security headers. Case-insensitive lookup over $headers (name => value).
     * Deliberately does not require Content-Security-Policy — build-standard
     * / security-standard §4 is explicit that an enforcing CSP shipped without
     * page-by-page testing breaks page builders, analytics, and checkout
     * scripts more often than it prevents a breach.
     *
     * Assumes $headers were gathered from an HTTPS response — HSTS only means
     * anything on HTTPS. A caller checking a plain-HTTP site should not call
     * this for the HSTS finding; the gatherer below skips it instead of
     * reporting a false positive.
     */
    public static function analyze_security_headers(array $headers): array
    {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = $value;
        }
        $findings = [];

        if (!isset($lower['strict-transport-security'])) {
            $findings[] = self::finding('missing-hsts', 'high', 'security', 'Strict-Transport-Security header missing', 'Site serves HTTPS but no strict-transport-security response header was seen', 'HTTP response headers');
        }
        if (!isset($lower['x-content-type-options']) || stripos((string) $lower['x-content-type-options'], 'nosniff') === false) {
            $findings[] = self::finding('missing-nosniff', 'medium', 'security', 'X-Content-Type-Options: nosniff missing', 'No x-content-type-options: nosniff header seen', 'HTTP response headers');
        }
        $has_frame_protection = isset($lower['x-frame-options'])
            || (isset($lower['content-security-policy']) && stripos((string) $lower['content-security-policy'], 'frame-ancestors') !== false);
        if (!$has_frame_protection) {
            $findings[] = self::finding('missing-frame-protection', 'medium', 'security', 'No clickjacking protection (X-Frame-Options or CSP frame-ancestors)', 'Neither x-frame-options nor a frame-ancestors CSP directive was seen', 'HTTP response headers');
        }
        if (!isset($lower['referrer-policy'])) {
            $findings[] = self::finding('missing-referrer-policy', 'medium', 'security', 'Referrer-Policy header missing', 'No referrer-policy response header was seen', 'HTTP response headers');
        }
        return $findings;
    }

    /**
     * A monitored URL (llms.txt, sitemap, robots.txt): non-200 is a finding,
     * and so is a 200 whose body is actually a 404 page — the bug this plugin
     * shipped in 1.9.1 (serve_llms_txt echoing a body under WordPress's own
     * default-404 status).
     */
    public static function analyze_url_status(string $label, string $url, int $status, string $body): array
    {
        if ($status !== 200) {
            return [self::finding('url-not-200-' . self::slug($label), 'high', 'code-quality-and-production-readiness', $label . ' did not return HTTP 200', 'Status ' . $status, $url)];
        }
        $trimmed = trim($body);
        $looks_like_404 = $trimmed === '';
        if (!$looks_like_404 && preg_match('/<title>[^<]*/i', $body, $tm)) {
            $looks_like_404 = (bool) preg_match('/(page )?not found|404/i', $tm[0]);
        }
        if ($looks_like_404) {
            return [self::finding('url-200-but-404-body-' . self::slug($label), 'high', 'code-quality-and-production-readiness', $label . ' returns 200 but the body looks like a 404 page', $trimmed === '' ? 'Empty body' : 'Body <title> matches a not-found pattern', $url)];
        }
        return [];
    }

    /**
     * A URL that should not exist must 404 for real — not 200, not a redirect
     * back to the homepage.
     */
    public static function analyze_404_handling(int $status_for_missing_url, string $final_url, string $home_url): array
    {
        $home_normalized  = rtrim($home_url, '/');
        $final_normalized = rtrim($final_url, '/');
        if ($status_for_missing_url === 200) {
            return [self::finding('missing-url-returns-200', 'high', 'code-quality-and-production-readiness', 'A nonexistent URL returns HTTP 200 instead of 404', 'Status 200 for a URL expected to be missing', $final_url)];
        }
        if ($final_normalized === $home_normalized && $status_for_missing_url >= 300 && $status_for_missing_url < 400) {
            return [self::finding('missing-url-redirects-home', 'high', 'code-quality-and-production-readiness', '404s silently redirect to the homepage instead of returning a real 404', 'Redirected to ' . $final_url, $final_url)];
        }
        return [];
    }

    /** Tax enabled with no configured rates is a Blocker: the store will charge nothing or fail at checkout. */
    public static function analyze_tax(bool $tax_enabled, array $rates): array
    {
        if ($tax_enabled && count($rates) === 0) {
            return [self::finding('tax-enabled-no-rates', 'blocker', 'functionality', 'Tax is enabled but no rates are configured', 'calc_taxes is on; the rate table returned zero rows', 'WooCommerce > Settings > Tax')];
        }
        return [];
    }

    /**
     * Stock policy should be an explicit decision. This can only detect
     * "left at the WooCommerce default" — that is a signal an owner never
     * looked at it, not proof the defaults are wrong for this store.
     */
    public static function analyze_stock(array $inventory_settings): array
    {
        $findings = [];
        $manage_stock = $inventory_settings['manage_stock'] ?? null;
        $backorders   = $inventory_settings['backorders'] ?? null;
        $low_stock    = $inventory_settings['low_stock_threshold'] ?? null;

        if ($manage_stock === null) {
            $findings[] = self::finding('stock-tracking-undecided', 'medium', 'functionality', 'Stock tracking appears left at its default, undecided value', 'manage_stock setting was not found/explicitly set', 'WooCommerce > Settings > Products > Inventory');
        }
        if ($backorders === null || $backorders === 'no') {
            $findings[] = self::finding('backorder-policy-default', 'low', 'functionality', 'Backorder behaviour looks like the untouched default', 'backorders setting is unset or the default "no"; this is a signal the owner has not made the call, not proof it is wrong', 'WooCommerce > Settings > Products > Inventory');
        }
        if ($low_stock === null || $low_stock === '') {
            $findings[] = self::finding('low-stock-threshold-unset', 'low', 'functionality', 'Low-stock threshold not configured', 'low_stock_threshold is empty', 'WooCommerce > Settings > Products > Inventory');
        }
        return $findings;
    }

    /**
     * Legal pages: $pages maps role => ['exists'=>bool,'published'=>bool,'id'=>int].
     * $wired maps a WooCommerce setting name => the page id it currently points at
     * (0/absent means unwired). $has_store says whether WooCommerce (or any
     * store) is active on this site.
     *
     * `terms` and `privacy` apply to every site and stay HIGH when missing or
     * unpublished regardless of $has_store. `refund` and `shipping` — and the
     * WooCommerce wiring settings, which by definition do not exist without
     * WooCommerce — only mean anything once there is a store: a free plugin's
     * site with no checkout has nothing to refund or ship, and demanding
     * those pages there is a false positive expensive enough to train an
     * agent (or an owner) to skim past the findings list, which is exactly
     * how a real HIGH gets missed. When $has_store is false those checks are
     * reported in `skipped`, never in `findings`.
     *
     * @return array{findings: array, skipped: array}
     */
    public static function analyze_legal_pages(array $pages, array $wired, bool $has_store): array
    {
        $findings = [];
        $skipped  = [];
        $store_only_roles = ['refund', 'shipping'];

        foreach (['terms', 'privacy', 'refund', 'shipping'] as $role) {
            if (!$has_store && in_array($role, $store_only_roles, true)) {
                $skipped[] = [
                    'check'  => ucfirst($role) . ' page',
                    'reason' => 'No store is active on this site (WooCommerce not found); ' . $role . ' pages only apply to a store',
                ];
                continue;
            }
            $info = $pages[$role] ?? ['exists' => false, 'published' => false, 'id' => 0];
            if (empty($info['exists'])) {
                $findings[] = self::finding('legal-page-missing-' . $role, 'high', 'trust-and-conversion', ucfirst($role) . ' page does not exist', 'No page found for role: ' . $role, $role);
                continue;
            }
            if (empty($info['published'])) {
                $findings[] = self::finding('legal-page-unpublished-' . $role, 'high', 'trust-and-conversion', ucfirst($role) . ' page exists but is not published', 'Page id ' . (int) ($info['id'] ?? 0) . ' is not in a published state', $role);
            }
        }

        if (!$has_store) {
            if ($wired) {
                $skipped[] = [
                    'check'  => 'Legal page wiring (WooCommerce settings)',
                    'reason' => 'No store is active on this site (WooCommerce not found); these settings only exist for a store',
                ];
            }
        } else {
            foreach ($wired as $setting => $page_id) {
                if ((int) $page_id === 0) {
                    $findings[] = self::finding('legal-page-not-wired-' . self::slug($setting), 'medium', 'trust-and-conversion', 'Legal page exists but is not wired into ' . $setting, 'Setting "' . $setting . '" has no page id set', $setting);
                }
            }
        }

        return ['findings' => $findings, 'skipped' => $skipped];
    }

    /** Any snippet with a recorded last_error is a live, currently-broken piece of code — at minimum High. */
    public static function analyze_snippets(array $snippets): array
    {
        $findings = [];
        foreach ($snippets as $snippet) {
            $error = trim((string) ($snippet['last_error'] ?? ''));
            if ($error === '') {
                continue;
            }
            $name = (string) ($snippet['name'] ?? ('snippet #' . ($snippet['id'] ?? '?')));
            $findings[] = self::finding('snippet-error-' . self::slug((string) ($snippet['id'] ?? $name)), 'high', 'code-quality-and-production-readiness', 'Snippet has a recorded error: ' . $name, $error, 'Snippet: ' . $name);
        }
        return $findings;
    }

    /**
     * Open registration with nothing needing it is a finding; a default role
     * above subscriber/customer is always a finding regardless of whether
     * registration is even open, since it applies to every future signup.
     */
    public static function analyze_registration(int $users_can_register, string $default_role, bool $store_needs_accounts): array
    {
        $findings = [];
        if ($users_can_register === 1 && !$store_needs_accounts) {
            $findings[] = self::finding('open-registration-unneeded', 'medium', 'security', 'Public user registration is open but nothing on the site needs it', 'users_can_register=1, no store/membership feature requires visitor accounts', 'Settings > General');
        }
        $safe_roles = ['subscriber', 'customer'];
        if (!in_array(strtolower($default_role), $safe_roles, true)) {
            $findings[] = self::finding('default-role-elevated', 'blocker', 'security', 'New-user default role is above subscriber/customer', 'default_role=' . $default_role, 'Settings > General');
        }
        return $findings;
    }

    /**
     * Build the response envelope. `pass` is true only when zero open
     * blocker/high findings remain — skipped/not_verified checks are never
     * silently counted toward a pass.
     *
     * @param array $findings    flat list of finding arrays from the analyzers above
     * @param array $skipped     [{check, reason}, ...]
     * @param array $not_verified [{check, reason, owner_should}, ...]
     */
    public static function summarize(array $findings, array $skipped, array $not_verified): array
    {
        $counts = ['blocker' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'observation' => 0];
        foreach ($findings as $f) {
            $sev = $f['severity'] ?? 'observation';
            if (isset($counts[$sev])) {
                $counts[$sev]++;
            }
        }
        $open_blocking = 0;
        foreach ($findings as $f) {
            if (($f['status'] ?? 'open') === 'open' && in_array($f['severity'] ?? '', ['blocker', 'high'], true)) {
                $open_blocking++;
            }
        }
        return [
            'pass'         => $open_blocking === 0,
            // `pass` only means nothing that RAN failed. `complete` is what says
            // everything was actually checked. A consumer that reads `pass` alone
            // will call a store with no WooCommerce checks "ready", which is the
            // exact false confidence this tool exists to remove.
            'complete'     => count($skipped) === 0,
            'counts'       => $counts,
            'findings'     => $findings,
            'skipped'      => $skipped,
            'not_verified' => $not_verified,
        ];
    }

    /** One finding in the pre-launch-audit-compatible shape. */
    private static function finding(string $id, string $severity, string $category, string $title, string $evidence, string $location): array
    {
        return [
            'id'             => $id,
            'severity'       => $severity,
            'category'       => $category,
            'title'          => $title,
            'evidence'       => $evidence,
            'location'       => $location,
            'status'         => 'open',
            'owner_decision' => false,
        ];
    }

    /** kebab-case-ish slug for building stable finding ids out of dynamic labels. */
    private static function slug(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-') ?: 'unknown';
    }

    // =========================================================================
    // GATHERERS — WordPress-dependent. Fetch, hand off to an analyzer, collect.
    // =========================================================================

    public static function site_readiness(array $args): array
    {
        $scope   = (string) ($args['scope'] ?? 'site');
        $post_id = (int) ($args['post_id'] ?? 0);
        $extra_urls = array_values(array_filter(array_map('strval', (array) ($args['urls'] ?? []))));

        $findings     = [];
        $skipped      = [];
        $not_verified = [
            ['check' => 'Viewport sweep at 375/768/1280/1440px', 'reason' => 'No browser in this session', 'owner_should' => 'Open the site at each width and confirm nothing overflows horizontally or overlaps.'],
            ['check' => 'Browser console errors', 'reason' => 'No browser in this session', 'owner_should' => 'Open devtools on the key pages and confirm the console is clean.'],
            ['check' => 'Real rendered contrast', 'reason' => 'Contrast depends on actual rendered background, not source colors', 'owner_should' => 'Check text-over-background contrast with a contrast checker on the live page.'],
            ['check' => 'Animation smoothness', 'reason' => 'Requires visually observing motion in a browser', 'owner_should' => 'Scroll/interact through the page and confirm animations do not stutter or jank.'],
        ];

        // ---- gather the pages to check --------------------------------------
        $page_targets = []; // [ ['location' => string, 'html' => string], ... ]

        if ($scope === 'page') {
            if (!$post_id) {
                return ['success' => false, 'error' => 'post_id is required when scope=page.'];
            }
            $url = get_permalink($post_id);
            if ($url) {
                $html = self::fetch_body($url);
                if ($html !== null) {
                    $page_targets[] = ['location' => $url, 'html' => $html];
                }
            }
        } else {
            // Every page here is a real HTTP round trip against this same site,
            // and every image below is another one. Uncapped, a large site
            // exhausts max_execution_time and the caller gets a dead request
            // instead of a report — so the crawl is bounded, and whatever the
            // budget could not reach is named in `skipped` rather than quietly
            // treated as checked.
            $posts = get_posts([
                'post_type'      => ['page', 'post'],
                'post_status'    => 'publish',
                'posts_per_page' => self::MAX_PAGES + 1,
                'fields'         => 'ids',
            ]);
            $posts = (array) $posts;
            if (count($posts) > self::MAX_PAGES) {
                $skipped[] = [
                    'check'  => 'page-level checks on ' . (count($posts) - self::MAX_PAGES) . ' further page(s)',
                    'reason' => 'Crawl budget is ' . self::MAX_PAGES . ' pages per run; re-run with scope=page and a post_id to check a specific one',
                ];
                $posts = array_slice($posts, 0, self::MAX_PAGES);
            }
            foreach ($posts as $id) {
                $url = get_permalink($id);
                if (!$url) {
                    continue;
                }
                $html = self::fetch_body($url);
                if ($html !== null) {
                    $page_targets[] = ['location' => $url, 'html' => $html];
                }
            }
        }
        foreach ($extra_urls as $url) {
            $html = self::fetch_body($url);
            if ($html !== null) {
                $page_targets[] = ['location' => $url, 'html' => $html];
            }
        }

        if (!$page_targets) {
            $skipped[] = ['check' => 'page-level checks (headings/alt/meta/OG/JSON-LD/links)', 'reason' => 'No fetchable pages found for the given scope'];
        }

        // ---- duplicate-body gate detection ----------------------------------
        // Fetched anonymously, every URL behind a coming-soon gate (or a
        // maintenance mode, or a catch-all redirect) comes back as the same
        // holding page. Analyzing that page per-URL produces confident,
        // detailed findings about a page nobody asked for — worse than no
        // answer. Detect it by content (see analyze_duplicate_body_gate) and
        // exclude the affected bodies from every page-level check below.
        $gate = self::analyze_duplicate_body_gate($page_targets);
        if ($gate['finding'] !== null) {
            $findings[] = $gate['finding'];
        }
        $gated_locations = array_flip($gate['gated_locations']);
        if ($gated_locations) {
            $skipped[] = [
                'check'  => 'page-level checks (headings/alt/meta/OG/JSON-LD/images) on ' . count($gated_locations) . ' page(s) with duplicate content',
                'reason' => 'These URLs returned a body identical to another URL, so nothing is known about their real content — see the duplicate-body-gate finding. Re-run this tool once any gate/maintenance mode is off (or take it off temporarily) to audit the real pages: ' . implode(', ', array_keys($gated_locations)),
            ];
        }

        $all_json_ld = [];
        $images_seen = [];
        $images_over_budget = false;
        foreach ($page_targets as $target) {
            if (isset($gated_locations[$target['location']])) {
                continue; // this body is a duplicate stand-in, not the real page — see the gate finding/skip above.
            }

            $findings = array_merge($findings, self::analyze_page_html($target['html'], $target['location']));

            if (preg_match_all('/<script[^>]{0,200}type=["\']application\/ld\+json["\'][^>]{0,200}>(.*?)<\/script>/is', $target['html'], $lm)) {
                foreach ($lm[1] as $block) {
                    $decoded = json_decode($block, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $all_json_ld[] = $decoded;
                    }
                }
            }

            // Image weights: fetch remote size via Content-Length rather than
            // downloading the file. The page's first <img> is treated as the
            // LCP candidate — a proxy, not a guarantee, since real LCP depends
            // on layout the way this tool cannot observe.
            if (preg_match_all('/<img\b[^>]{0,500}\bsrc=["\']([^"\']+)["\'][^>]{0,500}>/i', $target['html'], $srcm)) {
                foreach ($srcm[1] as $i => $src) {
                    $abs = self::to_absolute_url($src, $target['location']);
                    if (!$abs || isset($images_seen[$abs])) {
                        continue;
                    }
                    if (count($images_seen) >= self::MAX_IMAGES) {
                        $images_over_budget = true;
                        break;
                    }
                    // The first image on each page is the LCP candidate for
                    // that page — a proxy, since real LCP depends on layout
                    // this tool cannot observe. Judging only the very first
                    // image site-wide would leave every other hero unchecked.
                    $is_lcp = $i === 0;
                    $images_seen[$abs] = true;
                    $bytes = self::remote_content_length($abs);
                    if ($bytes === null) {
                        continue;
                    }
                    $images_seen[$abs] = ['url' => $abs, 'bytes' => $bytes, 'is_lcp' => $is_lcp];
                }
            }
        }
        if ($images_over_budget) {
            $skipped[] = [
                'check'  => 'image weight budget beyond the first ' . self::MAX_IMAGES . ' images',
                'reason' => 'Image budget is ' . self::MAX_IMAGES . ' images per run; re-run with scope=page to check one page in full',
            ];
        }
        $image_rows = array_values(array_filter($images_seen, 'is_array'));
        $all_targets_gated = $page_targets && count($gated_locations) >= count($page_targets);
        if ($image_rows) {
            $findings = array_merge($findings, self::analyze_image_weights($image_rows));
        } elseif (!$all_targets_gated) {
            // When every page target was gated, the skip above already
            // explains why no images were checked — this would just repeat it.
            $skipped[] = ['check' => 'image weight budget', 'reason' => 'No images with a readable Content-Length were found'];
        }

        // ---- schema / reviews -------------------------------------------------
        $has_real_reviews = self::site_has_real_reviews();
        if ($all_json_ld) {
            $findings = array_merge($findings, self::analyze_schema($all_json_ld, $has_real_reviews));
        }

        // ---- security headers ---------------------------------------------
        $home = home_url('/');
        $is_https = str_starts_with($home, 'https://');
        $headers = self::remote_headers($home);
        if ($headers !== null) {
            $header_findings = self::analyze_security_headers($headers);
            if (!$is_https) {
                // HSTS is meaningless without HTTPS in the first place — drop
                // that one finding rather than report a false positive, and
                // say so explicitly in skipped so it is never read as a pass.
                $header_findings = array_values(array_filter($header_findings, fn($f) => $f['id'] !== 'missing-hsts'));
                $skipped[] = ['check' => 'HSTS enforcement', 'reason' => 'Site is not served over HTTPS, so HSTS is moot'];
            }
            $findings = array_merge($findings, $header_findings);
        } else {
            $skipped[] = ['check' => 'security headers', 'reason' => 'Could not fetch the homepage to read response headers'];
        }

        // ---- llms.txt / sitemap / robots.txt -------------------------------
        $llms_url = home_url('/llms.txt');
        [$status, $body] = self::remote_status_and_body($llms_url);
        if ($status !== null) {
            $findings = array_merge($findings, self::analyze_url_status('llms.txt', $llms_url, $status, $body));
        } else {
            $skipped[] = ['check' => 'llms.txt', 'reason' => 'Request failed'];
        }

        // Ask the SEO tool where the sitemap actually is. Hardcoding the core
        // /wp-sitemap.xml path reports a false "sitemap missing" on every site
        // running Yoast, Rank Math, AIOSEO or SEOPress, which serve their own.
        $sitemap_url = Claudpress_Tools_SEO::sitemap_url();
        [$status, $body] = self::remote_status_and_body($sitemap_url);
        if ($status !== null) {
            $findings = array_merge($findings, self::analyze_url_status('sitemap', $sitemap_url, $status, $body));
        } else {
            $skipped[] = ['check' => 'sitemap', 'reason' => 'Request failed'];
        }

        $robots_url = home_url('/robots.txt');
        [$status, $body] = self::remote_status_and_body($robots_url);
        if ($status !== null) {
            $findings = array_merge($findings, self::analyze_url_status('robots.txt', $robots_url, $status, $body));
        } else {
            $skipped[] = ['check' => 'robots.txt', 'reason' => 'Request failed'];
        }

        // ---- 404 handling ---------------------------------------------------
        $probe_url = home_url('/claudpress-readiness-probe-' . substr(md5((string) time()), 0, 12) . '/');
        $probe = self::remote_status_and_final_url($probe_url);
        if ($probe !== null) {
            [$probe_status, $final_url] = $probe;
            $findings = array_merge($findings, self::analyze_404_handling($probe_status, $final_url, $home));
        } else {
            $skipped[] = ['check' => '404 handling', 'reason' => 'Probe request failed'];
        }

        // ---- snippets ---------------------------------------------------------
        if (class_exists('Claudpress_Snippets')) {
            $snippets = Claudpress_Snippets::all();
            $findings = array_merge($findings, self::analyze_snippets(is_array($snippets) ? $snippets : []));
        } else {
            $skipped[] = ['check' => 'snippet errors', 'reason' => 'Claudpress_Snippets is not available'];
        }

        // ---- legal pages ----------------------------------------------------
        $legal_pages = self::gather_legal_pages();
        $legal_wired = self::gather_legal_wiring();
        $legal_result = self::analyze_legal_pages($legal_pages, $legal_wired, class_exists('WooCommerce'));
        $findings = array_merge($findings, $legal_result['findings']);
        $skipped  = array_merge($skipped, $legal_result['skipped']);

        // ---- registration / roles -------------------------------------------
        $users_can_register = (int) get_option('users_can_register', 0);
        $default_role       = (string) get_option('default_role', 'subscriber');
        $store_needs_accounts = class_exists('WooCommerce'); // WooCommerce checkout typically wants customer accounts.
        $findings = array_merge($findings, self::analyze_registration($users_can_register, $default_role, $store_needs_accounts));

        // ---- WooCommerce-only checks ------------------------------------------
        if (class_exists('WooCommerce') && function_exists('WC')) {
            $tax_enabled = get_option('woocommerce_calc_taxes', 'no') === 'yes';
            $rates = self::gather_tax_rates();
            $findings = array_merge($findings, self::analyze_tax($tax_enabled, $rates));

            $inventory = [
                'manage_stock'        => get_option('woocommerce_manage_stock', false) !== false ? get_option('woocommerce_manage_stock') : null,
                'backorders'          => get_option('woocommerce_hold_stock_minutes', false) !== false ? 'set' : null,
                'low_stock_threshold' => get_option('woocommerce_notify_low_stock_amount', ''),
            ];
            $findings = array_merge($findings, self::analyze_stock($inventory));
        } else {
            $skipped[] = ['check' => 'WooCommerce tax sanity', 'reason' => 'WooCommerce is not active'];
            $skipped[] = ['check' => 'WooCommerce stock policy', 'reason' => 'WooCommerce is not active'];
        }

        $summary = self::summarize($findings, $skipped, $not_verified);
        return array_merge(['success' => true], $summary);
    }

    // ---- gather helpers -----------------------------------------------------

    private static function fetch_body(string $url): ?string
    {
        $resp = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($resp)) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }
        return (string) wp_remote_retrieve_body($resp);
    }

    private static function remote_status_and_body(string $url): array
    {
        $resp = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($resp)) {
            return [null, ''];
        }
        return [(int) wp_remote_retrieve_response_code($resp), (string) wp_remote_retrieve_body($resp)];
    }

    /**
     * Deliberately does NOT follow redirects: this backs the 404 probe, and a
     * followed redirect reports 200 at the destination, which is exactly the
     * "404s silently redirect to the homepage" bug it exists to catch.
     *
     * @return array{0:int,1:string}|null [status, final url]
     */
    private static function remote_status_and_final_url(string $url): ?array
    {
        $resp = wp_remote_get($url, ['timeout' => 15, 'redirection' => 0]);
        if (is_wp_error($resp)) {
            return null;
        }
        $final = $url;
        $headers = wp_remote_retrieve_headers($resp);
        if (is_object($headers) && method_exists($headers, 'offsetGet') && $headers->offsetGet('location')) {
            $final = (string) $headers->offsetGet('location');
        } elseif (is_array($headers) && !empty($headers['location'])) {
            $final = (string) $headers['location'];
        }
        return [(int) wp_remote_retrieve_response_code($resp), $final];
    }

    private static function remote_headers(string $url): ?array
    {
        $resp = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($resp)) {
            return null;
        }
        $headers = wp_remote_retrieve_headers($resp);
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            return $headers->getAll();
        }
        return is_array($headers) ? $headers : [];
    }

    private static function remote_content_length(string $url): ?int
    {
        $resp = wp_remote_head($url, ['timeout' => 10]);
        if (is_wp_error($resp)) {
            return null;
        }
        $len = wp_remote_retrieve_header($resp, 'content-length');
        return $len !== '' && $len !== null ? (int) $len : null;
    }

    private static function to_absolute_url(string $src, string $base): ?string
    {
        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }
        if (strpos($src, '//') === 0) {
            return 'https:' . $src;
        }
        if (strpos($src, '/') === 0) {
            $host = wp_parse_url($base, PHP_URL_HOST);
            $scheme = wp_parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $host ? $scheme . '://' . $host . $src : null;
        }
        return null; // relative paths without a leading slash are rare enough to skip rather than mis-resolve.
    }

    /** WooCommerce has no "are reviews real" flag; a positive review count on any product is the closest honest signal. */
    private static function site_has_real_reviews(): bool
    {
        if (!function_exists('wc_get_products')) {
            return false;
        }
        $counts = wp_count_comments();
        return isset($counts->approved) && (int) $counts->approved > 0;
    }

    private static function gather_tax_rates(): array
    {
        global $wpdb;
        if (!isset($wpdb) || !isset($wpdb->prefix)) {
            return [];
        }
        $table = $wpdb->prefix . 'woocommerce_tax_rates';
        $rows = $wpdb->get_results("SELECT tax_rate_id FROM {$table}", ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, array{exists:bool, published:bool, id:int}> */
    private static function gather_legal_pages(): array
    {
        $map = [
            'terms'    => (int) get_option('woocommerce_terms_page_id', 0),
            'privacy'  => (int) get_option('wp_page_for_privacy_policy', 0),
            'refund'   => (int) get_option('woocommerce_refund_returns_page_id', 0),
            'shipping' => (int) get_option('woocommerce_shipping_policy_page_id', 0),
        ];
        $out = [];
        foreach ($map as $role => $id) {
            $post = $id ? get_post($id) : null;
            $out[$role] = [
                'exists'    => (bool) $post,
                'published' => $post ? ($post->post_status === 'publish') : false,
                'id'        => $id,
            ];
        }
        return $out;
    }

    private static function gather_legal_wiring(): array
    {
        return [
            'woocommerce_terms_page_id'            => (int) get_option('woocommerce_terms_page_id', 0),
            'woocommerce_refund_returns_page_id'   => (int) get_option('woocommerce_refund_returns_page_id', 0),
        ];
    }
}
