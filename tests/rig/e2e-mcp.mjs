/**
 * End-to-end, through the real MCP endpoint.
 *
 * WHY THIS LAYER EXISTS.
 *
 * `tests/run.php` runs against hand-written stubs. `tests/rig/checks*.php` run
 * inside WordPress but call PHP classes directly. `tests/rig/ui/` drives the
 * admin screens in a browser. Between them they cover a great deal — and none
 * of them has ever sent a single byte through the thing every user actually
 * connects to: an HTTP POST of JSON-RPC to `/claudpress/v1/mcp`, carrying a
 * bearer token.
 *
 * Everything on that path is therefore untested: the REST route registration,
 * the auth middleware, the capability gate as the ENDPOINT applies it, the
 * JSON-RPC envelope, the tools/list payload a client reads to decide what it
 * can do, and the error shape a client sees when something is refused.
 *
 * A bug in any of those is invisible to every other suite and total for the
 * user — the plugin simply does not connect, or connects and refuses
 * everything, and the admin screens all look perfect.
 *
 * Usage:
 *
 *   node tests/rig/e2e-mcp.mjs --endpoint <url> --token <cp_...>
 *
 * or point it at a JSON file holding both (so a token never reaches argv,
 * which on Linux is world-readable via /proc):
 *
 *   node tests/rig/e2e-mcp.mjs --config /path/to/mcp.json
 *
 * Every artifact it creates is torn down in the same run, including on failure.
 *
 * ---------------------------------------------------------------------------
 * IT RUNS ON THE SHIPPED DEFAULTS. DO NOT "PREPARE" THE RIG FOR IT.
 *
 * Every tool it calls — content, snippets, memory, execute_php — is in a group
 * that is on by default, so all this needs is a token and abilities switched
 * on. The first version of the setup script helpfully turned ON every
 * capability group first, and that quietly broke three assertions in
 * `tests/rig/checks.php`, which exist precisely to prove that deploy, email and
 * updates are refused by default. Six red lines in a suite that had nothing
 * wrong with it, caused by the test next door tidying up for itself.
 *
 * If a future check genuinely needs a group that ships off, switch it on and
 * restore it in the `finally` block — the way the capability-gate check does.
 */

import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/* ------------------------------------------------------------------ args */

function args(argv = process.argv.slice(2)) {
  const out = {};
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) continue;
    const eq = a.indexOf('=');
    if (eq > -1) out[a.slice(2, eq)] = a.slice(eq + 1);
    else if (argv[i + 1] && !argv[i + 1].startsWith('--')) out[a.slice(2)] = argv[++i];
    else out[a.slice(2)] = true;
  }
  return out;
}

const opt = args();
let ENDPOINT = opt.endpoint;
let TOKEN = opt.token;

if (opt.config) {
  // The file may have WP-CLI chatter above the JSON; take the last JSON line.
  const raw = readFileSync(opt.config, 'utf8')
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l.startsWith('{'))
    .pop();
  const cfg = JSON.parse(raw);
  ENDPOINT = ENDPOINT || cfg.endpoint;
  TOKEN = TOKEN || cfg.token;
}

if (!ENDPOINT || !TOKEN) {
  console.error('Need --endpoint and --token, or --config pointing at a JSON file with both.');
  process.exit(2);
}

// Only needed for the capability-gate checks, which drive WP-CLI directly
// because switching a capability off is an owner action with no tool.
const WP_CLI = opt['wp-cli'] || 'C:/wp-cli/wp-cli.phar';
const RIG_PATH = opt.rig || 'C:/wp-rig/wordpress';

/* ---------------------------------------------------------------- report */

const rows = [];
const started = Date.now();

function check(label, condition, detail = '') {
  rows.push({ pass: !!condition, label, detail });
  return !!condition;
}

function print() {
  const secs = ((Date.now() - started) / 1000).toFixed(1);
  console.log('');
  for (const r of rows) {
    console.log(`${r.pass ? 'PASS' : 'FAIL'} ${r.label}${r.detail ? `  — ${r.detail}` : ''}`);
  }
  const bad = rows.filter((r) => !r.pass).length;
  console.log('');
  console.log('--------------------------------------------------------------');
  console.log(
    bad === 0
      ? `All ${rows.length} end-to-end MCP checks passed in ${secs}s.`
      : `${bad} of ${rows.length} end-to-end MCP checks FAILED in ${secs}s.`
  );
  return bad === 0;
}

/* ------------------------------------------------------------ transport */

let rpcId = 0;

