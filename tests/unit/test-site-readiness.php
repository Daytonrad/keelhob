<?php
/**
 * `site_readiness` is the exit code for the `build-standard` skill — the tool
 * whose whole reason to exist is that "done" stops being an argument and
 * becomes a machine-checkable answer. If its analyzers lie — flag a clean
 * page, or wave through a broken one — the tool is worse than not having it,
 * because a false "pass" is exactly the false confidence pre-launch audits
 * of real sites (Pranknologia, 56/100, undetected by the builder) showed
 * this plugin needs to stop shipping.
 *
 * Every analyzer here is a pure function: array/string/int/bool in, findings
 * out, no WordPress call inside. That is deliberate (see the file's own
 * docblock) and it is what makes this suite possible without a live
 * WordPress install — tests/bootstrap.php stubs only a handful of simple
 * WordPress functions and has no WP_Query/get_posts/wp_remote_get. So this
 * file tests only the analyzers above the "ANALYZERS" marker in
 * class-tools-readiness.php, never site_readiness() itself or its private
 * gather_ and remote_ helpers.
 *
 * Every rule below is tested in both directions: the failing case AND the
 * clean case that must NOT produce a finding. A checker that flags
 * everything is exactly as useless as one that flags nothing — false
 * positives are what make an owner stop trusting the audit, which is the
 * same failure mode as a false negative, just slower.
 */

require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-readiness.php';

// =============================================================================
// analyze_page_html — headings
// =============================================================================

CP_Test::group('site_readiness — heading order');

$ids = static function (array $findings): array {
    return array_column($findings, 'id');
};

$no_h1 = Claudpress_Tools_Readiness::analyze_page_html('<h2>Section</h2><p>text</p>', 'page-a');
CP_Test::ok(in_array('missing-h1', $ids($no_h1), true), 'a page with no <h1> at all is a finding');

$two_h1 = Claudpress_Tools_Readiness::analyze_page_html('<h1>One</h1><h1>Two</h1>', 'page-b');
CP_Test::ok(in_array('multiple-h1', $ids($two_h1), true), 'a page with two <h1> elements is a finding');

$skip = Claudpress_Tools_Readiness::analyze_page_html('<h1>Title</h1><h2>Section</h2><h4>Sub</h4>', 'page-c');
CP_Test::ok(in_array('skipped-heading-level', $ids($skip), true), 'h2 followed directly by h4 (skipping h3) is a finding');

$clean_headings = Claudpress_Tools_Readiness::analyze_page_html('<h1>Title</h1><h2>Section</h2><h3>Sub</h3>', 'page-d');
CP_Test::ok(!in_array('missing-h1', $ids($clean_headings), true), 'a page with exactly one <h1> is not flagged for a missing h1');
CP_Test::ok(!in_array('multiple-h1', $ids($clean_headings), true), 'a page with exactly one <h1> is not flagged for multiple h1s');
CP_Test::ok(!in_array('skipped-heading-level', $ids($clean_headings), true), 'h1 -> h2 -> h3 in order is never flagged as a skipped level');

// =============================================================================
// analyze_page_html — alt text
// =============================================================================

CP_Test::group('site_readiness — image alt text');

$missing_alt = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><img src="a.jpg">', 'page-e');
CP_Test::ok(in_array('images-missing-alt', $ids($missing_alt), true), 'an <img> with no alt attribute at all is a finding');

$empty_alt = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><img src="a.jpg" alt="">', 'page-f');
CP_Test::ok(!in_array('images-missing-alt', $ids($empty_alt), true), 'alt="" is a deliberate decorative-image marker and must pass, not be flagged as missing');

$real_alt = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><img src="a.jpg" alt="A dog running on a beach">', 'page-g');
CP_Test::ok(!in_array('images-missing-alt', $ids($real_alt), true), 'an <img> with real alt text is not flagged');

// =============================================================================
// analyze_page_html — dead anchors
// =============================================================================

CP_Test::group('site_readiness — href="#"');

$dead_href = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><a href="#">Click</a>', 'page-h');
CP_Test::ok(in_array('dead-hash-links', $ids($dead_href), true), 'a link with href="#" is a finding');

$real_href = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><a href="/contact">Click</a>', 'page-i');
CP_Test::ok(!in_array('dead-hash-links', $ids($real_href), true), 'a link with a real href is not flagged');

// =============================================================================
// analyze_page_html — title / meta description / Open Graph
// =============================================================================

CP_Test::group('site_readiness — title, meta description, Open Graph');

$bare = '<h1>T</h1>';
$bare_findings = $ids(Claudpress_Tools_Readiness::analyze_page_html($bare, 'page-j'));
foreach (['missing-title', 'missing-meta-description', 'missing-og-title', 'missing-og-description', 'missing-og-url'] as $expected) {
    CP_Test::ok(in_array($expected, $bare_findings, true), 'a page missing everything is flagged for ' . $expected);
}

$full = '<title>My Page</title>'
    . '<meta name="description" content="A real description">'
    . '<meta property="og:title" content="My Page">'
    . '<meta property="og:description" content="A real description">'
    . '<meta property="og:url" content="https://example.test/my-page">'
    . '<h1>T</h1>';
$full_findings = $ids(Claudpress_Tools_Readiness::analyze_page_html($full, 'page-k'));
foreach (['missing-title', 'missing-meta-description', 'missing-og-title', 'missing-og-description', 'missing-og-url'] as $absent) {
    CP_Test::ok(!in_array($absent, $full_findings, true), 'a page with a real ' . $absent . ' value is not flagged for it');
}

// =============================================================================
// analyze_page_html — placeholder text
// =============================================================================

CP_Test::group('site_readiness — placeholder text');

$lorem = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><p>Lorem Ipsum dolor sit amet</p>', 'page-l');
CP_Test::ok(in_array('placeholder-text', $ids($lorem), true), '"Lorem Ipsum" body copy is a finding');

