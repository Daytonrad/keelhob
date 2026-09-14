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


CP_Test::group('Conditions — nothing to decide means run');

CP_Test::ok(Claudpress_Snippet_Conditions::passes(null), 'no conditions at all');
CP_Test::ok(Claudpress_Snippet_Conditions::passes(''), 'an empty string');
CP_Test::ok(Claudpress_Snippet_Conditions::passes('not json'), 'junk that will not decode');
CP_Test::ok(Claudpress_Snippet_Conditions::passes('{"enabled":false,"rules":[]}'), 'switched off');
CP_Test::ok(
    Claudpress_Snippet_Conditions::passes('{"enabled":true,"relation":"AND","rules":[]}'),
    'switched on with no rules — a half-built form must not stop the snippet'
);

CP_Test::group('Conditions — one rule at a time');

$rule = static function (string $type, string $op, $value): string {
    return (string) wp_json_encode([
        'enabled'  => true,
        'relation' => 'AND',
        'rules'    => [['type' => $type, 'operator' => $op, 'value' => $value]],
    ]);
};

cp_test_request(['logged_in' => true, 'roles' => ['editor']]);
CP_Test::ok(Claudpress_Snippet_Conditions::passes($rule('user_status', 'is', 'logged_in')), 'user is logged in');
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($rule('user_status', 'is', 'logged_out')), '...so "logged out" fails');
CP_Test::ok(Claudpress_Snippet_Conditions::passes($rule('user_status', 'is_not', 'logged_out')), '"is not logged out" passes');
CP_Test::ok(Claudpress_Snippet_Conditions::passes($rule('user_role', 'is', 'editor')), 'role matches');
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($rule('user_role', 'is', 'administrator')), 'a role they do not have fails');

cp_test_request(['logged_in' => false]);
CP_Test::ok(
    !Claudpress_Snippet_Conditions::passes($rule('user_role', 'is', 'editor')),
    'a logged-out visitor has no role, so a role rule fails rather than erroring'
);

cp_test_request(['mobile' => true]);
CP_Test::ok(Claudpress_Snippet_Conditions::passes($rule('device', 'is', 'mobile')), 'device is mobile');
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($rule('device', 'is', 'desktop')), '...and not desktop');

cp_test_request(['front_page' => true, 'singular' => true]);
CP_Test::ok(Claudpress_Snippet_Conditions::passes($rule('page_type', 'is', 'front_page')), 'page type front_page');
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($rule('page_type', 'is', 'archive')), '...and not archive');

cp_test_request(['singular' => true, 'post_type' => 'product', 'object_id' => 42]);
CP_Test::ok(Claudpress_Snippet_Conditions::passes($rule('post_type', 'is', 'product')), 'post type matches');
CP_Test::ok(Claudpress_Snippet_Conditions::passes($rule('post_id', 'is', '42')), 'post id matches');
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($rule('post_id', 'is', '43')), 'a different id does not');

CP_Test::group('Conditions — URL path');

cp_test_request(['uri' => '/shop/hats/?colour=red']);
CP_Test::ok(Claudpress_Snippet_Conditions::passes($rule('url_path', 'contains', 'hats')), 'contains');
CP_Test::ok(Claudpress_Snippet_Conditions::passes($rule('url_path', 'starts_with', '/shop')), 'starts with');
CP_Test::ok(Claudpress_Snippet_Conditions::passes($rule('url_path', 'equals', '/shop/hats')), 'equals, ignoring the trailing slash');
CP_Test::ok(
    !Claudpress_Snippet_Conditions::passes($rule('url_path', 'contains', 'colour=red')),
    'the query string is not part of the path, so matching on it fails'
);
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($rule('url_path', 'starts_with', '/blog')), 'a different prefix fails');

CP_Test::group('Conditions — AND and OR');

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

