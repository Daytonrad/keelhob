<?php
/**
 * The Overview block and the capability cards.
 *
 * A SEPARATE FILE FROM test-admin-render.php, for the same reason
 * test-admin-shell.php is: that file pins the markup contract of the settings
 * forms and two lanes were editing this branch at once. Adding a file cannot
 * collide with another lane's edit.
 *
 * What is proved here, in order:
 *
 *   1. THE BAND IS RIGHT FOR EVERY COMBINATION of stored capability groups.
 *      Not a sample — an exhaustive sweep of the equivalence classes the band
 *      can possibly distinguish, plus the never-said case, which is the one a
 *      reviewer is most likely to get wrong.
 *   2. THE THREE CARDS WRITE NOTHING. Asserted twice over: the stored array is
 *      byte-identical after the view has run, and the rendered cards contain no
 *      form, no button, no link and no checkbox.
 *   3. THE CAPABILITY TOGGLE IS UNCHANGED. Same `cap[<key>]` field names, a
 *      real <input type="checkbox"> behind every switch, one per group, no
 *      `role="switch"`, no JavaScript, and the form still posts to
 *      admin-post.php with a nonce.
 *   4. The empty states exist, in the words the brief specified.
 *
 * It does not assert that anything LOOKS right. Computed styles are the browser
 * layer's job (tests/rig/ui/check-admin.mjs) and the house rule is that UI is
 * never claimed to work from reading the HTML.
 */

KH_Test::group('Overview — the band, for every combination of stored groups');

/** Every group key, and the eight that ship switched off. */
$kh_ov_groups = array_keys(Keelhob_Capabilities::groups());
$kh_ov_gated  = Keelhob_Capabilities::DISABLED_BY_DEFAULT;
$kh_ov_free   = array_values(array_diff($kh_ov_groups, $kh_ov_gated));

KH_Test::same(8, count($kh_ov_gated), 'there are eight groups that ship switched off');
KH_Test::ok(count($kh_ov_groups) >= 20, 'and around twenty groups in total (' . count($kh_ov_groups) . ')');
foreach ($kh_ov_gated as $kh_g) {
    KH_Test::ok(in_array($kh_g, $kh_ov_groups, true), 'the gated group ' . $kh_g . ' is a real group, not a stale name');
}

/** Store an explicit true/false for every group, so nothing is left to the default. */
$kh_ov_set = static function (array $on) use ($kh_ov_groups): void {
    $caps = [];
    foreach ($kh_ov_groups as $key) {
        $caps[$key] = in_array($key, $on, true);
    }
    kh_test_reset_options(['keelhob_settings' => ['capabilities' => $caps]]);
};

/**
 * EXHAUSTIVE, AND WHY THIS IS EXHAUSTIVE RATHER THAN A SAMPLE.
 *
 * There are 2^21 ways the stored array can look, which is two million — too
 * slow to sweep and pointless to sweep, because the band can only distinguish
 * two facts about that array:
 *
 *   A. is any of the eight gated groups on?   (-> Full access)
 *   B. is ANY group at all on?                (-> Reading only when not)
 *
 * So the complete cover is: all 256 subsets of the eight, crossed with every
 * distinguishable state of the rest — none on, exactly one on (each of the
 * fourteen in turn), and all of them on. That is 256 x 16 = 4096 stored arrays,
 * and it visits every equivalence class the logic has.
 *
 * Counted into one assertion rather than 4096, so a failure names the first
 * combination that broke instead of burying it in a wall of green ticks.
 */
$kh_ov_cases = 0;
$kh_ov_bad   = [];
$kh_ov_rest  = [[]];
foreach ($kh_ov_free as $kh_one) {
    $kh_ov_rest[] = [$kh_one];
}
$kh_ov_rest[] = $kh_ov_free;

