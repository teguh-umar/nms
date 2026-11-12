# HANDOFF → OpenCode · nms 1.0.0 Plan 1 (foundation and OpManager parity)

Owner: the Claude session for NMS Integration (`nms-integration-a5` in hub §6). It reviews everything you commit.
Written 2026-11-11. **Round 2 added 2026-11-12: start at §0. Round 1 (Tasks 1–9) is done and reviewed.**

## 0. Round 2: review fixes (do these, in order, one commit each)

The owner reviewed Tasks 1–9 at `2d4bfac` and fixed one blocker itself (`f3a2a9d`: the OpManager alarm lookup
missed cleared alarms; `Driver::alarmByKey(string $alarm_key, string $entity_key = '')` now returns an alarm in any
state). Base for round 2: `f3a2a9d` or later on `main-nms`. §2 and §3 below still apply. Every fix starts with a
failing test that would have caught it **on a real GLPI shape**, not only on a fake.

**R1. Outbound hooks (note/ack/clear to OpManager) are broken.** `src/Outbound/Hooks.php`:
- rows are enqueued in `new DbOutboxStore(0)`, but the cron sends only rows of its own `connections_id`, so they are
  never sent;
- the send builds a fake `Connection` named `opm.example.test` from global keys `opm_url` / `opm_api_key` /
  `webhook_token` that nms no longer stores;
- `Config::ticketingConfig()` reads global keys, so `service_user` is 0 and the plugin's own followups count as human
  (echo loop back to OpManager, D24–D26);
- the hook locks `opmanager_outbox`, the cron locks `nms_sync_<id>_outbox`: hook and cron can send one row twice.

Fix: `DbAlarmStore::activeForTicket()` also returns `connections_id`. The hooks group the ticket's active alarms by
`connections_id`; per group they load the `Connection` (`DbConnectionStore::get`), skip it when inactive or
`writeback_enabled = 0`, take `service_user` and `outbound_max_attempts` from that connection's `Settings`, plan with
`new DbOutboxStore($cid)`, enqueue there, and send under `DbSyncLock::lockName($cid) . '_outbox'` (the cron's name;
put it in one helper both use). `DriverFactory::for(Connection $c, ?int $timeout = null)` passes `Hooks::SEND_TIMEOUT`.
Remove `Hooks::OUTBOX_LOCK`. Tests: a followup on a ticket with alarms of connections 1 and 2 enqueues rows under 1 and
2; a followup by connection 1's `service_user` enqueues nothing; hook and cron use the same lock name; no
`opm.example.test` in `src/`.

**R2. Settings are still global in the UI and in four readers (Task 5 and 9 incomplete).** `src/SuiteConfig.php` still
shows and saves `opm_url`, `opm_api_key`, `opm_verify_ssl`, `webhook_token`, `match_mode`, every `ticket_*`,
`service_user`, `production_addressings`, `relink_window_hours`, `storm_*`, `outbound_max_attempts`, every `hygiene_*`
as global keys that nothing reads any more. Also global: `src/SelfCheck.php` (lines ~49–88), `front/coverage.php:26`,
`front/webhook.php:68` (`match_mode`), `src/Cron.php:117` (`match_mode`).
Fix: the connection form (`ConnectionForm`) edits every key of `Settings::DEFAULTS` for that connection (reuse the
field rendering now in `SuiteConfig`); the global Setup keeps only `asset_itemtypes`, `stale_warning_minutes`, the
connection list and the status strip; every reader takes the `Settings` of the connection it works for; SelfCheck checks
each active connection (secret stored encrypted, service user exists, production ranges exist). Test: grep test that
no `Config::get('<moved key>')` and no `Config::ticketingConfig()` remain outside `src/Migration/`; `Config::DEFAULTS`
holds no moved key (already tested) and no `opm_*` / `webhook_token` key.

**R3. Migration reads a table that does not exist.** `FromOpmanager::fetchConfigRow()` reads
`glpi_plugin_opmanager_config`. opmanager 1.3.2 stores its config in `glpi_configs`, context `plugin:opmanager`
(`\Config::getConfigurationValues('plugin:opmanager')`). On a real GLPI the request fails or returns nothing, so the
connection gets no URL, key, token or ticket settings. Fix it; the secrets there are GLPIKey-encrypted
(`opm_api_key`, `webhook_token`). Also the entity: spec §5.6b asks for the lowest common ancestor of the linked
assets' entities (root if none), not `min(entities_id)`. Tests: the fake DB serves `glpi_configs` rows
(`context`, `name`, `value`) exactly as GLPI stores them and has **no** `glpi_plugin_opmanager_config` table; URL, key,
token and three moved settings arrive in the connection; assets in entities 3 and 5 under parent 2 → entity 2.