$soon = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><p>This page is Coming Soon</p>', 'page-m');
CP_Test::ok(in_array('placeholder-text', $ids($soon), true), '"coming soon" body copy is a finding');

$todo_comment = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><!-- TODO: finish this section --><p>text</p>', 'page-n');
CP_Test::ok(in_array('placeholder-text', $ids($todo_comment), true), 'a TODO comment left in the markup is a finding');

// Real bug this was written to pin: a bare case-insensitive "todo" match
// fires on Spanish copy and on CSS class names, which is worse than useless
// on a Spanish-language or generically-named site.
$spanish = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><p>Todos los productos estan disponibles</p>', 'page-o');
CP_Test::ok(!in_array('placeholder-text', $ids($spanish), true), 'Spanish "Todos los productos" must not be mistaken for a TODO marker');

$css_class = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><ul class="todo-list"><li>Item</li></ul>', 'page-p');
CP_Test::ok(!in_array('placeholder-text', $ids($css_class), true), 'a CSS class named "todo-list" must not be mistaken for a TODO marker');

$clean_copy = Claudpress_Tools_Readiness::analyze_page_html('<h1>T</h1><p>Welcome to our real, finished homepage.</p>', 'page-q');
CP_Test::ok(!in_array('placeholder-text', $ids($clean_copy), true), 'ordinary finished copy is never flagged as placeholder text');

// =============================================================================
// analyze_page_html — JSON-LD validity
// =============================================================================

CP_Test::group('site_readiness — JSON-LD validity');

$invalid_ld = '<h1>T</h1><script type="application/ld+json">{ this is not json }</script>';
CP_Test::ok(in_array('invalid-json-ld', $ids(Claudpress_Tools_Readiness::analyze_page_html($invalid_ld, 'page-r')), true), 'a JSON-LD block that fails json_decode is a finding');

$valid_ld = '<h1>T</h1><script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage","name":"Test"}</script>';
CP_Test::ok(!in_array('invalid-json-ld', $ids(Claudpress_Tools_Readiness::analyze_page_html($valid_ld, 'page-s')), true), 'valid JSON-LD is not flagged');

// =============================================================================
// analyze_duplicate_body_gate — the coming-soon-gate false-positive fix
//
// Found for real on claudpress.com: fetched anonymously, every URL behind a
// coming-soon gate came back as the same holding page, and site_readiness
// reported placeholder-text/missing-meta-description/lcp-image-over-budget
// etc. findings about that holding page as though they described the real
// pages. The fix detects the condition by content (identical bodies across
// distinct URLs) and the gatherer (site_readiness) excludes those bodies
// from analyze_page_html/JSON-LD/image analysis, reporting them in `skipped`
// instead. Since site_readiness() itself calls WordPress functions and is
// out of reach for this suite, these tests exercise the pure detector
// directly and then replay the exact filtering logic the gatherer applies,
// to prove the false positive is actually gone end to end.
// =============================================================================

CP_Test::group('site_readiness — duplicate-body gate: three identical bodies');

$gate_all_same = Claudpress_Tools_Readiness::analyze_duplicate_body_gate([
    ['location' => 'https://x.test/a', 'html' => '<h1>T</h1><p>Coming Soon</p>'],
    ['location' => 'https://x.test/b', 'html' => '<h1>T</h1><p>Coming Soon</p>'],
    ['location' => 'https://x.test/c', 'html' => '<h1>T</h1><p>Coming Soon</p>'],
]);
CP_Test::ok($gate_all_same['finding'] !== null, 'three distinct URLs with byte-identical bodies raise the gate finding');
CP_Test::same('duplicate-body-gate', $gate_all_same['finding']['id'] ?? null, 'the gate finding has a stable id');
CP_Test::same('blocker', $gate_all_same['finding']['severity'] ?? null, 'when EVERY fetched URL is identical, nothing about the real site was observed at all — that is a blocker, the same footing as tax-enabled-no-rates, not merely a high');
sort($gate_all_same['gated_locations']);
CP_Test::same(['https://x.test/a', 'https://x.test/b', 'https://x.test/c'], $gate_all_same['gated_locations'], 'all three URLs sharing the identical body are named as gated');

// Replay the gatherer's own filtering logic (see site_readiness()) to prove
// the actual false positive from the real run cannot happen once wired up:
// placeholder-text must never reach `findings` for a gated body.
$replay_findings = [];
$replay_skipped  = [];
$gated_flip = array_flip($gate_all_same['gated_locations']);
foreach ([
    ['location' => 'https://x.test/a', 'html' => '<h1>T</h1><p>Coming Soon</p>'],
    ['location' => 'https://x.test/b', 'html' => '<h1>T</h1><p>Coming Soon</p>'],
    ['location' => 'https://x.test/c', 'html' => '<h1>T</h1><p>Coming Soon</p>'],
] as $page) {
    if (isset($gated_flip[$page['location']])) {
        $replay_skipped[] = ['check' => 'page-level checks on ' . $page['location'], 'reason' => 'gated'];
        continue;
    }
    $replay_findings = array_merge($replay_findings, Claudpress_Tools_Readiness::analyze_page_html($page['html'], $page['location']));
}
CP_Test::ok(!in_array('placeholder-text', $ids($replay_findings), true), 'the exact false positive from the real run: "coming soon" placeholder-text from the gated holding page must NOT be in findings once the gate is applied');
CP_Test::same(3, count($replay_skipped), 'all three gated pages are accounted for in skipped instead');
CP_Test::ok($replay_findings === [], 'with every page gated, zero page-level findings are produced at all — none of them describe a page that was actually seen');

CP_Test::group('site_readiness — duplicate-body gate: three distinct bodies (the ordinary case)');

