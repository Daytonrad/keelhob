<?php
/**
 * The skill standard's rules, tested on planted failures.
 *
 * This file exists because of a specific mistake worth not repeating. The check
 * this replaces looked for one skill's name anywhere in another skill's body
 * with strpos(), which passed on "unlike web-design-master" — a sentence that
 * links nothing. It read like a connectedness check and was cover. The first
 * clash threshold written here had the same shape: 0.45, against a shipped set
 * whose most similar pair scores 0.194, so it could never fire.
 *
 * So every rule below is asserted against input built to break it. A rule that
 * is never shown failing has not been shown to work.
 */

CP_Test::group('Skill standard — the rules fire on real failures');

/** Enough of a skill to be judged, plus whatever the case under test needs. */
$make = static function (string $name, array $over = []): array {
    $body = "# $name\n\n## When to use\n\nSomething.\n\n## When NOT to use\n\nSomething else.\n\n"
        . "## Routing examples\n\n- fires: alpha bravo\n- fires: charlie delta\n- skips: echo foxtrot\n\n"
        . "## Related skills\n\n- next: other\n"
        . str_repeat("\nfiller line\n", 30);

    return array_merge([
        'name'        => $name,
        'title'       => ucfirst($name),
        'description' => str_repeat('A description long enough to clear the floor. ', 4),
        'body'        => $body,
        'role'        => 'procedure',
        'requires'    => '',
        'next_skills' => 'other',
        'verified_by' => '',
    ], $over);
};

// ---- reading the front matter ----------------------------------------------

CP_Test::same(
    ['alpha', 'bravo-two'],
    Claudpress_Skill_Standard::slugs(' Alpha , bravo two ,, alpha '),
    'a comma list becomes clean, de-duplicated slugs — the front matter has no concept of a list, so this is the only place one is made'
);

CP_Test::same('procedure', Claudpress_Skill_Standard::role(['role' => 'nonsense']), 'an unknown role falls back to procedure rather than inventing a fourth kind');
CP_Test::same('gate', Claudpress_Skill_Standard::role(['role' => ' GATE ']), 'roles are matched loosely on case and spacing');

$ex = Claudpress_Skill_Standard::routing_examples(
    "## Routing examples\n\n- fires: fix my seo\n* skips: write a post\n- fires: check my rankings\n\n## Next\n\n- fires: not mine\n"
);
CP_Test::same(['fix my seo', 'check my rankings'], $ex['fires'], 'routing examples are read from their own section only');
CP_Test::same(['write a post'], $ex['skips'], 'and skips are read separately from fires');

// A skill is allowed to show what a skill looks like. skill-creator does, and
// the first version of this linter read the placeholder phrases inside its
// example code fence as if they were skill-creator's own — then failed it for
// having routing examples that route nowhere. A code sample is not structure.
$with_fence = "# demo\n\n```\n## Routing examples\n\n- fires: placeholder text\n```\n\n"
    . "## Routing examples\n\n- fires: the real one\n- skips: not mine\n";
CP_Test::same(
    ['the real one'],
    Claudpress_Skill_Standard::routing_examples($with_fence)['fires'],
    'a routing block inside a code fence is documentation, not the skill\'s own fixture'
);

CP_Test::ok(
    !Claudpress_Skill_Standard::has_section("# demo\n\n```\n## When to use\n```\n", 'When to use'),
    'and a heading shown inside a fence does not count as having that section'
);

// ---- the tokenizer, which everything about routing rests on ----------------

CP_Test::ok(in_array('seo', Claudpress_Skill_Standard::tokens('fix my seo'), true), 'three-letter words survive — "seo", "css" and "gdpr" are exactly the words that tell two skills apart');
CP_Test::ok(!in_array('thi', Claudpress_Skill_Standard::tokens('make this page premium'), true), 'a stopword is dropped even after stemming mangles it — "this" once survived as "thi" and quietly made the wordiest skill win every match');
CP_Test::same(
    Claudpress_Skill_Standard::stem('coupons'),
    Claudpress_Skill_Standard::stem('coupon'),
    'plural and singular are the same word, or a user phrase never matches the skill written to serve it'
);
CP_Test::same('css', Claudpress_Skill_Standard::stem('css'), 'a double-s word is left alone rather than becoming "cs"');

// ---- one skill at a time ---------------------------------------------------

$known = ['alpha', 'bravo', 'other'];

$codes = static function (array $findings): array {
    return array_values(array_unique(array_column($findings, 'code')));
};

CP_Test::same([], $codes(Claudpress_Skill_Standard::check($make('alpha'), $known)), 'a well-formed skill produces no findings at all');

CP_Test::ok(
    in_array('description-short', $codes(Claudpress_Skill_Standard::check($make('alpha', ['description' => 'Too short.']), $known)), true),
    'a thin description is caught — it is the only text that routes a task to a skill'
);

