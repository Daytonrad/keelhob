# The live rig

## Last full verification

Every layer here was run end to end on **2026-09-23**, against a freshly built
throwaway rig on **Keelhob 2.0.0 / WordPress 7.1 / PHP 8.5.9**, Windows.

| Layer | Command | Result |
|---|---|---|
| Unit | `php tests/run.php` | **2,623 / 2,623** passed |
| Browser — snippets | `node tests/rig/ui/check-snippets.mjs` | **54 / 54** passed |
| Browser — compose | `node tests/rig/ui/check-compose.mjs` | **26 / 26** passed |
| End-to-end MCP | `node tests/rig/e2e-mcp.mjs` | **30 / 30** passed |

That run is what corrected ROADMAP.md, which had claimed 57 browser checks
against a real 80. `tests/unit/test-doc-counts.php` now fails if ROADMAP's total
stops matching the per-file table in `tests/rig/ui/README.md`, so the figure
cannot drift again without the suite saying so.

Those four numbers are a **dated measurement**, not a claim about today, which
is why they are exact here while every other doc says "~2,600 assertions". Do
not turn this table into an approximation, and do not edit it without re-running
the four commands — replace it with the date and figures you actually got.

**If you are re-verifying, read "Serving it" first.** Two environment traps below
each produce a large number of failures that have nothing to do with the plugin,
and both cost a full investigation the first time.


`tests/run.php` runs against hand-written WordPress stubs. That is fast and it
catches most things, but it cannot catch a fix that is correct in isolation and
wrong in situ — a query that only fails against real `$wpdb`, an option that
autoloads when you thought it didn't, a table that never gets created because
its `install_table()` is called from the wrong hook.

So there is a second layer: a throwaway WordPress on SQLite, with the plugin
junctioned in from this repo, and a script that exercises the fixes against it.

No Docker and no MySQL required. It runs on this machine.

## Build it

```bash
bash tests/rig/build-rig.sh /c/tmp/wprig
bash tests/rig/run-all.sh  /c/tmp/wprig/wordpress
```

`build-rig.sh` is this section, automated, so the rig you build by hand and the
rig CI builds are the same rig. WordPress and the SQLite integration are pinned
inside it: with `latest`, a WordPress release could turn CI red on a morning
nobody touched the plugin. Everything below is what it does, and why.

## Building it by hand

Needs: PHP with `pdo_sqlite`, `sodium` and `openssl` (`php -m` to check),
WP-CLI, and `curl`/`unzip`.

```bash
RIG=/c/tmp/wprig                     # anywhere outside the repo
mkdir -p "$RIG" && cd "$RIG"

curl -sSL -o wp.zip https://wordpress.org/latest.zip && unzip -q wp.zip
curl -sSL -o sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
unzip -q sqlite.zip -d wordpress/wp-content/plugins/
cp wordpress/wp-content/plugins/sqlite-database-integration/db.copy wordpress/wp-content/db.php
```

Then edit `wordpress/wp-content/db.php` and replace the two placeholders:
`{SQLITE_IMPLEMENTATION_FOLDER_PATH}` with the absolute path of
`wp-content/plugins/sqlite-database-integration`, and `{SQLITE_PLUGIN}` with
`sqlite-database-integration/load.php`.

As of the integration plugin 3.0.1 the folder placeholder self-heals - `db.copy`
falls back to `realpath(__DIR__ . "/plugins/sqlite-database-integration")` when
the path does not exist. `{SQLITE_PLUGIN}` does not, so replace both.

Write `wordpress/wp-config.php` as normal, but with **real salts** — the
encryption in `Keelhob_Secrets` derives its key from `AUTH_KEY`,
`SECURE_AUTH_KEY` and `AUTH_SALT`, so a config with placeholder salts will
appear to work and hide a bug. Set `WP_HOME`/`WP_SITEURL` to
`http://localhost:8899`, and `FS_METHOD` to `direct`.

```bash
wp core install --url=http://localhost:8899 --title="Keelhob Rig" \
  --admin_user=admin --admin_password=rigpass --admin_email=rig@example.test --skip-email
```

