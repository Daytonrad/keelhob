<?php
/** Capability toggles: the owner's on/off switches over whole areas. */

KH_Test::group('Capabilities — default state');

kh_test_reset_options();

KH_Test::ok(Keelhob_Capabilities::is_group_enabled('content'), 'build groups default to enabled when nothing is configured');
KH_Test::ok(Keelhob_Capabilities::tool_allowed('create_post'), 'a grouped tool is allowed by default');
KH_Test::ok(Keelhob_Capabilities::tool_allowed('some_third_party_tool'), 'an ungrouped tool is allowed (plugins can add tools)');

// The eight high-blast-radius groups ship OFF. They used to be unreachable
// because they were behind a paywall, and that paywall was quietly doing the
// job of a safety fence. Deleting it without replacing the fence would have
// handed every install - and every site that merely upgraded - the ability to
// email customers, edit users, change payment gateways and push to production.
//
// So this asserts the fence exists. If it ever goes green by accident, a
// release has silently switched send_email on for everybody.
foreach (Keelhob_Capabilities::DISABLED_BY_DEFAULT as $group) {
    KH_Test::ok(!Keelhob_Capabilities::is_group_enabled($group), $group . ' ships switched off');
}
foreach (['content', 'design', 'snippets', 'seo', 'files', 'images', 'memory', 'skills', 'code_exec', 'store'] as $group) {
    KH_Test::ok(Keelhob_Capabilities::is_group_enabled($group), $group . ' ships switched on - the build journey needs it');
}

// And the tools inside a default-off group really are refused, not merely
// described as off somewhere in the settings screen.
KH_Test::ok(!Keelhob_Capabilities::tool_allowed('send_email'), 'send_email is refused on a fresh install');
KH_Test::ok(!Keelhob_Capabilities::tool_allowed('deploy_push'), 'deploy_push is refused on a fresh install');
KH_Test::ok(!Keelhob_Capabilities::tool_allowed('wc_payments'), 'wc_payments is refused on a fresh install');

$off = Keelhob_Capabilities::disabled_labels();
KH_Test::same(count(Keelhob_Capabilities::DISABLED_BY_DEFAULT), count($off), 'every default-off group is reported to the agent, so it stops rather than retries');

KH_Test::group('Capabilities — turning a group off');

kh_test_reset_options(['keelhob_settings' => ['capabilities' => ['payments' => false, 'content' => true]]]);

KH_Test::ok(!Keelhob_Capabilities::tool_allowed('wc_payments'), 'a tool in a disabled group is refused');
KH_Test::ok(Keelhob_Capabilities::tool_allowed('create_post'), 'other groups are unaffected');
KH_Test::ok(in_array('Payments (money)', Keelhob_Capabilities::disabled_labels(), true), 'the disabled group is named for the session instructions');

KH_Test::group('Capabilities — the always-on read-only set');

$all_off = [];
foreach (array_keys(Keelhob_Capabilities::groups()) as $group) {
    $all_off[$group] = false;
}
kh_test_reset_options(['keelhob_settings' => ['capabilities' => $all_off]]);

foreach (Keelhob_Capabilities::always_on() as $tool) {
    KH_Test::ok(Keelhob_Capabilities::tool_allowed($tool), 'always-on survives every toggle being off: ' . $tool);
}
KH_Test::ok(!Keelhob_Capabilities::tool_allowed('execute_php'), 'everything else is off when every group is off');
KH_Test::ok(!Keelhob_Capabilities::tool_allowed('run_agent'), 'autonomy is off when every group is off');

KH_Test::group('Capabilities — group definitions are coherent');