/** One JSON-RPC call. `auth` false sends no bearer, for the refusal checks. */
async function rpc(method, params = undefined, { auth = true, token = TOKEN } = {}) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' };
  if (auth) headers.Authorization = `Bearer ${token}`;

  const body = { jsonrpc: '2.0', id: ++rpcId, method };
  if (params !== undefined) body.params = params;

  const res = await fetch(ENDPOINT, { method: 'POST', headers, body: JSON.stringify(body) });
  const text = await res.text();

  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    // Some MCP servers answer as SSE. Pull the first data: line out of it.
    const line = text.split('\n').find((l) => l.startsWith('data:'));
    if (line) {
      try {
        json = JSON.parse(line.slice(5).trim());
      } catch {
        /* leave json null; the caller reports the raw shape */
      }
    }
  }
  return { status: res.status, json, text };
}

/** Call a tool and return its parsed payload, or null. */
async function tool(name, argv = {}) {
  const r = await rpc('tools/call', { name, arguments: argv });
  const content = r.json?.result?.content;
  const first = Array.isArray(content) ? content[0] : null;
  let data = null;
  if (first && typeof first.text === 'string') {
    try {
      data = JSON.parse(first.text);
    } catch {
      data = first.text;
    }
  }
  return { ...r, data, isError: !!r.json?.result?.isError, rpcError: r.json?.error || null };
}

/* ------------------------------------------------------------ the checks */

const made = { posts: [], snippets: [], memories: [] };

// Set while the capability-gate checks have raw code execution switched off,
// so the teardown can put it back even if something throws in between.
let restoreCodeExec = false;