for ($kh_mask = 0; $kh_mask < (1 << 8); $kh_mask++) {
    $kh_gated_on = [];
    for ($kh_bit = 0; $kh_bit < 8; $kh_bit++) {
        if ($kh_mask & (1 << $kh_bit)) {
            $kh_gated_on[] = $kh_ov_gated[$kh_bit];
        }
    }
    foreach ($kh_ov_rest as $kh_rest) {
        $kh_on = array_merge($kh_gated_on, $kh_rest);
        $kh_ov_set($kh_on);

        if ($kh_gated_on !== []) {
            $kh_want = Keelhob_Admin::BAND_FULL;
        } elseif ($kh_on === []) {
            $kh_want = Keelhob_Admin::BAND_READING;
        } else {
            $kh_want = Keelhob_Admin::BAND_BUILDING;
        }

        $kh_state = Keelhob_Admin::capability_state();
        $kh_ov_cases++;

        if ($kh_state['band'] !== $kh_want) {
            $kh_ov_bad[] = $kh_want . ' expected, got ' . $kh_state['band'] . ' for [' . implode(',', $kh_on) . ']';
        }
        // The counts have to agree with the band, or the cards and the tiles
        // would contradict each other while both looked plausible.
        if (count($kh_state['on']) !== count($kh_on)) {
            $kh_ov_bad[] = 'on-count ' . count($kh_state['on']) . ' != ' . count($kh_on);
        }
        if (count($kh_state['gated_on']) !== count($kh_gated_on)) {
            $kh_ov_bad[] = 'gated-count ' . count($kh_state['gated_on']) . ' != ' . count($kh_gated_on);
        }
        if ($kh_ov_bad !== []) {
            break 2;
        }
    }
}

KH_Test::same(4096, $kh_ov_cases, 'every equivalence class of the stored array was visited (' . $kh_ov_cases . ' combinations)');
KH_Test::same([], $kh_ov_bad, 'and the band is right for all of them' . ($kh_ov_bad ? ' — first failure: ' . $kh_ov_bad[0] : ''));

KH_Test::group('Overview — the three cases a reviewer gets wrong');

/*
 * THE NEVER-SAID CASE. An empty array does not mean "nothing is on" — it means
 * the owner has never expressed an opinion, and Keelhob_Capabilities::
 * default_enabled() then says yes to everything except the eight. A band
 * function that treated empty as "off" would tell a fresh install it was in
 * Reading only while an agent could rewrite every page on the site.
 */
kh_test_reset_options();
$kh_fresh = Keelhob_Admin::capability_state();
KH_Test::same(
    Keelhob_Admin::BAND_BUILDING,
    $kh_fresh['band'],
    'a fresh install, with nothing stored at all, reads as Building — NOT as Reading only'
);
KH_Test::same(
    count($kh_ov_groups) - 8,
    count($kh_fresh['on']),
    'with every group on except the eight that ship off (' . count($kh_fresh['on']) . ')'
);
KH_Test::same([], $kh_fresh['gated_on'], 'and none of the eight is on');
/*
 * And the honest part: Building does NOT mean "nothing sensitive is on".
 * snippets, files, code_exec, store and site_admin are sensitive groups that
 * simply are not among the eight, and they are ON by default. The band is
 * defined by the eight; the card says this out loud so the label cannot be read
 * as a safety claim it is not making.
 */
KH_Test::ok(
    $kh_fresh['sensitive_on'] !== [],
    'a fresh install has sensitive groups on (' . implode(', ', $kh_fresh['sensitive_on']) . '), which is why the card says so'
);

// Every group explicitly off.
$kh_ov_set([]);
KH_Test::same(Keelhob_Admin::BAND_READING, Keelhob_Admin::capability_band(), 'every group explicitly off reads as Reading only');
KH_Test::same(0, count(Keelhob_Admin::capability_state()['on']), 'with nothing on');

// One sensitive-but-not-gated group on, and nothing else: still Building.
$kh_ov_set(['code_exec']);
KH_Test::same(
    Keelhob_Admin::BAND_BUILDING,
    Keelhob_Admin::capability_band(),
    'raw code execution alone is Building, because it is not one of the eight — the band is defined by the eight'
);
KH_Test::same(['code_exec'], Keelhob_Admin::capability_state()['sensitive_on'], 'and it is reported as sensitive');

// One gated group on, and nothing else: Full access, however little else is on.
foreach ($kh_ov_gated as $kh_g) {
    $kh_ov_set([$kh_g]);
    KH_Test::same(
        Keelhob_Admin::BAND_FULL,
        Keelhob_Admin::capability_band(),
        $kh_g . ' on its own puts the site in Full access'
    );
}

// Each non-gated group on its own: Building, never Full access.
foreach ($kh_ov_free as $kh_g) {
    $kh_ov_set([$kh_g]);
    KH_Test::same(
        Keelhob_Admin::BAND_BUILDING,
        Keelhob_Admin::capability_band(),
        $kh_g . ' on its own is Building'
    );
}

/*
 * A key that is not a group must not be able to move the band. This is not
 * hypothetical: the stored array survives a downgrade, and a group removed in a
 * later version leaves its key behind.
 */