Link the plugin in rather than copying it, so edits are picked up immediately.
On Windows, from PowerShell:

```powershell
New-Item -ItemType Junction `
  -Path "<RIG>\wordpress\wp-content\plugins\keelhob" `
  -Target "<repo>\Keelhob"
```

On macOS/Linux a symlink does the same job.

CI copies the plugin instead. PHP resolves symlinks in `__FILE__`, so
`plugin_dir_path(__FILE__)` - and therefore `KEELHOB_DIR` - points at the
checkout, outside `wp-content/plugins`; only `plugin_basename()` and
`plugins_url()` are symlink-aware, through the `$wp_plugin_paths` map WordPress
keeps for this case. A copy puts every path where a real install puts it. On a
development machine the live-edit trade is worth it, and `KH_RIG_LINK=1 bash
tests/rig/build-rig.sh` links instead.

**`KH_RIG_LINK=1` does not work in Git Bash on Windows out of the box.** Without
`MSYS=winsymlinks:nativestrict` and Developer Mode enabled, `ln -s` recursively
*copies* the target and exits 0 — no warning, no error. The rig then builds,
loads, and passes every check while holding a frozen copy that never sees
another edit. `build-rig.sh` now checks the link is really a link, deletes the
copy and stops if it is not. If you hit that, use the default copy and
`sync-plugin.sh` below; it is what this machine does.

```bash
wp plugin activate keelhob
```

## Run the checks

All of them:

```bash
bash tests/rig/run-all.sh /path/to/rig/wordpress
# or: export KH_RIG=/path/to/rig/wordpress && bash tests/rig/run-all.sh
```

Three files. There used to be four, run in a set order because one plugin had
to be toggled on and off between them; there is one plugin now, so the order no
longer matters and checks-pro.php folded into checks.php.

```bash
wp eval-file <repo>/Keelhob/tests/rig/checks.php          # core, incl. every formerly-paid tool
wp eval-file <repo>/Keelhob/tests/rig/checks-studio.php   # the Studio screens
wp eval-file <repo>/Keelhob/tests/rig/checks-compose.php  # the AI Compose flow
```

Exits non-zero if anything fails. The `Live rig` job in
`.github/workflows/ci.yml` builds a rig from scratch and runs exactly this on
every push - one PHP version, because this layer tests the plugin in situ and
not its version compatibility, which `lint` and `test` already cover.

It covers the things stubs cannot honestly answer:

- **S1** — the path traversal, attempted through the real tool, and confirmed by
  looking for the file on disk afterwards. Also that a legitimate nested write
  still works, because a guard that blocks everything is not a fix.
- **S2** — that the API key is genuinely absent from the stored option, that it
  still reads back as plaintext for callers, and that the option really has
  `autoload` off in the database.
- **S3** — that a gateway secret passed to `wc_payments` does not appear in the
  log table.
- **S5** — that the bearer token is stored only as a hash.
- **Tiering** — which tools the registry actually hides on a free install, and
  that `execute_php`, `write_file`, `snippet_save` and `site_readiness` are not
  among them.
- **The safety loop** — that a snippet which does not parse cannot go active.

## Serving it

```bash
cd <RIG>/wordpress && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899
```

The extra workers matter: WordPress makes loopback requests to itself, and a
single-worker built-in server deadlocks waiting for a response it is not free to
serve.

**Bind to `127.0.0.1`, never `localhost`.** This cost a whole session. On
Windows `localhost` resolves to the IPv6 loopback `::1` first, so
`php -S localhost:8899` binds `::1` ONLY. The server starts, prints its banner
and logs nothing wrong — but `curl http://127.0.0.1:8899/` is refused outright,
because nothing is listening on IPv4. The server looked dead from the outside
while serving perfectly on the inside, and `server.log` showed healthy `[200]`
responses from `[::1]` the entire time. `127.0.0.1:8899` binds IPv4, and
`localhost` then still works in a browser, so there is no reason to use the
name.