cp_test_request(['logged_in' => true, 'mobile' => true]);
CP_Test::ok(Claudpress_Snippet_Conditions::passes($two('AND')), 'AND: both true');
CP_Test::ok(Claudpress_Snippet_Conditions::passes($two('OR')), 'OR: both true');

cp_test_request(['logged_in' => true, 'mobile' => false]);
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($two('AND')), 'AND: one false fails the lot');
CP_Test::ok(Claudpress_Snippet_Conditions::passes($two('OR')), 'OR: one true is enough');

cp_test_request(['logged_in' => false, 'mobile' => false]);
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($two('OR')), 'OR: none true fails');

CP_Test::group('Conditions — a value may be a list');

cp_test_request(['singular' => true, 'post_type' => 'page']);
$any = (string) wp_json_encode([
    'enabled' => true,
    'rules'   => [['type' => 'post_type', 'operator' => 'is', 'value' => ['post', 'page']]],
]);
CP_Test::ok(Claudpress_Snippet_Conditions::passes($any), 'an array value means "any of these"');

cp_test_request(['singular' => true, 'post_type' => 'product']);
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($any), '...and fails when it is none of them');

CP_Test::group('Conditions — malformed rules are dropped, not obeyed');

cp_test_request(['logged_in' => true]);
CP_Test::ok(
    Claudpress_Snippet_Conditions::passes('{"enabled":true,"rules":[{"type":"nonsense","operator":"is","value":"x"}]}'),
    'an unknown rule type is dropped — it must not silently block the snippet everywhere'
);
CP_Test::ok(
    Claudpress_Snippet_Conditions::passes('{"enabled":true,"rules":[{"type":"user_role","operator":"is","value":""}]}'),
    'a rule with no value yet is a half-finished form row, so it is dropped too'
);

$bad_op = Claudpress_Snippet_Conditions::normalize(
    '{"enabled":true,"rules":[{"type":"user_status","operator":"wibble","value":"logged_in"}]}'
);
CP_Test::same('is', $bad_op['rules'][0]['operator'], 'an operator that does not exist falls back to the first valid one');

/* ==========================================================================
 * The part that protects live sites.
 * ========================================================================== */

CP_Test::group('Conditions — pre-1.2.0 data behaves EXACTLY as it did');

// {"logged_in": true}
$legacy_in  = '{"logged_in":true}';
$legacy_out = '{"logged_in":false}';

cp_test_request(['logged_in' => true]);
CP_Test::ok(Claudpress_Snippet_Conditions::passes($legacy_in), 'legacy logged_in:true, visitor logged in');
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($legacy_out), 'legacy logged_in:false, visitor logged in');

cp_test_request(['logged_in' => false]);
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($legacy_in), 'legacy logged_in:true, visitor logged out');
CP_Test::ok(Claudpress_Snippet_Conditions::passes($legacy_out), 'legacy logged_in:false, visitor logged out');

// {"front_page": true|false}
cp_test_request(['front_page' => true]);
CP_Test::ok(Claudpress_Snippet_Conditions::passes('{"front_page":true}'), 'legacy front_page:true on the front page');
CP_Test::ok(!Claudpress_Snippet_Conditions::passes('{"front_page":false}'), 'legacy front_page:false on the front page');

cp_test_request(['front_page' => false]);
CP_Test::ok(!Claudpress_Snippet_Conditions::passes('{"front_page":true}'), 'legacy front_page:true elsewhere');
CP_Test::ok(Claudpress_Snippet_Conditions::passes('{"front_page":false}'), 'legacy front_page:false elsewhere');

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

cp_test_request(['singular' => true, 'post_type' => 'post', 'object_id' => 12]);
CP_Test::ok(Claudpress_Snippet_Conditions::passes($legacy_pt), 'legacy post_types on a matching single');
CP_Test::ok(Claudpress_Snippet_Conditions::passes($legacy_ids), 'legacy post_ids on a matching single');