$gate_all_different = Claudpress_Tools_Readiness::analyze_duplicate_body_gate([
    ['location' => 'https://x.test/a', 'html' => '<h1>Home</h1><p>Welcome to our real homepage.</p>'],
    ['location' => 'https://x.test/b', 'html' => '<h1>About</h1><p>Our real story.</p>'],
    ['location' => 'https://x.test/c', 'html' => '<h1>Contact</h1><p>Get in touch.</p>'],
]);
CP_Test::ok($gate_all_different['finding'] === null, 'three distinct URLs with three distinct bodies raise no gate finding at all — this is the regression that matters, the ordinary case must not break');
CP_Test::ok($gate_all_different['gated_locations'] === [], 'nothing is marked gated when every body differs');

// Confirm page-level analysis proceeds completely normally for all three —
// the fix must not suppress real findings on an ungated site.
$missing_h1_page = Claudpress_Tools_Readiness::analyze_page_html('<p>No heading here at all.</p>', 'https://x.test/d');
CP_Test::ok(in_array('missing-h1', $ids($missing_h1_page), true), 'a real, distinct page with an actual missing-h1 defect is still flagged normally — the gate detector does not swallow genuine findings');

CP_Test::group('site_readiness — duplicate-body gate: two identical + one different');

// Deliberate design: this is NOT treated the same as "every page is
// identical". Two matching URLs out of three is reported as `high` (serious,
// worth a human's attention) rather than `blocker` (the whole audit is
// void), because the third, distinct page is still real signal — its
// findings can still be trusted. Only the two matching URLs are excluded
// from page-level analysis; the wording says what was observed (identical
// bodies), not an assumed cause, since two legitimately identical pages are
// possible and should not be reported as "you have a gate".
$gate_partial = Claudpress_Tools_Readiness::analyze_duplicate_body_gate([
    ['location' => 'https://x.test/a', 'html' => '<h1>T</h1><p>Coming Soon</p>'],
    ['location' => 'https://x.test/b', 'html' => '<h1>T</h1><p>Coming Soon</p>'],
    ['location' => 'https://x.test/c', 'html' => '<h1>Contact</h1><p>Get in touch.</p>'],
]);
CP_Test::ok($gate_partial['finding'] !== null, 'two of three URLs sharing an identical body still raises a finding');
CP_Test::same('high', $gate_partial['finding']['severity'] ?? null, 'a partial match is deliberately `high`, not `blocker` — the third, distinct page is still trustworthy signal, so this is not "the whole audit is void"');
sort($gate_partial['gated_locations']);
CP_Test::same(['https://x.test/a', 'https://x.test/b'], $gate_partial['gated_locations'], 'only the two URLs that actually share a body are marked gated; the distinct third URL is left alone');
CP_Test::contains('byte-identical', $gate_partial['finding']['evidence'] ?? '', 'the finding states what was actually observed (identical bodies), not an assumed cause like "a gate is active"');

// Replay the gatherer's filtering: the distinct third page must still be
// analyzed normally and produce its own findings.
$partial_findings = [];
$partial_gated_flip = array_flip($gate_partial['gated_locations']);
foreach ([
    ['location' => 'https://x.test/a', 'html' => '<h1>T</h1><p>Coming Soon</p>'],
    ['location' => 'https://x.test/b', 'html' => '<h1>T</h1><p>Coming Soon</p>'],
    ['location' => 'https://x.test/c', 'html' => '<p>Get in touch, no heading.</p>'],
] as $page) {
    if (isset($partial_gated_flip[$page['location']])) {
        continue;
    }
    $partial_findings = array_merge($partial_findings, Claudpress_Tools_Readiness::analyze_page_html($page['html'], $page['location']));
}
CP_Test::ok(!in_array('placeholder-text', $ids($partial_findings), true), 'placeholder-text from the two gated URLs does not leak into findings');
CP_Test::ok(in_array('missing-h1', $ids($partial_findings), true), 'the third, distinct, non-gated page is still analyzed normally and its own real missing-h1 defect is reported');

// =============================================================================
// analyze_schema — aggregateRating / review without real reviews
// =============================================================================

CP_Test::group('site_readiness — schema review/rating honesty');