$seen = [];
$dupes = [];
foreach (Keelhob_Capabilities::groups() as $key => $group) {
    KH_Test::ok(!empty($group['label']) && !empty($group['desc']), $key . ' has a label and description (both are shown in the UI and the Guide)');
    KH_Test::ok(!empty($group['tools']), $key . ' lists at least one tool');
    foreach ($group['tools'] as $tool) {
        if (isset($seen[$tool])) {
            $dupes[] = $tool . ' (' . $seen[$tool] . ' + ' . $key . ')';
        }
        $seen[$tool] = $key;
    }
}
KH_Test::same([], $dupes, 'no tool belongs to two capability groups (the reverse map would be ambiguous)');

// The same ambiguity one level up, and the one that actually misleads a site
// owner. tool_allowed() checks always_on() FIRST and returns true before it
// ever looks at the group, so a tool listed in both is permanently on while
// the Dashboard files it under a toggle and tells the owner that switching
// that toggle off means it is "refused if it tries". agent_runs was in both.
//
// Whichever way a future tool is meant to go, it cannot go both: either it is
// read-only introspection that survives every restriction, or it belongs to a
// group the owner can switch off. Listing it twice promises a switch that does
// nothing.
$overlap = [];
foreach (Keelhob_Capabilities::groups() as $key => $group) {
    foreach ((array) ($group['tools'] ?? []) as $tool) {
        if (in_array($tool, Keelhob_Capabilities::always_on(), true)) {
            $overlap[] = $tool . ' (in group ' . $key . ')';
        }
    }
}
KH_Test::same([], $overlap, 'no always-on tool is also filed under a capability group');

// And the behaviour that assertion protects, stated directly.
Keelhob::instance()->update(['cap_automation' => false]);
KH_Test::ok(!Keelhob_Capabilities::tool_allowed('run_agent'), 'switching automation off refuses run_agent');


kh_test_reset_options();

KH_Test::group('Capabilities — the description must not understate the toggle');

// A permission prompt that understates the permission is a security defect, not
// a typo: the owner consents to something they were not told about. These four
// had drifted because tools were added to a group and the sentence was not.
//
// Asserted on the specific WORD that carries the risk, because that is the word
// a reader needs to see before ticking the box. If a future edit makes the
// sentence prettier and drops it, this fails — which is the point.
$groups = Keelhob_Capabilities::groups();

$must_mention = [
    // seo_sitemap can rewrite robots.txt and allow or block GPTBot, ClaudeBot
    // and Google-Extended. Blocking a crawler is a commercial decision, and the
    // toggle used to describe itself as "the sitemap".
    'seo'     => ['robots', 'crawler'],
    // skill_refresh fetches over the network and with force=true destroys the
    // owner's own edits. "Create/delete" covered neither.
    'skills'  => ['download', 'overwrite'],
    // The group has delete_post. "Create/edit" did not say so.
    'content' => ['delete'],
    // list_directory is browsing, and write_sandbox_file adds PHP that runs on
    // every request. "Read/write/delete files" undersold both.
    'files'   => ['browse', 'every request'],
];

foreach ($must_mention as $key => $words) {
    KH_Test::ok(isset($groups[$key]), "capability group \"$key\" still exists");
    $desc = strtolower((string) ($groups[$key]['desc'] ?? ''));
    foreach ($words as $word) {
        KH_Test::ok(
            strpos($desc, $word) !== false,
            "the \"$key\" description mentions \"$word\" — the owner reads this before granting it"
        );
    }
}

// And the general rule the four above are instances of: a group holding a tool
// that can destroy something has to say so somewhere in its sentence.
foreach ($groups as $key => $group) {
    $has_delete = false;
    foreach ((array) ($group['tools'] ?? []) as $tool) {
        if (strpos($tool, 'delete') !== false) {
            $has_delete = true;
            break;
        }
    }
    if (!$has_delete) {
        continue;
    }
    $desc = strtolower((string) ($group['desc'] ?? ''));
    KH_Test::ok(
        strpos($desc, 'delete') !== false || strpos($desc, 'remove') !== false || strpos($desc, 'destroy') !== false,
        "\"$key\" contains a delete tool, so its description says so"
    );
}