kh_test_reset_options(['keelhob_settings' => ['capabilities' => ['some_removed_group' => true]]]);
$kh_stale = Keelhob_Admin::capability_state();
KH_Test::same(count($kh_ov_groups), $kh_stale['total'], 'a leftover key from an older version is not counted as a group');
KH_Test::same(Keelhob_Admin::BAND_BUILDING, $kh_stale['band'], 'and it cannot move the band on its own');

// The partition is a partition: on + off = total, and sensitive_on is a subset.
kh_test_reset_options();
$kh_part = Keelhob_Admin::capability_state();
KH_Test::same(
    $kh_part['total'],
    count($kh_part['on']) + count($kh_part['off']),
    'every group is either on or off, never both and never neither'
);
KH_Test::same(
    [],
    array_diff($kh_part['sensitive_on'], $kh_part['on']),
    'nothing is reported as a sensitive group that is on without being on'
);
KH_Test::same(
    [],
    array_diff($kh_part['gated_on'], $kh_part['on']),
    'and the same for the gated eight'
);
KH_Test::same(
    Keelhob_Admin::capability_state()['band'],
    Keelhob_Admin::capability_band(),
    'capability_band() and capability_state() cannot disagree'
);

KH_Test::group('Overview — the band view writes NOTHING');

/*
 * RISK-REVIEW §B1 IS THE WHOLE REASON THIS TEST EXISTS.
 *
 * (a) Keelhob's real default already has snippets, files and execute_php ON, so
 *     a "Building" preset would switch off live code a site is running.
 * (b) A "Full access" preset would grant payments, orders, users, customer
 *     email and production deploys in one click.
 * (c) Writing an explicit value for every group destroys the "never said either
 *     way" state that default_enabled() depends on, permanently pinning groups
 *     against a future version that decides one of them is risky.
 *
 * So the view is asserted to be a view. The stored array must come back
 * identical — including still being ABSENT when it was absent, because writing
 * an empty array is itself the (c) failure.
 */
kh_test_reset_options();
$kh_before_absent = Keelhob::instance()->get('capabilities', 'ABSENT');
Keelhob_Admin::capability_state();
Keelhob_Admin::capability_band();
Keelhob_Admin::bands();
Keelhob_Admin::needs_attention();
KH_Test::same(
    $kh_before_absent,
    Keelhob::instance()->get('capabilities', 'ABSENT'),
    'reading the band on a fresh install does not populate the capabilities array'
);
KH_Test::same(
    [],
    (array) Keelhob::instance()->get('capabilities', []),
    'which is still empty afterwards — "the owner never said" survives being looked at'
);

$kh_ov_set(['content', 'seo', 'payments']);
$kh_before = Keelhob::instance()->get('capabilities', []);
Keelhob_Admin::capability_state();
Keelhob_Admin::capability_band();
Keelhob_Admin::needs_attention();
KH_Test::same(
    $kh_before,
    Keelhob::instance()->get('capabilities', []),
    'and reading it on a configured site leaves the stored array byte-identical'
);

/*
 * Asserted against the SOURCE as well, because the failure mode is a later edit
 * adding a convenience "apply this mode" handler, and that edit would pass
 * every behavioural test above until somebody clicked the new button.
 */
$kh_ov_admin_src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-admin.php');
$kh_ov_band_src  = (string) substr(
    $kh_ov_admin_src,
    (int) strpos($kh_ov_admin_src, 'const BAND_READING'),
    (int) strpos($kh_ov_admin_src, '// --- Screen and endpoint callbacks') - (int) strpos($kh_ov_admin_src, 'const BAND_READING')
);
KH_Test::ok($kh_ov_band_src !== '', 'the band section of class-admin.php was located');
foreach (['update_option(', '->update(', 'add_option(', 'delete_option('] as $kh_write) {
    KH_Test::ok(
        strpos($kh_ov_band_src, $kh_write) === false,
        'the band section calls no ' . $kh_write . ' — it is a view, not a control'
    );
}

KH_Test::group('Overview — the three bands are described, not offered');

$kh_bands = Keelhob_Admin::bands();
KH_Test::same(
    [Keelhob_Admin::BAND_READING, Keelhob_Admin::BAND_BUILDING, Keelhob_Admin::BAND_FULL],
    array_keys($kh_bands),
    'three bands, in increasing order of what the agent may do'
);
foreach ($kh_bands as $kh_key => $kh_band) {
    foreach (['label', 'blurb', 'change'] as $kh_field) {
        KH_Test::ok(
            isset($kh_band[$kh_field]) && trim((string) $kh_band[$kh_field]) !== '',
            $kh_key . ' band has a non-empty ' . $kh_field
        );
    }
}

