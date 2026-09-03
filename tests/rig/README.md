# The live rig

`tests/run.php` runs against hand-written WordPress stubs. That is fast and it
catches most things, but it cannot catch a fix that is correct in isolation and
wrong in situ — a query that only fails against real `$wpdb`, an option that
autoloads when you thought it didn't, a table that never gets created because
its `install_table()` is called from the wrong hook.

So there is a second layer: a throwaway WordPress on SQLite, with the plugin
junctioned in from this repo, and a script that exercises the fixes against it.

No Docker and no MySQL required. It runs on this machine.

## Build it

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

Write `wordpress/wp-config.php` as normal, but with **real salts** — the
encryption in `Claudpress_Secrets` derives its key from `AUTH_KEY`,
`SECURE_AUTH_KEY` and `AUTH_SALT`, so a config with placeholder salts will
appear to work and hide a bug. Set `WP_HOME`/`WP_SITEURL` to
`http://localhost:8899`, and `FS_METHOD` to `direct`.

```bash
wp core install --url=http://localhost:8899 --title="Claudpress Rig" \
  --admin_user=admin --admin_password=rigpass --admin_email=rig@example.test --skip-email
```

Link the plugin in rather than copying it, so edits are picked up immediately.
On Windows, from PowerShell:

```powershell
New-Item -ItemType Junction `
  -Path "<RIG>\wordpress\wp-content\plugins\claudpress" `
  -Target "<repo>\Claudpress"
```

On macOS/Linux a symlink does the same job.

```bash
wp plugin activate claudpress
```

## Run the checks

All of them:

```bash
bash tests/rig/run-all.sh /path/to/rig/wordpress
# or: export CP_RIG=/path/to/rig/wordpress && bash tests/rig/run-all.sh
```

Three files. There used to be four, run in a set order because one plugin had
to be toggled on and off between them; there is one plugin now, so the order no
longer matters and checks-pro.php folded into checks.php.

```bash
wp eval-file <repo>/Claudpress/tests/rig/checks.php          # core, incl. every formerly-paid tool
wp eval-file <repo>/Claudpress/tests/rig/checks-studio.php   # the Studio screens
wp eval-file <repo>/Claudpress/tests/rig/checks-compose.php  # the AI Compose flow
```

Exits non-zero if anything fails, so it can go in CI once there is CI.

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
cd <RIG>/wordpress && PHP_CLI_SERVER_WORKERS=4 php -S localhost:8899
```

The extra workers matter: WordPress makes loopback requests to itself, and a
single-worker built-in server deadlocks waiting for a response it is not free to
serve.

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
cp <repo>/Claudpress/tests/rig/ai-mock.php <RIG>/wordpress/wp-content/mu-plugins/
```

It hooks `pre_http_request`, matches the three provider hosts, and replays
canned responses in each vendor's own shape. Then set any placeholder key:

```bash
wp eval 'Claudpress::instance()->update(["agent_provider"=>"anthropic","agent_api_key"=>"sk-ant-RIG-MOCK-NOT-A-REAL-KEY","agent_model"=>"mock-model-newest"]);'
```

What it replaces is only the network hop. Building the request, parsing the
response, linting the code that comes back, the whole agent loop, usage
accounting — all of that runs for real. That is the part worth testing, and it
is exactly the part a stub of the plugin's own classes would have skipped.

Verified working: `test_connection`, `fetch_models`, and a complete `run_agent`
loop returning a summary, step count and token usage.

Define `CLAUDPRESS_MOCK_FAIL` to make every call fail, for testing error paths.

**It lives in `tests/rig/` and is copied in by hand on purpose.** It must never
be anywhere the build could sweep it into a zip.
