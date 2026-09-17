<?php
/**
 * Conditional logic.
 *
 * Two jobs here, and the second one matters more than the first.
 *
 * 1. The new engine does what it says: AND/OR, seven rule types, operators.
 *
 * 2. Conditions that were ALREADY STORED behave exactly as they did in 1.1.0.
 *    There is no migration — the old flat shape is lifted on read — so if the
 *    lift is wrong, live sites silently change behaviour on update. Nobody
 *    would get a warning; a snippet would just stop appearing, or start
 *    appearing where it never did. Those checks are the reason this file
 *    exists, and they are grouped on their own below.
 */


KH_Test::group('Conditions — nothing to decide means run');

KH_Test::ok(Keelhob_Snippet_Conditions::passes(null), 'no conditions at all');
KH_Test::ok(Keelhob_Snippet_Conditions::passes(''), 'an empty string');
KH_Test::ok(Keelhob_Snippet_Conditions::passes('not json'), 'junk that will not decode');
KH_Test::ok(Keelhob_Snippet_Conditions::passes('{"enabled":false,"rules":[]}'), 'switched off');
KH_Test::ok(
    Keelhob_Snippet_Conditions::passes('{"enabled":true,"relation":"AND","rules":[]}'),
    'switched on with no rules — a half-built form must not stop the snippet'
);

KH_Test::group('Conditions — one rule at a time');

$rule = static function (string $type, string $op, $value): string {
    return (string) wp_json_encode([
        'enabled'  => true,
        'relation' => 'AND',
        'rules'    => [['type' => $type, 'operator' => $op, 'value' => $value]],
    ]);
};

kh_test_request(['logged_in' => true, 'roles' => ['editor']]);
KH_Test::ok(Keelhob_Snippet_Conditions::passes($rule('user_status', 'is', 'logged_in')), 'user is logged in');
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($rule('user_status', 'is', 'logged_out')), '...so "logged out" fails');
KH_Test::ok(Keelhob_Snippet_Conditions::passes($rule('user_status', 'is_not', 'logged_out')), '"is not logged out" passes');
KH_Test::ok(Keelhob_Snippet_Conditions::passes($rule('user_role', 'is', 'editor')), 'role matches');
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($rule('user_role', 'is', 'administrator')), 'a role they do not have fails');

kh_test_request(['logged_in' => false]);
KH_Test::ok(
    !Keelhob_Snippet_Conditions::passes($rule('user_role', 'is', 'editor')),
    'a logged-out visitor has no role, so a role rule fails rather than erroring'
);

kh_test_request(['mobile' => true]);
KH_Test::ok(Keelhob_Snippet_Conditions::passes($rule('device', 'is', 'mobile')), 'device is mobile');
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($rule('device', 'is', 'desktop')), '...and not desktop');

kh_test_request(['front_page' => true, 'singular' => true]);
KH_Test::ok(Keelhob_Snippet_Conditions::passes($rule('page_type', 'is', 'front_page')), 'page type front_page');
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($rule('page_type', 'is', 'archive')), '...and not archive');

kh_test_request(['singular' => true, 'post_type' => 'product', 'object_id' => 42]);
KH_Test::ok(Keelhob_Snippet_Conditions::passes($rule('post_type', 'is', 'product')), 'post type matches');
KH_Test::ok(Keelhob_Snippet_Conditions::passes($rule('post_id', 'is', '42')), 'post id matches');
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($rule('post_id', 'is', '43')), 'a different id does not');

KH_Test::group('Conditions — URL path');

kh_test_request(['uri' => '/shop/hats/?colour=red']);
KH_Test::ok(Keelhob_Snippet_Conditions::passes($rule('url_path', 'contains', 'hats')), 'contains');
KH_Test::ok(Keelhob_Snippet_Conditions::passes($rule('url_path', 'starts_with', '/shop')), 'starts with');
KH_Test::ok(Keelhob_Snippet_Conditions::passes($rule('url_path', 'equals', '/shop/hats')), 'equals, ignoring the trailing slash');
KH_Test::ok(
    !Keelhob_Snippet_Conditions::passes($rule('url_path', 'contains', 'colour=red')),
    'the query string is not part of the path, so matching on it fails'
);
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($rule('url_path', 'starts_with', '/blog')), 'a different prefix fails');

KH_Test::group('Conditions — AND and OR');