KH_Test::group('Overview — the capability cards keep the save path exactly');

/*
 * Rendered for real. The collaborators the Connect screen reads from are
 * replaced with the same kind of stand-ins test-admin-render.php uses, because
 * the real registry and logger want a database.
 */
if (!class_exists('KH_Ov_Registry')) {
    class KH_Ov_Registry
    {
        public function names(): array
        {
            return ['get_site_info', 'create_post', 'wc_payments', 'execute_php', 'send_email'];
        }

        public function list(): array
        {
            return [['name' => 'get_site_info', 'description' => 'Return site info.', 'inputSchema' => ['type' => 'object']]];
        }
    }
}

if (!class_exists('KH_Ov_Bag')) {
    class KH_Ov_Bag
    {
        /** @var array<int, array<string, mixed>> */
        private array $rows;

        public function __construct(array $rows = [])
        {
            $this->rows = $rows;
        }

        public function all(): array
        {
            return $this->rows;
        }

        public function recent($limit = 100, $offset = 0): array
        {
            return array_slice($this->rows, (int) $offset, (int) $limit);
        }

        public function paged($limit, $offset): array
        {
            return array_slice($this->rows, (int) $offset, (int) $limit);
        }

        public function count_all(): int
        {
            return count($this->rows);
        }
    }
}

$kh_ov_kh           = Keelhob::instance();
$kh_ov_kh->registry = new KH_Ov_Registry();
$kh_ov_kh->skills   = new KH_Ov_Bag();
$kh_ov_kh->memory   = new KH_Ov_Bag();
$kh_ov_kh->logger   = new KH_Ov_Bag();

/** Render the Connect screen and hand back its HTML. */
$kh_ov_render = static function (): string {
    ob_start();
    (new Keelhob_Admin())->render();

    return (string) ob_get_clean();
};

kh_test_reset_options();
$kh_page = $kh_ov_render();

$kh_ov_group_defs = Keelhob_Capabilities::groups();

// THE FIELD NAMES. Not one of them may change: class-admin-settings.php reads
// `cap[<key>]` and nothing else, so a renamed field silently switches every
// group off on the next save.
foreach (array_keys($kh_ov_group_defs) as $kh_key) {
    KH_Test::contains(
        'name="cap[' . $kh_key . ']"',
        $kh_page,
        'the toggle for ' . $kh_key . ' still posts as cap[' . $kh_key . ']'
    );
}
KH_Test::same(
    count($kh_ov_group_defs),
    substr_count($kh_page, 'name="cap['),
    'exactly one toggle per group, and no extras'
);

// A REAL CHECKBOX BEHIND EVERY SWITCH.
KH_Test::same(
    count($kh_ov_group_defs),
    substr_count($kh_page, 'type="checkbox" class="kh-u-switch"'),
    'every switch is a real <input type="checkbox">'
);
KH_Test::ok(strpos($kh_page, 'role="switch"') === false, 'and not one of them is a div with role="switch"');
/*
 * NO PERMISSION CONTROL MAY DEPEND ON JAVASCRIPT. The Connect screen does carry
 * one script — the app picker, which only decides which config block is visible
 * and is documented at length as to why it must not reload — so this is
 * asserted against the capability form itself rather than the whole page.
 */
$kh_caps_from = (int) strpos($kh_page, 'name="keelhob_action" value="capabilities"');
KH_Test::ok($kh_caps_from > 0, 'the capability form is on the page');
$kh_caps_html = substr($kh_page, $kh_caps_from);
foreach (['<script', 'onclick', 'onchange', 'role="switch"'] as $kh_js) {
    KH_Test::ok(
        strpos($kh_caps_html, $kh_js) === false,
        'the capability form contains no ' . $kh_js . ' — it works with JavaScript off'
    );
}

// The save path itself.
KH_Test::contains('name="keelhob_action" value="capabilities"', $kh_page, 'the capability form still names the capabilities action');
KH_Test::contains('name="action" value="keelhob_save"', $kh_page, 'and still posts the keelhob_save action');
KH_Test::contains('admin-post.php', $kh_page, 'to admin-post.php');
KH_Test::ok(substr_count($kh_page, 'name="_wpnonce"') >= substr_count($kh_page, '<form'), 'every form on the screen carries a nonce');

