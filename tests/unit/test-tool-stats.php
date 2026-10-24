<?php
/**
 * The 1-100 rating: the maths, and the properties it has to keep.
 *
 * Keelhob_Tool_Stats::score() is pure on purpose — counters in, score out, no
 * database and no clock — precisely so this file can hold it to account. The
 * agent reads this number in the tool description and acts on it, so an
 * overconfident or wildly swinging score is not a cosmetic bug.
 */

KH_Test::group('Tool rating — a tool with no history');

$new = Keelhob_Tool_Stats::score([]);
KH_Test::same(null, $new['score'], 'no runs and no votes scores null, not 0 and not 100');
KH_Test::same('New', $new['label'], 'and reads "New"');
KH_Test::same('', Keelhob_Tool_Stats::description_line('zz_never_used'), 'so nothing is appended to its description');

KH_Test::group('Tool rating — one run does not make a tool perfect or broken');

$one_ok = Keelhob_Tool_Stats::score(['runs' => 1, 'ok_runs' => 1]);
KH_Test::same(81, $one_ok['score'], 'one success scores 81, not 100 (the 0.75 prior at weight 3)');
KH_Test::same('Good', $one_ok['label'], 'and reads "Good"');

$one_bad = Keelhob_Tool_Stats::score(['runs' => 1, 'ok_runs' => 0]);
KH_Test::same(56, $one_bad['score'], 'one failure scores 56, not 1');
KH_Test::same('Needs work', $one_bad['label'], 'and reads "Needs work"');

KH_Test::group('Tool rating — the owner\'s thumbs move the number');

// This is the exact behaviour the build is required to PROVE on the rig: one
// successful call, then a thumbs down, and the score has to visibly fall.
$before = Keelhob_Tool_Stats::score(['runs' => 1, 'ok_runs' => 1]);
$after  = Keelhob_Tool_Stats::score(['runs' => 1, 'ok_runs' => 1, 'thumbs_down' => 1]);
KH_Test::ok($after['score'] < $before['score'], 'a thumbs down lowers the score (' . $before['score'] . ' -> ' . $after['score'] . ')');
KH_Test::same(75, $after['score'], 'and by a stated amount, not an accident of rounding');

$up = Keelhob_Tool_Stats::score(['runs' => 1, 'ok_runs' => 1, 'thumbs_up' => 1]);
KH_Test::same($before['score'], $up['score'], 'a thumbs up on an already-successful call changes nothing — it agrees with the evidence');

$rescued = Keelhob_Tool_Stats::score(['runs' => 4, 'ok_runs' => 2, 'thumbs_up' => 3]);
$unrescued = Keelhob_Tool_Stats::score(['runs' => 4, 'ok_runs' => 2]);
KH_Test::ok($rescued['score'] > $unrescued['score'], 'thumbs up raises a half-failing tool (' . $unrescued['score'] . ' -> ' . $rescued['score'] . ')');

KH_Test::group('Tool rating — bounds and nonsense input');

KH_Test::same(100, Keelhob_Tool_Stats::score(['runs' => 500, 'ok_runs' => 500, 'thumbs_up' => 50])['score'], 'a long perfect record reaches 100');
$floor = Keelhob_Tool_Stats::score(['runs' => 500, 'ok_runs' => 0, 'thumbs_down' => 50]);
KH_Test::ok($floor['score'] >= 1, 'and a long disastrous one never goes below 1 (' . $floor['score'] . ')');
KH_Test::same('Poor', $floor['label'], 'which reads "Poor"');

$impossible = Keelhob_Tool_Stats::score(['runs' => 2, 'ok_runs' => 9]);
KH_Test::ok($impossible['score'] <= 100, 'more successes than runs cannot push the score past 100');
KH_Test::same(2, $impossible['ok_runs'], 'ok_runs is clamped to runs rather than trusted');

$negative = Keelhob_Tool_Stats::score(['runs' => -5, 'ok_runs' => -5, 'thumbs_up' => -2, 'thumbs_down' => -2]);
KH_Test::same(null, $negative['score'], 'negative counters read as no history rather than as a score');

KH_Test::group('Tool rating — a vote on a tool with no tallied run still counts');