$two = static function (string $relation): string {
    return (string) wp_json_encode([
        'enabled'  => true,
        'relation' => $relation,
        'rules'    => [
            ['type' => 'user_status', 'operator' => 'is', 'value' => 'logged_in'],
            ['type' => 'device', 'operator' => 'is', 'value' => 'mobile'],
        ],
    ]);
};

kh_test_request(['logged_in' => true, 'mobile' => true]);
KH_Test::ok(Keelhob_Snippet_Conditions::passes($two('AND')), 'AND: both true');
KH_Test::ok(Keelhob_Snippet_Conditions::passes($two('OR')), 'OR: both true');

kh_test_request(['logged_in' => true, 'mobile' => false]);
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($two('AND')), 'AND: one false fails the lot');
KH_Test::ok(Keelhob_Snippet_Conditions::passes($two('OR')), 'OR: one true is enough');

kh_test_request(['logged_in' => false, 'mobile' => false]);
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($two('OR')), 'OR: none true fails');

KH_Test::group('Conditions — a value may be a list');

kh_test_request(['singular' => true, 'post_type' => 'page']);
$any = (string) wp_json_encode([
    'enabled' => true,
    'rules'   => [['type' => 'post_type', 'operator' => 'is', 'value' => ['post', 'page']]],
]);
KH_Test::ok(Keelhob_Snippet_Conditions::passes($any), 'an array value means "any of these"');

kh_test_request(['singular' => true, 'post_type' => 'product']);
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($any), '...and fails when it is none of them');

KH_Test::group('Conditions — malformed rules are dropped, not obeyed');

kh_test_request(['logged_in' => true]);
KH_Test::ok(
    Keelhob_Snippet_Conditions::passes('{"enabled":true,"rules":[{"type":"nonsense","operator":"is","value":"x"}]}'),
    'an unknown rule type is dropped — it must not silently block the snippet everywhere'
);
KH_Test::ok(
    Keelhob_Snippet_Conditions::passes('{"enabled":true,"rules":[{"type":"user_role","operator":"is","value":""}]}'),
    'a rule with no value yet is a half-finished form row, so it is dropped too'
);

$bad_op = Keelhob_Snippet_Conditions::normalize(
    '{"enabled":true,"rules":[{"type":"user_status","operator":"wibble","value":"logged_in"}]}'
);
KH_Test::same('is', $bad_op['rules'][0]['operator'], 'an operator that does not exist falls back to the first valid one');

/* ==========================================================================
 * The part that protects live sites.
 * ========================================================================== */

KH_Test::group('Conditions — pre-1.2.0 data behaves EXACTLY as it did');

// {"logged_in": true}
$legacy_in  = '{"logged_in":true}';
$legacy_out = '{"logged_in":false}';

kh_test_request(['logged_in' => true]);
KH_Test::ok(Keelhob_Snippet_Conditions::passes($legacy_in), 'legacy logged_in:true, visitor logged in');
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($legacy_out), 'legacy logged_in:false, visitor logged in');

kh_test_request(['logged_in' => false]);
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($legacy_in), 'legacy logged_in:true, visitor logged out');
KH_Test::ok(Keelhob_Snippet_Conditions::passes($legacy_out), 'legacy logged_in:false, visitor logged out');

// {"front_page": true|false}
kh_test_request(['front_page' => true]);
KH_Test::ok(Keelhob_Snippet_Conditions::passes('{"front_page":true}'), 'legacy front_page:true on the front page');
KH_Test::ok(!Keelhob_Snippet_Conditions::passes('{"front_page":false}'), 'legacy front_page:false on the front page');

kh_test_request(['front_page' => false]);
KH_Test::ok(!Keelhob_Snippet_Conditions::passes('{"front_page":true}'), 'legacy front_page:true elsewhere');
KH_Test::ok(Keelhob_Snippet_Conditions::passes('{"front_page":false}'), 'legacy front_page:false elsewhere');

/*
 * The subtle one, and the whole reason `only_singular` exists.
 *
 * The 1.1.0 engine wrapped post_types and post_ids in is_singular(). On an
 * archive or a search page the rule DID NOT APPLY and the snippet ran. Read as
 * an ordinary "post type is post" rule it would fail there instead, and a
 * header snippet that had always shown on category pages would vanish on
 * update, on a site whose owner changed nothing.
 */
$legacy_pt  = '{"post_types":["post"]}';
$legacy_ids = '{"post_ids":[12]}';