// One card per group, naming the tools inside it, with the sensitive badge.
// Counted as a whole class attribute: `kh-u-cap` is also the stem of
// kh-u-cap-top, -id, -label and -desc, and a substring count scores those too.
KH_Test::same(
    count($kh_ov_group_defs),
    substr_count($kh_page, 'class="kh-u-cap"') + substr_count($kh_page, 'class="kh-u-cap is-off"'),
    'one capability card per group'
);
$kh_sensitive = 0;
foreach ($kh_ov_group_defs as $kh_key => $kh_g) {
    if (!empty($kh_g['sensitive'])) {
        $kh_sensitive++;
    }
    KH_Test::contains(
        esc_html((string) $kh_g['label']),
        $kh_page,
        'the card for ' . $kh_key . ' is labelled'
    );
    foreach ($kh_g['tools'] as $kh_tool) {
        KH_Test::contains(
            '<span class="kh-u-chip">' . $kh_tool . '</span>',
            $kh_page,
            'the ' . $kh_key . ' card names the tool ' . $kh_tool
        );
    }
}
KH_Test::same(
    $kh_sensitive,
    substr_count($kh_page, 'class="kh-u-badge"'),
    'a Sensitive badge on each of the ' . $kh_sensitive . ' sensitive groups, and nowhere else'
);

// Every group has an icon, and the map is not allowed to fall behind the list.
foreach (array_keys($kh_ov_group_defs) as $kh_key) {
    KH_Test::ok(
        isset(Keelhob_Brand::GROUP_ICONS[$kh_key]),
        'the icon map covers the group ' . $kh_key
    );
    KH_Test::ok(
        strpos(Keelhob_Brand::group_icon($kh_key), 'dashicons-') === 0,
        'and ' . $kh_key . ' resolves to a dashicon'
    );
}
KH_Test::same(
    'dashicons-admin-generic',
    Keelhob_Brand::group_icon('a_group_added_tomorrow'),
    'an unmapped group falls back to the generic cog rather than rendering nothing'
);
KH_Test::same(
    [],
    array_diff(array_keys(Keelhob_Brand::GROUP_ICONS), array_keys($kh_ov_group_defs)),
    'and the map holds no icons for groups that no longer exist'
);

KH_Test::group('Overview — the state cards are a view, in the markup too');

$kh_modes_from = (int) strpos($kh_page, '<div class="kh-u-modes">');
KH_Test::ok($kh_modes_from > 0, 'the three state cards are on the page');
$kh_modes_html = substr($kh_page, $kh_modes_from, (int) strpos($kh_page, 'kh-u-tiles') - $kh_modes_from);

foreach (['<form', '<button', '<a ', '<input', 'onclick', 'role="button"'] as $kh_ctrl) {
    KH_Test::ok(
        strpos($kh_modes_html, $kh_ctrl) === false,
        'the state cards contain no ' . trim($kh_ctrl) . ' — there is nothing on them to click'
    );
}
KH_Test::same(
    3,
    substr_count($kh_modes_html, 'class="kh-u-mode"') + substr_count($kh_modes_html, 'class="kh-u-mode is-current"'),
    'three cards'
);
KH_Test::same(1, substr_count($kh_modes_html, 'is-current'), 'exactly one of them is marked as current');
KH_Test::same(1, substr_count($kh_modes_html, 'kh-u-mode-pill'), 'and exactly one carries the Current pill');
foreach (Keelhob_Admin::bands() as $kh_band) {
    KH_Test::contains(esc_html((string) $kh_band['label']), $kh_modes_html, 'the band ' . $kh_band['label'] . ' is named');
}

KH_Test::group('Overview — the tiles and the two empty states');

KH_Test::contains('class="kh-u-tiles"', $kh_page, 'the stat tiles render');
KH_Test::same(4, substr_count($kh_page, 'class="kh-u-tile"'), 'four of them');
KH_Test::contains('Areas switched on', $kh_page, 'the first tile counts the areas that are on');
KH_Test::contains('Tools the agent may call', $kh_page, 'the second counts the tools that are reachable');

/*
 * The stat tiles must agree with the band view. With nothing stored, the gated
 * eight are off, so wc_payments and send_email are refused and three of the
 * five stand-in tools are callable.
 */
KH_Test::contains('3 / 5', $kh_page, 'the tool tile counts only the tools the capability gate actually allows');

