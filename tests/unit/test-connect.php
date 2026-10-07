<?php

/**
 * The Connect screen must hand each app something that app can actually read.
 *
 * The bug this replaces: one JSON block, labelled "Claude Desktop / Cursor /
 * Codex / other clients", in the shape {type, url, headers}. Claude Desktop
 * cannot use that at all — it has no HTTP transport. A real user followed that
 * screen, got nothing, and lost an evening to it.
 *
 * Naming an app and giving it a config it cannot read is worse than naming no
 * app, because the reader has no reason to doubt the screen. So every shape
 * here is pinned against what the vendor's own source says, and the cases that
 * silently produce a dead config are asserted one by one.
 */

declare(strict_types=1);

KH_Test::group('Connect — every app gets its own shape');

$EP    = 'https://example.com/wp-json/keelhob/v1/mcp';
$NAME  = 'keelhob-example';
$TOKEN = 'kh_abc123';

$clients = Keelhob_Connect::clients();
KH_Test::same(
    ['claude-code', 'claude-desktop', 'cursor', 'vscode'],
    array_keys($clients),
    'four apps are offered'
);

// Nothing may come back empty: an empty box on the screen reads as "nothing to
// do here", which is how the old bug felt to the person hitting it.
foreach (array_keys($clients) as $key) {
    KH_Test::ok(
        trim(Keelhob_Connect::config($key, $NAME, $EP, $TOKEN, false)) !== '',
        $key . ' produces something to copy'
    );
}

KH_Test::group('Connect — Claude Code');

$cc = Keelhob_Connect::config('claude-code', $NAME, $EP, $TOKEN, false);
KH_Test::contains('--transport http', $cc, 'uses the http transport');
KH_Test::contains('Authorization: Bearer ' . $TOKEN, $cc, 'carries the token as a header');
// Without -s user the server is saved against whatever folder the terminal was
// in. Start Claude elsewhere and the site is simply absent, with no error to
// explain it. That is a support question nobody can answer from the symptom.
KH_Test::contains('-s user', $cc, 'saves it for every folder, not just the current one');

KH_Test::group('Connect — Claude Desktop needs a bridge');

$cd = Keelhob_Connect::config('claude-desktop', $NAME, $EP, $TOKEN, false);
$j  = json_decode($cd, true);
KH_Test::ok(is_array($j), 'it is valid JSON');
KH_Test::ok(isset($j['mcpServers'][$NAME]), 'under mcpServers');

$entry = $j['mcpServers'][$NAME] ?? [];
// The whole point: Claude Desktop cannot take a url. If this ever becomes a
// {type,url,headers} block again, it is the original bug, back.
KH_Test::ok(!isset($entry['url']), 'it does NOT hand Claude Desktop a bare url — it cannot read one');
KH_Test::same('npx', $entry['command'] ?? null, 'it runs the bridge');
KH_Test::ok(in_array('mcp-remote', $entry['args'] ?? [], true), 'the bridge is mcp-remote');
KH_Test::ok(in_array($EP, $entry['args'] ?? [], true), 'pointed at this site');

// A token in args is readable by every other user on the machine, from the
// process list. mcp-remote expands ${VAR} from the environment, so it belongs
// in env.
KH_Test::same('Bearer ' . $TOKEN, $entry['env']['AUTH_HEADER'] ?? null, 'the token is in env');
KH_Test::ok(
    !in_array('Bearer ' . $TOKEN, $entry['args'] ?? [], true),
    'and never in args, where the process list would expose it'
);
KH_Test::ok(
    strpos($cd, '${AUTH_HEADER}') !== false,
    'args reference it by name instead'
);

KH_Test::group('Connect — --allow-http only when the site is plain http');

// On a local site the bridge refuses a plain-http address unless this flag is
// present. Leaving it out is a dead config for every LocalWP user.
$local = Keelhob_Connect::config('claude-desktop', $NAME, 'http://my-site.local/wp-json/keelhob/v1/mcp', $TOKEN, true);
KH_Test::ok(
    in_array('--allow-http', json_decode($local, true)['mcpServers'][$NAME]['args'] ?? [], true),
    'a local http site gets --allow-http'
);
// And the reverse: printing it for an https site is telling somebody to weaken
// a connection that was already fine.
KH_Test::ok(
    !in_array('--allow-http', $entry['args'] ?? [], true),
    'an https site does NOT get it'
);