// The parser matches one key per line. Everything after a newline is silently
// discarded, and the skill becomes unroutable with nothing anywhere saying so.
CP_Test::ok(
    in_array('description-wrapped', $codes(Claudpress_Skill_Standard::check($make('alpha', ['description' => "line one\nline two"]), $known)), true),
    'a wrapped description is caught, because the front-matter parser would drop half of it without complaint'
);

CP_Test::ok(
    in_array('section-missing', $codes(Claudpress_Skill_Standard::check($make('alpha', ['body' => "# alpha\n\nnothing else\n"]), $known)), true),
    'missing sections are caught'
);

CP_Test::ok(
    in_array('dead-end', $codes(Claudpress_Skill_Standard::check($make('alpha', ['next_skills' => '', 'verified_by' => '']), $known)), true),
    'a skill that names nothing to do next and nothing that verifies it is a dead end — an agent finishes it and stops'
);

CP_Test::ok(
    in_array('dangling-edge', $codes(Claudpress_Skill_Standard::check($make('alpha', ['next_skills' => 'ghost']), $known)), true),
    'an edge pointing at a skill that does not exist is caught'
);

CP_Test::ok(
    in_array('self-edge', $codes(Claudpress_Skill_Standard::check($make('alpha', ['next_skills' => 'alpha']), $known)), true),
    'a skill cannot satisfy the connectedness rule by pointing at itself'
);

CP_Test::ok(
    in_array('too-long', $codes(Claudpress_Skill_Standard::check($make('alpha', ['body' => str_repeat("line\n", 500)]), $known)), true),
    'a procedure over the line limit is caught'
);
CP_Test::ok(
    !in_array('too-long', $codes(Claudpress_Skill_Standard::check($make('alpha', ['role' => 'reference', 'body' => str_repeat("line\n", 500)]), $known)), true),
    'and a reference gets the longer allowance, which is why security-standard is legal at 555 lines rather than waived'
);

// ---- rules that need the whole set -----------------------------------------

CP_Test::group('Skill standard — the graph rules');

$graph_codes = static function (array $skills) use ($codes): array {
    return $codes(Claudpress_Skill_Standard::check_graph($skills));
};

// THE one that makes this whole design dangerous if it is missing. A verifies
// B, B verifies A: "am I done yet" never terminates and the agent hangs.
$loop = [
    'alpha' => $make('alpha', ['verified_by' => 'bravo', 'next_skills' => 'bravo']),
    'bravo' => $make('bravo', ['verified_by' => 'alpha', 'next_skills' => 'alpha']),
];
CP_Test::ok(in_array('cycle', $graph_codes($loop), true), 'a two-skill verifier loop is caught — without this the standard itself can hang an agent forever');

$long_loop = [
    'alpha' => $make('alpha', ['requires' => 'bravo', 'next_skills' => 'bravo']),
    'bravo' => $make('bravo', ['requires' => 'other', 'next_skills' => 'other']),
    'other' => $make('other', ['requires' => 'alpha', 'next_skills' => 'alpha']),
];
CP_Test::ok(in_array('cycle', $graph_codes($long_loop), true), 'and so is a three-skill loop, which no amount of reading the files would reveal');

$fine = [
    'alpha' => $make('alpha', ['next_skills' => 'bravo']),
    'bravo' => $make('bravo', ['next_skills' => 'other']),
    'other' => $make('other', ['next_skills' => 'alpha']),
];
CP_Test::ok(!in_array('cycle', $graph_codes($fine), true), 'a ring of next_skills is NOT a cycle — "consider this afterwards" is advice, and only requires and verified_by are obligations that could loop');

// Nobody points at it, so it is reached only if the owner's own wording happens
// to match its description.
$orphan = [
    'alpha' => $make('alpha', ['next_skills' => 'bravo']),
    'bravo' => $make('bravo', ['next_skills' => 'alpha']),
    'other' => $make('other', ['next_skills' => 'alpha']),
];
CP_Test::ok(in_array('orphan', $graph_codes($orphan), true), 'a skill nothing points at is caught — a skill nobody can reach does not exist');

$heavy = [
    'alpha' => $make('alpha', ['requires' => 'bravo', 'body' => str_repeat('x', 40000)]),
    'bravo' => $make('bravo', ['requires' => 'other', 'body' => str_repeat('x', 40000)]),
    'other' => $make('other', ['next_skills' => 'alpha', 'body' => str_repeat('x', 40000)]),
];
CP_Test::ok(in_array('too-heavy', $graph_codes($heavy), true), 'a requires-chain over the context budget is caught before an agent has to read it');