cp_test_request(['singular' => true, 'post_type' => 'page', 'object_id' => 99]);
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($legacy_pt), 'legacy post_types on a non-matching single');
CP_Test::ok(!Claudpress_Snippet_Conditions::passes($legacy_ids), 'legacy post_ids on a non-matching single');

cp_test_request(['singular' => false, 'archive' => true, 'post_type' => '', 'object_id' => 0]);
CP_Test::ok(
    Claudpress_Snippet_Conditions::passes($legacy_pt),
    'legacy post_types on an ARCHIVE still passes — 1.1.0 skipped the rule off a single, and so must this'
);
CP_Test::ok(
    Claudpress_Snippet_Conditions::passes($legacy_ids),
    'legacy post_ids on an ARCHIVE still passes, for the same reason'
);

// ...and a rule built in the NEW editor does not carry that exemption.
$new_pt = (string) wp_json_encode([
    'enabled' => true,
    'rules'   => [['type' => 'post_type', 'operator' => 'is', 'value' => 'post']],
]);
CP_Test::ok(
    !Claudpress_Snippet_Conditions::passes($new_pt),
    'a NEW post_type rule does fail on an archive — the exemption is for old data only'
);

// Several legacy keys at once were ANDed together.
cp_test_request(['logged_in' => true, 'front_page' => true, 'singular' => false]);
CP_Test::ok(
    Claudpress_Snippet_Conditions::passes('{"logged_in":true,"front_page":true}'),
    'two legacy keys, both satisfied'
);
CP_Test::ok(
    !Claudpress_Snippet_Conditions::passes('{"logged_in":false,"front_page":true}'),
    'two legacy keys, one not — legacy was always AND'
);

$lifted = Claudpress_Snippet_Conditions::normalize('{"logged_in":true,"post_types":["post"]}');
CP_Test::same('AND', $lifted['relation'], 'lifted legacy data is always AND');
CP_Test::same(2, count($lifted['rules']), 'and keeps one rule per legacy key');
CP_Test::ok($lifted['enabled'], 'legacy data that had any rule at all counts as switched on');

CP_Test::group('Conditions — building from the editor form');

$built = Claudpress_Snippet_Conditions::from_form([
    'conditions_enabled'  => '1',
    'conditions_relation' => 'OR',
    'cond_type'           => ['user_status', 'url_path', 'user_role'],
    'cond_operator'       => ['is', 'starts_with', 'is'],
    'cond_value'          => ['logged_in', '/members', ''],
]);
CP_Test::ok($built['enabled'], 'the tick box carries through');
CP_Test::same('OR', $built['relation'], 'so does the relation');
CP_Test::same(2, count($built['rules']), 'the empty third row is dropped rather than saved as a rule that matches nothing');

CP_Test::same(
    null,
    Claudpress_Snippet_Conditions::from_form([]),
    'an empty form clears the conditions rather than storing an empty object'
);

$kept = Claudpress_Snippet_Conditions::from_form([
    'cond_type'     => ['device'],
    'cond_operator' => ['is'],
    'cond_value'    => ['mobile'],
]);
CP_Test::ok(!$kept['enabled'], 'unticking the box switches the rules off');
CP_Test::same(1, count($kept['rules']), '...but keeps them, so a save does not throw away somebody\'s work');

CP_Test::group('Conditions — the list-screen summary');

CP_Test::same('', Claudpress_Snippet_Conditions::summary(null), 'nothing to summarise');
CP_Test::contains(
    'Logged in',
    Claudpress_Snippet_Conditions::summary('{"enabled":true,"rules":[{"type":"user_status","operator":"is","value":"logged_in"}]}'),
    'a single rule reads in words, not slugs'
);
CP_Test::contains(
    '+ 1 more',
    Claudpress_Snippet_Conditions::summary($two('AND')),
    'extra rules are counted rather than listed'
);

// Leave the request as an ordinary logged-out front page, so a later test file
// that forgets to set one does not inherit something surprising.
cp_test_request();