**R4. Leftover opmanager identity (Task 1 incomplete; `identity_test` missed them).** In `src/`, `front/`, `tools/`:
every `/plugins/opmanager/` path (`ConnectionForm`, `Coverage/Page`, `Menu` manual page, `SuiteConfig` forms and links,
`front/config.php`, `front/connection.php`, `Config::WEBHOOK_PATH`, which Setup shows as the URL to give OpManager);
lock `SyncLock::NAME = 'opmanager_alarm_sync'` (shared with the live opmanager plugin) → `nms_alarm_sync`, and the
SelfCheck text; hook `opmanager_liveness` → `nms_liveness` (plan Task 1) incl. the Setup hint; CSS id
`opmanager-monitoring`; CSV name `opmanager-coverage-…`; `tools/selfcheck.php` path; `tools/release_check.py` install
dir; `__opmanager_selfcheck_…`. Keep: brand value `'opmanager'`, class/namespace `Driver\OpManager`, user-facing
"OpManager" where it means the product. Extend `identity_test` to fail on `/plugins/opmanager`, `opmanager_` and
`opmanager-` outside `src/Migration/`, `tests/`, `docs/`, `CHANGELOG.md`.

Stop after R4 and report as in §4.

**Before the first edit**, load these skills:
- `pgn-suite-handoff`, which loads `handoff-discipline`;
- `glpi-plugin-development`, for GLPI 11 API questions.

## 1. Where everything is

- **Plugin repo:** `PGN GLPI Suite/plugins/NMS Integration/`.
  - Branch **`main-nms`**. Its base is the commit that adds this handoff, on top of `9f217b2` (the opmanager 1.3.2 tree,
    history restarted).
  - Do not commit to, check out, merge or delete `main` or `nms-spec`. They hold the old unscrubbed history and stay
    until the user decides.
  - The repo has no remote. Never add one and never push.
- **Spec:** `docs/superpowers/specs/2026-11-05-nms-integration-design.md`, approved by the user on 2026-11-10.
- **Plan:** `docs/superpowers/plans/2026-11-10-nms-plan1-foundation.md`.
  - **You do Tasks 1–9, in order.**
  - Task 0 is done. Task 10 (runbook, version, CHANGELOG, zip, tag) is the owner's.
  - The plan's **Global Constraints** and **Review Focus** apply to every task. Each Review Focus item names the task
    and the test that pins it.
- **Reference spec for the hygiene feature (D34):** `docs/superpowers/specs/2026-11-06-opmanager-unmanaged-hygiene-design.md`,
  §8 lists every table, column, right and config key that Task 4, 5 and 8 must carry.
- **Code graph:** run `graphify query "<question>"` in this folder before grepping. The graph may still describe the old
  1.2.0 tree until the owner rebuilds it, so confirm with grep. Class names in strings are invisible to the graph.

## 2. Current state

- **Version:** the tree is opmanager 1.3.2 unchanged (`setup.php` still says opmanager). Task 1 renames it.
- **Test command** (local PHP has no php.ini):
  `php -d extension_dir="C:/Users/teguh.umar/Downloads/php/ext" -d extension=openssl -d extension=curl -d extension=mbstring tests/run_all.php`
  The last line must be `SUITES OK`. It is green at the base.
- **Test files load classes by hand.** A new test must `require` every class and interface it uses, in dependency order.
  A missing require is a fatal, not a FAIL line. When a task moves or renames a class (Task 1, Task 3), update every
  test's `require` and `use` lines.
- **The plan names some constants and keys from the 1.3.2 structure without reading every file** (for example
  `AssetSections::ORDER`, `Menu::ORDER`, `SuiteConfig::ORDER`, and the natural keys in Task 8 step 4). If the real code
  differs, follow the code's existing name or key and say so in your report. Do not invent a second mechanism.

## 3. Rules for every task

- **One commit per task.**
  - Work test-first: failing test, then code, then passing test, then commit.
  - Use the commit message the plan gives, with the trailer line `Co-Authored-By: <your model> <noreply@...>`.
  - Never `--amend`, `--no-verify` or push.
- **Checks before each commit:**
  - Run `php -l` on each changed PHP file.
  - Run the full suite, and report its real last line.
  - Grep for unimported global classes (`Foo::` without a `use` line or a leading `\`).
- **OpManager behaviour must not change.** Every existing 1.3.2 test keeps its assertions. You may change only
  namespaces, `require`/`use` lines, constructors (to pass a connection or driver), and identity strings renamed by
  Task 1. If an assertion itself has to change, stop and report instead.
- **opmanager stays untouched.** nms reads `glpi_plugin_opmanager_*` and `plugin:opmanager` only inside
  `src/Migration/FromOpmanager.php`, and never writes them.
- **Secrets.** No API key, password or webhook token in any output, log, exception message, rendered HTML or fixture.
  Fixtures use the literal fake values `key-xyz` and `token-xyz`, and the tests check that neither leaks.
- **No real data.**
  - Fixtures use invented names only: `opm.example.test`, `core-sw-01`, `vm-app-01`, `10.20.x.x`.
  - Never copy names, IPs, subnets or users from screenshots, the hub file, other plugins' docs or the VM.
- **Do not touch:** `graphify-out/`, `CLAUDE.local.md`, `.claude/`, `lib/pgnsuite/` (shared library; its SHAs are
  locked by `tests/lib_sha_test.php`).

## 4. When you stop

Stop after Task 9, or earlier if a rule above makes you stop. Report:
- each commit hash with its task number;
- the real last line of the suite after each task;
- every place where you followed the code instead of the plan's name, with the reason;
- anything left unverified.

The owner reviews, then does Task 10.