async function run() {
  /* ---- 1. the door itself ------------------------------------------- */

  const noAuth = await rpc('tools/list', {}, { auth: false });
  check(
    'an unauthenticated call is refused',
    noAuth.status === 401 || noAuth.json?.error,
    `HTTP ${noAuth.status}`
  );

  const badToken = await rpc('tools/list', {}, { token: 'cp_definitely_not_a_real_token_000000' });
  check(
    'a wrong bearer token is refused',
    badToken.status === 401 || badToken.json?.error,
    `HTTP ${badToken.status}`
  );
  // Nothing about the real token may leak through the refusal.
  check(
    'and the refusal does not leak the real token',
    !badToken.text.includes(TOKEN),
    'response body checked'
  );

  const init = await rpc('initialize', {
    protocolVersion: '2025-06-18',
    capabilities: {},
    clientInfo: { name: 'claudpress-e2e', version: '1.0.0' },
  });
  check('initialize succeeds', init.status === 200 && !!init.json?.result, `HTTP ${init.status}`);
  check(
    'initialize names the server',
    !!init.json?.result?.serverInfo?.name,
    init.json?.result?.serverInfo?.name || 'no serverInfo'
  );
  check(
    'and returns a protocol version',
    !!init.json?.result?.protocolVersion,
    init.json?.result?.protocolVersion || 'none'
  );

  /* ---- 2. what a client is told it can do ----------------------------- */

  const list = await rpc('tools/list');
  const tools = list.json?.result?.tools || [];
  check('tools/list returns tools', tools.length > 0, `${tools.length} tools`);
  check(
    'every tool has a name, a description and a schema',
    tools.every((t) => t.name && t.description && t.inputSchema),
    tools.filter((t) => !t.name || !t.description || !t.inputSchema).map((t) => t.name).join(', ') || 'all complete'
  );
  check(
    'no duplicate tool names',
    new Set(tools.map((t) => t.name)).size === tools.length
  );

  const names = new Set(tools.map((t) => t.name));
  for (const required of ['get_site_info', 'create_post', 'snippet_save', 'execute_php', 'memory_save']) {
    check(`tools/list offers ${required}`, names.has(required));
  }

  /* ---- 3. read-only, then writes ------------------------------------- */

  const info = await tool('get_site_info');
  check('get_site_info answers', !info.isError && info.data?.success === true, info.isError ? 'error' : 'ok');
  // Named keys, not a substring of the whole blob: an assertion that passes
  // because the word appears SOMEWHERE in the JSON is not an assertion.
  const wpVersion = info.data?.wordpress_version ?? info.data?.wp_version ?? info.data?.wordpress?.version;
  check('and reports a WordPress version', typeof wpVersion === 'string' && /^\d/.test(wpVersion), String(wpVersion));

  const post = await tool('create_post', {
    title: 'ZZ e2e probe post',
    content: 'Written by tests/rig/e2e-mcp.mjs. Safe to delete.',
    status: 'draft',
    post_type: 'post',
  });
  const postId = Number(post.data?.id || post.data?.post_id || 0);
  if (postId) made.posts.push(postId);
  check('create_post creates a post', postId > 0, postId ? `id ${postId}` : JSON.stringify(post.data).slice(0, 120));

  // get_post nests the record under `post`. Asserted on the exact path rather
  // than on a substring of the serialised response, so a change in shape fails
  // here instead of passing because the title happens to appear somewhere.
  const got = await tool('get_post', { id: postId });
  check(
    'get_post reads it back',
    got.data?.post?.title === 'ZZ e2e probe post',
    String(got.data?.post?.title ?? '(no post.title)').slice(0, 60)
  );
  check('and reports the status it was created with', got.data?.post?.status === 'draft', String(got.data?.post?.status));

  /* ---- 4. the snippet engine, through the wire ------------------------ */

  const snip = await tool('snippet_save', {
    name: 'ZZ e2e probe snippet',
    snippet_type: 'php',
    code: 'add_action("init", function () { return true; });',
    location: 'everywhere',
    active: false,
  });
  const snipId = Number(snip.data?.id || 0);
  if (snipId) made.snippets.push(snipId);
  check('snippet_save creates a snippet', snipId > 0, snipId ? `id ${snipId}` : JSON.stringify(snip.data).slice(0, 120));

  // The parse gate, as the endpoint enforces it. This is the single most
  // important safety property in the whole plugin.
  const broken = await tool('snippet_save', {
    name: 'ZZ e2e broken snippet',
    snippet_type: 'php',
    code: 'function (((( {',
    active: true,
  });
  const brokenId = Number(broken.data?.id || 0);
  if (brokenId) made.snippets.push(brokenId);
  check(
    'code that does not parse cannot be activated through MCP',
    broken.isError || !broken.data?.active,
    broken.isError ? 'refused outright' : `active=${JSON.stringify(broken.data?.active)}`
  );

  // Conditions, in the shape 1.2.0 writes.
  const cond = await tool('snippet_save', {
    id: snipId,
    conditions: {
      enabled: true,
      relation: 'AND',
      rules: [{ type: 'user_status', operator: 'is', value: 'logged_out' }],
    },
  });
  check('snippet_save accepts the 1.2.0 conditions shape', !cond.isError, cond.isError ? 'refused' : 'ok');

  // And the pre-1.2.0 shape an older agent would still send.
  const legacy = await tool('snippet_save', {
    id: snipId,
    conditions: { logged_in: true, post_types: ['post'] },
  });
  check('...and still accepts the pre-1.2.0 shape', !legacy.isError, legacy.isError ? 'refused' : 'ok');

  /* ---- 5. memory ------------------------------------------------------ */

  const mem = await tool('memory_save', {
    key: 'zz_e2e_probe',
    value: 'written by the end-to-end check',
  });
  check('memory_save writes', !mem.isError, mem.isError ? 'error' : 'ok');
  made.memories.push('zz_e2e_probe');

  const memList = await tool('memory_list');
  check(
    'memory_list reads it back',
    JSON.stringify(memList.data).includes('zz_e2e_probe'),
    'found in list'
  );

  /* ---- 6. refusals are refusals -------------------------------------- */

  const nonsense = await tool('this_tool_does_not_exist', {});
  check(
    'an unknown tool is refused, not silently ignored',
    nonsense.isError || !!nonsense.rpcError,
    nonsense.rpcError ? `rpc error ${nonsense.rpcError.code}` : 'isError'
  );

  const badArgs = await tool('get_post', { id: 999999999 });
  check(
    'a missing post is an error, not an empty success',
    badArgs.isError || badArgs.data === null || /not found|no post/i.test(JSON.stringify(badArgs.data)),
    JSON.stringify(badArgs.data).slice(0, 80)
  );

  /* ---- 7. the capability gate, as the ENDPOINT applies it ------------- */

  /*
   * The safety fence the whole product rests on, tested from OUTSIDE.
   *
   * Unit tests prove Claudpress_Capabilities::tool_allowed() returns false.
   * That is not the same claim. The claim that matters is that an agent
   * holding a valid token, talking to the real endpoint, is neither offered
   * the tool nor able to call it by name anyway — and only an HTTP request
   * can establish that.
   *
   * Driven through WP-CLI rather than a tool call, because switching a
   * capability off is an owner action and deliberately has no tool.
   */
  if (opt.wpcli !== false) {
    const off = setGroup('code_exec', false);
    restoreCodeExec = off === true;
    if (off === null) {
      check('capability gate: could not drive WP-CLI, skipped', true, 'set --wpcli=false to silence');
    } else {
      const gated = await rpc('tools/list');
      const gatedNames = new Set((gated.json?.result?.tools || []).map((t) => t.name));
      check(
        'switching a capability group OFF removes its tools from tools/list',
        !gatedNames.has('execute_php'),
        `execute_php ${gatedNames.has('execute_php') ? 'STILL OFFERED' : 'withdrawn'} (${gatedNames.size} tools)`
      );

      // And calling it anyway, by name, must still be refused.
      const sneak = await tool('execute_php', { code: 'return 1;' });
      check(
        '...and calling it by name anyway is refused',
        sneak.isError || !!sneak.rpcError || sneak.data?.success === false,
        JSON.stringify(sneak.data ?? sneak.rpcError).slice(0, 100)
      );

      /*
       * From here until the restore, the rig has raw code execution switched
       * OFF. `restoreCodeExec` tells the teardown to put it back even if
       * something throws in between — which is exactly what happened when this
       * line still said 'code' and setGroup() threw on the unknown group: the
       * restore never ran, and every later probe reported "capability is
       * switched OFF" as though the plugin were broken.
       */
      setGroup('code_exec', true);
      restoreCodeExec = false;

      const back = await rpc('tools/list');
      const backNames = new Set((back.json?.result?.tools || []).map((t) => t.name));
      check(
        'switching it back on restores them',
        backNames.has('execute_php'),
        `${backNames.size} tools`
      );
    }
  }

  return { tools: names };
}