**On Windows `PHP_CLI_SERVER_WORKERS` does nothing.** The server prints
`forking is not supported on this platform` and serves one request at a time,
so a browser run stalls on the login POST and every check times out. The fix is
to start the SAME command two or three more times — the built-in server allows
several instances to share a port, and that is what gives you concurrency:

```bash
# Windows: three instances, not one. Each is a separate process.
for i in 1 2 3; do
  powershell -Command "Start-Process C:\PHP\php.exe -ArgumentList '-S','127.0.0.1:8899','-t','<RIG>/wordpress' -WindowStyle Hidden"
done
```

If you find several `php -S` on the same port, **they are not orphans.** Killing
them down to one is how you turn a green suite red. (That was done once, and the
run it broke reported 36 of 50 failures that were all the same timeout.)

## Pushing a code change into a rig you are keeping

```bash
bash tests/rig/sync-plugin.sh <RIG>/wordpress
```

`build-rig.sh` deletes and recreates the whole WordPress install, so every
snippet, page and setting the rig is carrying goes with it. That is correct for
CI and wrong for a rig kept across sessions to test fixes against.

`sync-plugin.sh` copies only the plugin — exactly the file list
`bin/build-zip.sh` ships, no more — and leaves the site around it alone. It
parse-checks every PHP file *before* copying anything, because a syntax error
copied into a running rig takes down the admin screen you would use to find it.
No reactivation is needed; reload the page.

## Throwing it away

Delete the rig directory. Nothing in it is precious, and rebuilding takes a
couple of minutes. Never point the rig at a database you care about.

## One rig quirk that will waste your time

Do not trust `sqlite_master.sql` or `PRAGMA table_info` to tell you whether a
column exists. The SQLite integration plugin translates MySQL DDL, and the
stored schema text does not always list columns that genuinely are there:
`dbDelta` adds them, values write and read back correctly, and the introspection
still says they are missing.

Check behaviour instead — write a row, read it back. If that works, the column
exists, whatever the DDL claims.

This is a property of the rig, not of the plugin. On MySQL, `dbDelta` behaves
the way it is documented to.

## Testing the AI paths without a key or any spend

Copy `ai-mock.php` into the rig:

```bash
cp <repo>/Keelhob/tests/rig/ai-mock.php <RIG>/wordpress/wp-content/mu-plugins/
```

It hooks `pre_http_request`, matches the three provider hosts, and replays
canned responses in each vendor's own shape. Then set any placeholder key:

```bash
wp eval 'Keelhob::instance()->update(["agent_provider"=>"anthropic","agent_api_key"=>"sk-ant-RIG-MOCK-NOT-A-REAL-KEY","agent_model"=>"mock-model-newest"]);'
```

What it replaces is only the network hop. Building the request, parsing the
response, linting the code that comes back, the whole agent loop, usage
accounting — all of that runs for real. That is the part worth testing, and it
is exactly the part a stub of the plugin's own classes would have skipped.

Verified working: `test_connection`, `fetch_models`, and a complete `run_agent`
loop returning a summary, step count and token usage.

Define `KEELHOB_MOCK_FAIL` to make every call fail, for testing error paths.

**It lives in `tests/rig/` and is copied in by hand on purpose.** It must never
be anywhere the build could sweep it into a zip.

## End-to-end, through the MCP endpoint

`tests/rig/e2e-mcp.mjs` is the only layer that talks to the plugin the way a
real client does: an HTTP POST of JSON-RPC to `/keelhob/v1/mcp`, carrying a
bearer token.

Everything on that path — the REST route, the auth middleware, the JSON-RPC
envelope, the `tools/list` payload, and the capability gate **as the endpoint
applies it** — is invisible to the other three layers. A bug in any of them is
total for the user (the plugin does not connect, or connects and refuses
everything) while every admin screen still looks perfect.

```bash
# 1. Switch abilities on and mint a token INTO A FILE.
#    Not into argv: on Linux /proc/<pid>/cmdline is world-readable.
php /c/wp-cli/wp-cli.phar --path=/c/wp-rig/wordpress eval '
  Keelhob::instance()->update(["abilities_enabled" => true]);
  echo json_encode([
    "token"    => Keelhob::set_token(),
    "endpoint" => rest_url("keelhob/v1/mcp"),
  ]);
' > /tmp/mcp.json

# 2. Run it.
node tests/rig/e2e-mcp.mjs --config /tmp/mcp.json
```