$clash = [
    'alpha' => $make('alpha', ['description' => 'Audit and fix the search engine optimisation of a whole website across every page and produce a report.', 'next_skills' => 'bravo']),
    'bravo' => $make('bravo', ['description' => 'Audit and fix the search engine optimisation of a whole website across every page and produce a report.', 'next_skills' => 'alpha']),
];
CP_Test::ok(in_array('clash', $graph_codes($clash), true), 'two skills with the same description are flagged as competing — this is how a skill pack rots as it grows, and it must be caught at eighteen rather than at sixty');

// ---- routing ---------------------------------------------------------------

$routing = [
    'coupon-maker' => $make('coupon-maker', [
        'description' => 'Create discount coupons and sales for the shop, set their rules, and schedule when they start and end.',
        'body' => "# coupon-maker\n\n## When to use\n\nDiscount coupons.\n\n## When NOT to use\n\nNo.\n\n"
            . "## Routing examples\n\n- fires: create a coupon for black friday\n- fires: schedule a discount sale\n- skips: harden the login page\n\n## Related skills\n\n- next\n",
        'next_skills' => 'login-guard',
    ]),
    'login-guard' => $make('login-guard', [
        'description' => 'Harden the login page against brute force attempts, add two factor, and lock out repeated failures.',
        'body' => "# login-guard\n\n## When to use\n\nLogin hardening.\n\n## When NOT to use\n\nNo.\n\n"
            . "## Routing examples\n\n- fires: harden the login page\n- fires: lock out brute force attempts\n- skips: create a coupon for black friday\n\n## Related skills\n\n- next\n",
        'next_skills' => 'coupon-maker',
    ]),
];
CP_Test::same([], $graph_codes($routing), 'two genuinely separate skills route their own phrases and reject each other\'s');

// Two distinct failures, told apart on purpose. The first time this was written
// both fixtures produced the same code, which would have left one of the two
// rules asserted by accident and the other not asserted at all.

// No overlap whatsoever: the description does not contain a single word its own
// users would type. Nothing routes here, ever.
$missed = [
    'coupon-maker' => $make('coupon-maker', [
        'description' => 'Create discount coupons and sales for the shop, set their rules, and schedule when they start and end.',
        'body' => "# coupon-maker\n\n## When to use\n\nDiscounts.\n\n## When NOT to use\n\nNo.\n\n"
            . "## Routing examples\n\n- fires: harden the login page\n- fires: install a firewall plugin\n- skips: nothing relevant\n\n## Related skills\n\n- next\n",
        'next_skills' => 'login-guard',
    ]),
    'login-guard' => $routing['login-guard'],
];
CP_Test::ok(
    in_array('routing-miss', $graph_codes($missed), true),
    'a phrase sharing no word with its own skill is an error — the description is the router, and this one routes nowhere'
);

// Partial overlap, but a rival matches better. This is the subtler failure: the
// skill looks plausible in isolation and still loses every time.
$lost = [
    'coupon-maker' => $make('coupon-maker', [
        'description' => 'Create discount coupons and sales for the shop, set their rules, and schedule when they start and end.',
        'body' => "# coupon-maker\n\n## When to use\n\nDiscounts.\n\n## When NOT to use\n\nNo.\n\n"
            . "## Routing examples\n\n- fires: schedule a lockout after repeated failures\n- fires: create a coupon\n- skips: nothing relevant\n\n## Related skills\n\n- next\n",
        'next_skills' => 'login-guard',
    ]),
    'login-guard' => $routing['login-guard'],
];
CP_Test::ok(
    in_array('routing-lost', $graph_codes($lost), true),
    'a phrase that matches a rival better is an error — this is the check that catches a good skill nobody can reach'
);

$overreaching = $routing;
$overreaching['coupon-maker']['body'] = str_replace('- skips: harden the login page', '- skips: schedule a discount sale', $overreaching['coupon-maker']['body']);
CP_Test::ok(
    in_array('routing-overreach', $graph_codes($overreaching), true),
    'and a skips phrase that this skill wins anyway is an error — the other half of routing is staying out of jobs that are not yours'
);

// ---- scoring ---------------------------------------------------------------

CP_Test::same(100, Claudpress_Skill_Standard::score([]), 'a clean skill scores 100');
CP_Test::same(88, Claudpress_Skill_Standard::score([['level' => 'error']]), 'one error costs more than one warning');
CP_Test::same(97, Claudpress_Skill_Standard::score([['level' => 'warn']]), 'a warning is a nudge, not a failure');
CP_Test::same(0, Claudpress_Skill_Standard::score(array_fill(0, 50, ['level' => 'error'])), 'the score floors at zero rather than going negative');
CP_Test::ok(Claudpress_Skill_Standard::has_errors([['level' => 'warn'], ['level' => 'error']]), 'has_errors sees an error among warnings');
CP_Test::ok(!Claudpress_Skill_Standard::has_errors([['level' => 'warn']]), 'and warnings alone never fail a build');