/**
 * Switch one capability group on or off, on the rig, through WP-CLI.
 *
 * Returns null when WP-CLI is not reachable, so the checks above degrade to a
 * skip rather than a false failure on a machine without it.
 */
function setGroup(group, on) {
  /*
   * The group name is CHECKED against the plugin's own list before anything is
   * written.
   *
   * Without this, a typo wrote a key nothing reads, the tool stayed available,
   * and the check reported "execute_php STILL OFFERED" — which reads exactly
   * like a hole in the capability fence. It cost a real investigation. A test
   * that can fail for a reason other than the thing it is testing is worse than
   * no test.
   */
  const php = `<?php
$groups = Claudpress_Capabilities::groups();
if (!array_key_exists(${JSON.stringify(group)}, $groups)) {
    echo 'NOSUCHGROUP:' . implode(',', array_keys($groups));
    return;
}
$s = get_option('claudpress_settings', []);
$caps = isset($s['capabilities']) && is_array($s['capabilities']) ? $s['capabilities'] : [];
$caps[${JSON.stringify(group)}] = ${on ? 'true' : 'false'};
$s['capabilities'] = $caps;
update_option('claudpress_settings', $s);
echo 'ok';
`;
  const file = join(tmpdir(), `cp-cap-${Date.now()}.php`);
  try {
    writeFileSync(file, php);
    const r = spawnSync('php', [WP_CLI, `--path=${RIG_PATH}`, 'eval-file', file], { encoding: 'utf8' });
    unlinkSync(file);
    const out = r.stdout || '';
    if (out.includes('NOSUCHGROUP:')) {
      // Loud, not null: this is a broken test, not an absent environment.
      throw new Error(
        `capability group "${group}" does not exist. Real groups: ` +
          out.split('NOSUCHGROUP:')[1].split('\n')[0]
      );
    }
    return out.includes('ok') ? true : null;
  } catch (e) {
    if (String(e.message).includes('does not exist')) throw e;
    return null;
  }
}

let ok = false;
try {
  await run();
  ok = print();
} catch (e) {
  console.error('\nFAILED:', e.message);
  print();
} finally {
  // Teardown, in the same run that created them — including on failure.
  //
  // The capability first: leaving the rig with raw code execution disabled is
  // the most confusing possible parting gift, because nothing announces it and
  // every later probe then looks like a product failure.
  if (restoreCodeExec) {
    try {
      setGroup('code_exec', true);
      console.log('Teardown: restored the code_exec capability.');
    } catch (e) {
      console.error('Teardown: COULD NOT restore code_exec —', e.message);
    }
  }

  let cleaned = 0;
  for (const id of made.posts) {
    const r = await tool('delete_post', { id, force: true });
    if (!r.isError) cleaned++;
  }
  for (const id of made.snippets) {
    const r = await tool('snippet_delete', { id });
    if (!r.isError) cleaned++;
  }
  for (const key of made.memories) {
    const r = await tool('memory_delete', { key });
    if (!r.isError) cleaned++;
  }
  console.log(`\nTeardown: removed ${cleaned} of ${made.posts.length + made.snippets.length + made.memories.length} artifact(s).`);
  process.exit(ok ? 0 : 1);
}