kh_test_request(['singular' => true, 'post_type' => 'post', 'object_id' => 12]);
KH_Test::ok(Keelhob_Snippet_Conditions::passes($legacy_pt), 'legacy post_types on a matching single');
KH_Test::ok(Keelhob_Snippet_Conditions::passes($legacy_ids), 'legacy post_ids on a matching single');

kh_test_request(['singular' => true, 'post_type' => 'page', 'object_id' => 99]);
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($legacy_pt), 'legacy post_types on a non-matching single');
KH_Test::ok(!Keelhob_Snippet_Conditions::passes($legacy_ids), 'legacy post_ids on a non-matching single');

kh_test_request(['singular' => false, 'archive' => true, 'post_type' => '', 'object_id' => 0]);
KH_Test::ok(
    Keelhob_Snippet_Conditions::passes($legacy_pt),
    'legacy post_types on an ARCHIVE still passes — 1.1.0 skipped the rule off a single, and so must this'
);
KH_Test::ok(
    Keelhob_Snippet_Conditions::passes($legacy_ids),
    'legacy post_ids on an ARCHIVE still passes, for the same reason'
);

// ...and a rule built in the NEW editor does not carry that exemption.
$new_pt = (string) wp_json_encode([
    'enabled' => true,
    'rules'   => [['type' => 'post_type', 'operator' => 'is', 'value' => 'post']],
]);
KH_Test::ok(
    !Keelhob_Snippet_Conditions::passes($new_pt),
    'a NEW post_type rule does fail on an archive — the exemption is for old data only'
);

// Several legacy keys at once were ANDed together.
kh_test_request(['logged_in' => true, 'front_page' => true, 'singular' => false]);
KH_Test::ok(
    Keelhob_Snippet_Conditions::passes('{"logged_in":true,"front_page":true}'),
    'two legacy keys, both satisfied'
);
KH_Test::ok(
    !Keelhob_Snippet_Conditions::passes('{"logged_in":false,"front_page":true}'),
    'two legacy keys, one not — legacy was always AND'
);

$lifted = Keelhob_Snippet_Conditions::normalize('{"logged_in":true,"post_types":["post"]}');
KH_Test::same('AND', $lifted['relation'], 'lifted legacy data is always AND');
KH_Test::same(2, count($lifted['rules']), 'and keeps one rule per legacy key');
KH_Test::ok($lifted['enabled'], 'legacy data that had any rule at all counts as switched on');

KH_Test::group('Conditions — building from the editor form');

$built = Keelhob_Snippet_Conditions::from_form([
    'conditions_enabled'  => '1',
    'conditions_relation' => 'OR',
    'cond_type'           => ['user_status', 'url_path', 'user_role'],
    'cond_operator'       => ['is', 'starts_with', 'is'],
    'cond_value'          => ['logged_in', '/members', ''],
]);
KH_Test::ok($built['enabled'], 'the tick box carries through');
KH_Test::same('OR', $built['relation'], 'so does the relation');
KH_Test::same(2, count($built['rules']), 'the empty third row is dropped rather than saved as a rule that matches nothing');

KH_Test::same(
    null,
    Keelhob_Snippet_Conditions::from_form([]),
    'an empty form clears the conditions rather than storing an empty object'
);

$kept = Keelhob_Snippet_Conditions::from_form([
    'cond_type'     => ['device'],
    'cond_operator' => ['is'],
    'cond_value'    => ['mobile'],
]);
KH_Test::ok(!$kept['enabled'], 'unticking the box switches the rules off');
KH_Test::same(1, count($kept['rules']), '...but keeps them, so a save does not throw away somebody\'s work');

KH_Test::group('Conditions — the list-screen summary');

KH_Test::same('', Keelhob_Snippet_Conditions::summary(null), 'nothing to summarise');
KH_Test::contains(
    'Logged in',
    Keelhob_Snippet_Conditions::summary('{"enabled":true,"rules":[{"type":"user_status","operator":"is","value":"logged_in"}]}'),
    'a single rule reads in words, not slugs'
);
KH_Test::contains(
    '+ 1 more',
    Keelhob_Snippet_Conditions::summary($two('AND')),
    'extra rules are counted rather than listed'
);

// Leave the request as an ordinary logged-out front page, so a later test file
// that forgets to set one does not inherit something surprising.
kh_test_request();