$vote_only = Keelhob_Tool_Stats::score(['runs' => 0, 'ok_runs' => 0, 'thumbs_down' => 1]);
KH_Test::ok($vote_only['score'] !== null, 'the owner\'s verdict alone produces a score');
KH_Test::ok($vote_only['score'] < 75, 'and a thumbs down with no runs behind it is below "Good" (' . $vote_only['score'] . ')');

KH_Test::group('Tool rating — labels line up with the thresholds');

KH_Test::same('Excellent', Keelhob_Tool_Stats::label(90), '90 is Excellent');
KH_Test::same('Good', Keelhob_Tool_Stats::label(89), '89 is Good');
KH_Test::same('Good', Keelhob_Tool_Stats::label(75), '75 is Good');
KH_Test::same('Needs work', Keelhob_Tool_Stats::label(74), '74 is Needs work');
KH_Test::same('Needs work', Keelhob_Tool_Stats::label(50), '50 is Needs work');
KH_Test::same('Poor', Keelhob_Tool_Stats::label(49), '49 is Poor');
KH_Test::same('New', Keelhob_Tool_Stats::label(null), 'null is New');

KH_Test::group('Tool rating — old evidence fades, it is never erased in one step');

// The counters are halved at WINDOW rather than kept as fifty rows per tool.
// Without a decay, forty failures in March would hold a fixed tool down
// forever; with one that resets, a rating would mean nothing by Tuesday.
$below = ['runs' => Keelhob_Tool_Stats::WINDOW - 1, 'ok_runs' => 10, 'thumbs_up' => 4, 'thumbs_down' => 2];
KH_Test::same($below, Keelhob_Tool_Stats::decay($below), 'under the window, nothing is touched');

$at = Keelhob_Tool_Stats::decay(['runs' => Keelhob_Tool_Stats::WINDOW, 'ok_runs' => 20, 'thumbs_up' => 4, 'thumbs_down' => 3]);
KH_Test::same(25, $at['runs'], 'at the window the run count halves');
KH_Test::same(10, $at['ok_runs'], 'and so do the successes');
KH_Test::same(2, $at['thumbs_up'], 'and the thumbs up');
KH_Test::same(1, $at['thumbs_down'], 'and the thumbs down');

// The property that matters: halving must not change what the score SAYS.
$pre  = Keelhob_Tool_Stats::score(['runs' => 50, 'ok_runs' => 40, 'thumbs_up' => 6, 'thumbs_down' => 2]);
$post = Keelhob_Tool_Stats::score(Keelhob_Tool_Stats::decay(['runs' => 50, 'ok_runs' => 40, 'thumbs_up' => 6, 'thumbs_down' => 2]));
KH_Test::ok(abs($pre['score'] - $post['score']) <= 3, 'halving moves the score by at most 3 points (' . $pre['score'] . ' -> ' . $post['score'] . ')');

$decayed = Keelhob_Tool_Stats::decay(['runs' => 60, 'ok_runs' => 0, 'thumbs_up' => 0, 'thumbs_down' => 9]);
KH_Test::ok($decayed['ok_runs'] <= $decayed['runs'], 'decay can never invent a success it did not have');
KH_Test::ok($decayed['runs'] < 60, 'and it does reduce the weight of the past');

KH_Test::group('Tool rating — the line the model reads');

// description_line() goes through the database, which the fake $wpdb answers
// with nothing, so the untouched case is the one asserted here. The populated
// case is proved on the rig, over the real MCP endpoint.
KH_Test::same('', Keelhob_Tool_Stats::description_line('create_post'), 'a site with no history adds nothing to any description');

$base = 'Create a post.';
KH_Test::same($base, Keelhob_Tool_Registry::decorate_description('create_post', $base), 'and the decorated description is byte-for-byte the original');

KH_Test::group('Tool rating — the column is not called object_id');

// The CREATE TABLE itself, not the whole file: the prose above it names the
// trap deliberately, and a search over comments would pass for the wrong
// reason.
preg_match('/dbDelta\("(.*?)"\);/s', (string) file_get_contents(KEELHOB_DIR . 'includes/class-tool-stats.php'), $ddl);
$sql = (string) ($ddl[1] ?? '');
KH_Test::ok($sql !== '', 'the tool-stats CREATE TABLE statement was found');
KH_Test::ok(strpos($sql, 'object_id') === false, 'no column named object_id — $wpdb casts it to int and would store every tool name as 0');
KH_Test::contains('UNIQUE KEY tool (tool)', $sql, 'the tool name is the unique key, as a string');