KH_Test::group('Connect — Cursor and VS Code use different key names');

$cur = json_decode(Keelhob_Connect::config('cursor', $NAME, $EP, $TOKEN, false), true);
KH_Test::ok(isset($cur['mcpServers'][$NAME]), 'Cursor uses mcpServers');
KH_Test::same($EP, $cur['mcpServers'][$NAME]['url'] ?? null, 'with a url');
KH_Test::same('Bearer ' . $TOKEN, $cur['mcpServers'][$NAME]['headers']['Authorization'] ?? null, 'and a header');
// Cursor's documented remote example has no type key. Adding one is not
// obviously harmful, but shipping a shape nobody documents is how this screen
// got into trouble the first time.
KH_Test::ok(!isset($cur['mcpServers'][$NAME]['type']), 'and no type key, matching their documented example');

$vs = json_decode(Keelhob_Connect::config('vscode', $NAME, $EP, $TOKEN, false), true);
// VS Code's schema (mcpConfiguration.ts) calls the top-level key "servers".
// Handing it "mcpServers" in .vscode/mcp.json is the same class of mistake as
// the original bug: right-looking, silently wrong.
KH_Test::ok(isset($vs['servers'][$NAME]), 'VS Code uses servers, NOT mcpServers');
KH_Test::ok(!isset($vs['mcpServers']), 'and definitely not both');
KH_Test::same('http', $vs['servers'][$NAME]['type'] ?? null, 'type is http');
KH_Test::same($EP, $vs['servers'][$NAME]['url'] ?? null, 'url is required by their schema');
KH_Test::same('Bearer ' . $TOKEN, $vs['servers'][$NAME]['headers']['Authorization'] ?? null, 'headers is a string map');

KH_Test::group('Connect — the two shapes are genuinely different');

// If these ever became equal, one of the two apps is being handed the other
// one's config and nothing else in this file would notice.
KH_Test::ok(
    Keelhob_Connect::config('cursor', $NAME, $EP, $TOKEN, false)
        !== Keelhob_Connect::config('vscode', $NAME, $EP, $TOKEN, false),
    'Cursor and VS Code do not get identical JSON'
);

KH_Test::group('Connect — requirements are stated, not assumed');

// The missing sentence that cost a whole evening.
KH_Test::contains(
    'Node.js',
    Keelhob_Connect::requirement('claude-desktop', false),
    'Claude Desktop says it needs Node.js'
);
KH_Test::ok(
    strpos(Keelhob_Connect::requirement('cursor', false), 'Node.js') === false,
    'and apps that do not need Node are not told they do'
);
KH_Test::ok(
    strpos(Keelhob_Connect::requirement('claude-code', false), 'desktop app') !== false,
    'Claude Code explains the terminal and the desktop app share one file'
);

KH_Test::group('Connect — a local site warns about itself');

KH_Test::same([], Keelhob_Connect::local_site_notes(false, 'example.com'), 'a real https site gets no local warnings');
KH_Test::ok(count(Keelhob_Connect::local_site_notes(true, 'my-site.local')) >= 2, 'a local site does');
KH_Test::contains(
    'SSL',
    implode(' ', Keelhob_Connect::local_site_notes(true, 'my-site.local')),
    'and the first thing it says is to leave SSL off'
);

foreach (['localhost', '127.0.0.1', 'my-site.local', 'shop.test', 'x.ddev.site'] as $h) {
    KH_Test::ok(Keelhob_Connect::looks_local($h), $h . ' is recognised as a site on your own computer');
}
foreach (['example.com', 'keelhob.com', 'localhost.example.com', 'notlocal.org'] as $h) {
    KH_Test::ok(!Keelhob_Connect::looks_local($h), $h . ' is not');
}

KH_Test::group('Connect — the screen and the manual name the same apps');

// Drift is the failure here. The manual said "Claude Desktop, Cursor or Codex:
// the same page gives you a JSON block", which was the same wrong claim in a
// second place. Both must list what is really supported.
$manual = (string) file_get_contents(KEELHOB_DIR . 'DOCUMENTATION.md');
foreach ($clients as $key => $c) {
    KH_Test::contains($c['label'], $manual, 'the manual covers ' . $c['label']);
}
KH_Test::contains('Node.js', $manual, 'and the manual states the Node.js requirement too');
