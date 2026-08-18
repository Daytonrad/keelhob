<?php
/**
 * SEO head injection — the four bugs a live audit found.
 *
 * Every one of these failed silently. The tool reported success, the meta was
 * stored correctly, and the page simply did not carry the tag. The only way to
 * catch that class of bug is to read what actually reaches the source, so these
 * assert against the real source file rather than mocking WordPress.
 */

CP_Test::group('SEO — head injection reads the keys seo_meta actually writes');

$src = (string) file_get_contents(CLAUDPRESS_DIR . 'includes/tools/class-tools-seo.php');

// Bug 1: the storage key is og_desc; the injector used to build og_description,
// which matches no stored meta, so og:description was never emitted.
preg_match("/'og_description'\s*=>\s*'_claudpress_seo_og_desc'/", $src, $store_desc);
CP_Test::ok(
    strpos($src, "'og_desc' => 'og:description'") !== false,
    'the head injector reads og_desc (the key seo_meta stores), not og_description'
);
CP_Test::ok(
    strpos($src, "'_claudpress_seo_' . \$meta_key") !== false,
    'meta keys are still built from the same prefix, so the mapping above is what decides the key'
);

CP_Test::group('SEO — llms.txt is served with a 200, not a 404 body');

// Bug 2: WordPress has already set up a 404 by template_redirect. Echoing the
// body without correcting the status ships the right bytes under a 404 status,
// and every AI crawler the file exists for discards it.
preg_match('/function serve_llms_txt\(\): void\s*\{(.*?)\n    \}/s', $src, $fn);
$body = $fn[1] ?? '';
CP_Test::ok($body !== '', 'serve_llms_txt() is present');
CP_Test::ok(strpos($body, 'status_header(200)') !== false, 'serve_llms_txt() sets a 200 status before echoing');
CP_Test::ok(
    strpos($body, 'status_header(200)') < strpos($body, 'echo $content'),
    'the status is set BEFORE the body is echoed (headers cannot follow output)'
);
CP_Test::ok(strpos($body, "return; // let it 404") !== false, 'an unset llms.txt still 404s rather than serving an empty file');

CP_Test::group('SEO — indexable pages that are not is_singular() still get meta');

// Bug 3: the injector bailed on !is_singular(), so the shop archive, the blog
// index and a static front page shipped with no description and no Open Graph.
CP_Test::ok(strpos($src, 'private static function current_seo_post_id(): int') !== false, 'there is one resolver for "which post carries this request\'s SEO meta"');
foreach (['is_front_page', 'is_home', 'is_shop'] as $ctx) {
    CP_Test::ok(strpos($src, $ctx) !== false, 'the resolver handles ' . $ctx . '()');
}
CP_Test::ok(
    !preg_match('/if\s*\(\s*!is_singular\(\)\s*\)\s*\{\s*return;/', $src),
    'the old blanket "bail unless is_singular" guard is gone'
);

CP_Test::group('SEO — the stored SEO title actually reaches <title>');

// Bug 4: seo_meta accepted and stored _claudpress_seo_title, but nothing ever
// read it back, so setting an SEO title silently did nothing at all.
CP_Test::ok(strpos($src, "add_filter('pre_get_document_title'") !== false, 'a document-title filter is registered');
CP_Test::ok(strpos($src, 'function filter_title(') !== false, 'the filter callback exists');
CP_Test::ok(strpos($src, "'_claudpress_seo_title'") !== false, 'the filter reads the key seo_meta writes');

CP_Test::group('SEO — link previews carry the tags a preview actually needs');

foreach (['og:url', 'og:type', 'og:site_name', 'twitter:card'] as $tag) {
    CP_Test::ok(strpos($src, $tag) !== false, 'the injector emits ' . $tag);
}

CP_Test::group('SEO — a dedicated SEO plugin still wins');

// Claudpress's meta is a fallback. If Yoast/RankMath/AIOSEO/SEOPress is active it
// must stand down, or every page ships two competing descriptions.
CP_Test::ok(
    substr_count($src, "self::active_seo_plugins() !== ['Claudpress fallback']") >= 2,
    'both the head injector and the title filter stand down when a real SEO plugin is active'
);