$rating_block = [['@context' => 'https://schema.org', '@type' => 'Product', 'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => '5']]];
$rating_findings = $ids(Claudpress_Tools_Readiness::analyze_schema($rating_block, false));
CP_Test::ok(in_array('schema-aggregate-rating-no-reviews', $rating_findings, true), 'aggregateRating markup with no real reviews behind it is a finding');

$rating_with_reviews = $ids(Claudpress_Tools_Readiness::analyze_schema($rating_block, true));
CP_Test::ok(!in_array('schema-aggregate-rating-no-reviews', $rating_with_reviews, true), 'the same aggregateRating markup is clean once the site has real reviews');

$review_block = [['@context' => 'https://schema.org', '@type' => 'Product', 'review' => ['@type' => 'Review', 'reviewBody' => 'Great']]];
CP_Test::ok(in_array('schema-review-no-reviews', $ids(Claudpress_Tools_Readiness::analyze_schema($review_block, false)), true), 'review markup with no real reviews behind it is a finding');
CP_Test::ok(!in_array('schema-review-no-reviews', $ids(Claudpress_Tools_Readiness::analyze_schema($review_block, true)), true), 'the same review markup is clean once the site has real reviews');

// @graph is a container, not a leaf — the analyzer must recurse into it to
// see the node that actually carries the rating.
$graph_block = [[
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'WebPage', 'name' => 'Home'],
        ['@type' => 'Product', 'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => '5']],
    ],
]];
CP_Test::ok(in_array('schema-aggregate-rating-no-reviews', $ids(Claudpress_Tools_Readiness::analyze_schema($graph_block, false)), true), 'an aggregateRating node nested inside @graph is still found by recursing into the graph');

$no_rating_markup = [['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'Home']];
CP_Test::ok(Claudpress_Tools_Readiness::analyze_schema($no_rating_markup, false) === [], 'ordinary schema with no rating or review markup produces no findings');

// =============================================================================
// analyze_image_weights
// =============================================================================

CP_Test::group('site_readiness — image weight budget');

$over_budget = Claudpress_Tools_Readiness::analyze_image_weights([['url' => 'a.jpg', 'bytes' => 301 * 1024, 'is_lcp' => false]]);
CP_Test::ok(in_array('image-over-budget', $ids($over_budget), true), 'a non-LCP image over 300KB is a finding');

$lcp_over = Claudpress_Tools_Readiness::analyze_image_weights([['url' => 'hero.jpg', 'bytes' => 201 * 1024, 'is_lcp' => true]]);
CP_Test::ok(in_array('lcp-image-over-budget', $ids($lcp_over), true), 'an LCP image over 200KB is a finding even though it is under the general 300KB cap');

$under_budget = Claudpress_Tools_Readiness::analyze_image_weights([['url' => 'b.jpg', 'bytes' => 250 * 1024, 'is_lcp' => false]]);
CP_Test::ok($under_budget === [], 'a 250KB non-LCP image is under the 300KB cap and produces no finding');

// Boundary: the check is a strict ">" comparison in the source, so a value
// exactly at the cap must pass, and one byte over must fail.
$exactly_at_general_cap = Claudpress_Tools_Readiness::analyze_image_weights([['url' => 'c.jpg', 'bytes' => 300 * 1024, 'is_lcp' => false]]);
CP_Test::ok($exactly_at_general_cap === [], 'a non-LCP image at exactly 300KB (not over) passes, since the budget check is a strict ">"');

$one_byte_over_general_cap = Claudpress_Tools_Readiness::analyze_image_weights([['url' => 'c.jpg', 'bytes' => 300 * 1024 + 1, 'is_lcp' => false]]);
CP_Test::ok(in_array('image-over-budget', $ids($one_byte_over_general_cap), true), 'a non-LCP image one byte over 300KB is a finding');

$exactly_at_lcp_cap = Claudpress_Tools_Readiness::analyze_image_weights([['url' => 'hero.jpg', 'bytes' => 200 * 1024, 'is_lcp' => true]]);
CP_Test::ok($exactly_at_lcp_cap === [], 'an LCP image at exactly 200KB (not over) passes, since the budget check is a strict ">"');

// =============================================================================
// analyze_security_headers
// =============================================================================

CP_Test::group('site_readiness — security headers');

$no_headers = Claudpress_Tools_Readiness::analyze_security_headers([]);
CP_Test::ok(in_array('missing-hsts', $ids($no_headers), true), 'no strict-transport-security header at all is a High finding');
$hsts_finding = current(array_filter($no_headers, fn($f) => $f['id'] === 'missing-hsts'));
CP_Test::same('high', $hsts_finding['severity'] ?? null, 'the missing-HSTS finding is specifically High severity');

$hsts_titlecase = Claudpress_Tools_Readiness::analyze_security_headers([
    'Strict-Transport-Security' => 'max-age=31536000',
    'X-Content-Type-Options'    => 'nosniff',
    'X-Frame-Options'           => 'DENY',
    'Referrer-Policy'           => 'no-referrer',
]);
CP_Test::ok(!in_array('missing-hsts', $ids($hsts_titlecase), true), 'a Title-Case "Strict-Transport-Security" header is recognized, not missed on casing');

$hsts_lowercase = Claudpress_Tools_Readiness::analyze_security_headers([
    'strict-transport-security' => 'max-age=31536000',
    'x-content-type-options'    => 'nosniff',
    'x-frame-options'           => 'DENY',
    'referrer-policy'           => 'no-referrer',
]);
CP_Test::ok(!in_array('missing-hsts', $ids($hsts_lowercase), true), 'a fully-lowercase "strict-transport-security" header is recognized identically to the Title-Case form');
CP_Test::same($ids($hsts_titlecase), $ids($hsts_lowercase), 'Title-Case and lowercase header names produce the exact same findings — the lookup is case-insensitive');

// Deliberate per skills/security-standard.md §4: an enforcing CSP must NOT be
// required. A response with every other protection present but NO
// Content-Security-Policy header at all must not be flagged for missing CSP.
$no_csp_but_otherwise_complete = Claudpress_Tools_Readiness::analyze_security_headers([
    'strict-transport-security' => 'max-age=31536000',
    'x-content-type-options'    => 'nosniff',
    'x-frame-options'           => 'DENY',
    'referrer-policy'           => 'no-referrer',
]);
CP_Test::ok($no_csp_but_otherwise_complete === [], 'a response with no Content-Security-Policy header at all, but every other protection present, produces zero findings — CSP is deliberately not required');

// =============================================================================
// analyze_url_status
// =============================================================================

CP_Test::group('site_readiness — monitored URL status (llms.txt / sitemap / robots.txt)');

$non_200 = Claudpress_Tools_Readiness::analyze_url_status('llms.txt', 'https://example.test/llms.txt', 500, '');
CP_Test::ok(in_array('url-not-200-llms-txt', $ids($non_200), true), 'a non-200 status for a monitored URL is a finding');

// The bug this plugin actually shipped: WordPress's own 404 template renders
// under a 200 status when the request is served in a way that never calls
// status_header(404), so a 200 whose body IS a 404 page must still be caught.
$fake_200 = Claudpress_Tools_Readiness::analyze_url_status('sitemap', 'https://example.test/wp-sitemap.xml', 200, '<html><head><title>Page not found</title></head><body>Nothing here</body></html>');
CP_Test::ok(in_array('url-200-but-404-body-sitemap', $ids($fake_200), true), 'a 200 response whose body <title> matches a not-found pattern is a finding, not a pass');

$empty_200 = Claudpress_Tools_Readiness::analyze_url_status('robots.txt', 'https://example.test/robots.txt', 200, '   ');
CP_Test::ok(in_array('url-200-but-404-body-robots-txt', $ids($empty_200), true), 'a 200 response with an effectively empty body is a finding');

$real_body = Claudpress_Tools_Readiness::analyze_url_status('robots.txt', 'https://example.test/robots.txt', 200, "User-agent: *\nDisallow:");
CP_Test::ok($real_body === [], 'a 200 response with a real, non-empty, non-404-looking body passes');

// THE regression, and the general case of the two checks above. A coming-soon
// gate on template_redirect intercepted /sitemap.xml and served the holding
// page: status 200, content-type text/html, three kilobytes of HTML where an
// XML sitemap should be. Every check here passed it, because it only ever
// asked "is this a 404?" and it genuinely was not one. Found on
// claudpress.com after the gate was fixed to return real 404s, which is what
// made the sitemap's own answer visible in the first place.
$gate_page = '<!doctype html><html lang="en"><head><title>Claudpress &mdash; coming soon</title>'
    . '</head><body><h1>Let an AI actually run your WordPress site.</h1></body></html>';

$intercepted = Claudpress_Tools_Readiness::analyze_url_status('sitemap', 'https://example.test/wp-sitemap.xml', 200, $gate_page);
CP_Test::ok(in_array('url-200-but-wrong-content-sitemap', $ids($intercepted), true), 'an HTML page served where an XML sitemap belongs is a finding, even though it is a perfectly healthy 200');
CP_Test::contains('intercepting', (string) ($intercepted[0]['evidence'] ?? ''), 'and the evidence names the likely cause, because "wrong content" alone does not tell anyone where to look');

$llms_intercepted = Claudpress_Tools_Readiness::analyze_url_status('llms.txt', 'https://example.test/llms.txt', 200, $gate_page);
CP_Test::ok(in_array('url-200-but-wrong-content-llms-txt', $ids($llms_intercepted), true), 'the same interception is caught on llms.txt');

$robots_intercepted = Claudpress_Tools_Readiness::analyze_url_status('robots.txt', 'https://example.test/robots.txt', 200, $gate_page);
CP_Test::ok(in_array('url-200-but-wrong-content-robots-txt', $ids($robots_intercepted), true), 'and on robots.txt');

// The real things must still pass. A check that flags a correct site is worse
// than no check at all, because it is the one that gets switched off.
$real_sitemap = Claudpress_Tools_Readiness::analyze_url_status('sitemap', 'https://example.test/wp-sitemap.xml', 200, '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></sitemapindex>');
CP_Test::ok($real_sitemap === [], 'a real XML sitemap passes');

$real_llms = Claudpress_Tools_Readiness::analyze_url_status('llms.txt', 'https://example.test/llms.txt', 200, "# Claudpress\n\n> A free WordPress plugin.\n");
CP_Test::ok($real_llms === [], 'a real llms.txt passes');

$wrong_shape_llms = Claudpress_Tools_Readiness::analyze_url_status('llms.txt', 'https://example.test/llms.txt', 200, "Claudpress\nno heading here\n");
CP_Test::ok(in_array('url-200-but-wrong-content-llms-txt', $ids($wrong_shape_llms), true), 'an llms.txt that does not open with an H1 is not in the llmstxt.org format the answer engines read');

// An unmonitored label keeps the old, looser behaviour rather than being
// guessed at. A shape rule invented for a URL nobody described is how a
// correct site starts failing.
$unknown = Claudpress_Tools_Readiness::analyze_url_status('something-else', 'https://example.test/x', 200, $gate_page);
CP_Test::ok($unknown === [], 'a label with no declared shape is only checked for a 404-shaped body');

// =============================================================================
// analyze_404_handling
// =============================================================================

CP_Test::group('site_readiness — 404 handling for URLs that should not exist');

$missing_returns_200 = Claudpress_Tools_Readiness::analyze_404_handling(200, 'https://example.test/does-not-exist/', 'https://example.test/');
CP_Test::ok(in_array('missing-url-returns-200', $ids($missing_returns_200), true), 'a nonexistent URL returning HTTP 200 is a finding');

$redirects_home = Claudpress_Tools_Readiness::analyze_404_handling(301, 'https://example.test', 'https://example.test/');
CP_Test::ok(in_array('missing-url-redirects-home', $ids($redirects_home), true), 'a 3xx for a nonexistent URL that lands on the homepage is a finding');

$real_404 = Claudpress_Tools_Readiness::analyze_404_handling(404, 'https://example.test/does-not-exist/', 'https://example.test/');
CP_Test::ok($real_404 === [], 'a real 404 for a nonexistent URL passes');

// =============================================================================
// analyze_tax
// =============================================================================

CP_Test::group('site_readiness — tax sanity');

$tax_no_rates = Claudpress_Tools_Readiness::analyze_tax(true, []);
CP_Test::ok(in_array('tax-enabled-no-rates', $ids($tax_no_rates), true), 'tax enabled with zero configured rates is a finding');
$tax_finding = current(array_filter($tax_no_rates, fn($f) => $f['id'] === 'tax-enabled-no-rates'));
CP_Test::same('blocker', $tax_finding['severity'] ?? null, 'tax enabled with zero rates is specifically a Blocker, not merely a High');

$tax_with_rates = Claudpress_Tools_Readiness::analyze_tax(true, [['tax_rate_id' => 1]]);
CP_Test::ok($tax_with_rates === [], 'tax enabled with at least one configured rate passes');

$tax_disabled_no_rates = Claudpress_Tools_Readiness::analyze_tax(false, []);
CP_Test::ok($tax_disabled_no_rates === [], 'tax disabled with zero rates passes — nothing to configure');

// =============================================================================
// analyze_snippets
// =============================================================================

CP_Test::group('site_readiness — snippet errors');

$broken_snippet = Claudpress_Tools_Readiness::analyze_snippets([['id' => 1, 'name' => 'My Snippet', 'last_error' => 'Fatal error: undefined function']]);
CP_Test::ok(count($broken_snippet) === 1, 'a snippet with a recorded last_error produces exactly one finding');
CP_Test::same('high', $broken_snippet[0]['severity'] ?? null, 'a snippet error is at least High severity');

$clean_snippet = Claudpress_Tools_Readiness::analyze_snippets([['id' => 2, 'name' => 'Clean Snippet', 'last_error' => '']]);
CP_Test::ok($clean_snippet === [], 'a snippet with no recorded error produces no finding');

$snippet_no_error_key = Claudpress_Tools_Readiness::analyze_snippets([['id' => 3, 'name' => 'No Key']]);
CP_Test::ok($snippet_no_error_key === [], 'a snippet row with no last_error key at all is treated the same as no error');

// =============================================================================
// analyze_registration
// =============================================================================

CP_Test::group('site_readiness — registration and default role');

$elevated_role_needed = Claudpress_Tools_Readiness::analyze_registration(1, 'administrator', true);
CP_Test::ok(in_array('default-role-elevated', $ids($elevated_role_needed), true), 'a default role above subscriber/customer is a finding even when registration is open and the store needs accounts');

$elevated_role_unneeded = Claudpress_Tools_Readiness::analyze_registration(0, 'editor', false);
CP_Test::ok(in_array('default-role-elevated', $ids($elevated_role_unneeded), true), 'a default role above subscriber/customer is a finding even when registration is closed and nothing needs it — it applies to every future signup');

$open_reg_unneeded = Claudpress_Tools_Readiness::analyze_registration(1, 'subscriber', false);
CP_Test::ok(in_array('open-registration-unneeded', $ids($open_reg_unneeded), true), 'open registration with nothing on the site needing it is a finding');

$open_reg_needed = Claudpress_Tools_Readiness::analyze_registration(1, 'subscriber', true);
CP_Test::ok($open_reg_needed === [], 'open registration with a safe default role, on a store that needs accounts, passes cleanly');

$closed_reg_safe_role = Claudpress_Tools_Readiness::analyze_registration(0, 'customer', false);
CP_Test::ok($closed_reg_safe_role === [], 'closed registration with a safe default role and nothing needing accounts passes cleanly');

// =============================================================================
// analyze_legal_pages
// =============================================================================

CP_Test::group('site_readiness — finding a legal page when no setting names it');

// THE regression. `terms` was read only from woocommerce_terms_page_id, an
// option WooCommerce creates. On a site with no store it is always 0, so the
// site was told its Terms page did not exist, wrote one, published it, and was
// told again. A HIGH that cannot be cleared by doing what it asks is the one
// that teaches an owner to skim the whole list.

$published = static function (int $id): ?bool {
    // 41 = the published Terms page; 42 = a draft; anything else is gone.
    if ($id === 41) return true;
    if ($id === 42) return false;
    return null;
};
$by_slug = static function (array $slugs): int {
    return in_array('terms', $slugs, true) ? 41 : 0;
};

$r = Claudpress_Tools_Readiness::resolve_legal_pages(['terms' => 0], $published, $by_slug);
CP_Test::ok($r['terms']['exists'], 'a Terms page at /terms is found even though no setting points at it');
CP_Test::ok($r['terms']['published'], '...and its publish state is read from the page itself');
CP_Test::same(41, $r['terms']['id'], '...and the id reported is the page that was actually found');
CP_Test::same('slug', $r['terms']['found_by'], '...and the report says how it was found, so "found_by: slug" on a store is a hint to wire it up');

// A setting that names a page which has since been deleted is the same
// situation as no setting at all: nothing currently says where the page is.
$r = Claudpress_Tools_Readiness::resolve_legal_pages(['terms' => 999], $published, $by_slug);
CP_Test::ok($r['terms']['exists'], 'a setting pointing at a deleted page falls back to the slug rather than reporting "missing"');
CP_Test::same(41, $r['terms']['id'], '...and reports the page that is really there');

// An explicit setting still wins. On a store it is what the checkout links to,
// so a slug that happens to match must not quietly replace it.
$r = Claudpress_Tools_Readiness::resolve_legal_pages(['terms' => 42], $published, $by_slug);
CP_Test::same(42, $r['terms']['id'], 'an explicit setting wins over a slug match');
CP_Test::same('setting', $r['terms']['found_by'], '...and says so');
CP_Test::ok(!$r['terms']['published'], '...including when the page it names is only a draft, which is a different finding entirely');

// Nothing anywhere is still "missing", which is the finding that should exist.
$none = static function (array $slugs): int { return 0; };
$r = Claudpress_Tools_Readiness::resolve_legal_pages(['terms' => 0], $published, $none);
CP_Test::ok(!$r['terms']['exists'], 'with no setting and no page, it is genuinely missing');
CP_Test::same(0, $r['terms']['id'], '...and reports no id rather than a stale one');
CP_Test::same('none', $r['terms']['found_by'], '...and says nothing found it');

// End to end: the resolver feeds the analyzer, and a real page clears the HIGH.
$found = Claudpress_Tools_Readiness::resolve_legal_pages(['terms' => 0, 'privacy' => 41], $published, $by_slug);
$out   = Claudpress_Tools_Readiness::analyze_legal_pages($found, [], false);
$found_ids = array_map(static function ($f) { return $f['id']; }, $out['findings']);
CP_Test::ok(!in_array('legal-page-missing-terms', $found_ids, true), 'a Terms page found by slug clears the missing-terms HIGH — the whole point');
CP_Test::ok(!in_array('legal-page-missing-privacy', $found_ids, true), 'and privacy stays clear too');

CP_Test::group('site_readiness — legal page existence, publish state, and wiring');

$missing_page = Claudpress_Tools_Readiness::analyze_legal_pages(
    ['terms' => ['exists' => false, 'published' => false, 'id' => 0]],
    [],
    true
);
CP_Test::ok(in_array('legal-page-missing-terms', $ids($missing_page['findings']), true), 'a legal page role that does not exist is its own distinct finding');

$unpublished_page = Claudpress_Tools_Readiness::analyze_legal_pages(
    ['terms' => ['exists' => true, 'published' => false, 'id' => 5]],
    [],
    true
);
CP_Test::ok(in_array('legal-page-unpublished-terms', $ids($unpublished_page['findings']), true), 'a legal page that exists but is not published is a distinct finding from "missing"');
CP_Test::ok(!in_array('legal-page-missing-terms', $ids($unpublished_page['findings']), true), 'an existing-but-unpublished page is not also counted as missing');

$exists_not_wired = Claudpress_Tools_Readiness::analyze_legal_pages(
    ['terms' => ['exists' => true, 'published' => true, 'id' => 5]],
    ['woocommerce_terms_page_id' => 0],
    true
);
CP_Test::ok(in_array('legal-page-not-wired-woocommerce-terms-page-id', $ids($exists_not_wired['findings']), true), 'a published legal page that WooCommerce is not actually pointed at is its own distinct finding');
CP_Test::ok(!in_array('legal-page-missing-terms', $ids($exists_not_wired['findings']), true), 'an existing, published, unwired page is not also flagged as missing');
CP_Test::ok(!in_array('legal-page-unpublished-terms', $ids($exists_not_wired['findings']), true), 'an existing, published, unwired page is not also flagged as unpublished');

$fully_wired = Claudpress_Tools_Readiness::analyze_legal_pages(
    [
        'terms'    => ['exists' => true, 'published' => true, 'id' => 5],
        'privacy'  => ['exists' => true, 'published' => true, 'id' => 6],
        'refund'   => ['exists' => true, 'published' => true, 'id' => 7],
        'shipping' => ['exists' => true, 'published' => true, 'id' => 8],
    ],
    ['woocommerce_terms_page_id' => 5, 'woocommerce_refund_returns_page_id' => 7],
    true
);
CP_Test::ok($fully_wired['findings'] === [], 'four existing, published, fully-wired legal pages produce zero findings');
CP_Test::ok($fully_wired['skipped'] === [], 'with a store active, nothing about legal pages is skipped');

// ---- store-only roles: refund/shipping only mean anything with a store ----
// This is the false positive found for real on claudpress.com: a free GPL
// plugin's site with no checkout has nothing to refund or ship, so demanding
// those pages at HIGH severity is noise expensive enough to train an agent
// (or an owner) to skim past the findings list — exactly how a real HIGH
// gets missed.

CP_Test::group('site_readiness — legal pages: refund/shipping are store-only');

$no_store_all_missing = Claudpress_Tools_Readiness::analyze_legal_pages(
    ['terms' => ['exists' => false, 'published' => false, 'id' => 0], 'privacy' => ['exists' => false, 'published' => false, 'id' => 0]],
    ['woocommerce_terms_page_id' => 0, 'woocommerce_refund_returns_page_id' => 0],
    false
);
CP_Test::ok(in_array('legal-page-missing-terms', $ids($no_store_all_missing['findings']), true), 'terms still raises HIGH when missing, with no store active');
CP_Test::ok(in_array('legal-page-missing-privacy', $ids($no_store_all_missing['findings']), true), 'privacy still raises HIGH when missing, with no store active');
CP_Test::ok(!in_array('legal-page-missing-refund', $ids($no_store_all_missing['findings']), true), 'refund is NOT flagged as missing when there is no store — refund only means something with a store');
CP_Test::ok(!in_array('legal-page-missing-shipping', $ids($no_store_all_missing['findings']), true), 'shipping is NOT flagged as missing when there is no store — shipping only means something with a store');
CP_Test::ok(!in_array('legal-page-not-wired-woocommerce-terms-page-id', $ids($no_store_all_missing['findings']), true), 'WooCommerce wiring settings are not evaluated at all when there is no store — they do not exist without WooCommerce');

$skipped_checks = array_column($no_store_all_missing['skipped'], 'check');
CP_Test::ok(in_array('Refund page', $skipped_checks, true), 'refund is reported in skipped, not silently dropped, when there is no store');
CP_Test::ok(in_array('Shipping page', $skipped_checks, true), 'shipping is reported in skipped, not silently dropped, when there is no store');

$no_store_terms_and_privacy_exist = Claudpress_Tools_Readiness::analyze_legal_pages(
    ['terms' => ['exists' => true, 'published' => true, 'id' => 5], 'privacy' => ['exists' => true, 'published' => true, 'id' => 6]],
    [],
    false
);
CP_Test::ok($no_store_terms_and_privacy_exist['findings'] === [], 'a no-store site with real terms and privacy pages produces zero findings — refund/shipping are not demanded');

$with_store_all_missing = Claudpress_Tools_Readiness::analyze_legal_pages(
    ['terms' => ['exists' => false, 'published' => false, 'id' => 0], 'privacy' => ['exists' => false, 'published' => false, 'id' => 0]],
    [],
    true
);
$with_store_findings = $ids($with_store_all_missing['findings']);
foreach (['legal-page-missing-terms', 'legal-page-missing-privacy', 'legal-page-missing-refund', 'legal-page-missing-shipping'] as $expected) {
    CP_Test::ok(in_array($expected, $with_store_findings, true), 'with a store active, all four legal page roles (including ' . $expected . ') still raise a finding when missing');
}
foreach ($with_store_all_missing['findings'] as $f) {
    if (str_starts_with($f['id'], 'legal-page-missing-')) {
        CP_Test::same('high', $f['severity'], $f['id'] . ' is specifically HIGH severity');
    }
}

// =============================================================================
// analyze_stock
// =============================================================================

CP_Test::group('site_readiness — stock policy');

$undecided_stock = Claudpress_Tools_Readiness::analyze_stock([]);
CP_Test::ok(in_array('stock-tracking-undecided', $ids($undecided_stock), true), 'manage_stock left unset is a finding');
CP_Test::ok(in_array('backorder-policy-default', $ids($undecided_stock), true), 'backorders left unset (the untouched default) is a finding');
CP_Test::ok(in_array('low-stock-threshold-unset', $ids($undecided_stock), true), 'an unset low-stock threshold is a finding');

$decided_stock = Claudpress_Tools_Readiness::analyze_stock([
    'manage_stock'        => 'yes',
    'backorders'          => 'yes',
    'low_stock_threshold' => '5',
]);
CP_Test::ok($decided_stock === [], 'stock settings that have all been explicitly decided produce no findings');

// =============================================================================
// summarize
// =============================================================================

CP_Test::group('site_readiness — summarize: pass vs complete');

$blocker_finding = ['id' => 'x', 'severity' => 'blocker', 'category' => 'functionality', 'title' => 't', 'evidence' => 'e', 'location' => 'l', 'status' => 'open', 'owner_decision' => false];
$high_finding = ['id' => 'y', 'severity' => 'high', 'category' => 'functionality', 'title' => 't', 'evidence' => 'e', 'location' => 'l', 'status' => 'open', 'owner_decision' => false];
$medium_finding = ['id' => 'z', 'severity' => 'medium', 'category' => 'functionality', 'title' => 't', 'evidence' => 'e', 'location' => 'l', 'status' => 'open', 'owner_decision' => false];
$low_finding = ['id' => 'w', 'severity' => 'low', 'category' => 'functionality', 'title' => 't', 'evidence' => 'e', 'location' => 'l', 'status' => 'open', 'owner_decision' => false];
$observation_finding = ['id' => 'v', 'severity' => 'observation', 'category' => 'functionality', 'title' => 't', 'evidence' => 'e', 'location' => 'l', 'status' => 'open', 'owner_decision' => false];

CP_Test::same(false, Claudpress_Tools_Readiness::summarize([$blocker_finding], [], [])['pass'], 'pass is false with an open blocker finding');
CP_Test::same(false, Claudpress_Tools_Readiness::summarize([$high_finding], [], [])['pass'], 'pass is false with an open high finding');
CP_Test::same(true, Claudpress_Tools_Readiness::summarize([$medium_finding, $low_finding, $observation_finding], [], [])['pass'], 'pass is true when only medium/low/observation findings remain open');

// pass and complete are answering two different questions and must not be
// conflated: pass means "nothing that ran failed", complete means
// "everything ran". A site with WooCommerce disabled can have pass=true and
// complete=false at the same time, and that is the whole point of the field.
$with_skips = Claudpress_Tools_Readiness::summarize([], [['check' => 'WooCommerce tax sanity', 'reason' => 'WooCommerce is not active']], []);
CP_Test::same(false, $with_skips['complete'], 'complete is false when any check was skipped');
CP_Test::same(true, $with_skips['pass'], 'pass can be true even while complete is false — a skipped check is never counted as a failure, but it is also never counted as a pass on that check');

$no_skips = Claudpress_Tools_Readiness::summarize([], [], []);
CP_Test::same(true, $no_skips['complete'], 'complete is true when nothing was skipped');
CP_Test::same(true, $no_skips['pass'], 'pass is true with no findings and nothing skipped');

// =============================================================================
// Finding shape and id stability — cross-cutting, checked once rather than
// repeated per analyzer.
// =============================================================================

CP_Test::group('site_readiness — every finding has the pre-launch-audit-compatible shape');

$sample_findings = array_merge(
    Claudpress_Tools_Readiness::analyze_page_html('<img src="a.jpg"><a href="#">x</a>', 'sample-a'),
    Claudpress_Tools_Readiness::analyze_image_weights([['url' => 'x.jpg', 'bytes' => 999999, 'is_lcp' => true]]),
    Claudpress_Tools_Readiness::analyze_security_headers([]),
    Claudpress_Tools_Readiness::analyze_tax(true, []),
    Claudpress_Tools_Readiness::analyze_registration(1, 'administrator', false),
    Claudpress_Tools_Readiness::analyze_snippets([['id' => 1, 'last_error' => 'boom']]),
    Claudpress_Tools_Readiness::analyze_legal_pages(['terms' => ['exists' => false, 'published' => false, 'id' => 0]], [], true)['findings'],
    [Claudpress_Tools_Readiness::analyze_duplicate_body_gate([['location' => 'https://example.test/a', 'html' => 'same'], ['location' => 'https://example.test/b', 'html' => 'same']])['finding']]
);
CP_Test::ok(count($sample_findings) >= 6, 'the cross-analyzer sample actually produced findings to check the shape of (' . count($sample_findings) . ')');

$valid_severities = ['blocker', 'high', 'medium', 'low', 'observation'];
$shape_ok = true;
$bad_severity = null;
foreach ($sample_findings as $f) {
    foreach (['id', 'severity', 'category', 'title', 'evidence', 'location', 'status', 'owner_decision'] as $key) {
        if (!array_key_exists($key, $f)) {
            $shape_ok = false;
        }
    }
    if (!in_array($f['severity'] ?? null, $valid_severities, true)) {
        $shape_ok = false;
        $bad_severity = $f['severity'] ?? '(missing)';
    }
}
CP_Test::ok($shape_ok, 'every finding across every analyzer has id/severity/category/title/evidence/location/status/owner_decision, and severity is one of blocker|high|medium|low|observation' . ($bad_severity !== null ? ' (saw: ' . $bad_severity . ')' : ''));

CP_Test::group('site_readiness — finding ids are stable across repeated runs');

$run1 = Claudpress_Tools_Readiness::analyze_page_html('<img src="a.jpg"><a href="#">x</a>', 'sample-a');
$run2 = Claudpress_Tools_Readiness::analyze_page_html('<img src="a.jpg"><a href="#">x</a>', 'sample-a');
CP_Test::same($ids($run1), $ids($run2), 'calling analyze_page_html twice with identical input yields identical finding ids');

$snippet_run1 = Claudpress_Tools_Readiness::analyze_snippets([['id' => 42, 'name' => 'Repeat', 'last_error' => 'boom']]);
$snippet_run2 = Claudpress_Tools_Readiness::analyze_snippets([['id' => 42, 'name' => 'Repeat', 'last_error' => 'boom']]);
CP_Test::same($ids($snippet_run1), $ids($snippet_run2), 'calling analyze_snippets twice with identical input yields identical finding ids');
