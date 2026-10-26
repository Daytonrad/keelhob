<?php
/**
 * Saving the capability form writes EVERY group, on purpose.
 *
 * WHY THIS IS A TEST.
 *
 * Twice now the stored array has been read as a defect: "saving the form pins
 * every group to an explicit true/false, so Keelhob_Capabilities::
 * default_enabled() never decides anything for that site again." That is an
 * accurate description and the wrong conclusion, and the obvious fix — store
 * only the groups that DIFFER from their default — quietly destroys the one
 * thing the array exists to record.
 *
 * The array answers "did the owner say?", not "what is on?". Store only the
 * differences and a group the owner deliberately switched ON becomes
 * byte-for-byte identical to one that is merely on by default. The next time
 * a default changed, their deliberate choice would flip underneath them with
 * nothing on screen to explain it — and Keelhob::upgrade_from_paid_tier()
 * exists precisely because silently changing a capability is its own kind of
 * bug.
 *
 * So this file pins both halves:
 *
 *   1. Saving writes a value for every group that exists at save time.
 *   2. A group added in a LATER version is absent from that array, so
 *      default_enabled() still decides it and a new sensitive group still
 *      arrives switched off.
 *
 * (2) is the part that makes (1) safe, and it is the part a reader doubts.
 */

KH_Test::group('Capability save — every group gets an explicit value');

$kh_caps_from_post = new ReflectionMethod('Keelhob_Admin_Settings', 'caps_from_post');
@$kh_caps_from_post->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor

$kh_all_groups = array_keys(Keelhob_Capabilities::groups());
KH_Test::ok(count($kh_all_groups) > 10, 'there are groups to save (so this test is looking at something)');

kh_test_reset_options();
$_POST = ['cap' => ['content' => '1', 'seo' => '1']];
$kh_saved = $kh_caps_from_post->invoke(null);

KH_Test::same(
    $kh_all_groups,
    array_keys($kh_saved),
    'the saved array carries one key per group, not only the ticked ones'
);
KH_Test::ok($kh_saved['content'] === true, 'a ticked group is stored as true');
KH_Test::ok($kh_saved['payments'] === false, 'an unticked group is stored as false, not left absent');

/*
 * The specific case the "fix" would have broken. `snippets` is on by default,
 * so ticking it looks like a no-op — and it is not. It is the owner saying so.
 */
KH_Test::ok(
    Keelhob_Capabilities::default_enabled('snippets'),
    'snippets is on by default (the premise of the next assertion)'
);
KH_Test::ok(
    array_key_exists('snippets', $kh_saved),
    'a group that merely agrees with its default is STILL recorded, so "the owner chose this" survives'
);

/* Ticking nothing is a real choice and must not be read as "never said". */
kh_test_reset_options();
$_POST = [];
$kh_none = $kh_caps_from_post->invoke(null);
KH_Test::same($kh_all_groups, array_keys($kh_none), 'an empty form still records every group');
KH_Test::same(
    [],
    array_filter($kh_none),
    'and records every one of them as off'
);

/* Keys are sanitised: the group list is what decides, never the post. */
kh_test_reset_options();
$_POST = ['cap' => ['content' => '1', 'not_a_group' => '1', '<script>' => '1']];
$kh_dirty = $kh_caps_from_post->invoke(null);
KH_Test::same($kh_all_groups, array_keys($kh_dirty), 'a posted key that is not a group is ignored entirely');
KH_Test::ok($kh_dirty['content'] === true, 'and the real groups alongside it still save');

KH_Test::group('Capability save — a group added later still gets its default');

/*
 * Simulate the upgrade: a site saved the form when `content` and `payments`
 * were the only groups, and a later release adds one. The stored array cannot
 * mention it, so default_enabled() is what answers — which is why pinning
 * every CURRENT group does not stop a future sensitive group shipping off.
 */
kh_test_reset_options(['keelhob_settings' => ['capabilities' => ['content' => true, 'payments' => false]]]);

KH_Test::ok(
    Keelhob_Capabilities::is_group_enabled('content'),
    'the saved value is obeyed for a group the owner did answer for'
);
KH_Test::ok(
    !Keelhob_Capabilities::is_group_enabled('payments'),
    'including when the answer was off'
);

/* `abilities` and `sitecare_fix` are the two groups 2.0.0 actually added, so
 * they are the real instance of this, not an invented one. */
foreach (['abilities', 'sitecare_fix'] as $kh_new_group) {
    KH_Test::ok(
        in_array($kh_new_group, Keelhob_Capabilities::DISABLED_BY_DEFAULT, true),
        $kh_new_group . ' is a default-off group (the premise of the next assertion)'
    );
    KH_Test::ok(
        !Keelhob_Capabilities::is_group_enabled($kh_new_group),
        $kh_new_group . ' is STILL off on a site that saved the form before it existed'
    );
}

/* And a group added later that defaults ON is reachable, so the same mechanism
 * is not quietly refusing everything it does not recognise. */
KH_Test::ok(
    Keelhob_Capabilities::is_group_enabled('sitecare'),
    'a later group that defaults ON is on, on that same saved site'
);

$_POST = [];
