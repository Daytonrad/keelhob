<?php
/**
 * Seo tools that belong to Claudpress Pro.
 *
 * Carved out of the free plugin rather than gated inside it. A licence check
 * living in a file the user already has is a five-minute edit away from being
 * deleted, so the code is simply not in the free zip.
 *
 * seo_analyze reads back what the free seo_meta and seo_schema tools wrote, so
 * it calls across to Claudpress_Tools_Seo rather than duplicating them. Those
 * two stay free: writing meta and schema is part of building a site, while
 * scoring it against a target keyword is the ongoing optimisation loop.
 *
 * seo_redirect moved but do_redirects did NOT. Creating a redirect is a Pro
 * action; SERVING one that already exists must keep working on the free tier,
 * or a customer who lets Pro lapse would have every redirect they ever made
 * turn into a 404. Downgrading should cost you new features, never break the
 * site you already have.
 *
 * Tool names and shapes are unchanged, so an agent that worked against a Pro
 * site before the split sees no difference.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Seo_Pro
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('seo_analyze', 'Analyze a post for a target keyword and return an actionable SEO + GEO (AI-answer-engine) report: classical on-page checks (title/meta length, keyword presence/density, word count, headings, internal/external links, image alt coverage) plus GEO checks that matter for being cited by ChatGPT/Perplexity/Google AI Overviews/Claude (answer-first opening paragraph, FAQ/list/table structuring, JSON-LD schema presence, author byline + last-updated date for E-E-A-T). Read-only.', [
            'type' => 'object',
            'properties' => [
                'post_id'       => ['type' => 'integer'],
                'focus_keyword' => ['type' => 'string'],
            ],
            'required' => ['post_id'],
        ], [self::class, 'seo_analyze'], ['readOnlyHint' => true]);

        $r->add('seo_redirect', 'Manage 301/302 redirects via a built-in Claudpress redirect table that runs on template_redirect (independent of any redirect plugin the site may also have active). Actions: add, list, delete.', [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['add', 'list', 'delete'], 'default' => 'list'],
                'from'   => ['type' => 'string', 'description' => 'Source path, e.g. /old-page/'],
                'to'     => ['type' => 'string', 'description' => 'Target URL or path.'],
                'type'   => ['type' => 'integer', 'enum' => [301, 302], 'default' => 301],
                'id'     => ['type' => 'string', 'description' => 'For delete: the "from" path.'],
            ],
            'required' => ['action'],
        ], [self::class, 'seo_redirect'], ['destructiveHint' => true]);
    }

    public static function seo_analyze(array $args): array
    {
        $post = get_post((int) ($args['post_id'] ?? 0));
        if (!$post) {
            return ['success' => false, 'error' => 'Post not found.'];
        }
        $kw    = strtolower(trim((string) ($args['focus_keyword'] ?? '')));
        $title = get_the_title($post);
        $body  = wp_strip_all_tags(apply_filters('the_content', $post->post_content));
        $words = str_word_count($body);
        $slug  = $post->post_name;

        $meta   = Claudpress_Tools_Seo::seo_meta(['action' => 'get', 'post_id' => $post->ID]);
        $seo_title = $meta['meta']['title'] ?? '';
        $seo_desc  = $meta['meta']['description'] ?? '';

        preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $post->post_content, $hmatches, PREG_SET_ORDER);
        $headings = array_map(fn($m) => ['level' => (int) $m[1], 'text' => trim(wp_strip_all_tags($m[2]))], $hmatches);

        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $post->post_content, $links);
        $internal = $external = 0;
        foreach ($links[1] ?? [] as $href) {
            $host = wp_parse_url($href, PHP_URL_HOST);
            if (!$host || $host === $home) {
                $internal++;
            } else {
                $external++;
            }
        }
        preg_match_all('/<img\s[^>]*>/i', $post->post_content, $imgs);
        $img_total = count($imgs[0] ?? []);
        $img_no_alt = 0;
        foreach ($imgs[0] ?? [] as $img) {
            if (!preg_match('/\balt=["\'][^"\']+["\']/i', $img)) {
                $img_no_alt++;
            }
        }

        $checks = [];
        $len_t = mb_strlen($seo_title ?: $title);
        $checks[] = ['check' => 'SEO title length', 'value' => $len_t, 'ok' => $len_t >= 30 && $len_t <= 60, 'ideal' => '30–60 chars'];
        $len_d = mb_strlen($seo_desc);
        $checks[] = ['check' => 'Meta description length', 'value' => $len_d, 'ok' => $len_d >= 120 && $len_d <= 160, 'ideal' => '120–160 chars'];
        $checks[] = ['check' => 'Word count', 'value' => $words, 'ok' => $words >= 300, 'ideal' => '≥ 300 words'];

        $density = null;
        if ($kw !== '') {
            $occ = substr_count(strtolower($body), $kw);
            $density = $words ? round($occ / max(1, $words) * 100, 2) : 0;
            $checks[] = ['check' => 'Keyword in SEO title', 'ok' => str_contains(strtolower($seo_title ?: $title), $kw)];
            $checks[] = ['check' => 'Keyword in URL slug', 'ok' => str_contains($slug, sanitize_title($kw))];
            $checks[] = ['check' => 'Keyword in meta description', 'ok' => str_contains(strtolower($seo_desc), $kw)];
            $first_para = mb_substr($body, 0, 200);
            $checks[] = ['check' => 'Keyword in first paragraph', 'ok' => str_contains(strtolower($first_para), $kw)];
            $checks[] = ['check' => 'Keyword density', 'value' => $density . '%', 'ok' => $density >= 0.5 && $density <= 2.5, 'ideal' => '0.5–2.5%'];
        }
        $checks[] = ['check' => 'Internal links', 'value' => $internal, 'ok' => $internal >= 1];
        $checks[] = ['check' => 'Images missing alt', 'value' => $img_no_alt . '/' . $img_total, 'ok' => $img_no_alt === 0];
        $checks[] = ['check' => 'Has H1/H2 structure', 'value' => count($headings), 'ok' => count($headings) >= 1];

        // ---- GEO / AI-answer-engine checks -------------------------------
        $geo_checks = [];

        $first_para_text = trim(mb_substr($body, 0, 320));
        $answer_first = $first_para_text !== '' && $words > 0
            && (mb_strlen($first_para_text) >= 40)
            && !preg_match('/^(in this (post|article|guide)|welcome to|today,? we|have you ever)/i', $first_para_text);
        $geo_checks[] = ['check' => 'Answer-first opening (states the answer, not a preamble)', 'ok' => $answer_first, 'note' => 'AI engines extract the first direct statement; avoid "In this article we will..." openings.'];

        $has_faq = (bool) preg_match('/<h[2-4][^>]*>[^<]*\?[^<]*<\/h[2-4]>/i', $post->post_content);
        $geo_checks[] = ['check' => 'FAQ-style Q&A headings present', 'ok' => $has_faq, 'note' => 'Question-phrased H2/H3 headings are what answer engines lift as direct citations.'];

        $has_list_or_table = (bool) preg_match('/<(ul|ol|table)[\s>]/i', $post->post_content);
        $geo_checks[] = ['check' => 'List/table structuring present', 'ok' => $has_list_or_table, 'note' => 'Lists and tables are the most extractable/citable content shape for LLM answer engines.'];

        $schema = Claudpress_Tools_Seo::seo_schema(['action' => 'get', 'post_id' => $post->ID]);
        $has_schema = !empty($schema['schema']);
        $geo_checks[] = ['check' => 'JSON-LD schema attached', 'ok' => $has_schema, 'note' => 'Use seo_schema to attach Article/FAQPage/HowTo/Product schema — AI engines weight structured data heavily for attribution.'];

        $author = get_userdata((int) $post->post_author);
        $has_bio = $author && trim((string) $author->description) !== '';
        $geo_checks[] = ['check' => 'Author byline has a bio (E-E-A-T)', 'ok' => (bool) $has_bio, 'note' => 'Named author + credentials is a trust signal both Google and AI engines use to decide who to cite/recommend.'];

        $modified = strtotime($post->post_modified_gmt);
        $fresh = $modified && (time() - $modified) < (180 * DAY_IN_SECONDS);
        $geo_checks[] = ['check' => 'Updated within last 6 months', 'value' => $post->post_modified, 'ok' => (bool) $fresh, 'note' => 'Stale content is deprioritized by AI answer engines in favor of recently-verified sources.'];

        $geo_checks[] = ['check' => 'Has outbound citation(s)', 'value' => $external, 'ok' => $external >= 1, 'note' => 'Linking to authoritative external sources signals the page is a well-sourced primary reference, not a rehash.'];

        $checks = array_merge($checks, $geo_checks);

        $passed = count(array_filter($checks, fn($c) => ($c['ok'] ?? false)));
        $geo_passed = count(array_filter($geo_checks, fn($c) => ($c['ok'] ?? false)));
        return [
            'success'      => true,
            'post_id'      => $post->ID,
            'title'        => $title,
            'focus_keyword' => $kw,
            'word_count'   => $words,
            'keyword_density' => $density,
            'headings'     => $headings,
            'links'        => ['internal' => $internal, 'external' => $external],
            'images'       => ['total' => $img_total, 'missing_alt' => $img_no_alt],
            'score'        => round($passed / max(1, count($checks)) * 100),
            'geo_score'    => round($geo_passed / max(1, count($geo_checks)) * 100),
            'checks'       => $checks,
        ];
    }

    public static function seo_redirect(array $args): array
    {
        $action = $args['action'] ?? 'list';
        $store  = get_option('claudpress_redirects', []);
        if (!is_array($store)) {
            $store = [];
        }

        switch ($action) {
            case 'add':
                $from = '/' . ltrim((string) ($args['from'] ?? ''), '/');
                $store[$from] = ['to' => (string) ($args['to'] ?? ''), 'type' => (int) ($args['type'] ?? 301)];
                update_option('claudpress_redirects', $store);
                return ['success' => true, 'from' => $from, 'to' => $store[$from]['to'], 'total' => count($store)];
            case 'delete':
                $id = '/' . ltrim((string) ($args['id'] ?? $args['from'] ?? ''), '/');
                unset($store[$id]);
                update_option('claudpress_redirects', $store);
                return ['success' => true, 'deleted' => $id];
            case 'list':
            default:
                return ['success' => true, 'redirects' => $store];
        }
    }
}