**`--rig` defaults to `C:/wp-rig/wordpress`, and getting it wrong is silent.**
The capability-gate section drives WP-CLI directly. Point `--rig` at a rig that
is not there and WP-CLI cannot be reached, so those checks collapse into a
single `capability gate: could not drive WP-CLI, skipped` **PASS**. The suite
then reports 28 of 28 green when the real total is 30, and nothing looks wrong.
Always pass `--rig` explicitly, and treat a total under 30 as a misconfigured
run rather than a smaller suite.

**Do not switch extra capability groups on first.** Everything it calls is on by
default. Turning them all on breaks three assertions in `checks.php`, which
exist to prove that deploy, email and updates are refused by default — six red
lines in a suite with nothing wrong with it.

It tears down everything it creates, including on failure, and including the one
capability it temporarily switches off.

---

## Upgrading an old release (`tests/rig/upgrade-check.sh`)

```bash
bash tests/rig/upgrade-check.sh v1.1.0 /c/wp-rig/wordpress
```

Every other suite tests ONE version of the plugin in isolation. This is the only
one that tests the thing that actually happens to real people: an existing site,
carrying data written by an OLDER schema, being handed new files by an automatic
update. A migration that drops a column, a read path that assumes a shape only
the new version writes, or an upgrade that quietly switches capabilities on —
none of that shows up on a fresh install, which is all a rig normally is.

**It is destructive on purpose.** Step 1 wipes every `keelhob_*` table and
option, because `maybe_upgrade()` returns early when `keelhob_db_version`
already matches: copying old files over a new database would mean the migration
under test never runs, and every assertion after it passes for the wrong reason.
The wipe is scoped to the prefix, and the run asserts at the end that the
neighbouring plugin's tables, the posts and the users are all still there.

Two details that are load-bearing:

- **Fixtures are seeded through the OLD version's own `save()`**, never by
  INSERTing rows. A hand-written fixture encodes what you BELIEVE the old shape
  was; the old code encodes what it was. A guess would be written from the same
  misunderstanding that caused the bug, and would hide it.
- **The new version arrives as the real release zip**, unpacked over the
  directory with no deactivate/reactivate — which is exactly what WordPress's
  updater does.

It tears its fixtures down in the same run that asserts on them, pass or fail,
and leaves the rig on the working tree.

**v1.1.0 → 1.2.0 result: 62 passed, 0 failed.** The assertions worth knowing
about:

| What | Why it matters |
|---|---|
| every snippet's `active` flag is UNCHANGED | an update that switches somebody's code ON is worse than one that loses it — they notice missing things |
| `conditions_json` is **not rewritten** | 1.2.0 lifts the old flat shape at READ time; rewriting the column would make a downgrade impossible |
| no capability group gained or lost | an automatic update must never hand a site payments, users, comms or deploy |
| the live snippet still RUNS | surviving as a database row is not the same as working |
| the old flat conditions lift to 4 rules with `only_singular` preserved | 1.1.0 wrapped post_type/post_id in `is_singular()`, so on an archive those rules did not APPLY rather than failing — dropping that flag silently changes what an upgraded site shows |

**Three of its own bugs, worth not repeating.** The first draft called
`Keelhob_Snippet_Conditions::decode()` (a plain JSON parser) where it meant
`normalize()` (the lifter), and reported `rules: 0` — which reads exactly like a
migration that had dropped somebody's conditions. The second searched the
rendered HTML for `front_page`, but `<div id="kh-cond-rules">` ships empty and
the builder draws its rows from `wp_localize_script` data. The third kept its
tally in `$GLOBALS` while `wp eval-file` evals the body inside a method, so the
counters stayed at zero, real FAIL lines printed, and the run exited 0 announcing
that it had passed. All three are guarded in comments at the point they bit.