// The empty states, in the words the brief specified.
KH_Test::contains('Recent activity', $kh_page, 'the recent activity card renders');
KH_Test::contains('Needs attention', $kh_page, 'and the needs-attention card');
KH_Test::contains(
    'All good. Nothing needs you right now.',
    $kh_page,
    'with a real empty state when nothing wants the owner'
);

KH_Test::group('Overview — Needs attention reports real problems');

/*
 * Abilities off is the quiet state: an owner who has not switched the plugin on
 * is not nagged about notification channels for an agent that cannot act.
 */
kh_test_reset_options(['keelhob_settings' => ['abilities_enabled' => false]]);
KH_Test::same([], Keelhob_Admin::needs_attention(), 'nothing needs attention while AI abilities are off');

// Abilities on, nothing else configured: the undeliverable-alert warning fires.
kh_test_reset_options(['keelhob_settings' => [
    'abilities_enabled' => true,
    'log_calls'         => true,
    'consent_version'   => Keelhob_Admin::CONSENT_VERSION,
]]);
$kh_flags = Keelhob_Admin::needs_attention();
KH_Test::ok($kh_flags !== [], 'with abilities on and no notification channel, something needs attention');
$kh_texts = implode(' ', array_column($kh_flags, 'text'));
KH_Test::contains('would not reach you', $kh_texts, 'and it says an alert would not reach the owner');
foreach ($kh_flags as $kh_flag) {
    KH_Test::ok(
        in_array($kh_flag['level'], ['bad', 'mid'], true),
        'every flag carries a level the stylesheet knows: ' . $kh_flag['level']
    );
    KH_Test::ok(trim($kh_flag['text']) !== '', 'and says what is wrong in words, not in a colour');
}

// A gated group being on is reported, with the right count.
kh_test_reset_options(['keelhob_settings' => [
    'abilities_enabled' => true,
    'log_calls'         => true,
    'capabilities'      => ['payments' => true, 'users' => true],
]]);
$kh_texts = implode(' ', array_column(Keelhob_Admin::needs_attention(), 'text'));
KH_Test::contains('2 areas that ship switched off are on', $kh_texts, 'two gated groups on is reported as two');

// Logging off is reported, because the Activity screen is then empty by design
// rather than by accident.
// log_calls has to be set to false explicitly: settings() merges the stored
// array over the defaults, and the default for log_calls is ON.
kh_test_reset_options(['keelhob_settings' => ['abilities_enabled' => true, 'log_calls' => false]]);
$kh_texts = implode(' ', array_column(Keelhob_Admin::needs_attention(), 'text'));
KH_Test::contains('logging is off', $kh_texts, 'tool-call logging being off is reported');

KH_Test::group('Overview — Recent activity lists what the agent did');

/*
 * Rows this time, so the populated branch is exercised and not only the empty
 * one. The stand-in logger is the same shape as Keelhob_Logger::recent().
 */
$kh_ov_kh->logger = new KH_Ov_Bag([
    ['created_at' => '2026-08-08 10:00:00', 'tool' => 'create_post', 'ok' => 1, 'args_json' => '{}', 'summary' => ''],
    ['created_at' => '2026-08-08 09:00:00', 'tool' => 'execute_php', 'ok' => 0, 'args_json' => '{}', 'summary' => 'boom'],
]);
kh_test_reset_options();
$kh_page2 = $kh_ov_render();

KH_Test::contains('class="kh-u-feed"', $kh_page2, 'the activity list renders when there is activity');
KH_Test::contains('>create_post<', $kh_page2, 'naming the tool that ran');
// The words, not the dot: a failed call that differed only by a ring colour
// would say nothing to a screen reader or a colour-blind reader.
KH_Test::contains('worked', $kh_page2, 'a call that worked says so in words');
KH_Test::contains('failed', $kh_page2, 'and one that failed says so in words');
KH_Test::ok(
    strpos($kh_page2, 'All good. Nothing needs you right now.') !== false
        || strpos($kh_page2, 'class="kh-u-flags"') !== false,
    'the needs-attention card always says something — either a flag or the empty state'
);
KH_Test::contains('See all activity', $kh_page2, 'with a link through to the full Activity screen');

// Leave the suite as it was found: a later file that renders a page must not
// inherit this file's capability array or its stand-in collaborators.
$kh_ov_kh->logger = new KH_Ov_Bag();
kh_test_reset_options();
