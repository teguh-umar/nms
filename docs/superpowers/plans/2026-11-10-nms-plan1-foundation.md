# NMS Integration — Plan 1 of 4: foundation and OpManager parity

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. In this suite the executor is OpenCode via `HANDOFF-opencode.md` (hub §4 rule 10).

**Goal:** Turn the opmanager 1.3.2 code into plugin `nms` with connections and a driver layer, OpManager as its first driver, migration from opmanager, and full 1.3.2 parity for the PGN OpManager connection.

**Architecture:** The 1.3.2 tree is renamed to `nms`. A `connections` table holds one row per NMS instance. Every store, cron pass, webhook and setting is keyed by `connections_id`. Brand code sits behind `Driver\Driver`; the existing `src/OpManager/*` classes become `Driver\OpManager\*`. A one-shot, idempotent `Migration\FromOpmanager` copies opmanager's data into one disabled OpManager connection.

**Tech Stack:** PHP 8.2+, GLPI 11 plugin API, ext-curl (no Guzzle), the repo's own test runner (`tests/run_all.php`, `check()` from `tests/bootstrap.php`).

**Spec:** `docs/superpowers/specs/2026-11-05-nms-integration-design.md` (approved 2026-11-10).

**The four plans:**
1. **This plan:** foundation, the OpManager driver, migration, parity. At the end, `nms` can replace `opmanager` in production.
2. Approval queue, asset creation, entity scope per connection, entity isolation of every list (spec goals 7–9, §5.6b–c).
3. Subsidiary drivers: PRTG, Zabbix, LibreNMS, SolarWinds, built from vendor docs and fixtures for versions N-3..N (§5.3).
4. Cross-NMS behaviour: dedup on the GLPI asset behind the tickets switch (§5.6), hygiene "not monitored" mapping per brand, dev labels in coverage (§5.10, §5.11 item 7), connection/brand filters in the UI.

## Global Constraints

- Key/dir `nms`; PSR-4 `GlpiPlugin\Nms\` → `src/`; tables `glpi_plugin_nms_*`; rights `plugin_nms_*`; config context `plugin:nms`; display name `NMS Integration`.
- Search option IDs **8380–8389** only. AssetTab order **65**, Menu order **65**, PGN Suite config tab order **75**.
- Version `1.0.0-rc1` for the first build of this plan. CHANGELOG in Keep a Changelog format from the first rc (hub rule 8).
- ext-curl only; never add Guzzle or any composer runtime dependency.
- Secrets (API keys, webhook tokens) are stored with `GLPIKey` and listed in `secured_fields`. They never appear in output, logs, exceptions or fixtures.
- Fixtures and tests use invented data only: `opm.example.test`, `core-sw-01`, `vm-app-01`, `10.20.x.x`. No real names, IPs, subnets or hostnames.
- The nine production lessons in spec §5.11 apply unchanged (`_skip_rules`, `Ticket_User` ASSIGN after add, one assigned group, observers, `RightDefinition::label()`, lib SHA test).
- `opmanager` and `nms` run side by side: `nms` must never read a `plugin:opmanager` config key or write a `glpi_plugin_opmanager_*` table, except `Migration\FromOpmanager`, which only reads them.

## Review Focus

1. **Migration run twice:** a second run (Setup button or CLI) adds no duplicate device, alarm, ticket link, outbox row, exception or hygiene item, and updates rows changed since the first run (Task 8 test `migration_idempotent_test`).
2. **Migration without opmanager:** on a GLPI where `glpi_plugin_opmanager_*` does not exist, install succeeds, creates no connection, and Setup shows no copy button (Task 8 test `migration_absent_test`).
3. **One connection failing:** a driver that throws during DeviceSync or AlarmSync for connection 1 still lets connection 2 run, and records the error only on connection 1 (Task 6 test `cron_isolation_test`).
4. **Webhook with an unknown or disabled connection's token:** answers 403, writes nothing, logs one line without the token (Task 7 test `webhook_routing_test`).
5. **Disabled connection:** no cron pass, no webhook processing, no outbox send for it, even with pending outbox rows (Tasks 6 and 7).

---

### Task 0 (owner, not OpenCode): baseline and history restart

**Files:** whole tree.

- [ ] Create an orphan branch `main-nms` whose single initial commit is the opmanager tree at tag `1.3.2` (`67118af`), plus `docs/superpowers/specs/2026-11-05-nms-integration-design.md` and this plan. Do not carry the old history: it holds unscrubbed data (spec §5.12).
- [ ] Keep the old `main` and `nms-spec` branches locally until the user agrees to delete them. Never push either.
- [ ] Run the suite: last line must be `SUITES OK` (the opmanager 1.3.2 baseline).
- [ ] Commit `HANDOFF-opencode.md` on `main-nms` and tell the user it is ready for OpenCode.

### Task 1: Rename identity opmanager → nms

**Files:**
- Modify: `setup.php`, `composer.json`, `install/install.php`, `install/uninstall.php`, every `src/**/*.php`, every `front/*.php`, every `tests/*.php`.
- Create: `tests/identity_test.php`.

**Interfaces:**
- Produces: namespace `GlpiPlugin\Nms`; constants `Config::CONTEXT = 'plugin:nms'`, `Config::WEBHOOK_PATH = '/plugins/nms/front/webhook.php'`; `PLUGIN_NMS_VERSION = '1.0.0-rc1'`; functions `plugin_version_nms()`, `plugin_init_nms()`, `plugin_nms_install()`, `plugin_nms_uninstall()`; table prefix `glpi_plugin_nms_`; rights `plugin_nms_config`, `plugin_nms_monitoring`, `plugin_nms_hygiene`; hook name `nms_liveness` (was `opmanager_liveness`).

- [ ] **Step 1: Write `tests/identity_test.php`**

  It greps the tree (excluding `tests/`, `docs/`, `CHANGELOG.md`, `src/Migration/`) and asserts:
  - `check('no opmanager namespace', <count of "GlpiPlugin\\Opmanager"> === 0)`;
  - `check('no opmanager tables', <count of "glpi_plugin_opmanager_"> === 0)`;
  - `check('no opmanager rights', <count of "plugin_opmanager_"> === 0)`;
  - `check('context', Config::CONTEXT === 'plugin:nms')`;
  - `check('orders', AssetSections::ORDER === 65 && Menu::ORDER === 65 && SuiteConfig::ORDER === 75)`;
  - `check('search options in range', every id returned by the plugin's search-option provider is within 8380..8389)`.

  If `AssetSections`, `Menu` or `SuiteConfig` have no `ORDER` constant yet, add one holding today's literal and use it at the registration call.
- [ ] **Step 2:** Run `php tests/identity_test.php`. Expected: FAIL.
- [ ] **Step 3:** Rename everything listed under Interfaces. Set orders 65/65/75 and move every search option ID into 8380–8389, keeping their relative order. The display name becomes `NMS Integration`. The menu entry label becomes `NMS Integration`. Test fixtures keep their invented data.
- [ ] **Step 4:** Run `tests/run_all.php`. Expected: `SUITES OK`, `identity_test` included.
- [ ] **Step 5:** Commit `refactor: rename plugin identity opmanager -> nms (1.0.0-rc1 dev)`.

### Task 2: Connections table and store

**Files:**
- Create: `src/Connection/Connection.php`, `src/Connection/ConnectionStore.php` (interface), `src/Connection/DbConnectionStore.php`, `tests/connection_test.php`.
- Modify: `install/install.php` (table), `setup.php` (`secured_fields`).

**Interfaces:**
- Produces:
  - Table `glpi_plugin_nms_connections`: `id`, `name` VARCHAR(255), `brand` VARCHAR(32), `entities_id` INT UNSIGNED, `is_recursive` TINYINT DEFAULT 1, `url` VARCHAR(255), `api_key` TEXT (secured), `username` VARCHAR(255), `password` TEXT (secured), `verify_tls` TINYINT DEFAULT 1, `is_active` TINYINT DEFAULT 0, `writeback_enabled` TINYINT DEFAULT 0, `tickets_enabled` TINYINT DEFAULT 0, `webhook_token` TEXT (secured), `settings` LONGTEXT (JSON, Task 5), `last_device_sync`, `last_alarm_sync` TIMESTAMP NULL, `last_status` VARCHAR(16), `last_error` TEXT, `date_creation`, `date_mod`. UNIQUE (`name`).
  - `final class Connection` (readonly props matching the columns, secrets decrypted only in `secret(string $field): string`).
  - `interface ConnectionStore { public function active(): array; public function get(int $id): ?Connection; public function byWebhookToken(string $token): ?Connection; public function save(array $fields): int; public function recordStatus(int $id, string $kind, string $status, string $error, string $now): void; }` where `$kind` is `'device'` or `'alarm'`.
  - Brand constants `Connection::BRANDS = ['opmanager', 'prtg', 'zabbix', 'librenms', 'solarwinds']`.

- [ ] **Step 1:** Write `tests/connection_test.php` with a fake DB (pattern from `tests/outbox_schema_test.php`): `active()` returns only `is_active = 1`; `byWebhookToken()` compares in constant time (`hash_equals`) and ignores inactive rows; `save()` rejects a brand outside `BRANDS`; `recordStatus('device', 'error', 'x', ...)` sets `last_status`, `last_error` and `last_device_sync`; `secret('api_key')` round-trips through the GLPIKey stub; `(string) $connection` and `var_export` contain no secret.
- [ ] **Step 2:** Run it. Expected: FAIL.
- [ ] **Step 3:** Implement. Add `connections.api_key`, `connections.password`, `connections.webhook_token` to `secured_fields`.
- [ ] **Step 4:** Run the suite. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit `feat: connections table and store`.

### Task 3: Driver interface and the OpManager driver

**Files:**
- Create: `src/Driver/Driver.php`, `src/Driver/Capabilities.php`, `src/Driver/DriverFactory.php`, `src/Driver/UnsupportedOperation.php`, `tests/driver_opmanager_test.php`, `tests/driver_factory_test.php`.
- Move: `src/OpManager/*` → `src/Driver/OpManager/*` (namespace `GlpiPlugin\Nms\Driver\OpManager`); add `src/Driver/OpManager/OpManagerDriver.php`.

**Interfaces:**
- Consumes: `Connection` (Task 2).
- Produces:
  - `interface Driver { public function capabilities(): Capabilities; public function testConnection(): array /* {ok: bool, message: string} */; public function listDevices(): array; public function listActiveAlarms(): array; public function alarmByKey(string $key): ?array; public function addNote(string $alarm_key, string $text): void; public function acknowledge(string $alarm_key): void; public function clear(string $alarm_key): void; }`. `listDevices()` and `listActiveAlarms()` return the **same array shapes** that `DeviceParser` and `AlarmParser` return in 1.3.2, so `DeviceSync`, `AlarmSync` and `Hygiene` do not change shape. Write methods throw `UnsupportedOperation` when the capability is false.
  - `final class Capabilities { public function __construct(public readonly bool $devices, public readonly bool $alarms, public readonly bool $availability, public readonly bool $note, public readonly bool $ack, public readonly bool $clear, public readonly bool $webhook) {} }`.
  - `DriverFactory::for(Connection $c): Driver`. It throws `\InvalidArgumentException` for a brand with no driver yet (Plan 3 adds them).
  - `DeviceSource` and `AlarmSource` interfaces are removed. Their callers take a `Driver`.

- [ ] **Step 1:** Write `tests/driver_opmanager_test.php` using `tests/fixtures/opm_stub.php`: `OpManagerDriver` built from a `Connection` returns the same arrays as 1.3.2's `DeviceParser`/`AlarmParser` for the stub; capabilities are all true; the API key goes in the `apiKey` header and never in the URL; `addNote`/`clear`/`acknowledge` send POST form bodies as in 1.3.2's `Sender` tests. Write `tests/driver_factory_test.php`: `for()` with brand `opmanager` returns `OpManagerDriver`; brand `zabbix` throws `InvalidArgumentException`.
- [ ] **Step 2:** Run both. Expected: FAIL.
- [ ] **Step 3:** Implement by moving and wrapping. `Outbound\Sender` calls the `Driver` write methods instead of `Client` directly.
- [ ] **Step 4:** Run the suite. Expected: `SUITES OK` with the old client, parser and sender tests updated only for namespace and constructor.
- [ ] **Step 5:** Commit `feat: driver interface, OpManager as first driver`.

### Task 4: `connections_id` on every data table

**Files:**
- Modify: `install/install.php`; `src/DbDeviceStore.php`, `src/Ticketing/DbAlarmStore.php`, `src/Ticketing/DbTicketRequestStore.php`, `src/Outbound/DbOutboxStore.php`, `src/Hygiene/DbHygieneStore.php`, `src/Ticketing/DbDeviceIndex.php`, `src/Coverage/DbCoverageSource.php`; their interfaces.
- Test: `tests/schema_test.php`, `tests/connection_scope_test.php`.

**Interfaces:**
- Produces: column `connections_id` INT UNSIGNED NOT NULL DEFAULT 0 with KEY on `devices`, `alarms`, `outbox`, `storms`, `ticketrequests`, `deviceexceptions`, `hygienetickets`. Unique keys that were on an external id become (`connections_id`, external id): devices (`connections_id`, `device_key`), alarms (`connections_id`, `alarm_key`). Every Db*Store constructor takes `int $connections_id` and scopes every read and write to it. Interface method signatures are otherwise unchanged.

- [ ] **Step 1:** Extend `tests/schema_test.php` to assert the columns and composite unique keys. Write `tests/connection_scope_test.php`: two stores for connections 1 and 2 over one fake DB; an alarm with key `A1` saved under each is two rows; `find('A1')` on store 1 never returns store 2's row; `pending()` on outbox store 1 never returns store 2's rows.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the suite. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit `feat: scope every store by connection`.

### Task 5: Per-connection settings

**Files:**
- Create: `src/Connection/Settings.php`, `tests/settings_test.php`.
- Modify: `src/Config.php`, every reader of the moved keys (ticketing, hygiene, coverage, outbound).

**Interfaces:**
- Produces: `final class Settings { public const DEFAULTS = [...]; public static function fromJson(string $json): self; public function get(string $key): string; public function with(array $values): self; public function toJson(): string; }`. `DEFAULTS` holds every 1.3.2 `Config::DEFAULTS` key that spec §5.5 and §5.10 move per connection, with 1.3.2's default values unchanged: `match_mode`, `ticket_*`, `service_user`, `relink_window_hours`, `storm_*`, `outbound_max_attempts`, `production_addressings`, `coverage_states`, every `hygiene_*` key. `Config` keeps only global keys: `asset_itemtypes`, `stale_warning_minutes`. Readers take a `Settings` from the current `Connection` instead of `Config::get()`.

- [ ] **Step 1:** Write `tests/settings_test.php`: `fromJson('')` returns all defaults; unknown keys in JSON are dropped; `with()` does not mutate; every moved key's default equals the 1.3.2 `Config::DEFAULTS` value (list the keys literally in the test); `Config::DEFAULTS` no longer contains any moved key.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement and rewire readers.
- [ ] **Step 4:** Run the suite. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit `feat: ticket, hygiene and coverage settings per connection`.

### Task 6: Cron passes per connection

**Files:**
- Modify: `src/Cron.php`, `src/Ticketing/DbSyncLock.php`, `src/Outbound/Sender.php`, `src/Hygiene/HygieneRun.php`.
- Test: `tests/cron_isolation_test.php`, `tests/synclock_test.php`.

**Interfaces:**
- Consumes: `ConnectionStore::active()`, `DriverFactory::for()`, `Settings`.
- Produces: `Cron::cronDeviceSync`, `cronAlarmSync`, `cronOutboxSend`, `cronHygieneCheck` each loop over `active()`. Each connection runs inside `try/catch (\Throwable)`. A failure calls `recordStatus($id, $kind, 'error', <message without secrets>, $now)` and moves on. `DbSyncLock` takes a lock name `nms_sync_<connections_id>`. A connection with `tickets_enabled = 0` runs AlarmSync for storage and the tab only: no ticket, followup or storm call. A connection with `writeback_enabled = 0` gets no outbox rows enqueued.

- [ ] **Step 1:** Write `tests/cron_isolation_test.php`: connection 1's fake driver throws on `listDevices`; connection 2's returns two devices. After `cronDeviceSync`, connection 2 has two devices and status `ok`; connection 1 has status `error`; the error text contains no `api_key` value. Add: an inactive connection is never called; `tickets_enabled = 0` → `TicketGateway` fake receives zero calls for an alarm that would ticket under `tickets_enabled = 1`; `writeback_enabled = 0` → outbox stays empty after a followup hook. Extend `synclock_test`: locks for connections 1 and 2 do not block each other.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the suite. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit `feat: cron runs per connection, one failure never stops the others`.

### Task 7: Webhook routed by connection

**Files:**
- Modify: `front/webhook.php`, `src/Webhook/Receiver.php`.
- Test: `tests/webhook_routing_test.php`, `tests/receiver_test.php`.

**Interfaces:**
- Consumes: `ConnectionStore::byWebhookToken()`.
- Produces: the receiver reads the token from header `X-NMS-Token`, and also accepts `X-OPM-Token` (spec §6: keeping it for OpManager is acceptable). It resolves the connection and runs the 1.3.2 pipeline with that connection's stores, driver and settings. Responses: unknown, missing or inactive token → 403; OpManager "Test Action" placeholders → 200 `test received` (as in 1.3.2).

- [ ] **Step 1:** Write `tests/webhook_routing_test.php`: a valid token for connection 2 writes only connection 2's rows; an unknown token → 403, nothing written, the log line contains no token; a token of an inactive connection → 403; `X-OPM-Token` works the same as `X-NMS-Token`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the suite. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit `feat: webhook routed by connection token`.

### Task 8: Migration from opmanager

**Files:**
- Create: `src/Migration/FromOpmanager.php`, `tools/migrate_from_opmanager.php` (CLI), `tests/migration_idempotent_test.php`, `tests/migration_absent_test.php`.
- Modify: `install/install.php` (call on install), `front/config.php` (button "Copy from opmanager again", `plugin_nms_config` UPDATE, CSRF).

**Interfaces:**
- Consumes: `ConnectionStore`, `Settings`.
- Produces: `FromOpmanager::run(\DBmysql $db, string $now): array` returning counts per table plus `connections_id`. Behaviour:
  1. If `glpi_plugin_opmanager_devices` does not exist, return `['skipped' => 'opmanager not installed']`.
  2. Find or create the connection named `OpManager (migrated)`, brand `opmanager`, `is_active = 0`, `tickets_enabled = 1`, `writeback_enabled = 1`. Copy `opm_url` to `url`, `opm_verify_ssl` to `verify_tls`. Decrypt `opm_api_key` and `webhook_token` with GLPIKey and re-encrypt into the new row. Copy every moved key (Task 5) into `settings`.
  3. Entity: the lowest common ancestor of the entities of the linked assets, `is_recursive = 1`; root entity if there are none (spec §5.6b).
  4. Upsert rows from opmanager's `devices`, `alarms`, `outbox`, `storms`, `ticketrequests`, `deviceexceptions`, `hygienetickets`, `hygieneitems` into nms tables with that `connections_id`. Natural keys: `device_key`, `alarm_key`, outbox (`alarm_key`, `action`, `date_creation`), storms `tickets_id`, ticketrequests `request_key`, exceptions (`device_key`, `date_creation`), hygienetickets `tickets_id`, hygieneitems (ticket, `device_key`). Insert if absent, update if changed, never delete.
  5. Rights: for every profile, `plugin_nms_config` = opmanager `config`, `plugin_nms_monitoring` = opmanager `monitoring`, `plugin_nms_hygiene` = opmanager `hygiene`, only where the nms right is still 0.
  6. Never write to an opmanager table or config key.
- `tools/migrate_from_opmanager.php` boots GLPI like the other `tools/` scripts, runs `run()`, and prints the counts.

- [ ] **Step 1:** Write `tests/migration_idempotent_test.php` over a fake DB seeded with invented opmanager rows (2 devices, 3 alarms, 1 outbox, 1 exception, 1 hygiene ticket with 2 items, 2 profiles). First `run()` copies all of them. A second `run()` adds 0 rows. After changing one opmanager alarm's state and adding one alarm, a third `run()` updates 1 and inserts 1. The connection stays `is_active = 0` throughout. The decrypted API key equals the source. No query targets a `glpi_plugin_opmanager_` table with INSERT, UPDATE or DELETE. Write `tests/migration_absent_test.php`: without opmanager tables, `run()` returns `skipped`, and no connection exists.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the suite. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit `feat: idempotent migration from opmanager (install, Setup button, CLI)`.

### Task 9: Setup page for connections

**Files:**
- Create: `front/connection.php`, `front/connection.form.php`, `src/Connection/ConnectionForm.php`, `tests/connection_form_test.php`.
- Modify: `front/config.php`, `src/SetupStatus.php`, `src/SuiteConfig.php`.

**Interfaces:**
- Consumes: Tasks 2, 3, 5, 8.
- Produces: Setup lists connections (name, brand, entity, active, tickets, write-back, last sync, last status). The form edits every connection column and its `Settings`. Secret fields show "set / not set" and are never echoed. The Test button calls `Driver::testConnection()` and shows `message`. Write-back and Webhook switches are disabled with the tooltip "Not supported by this NMS" when the capability is false. The brand select lists only brands that `DriverFactory` supports. The status strip shows one line per connection. Rights: view `plugin_nms_config` READ, save `plugin_nms_config` UPDATE; CSRF on every POST.

- [ ] **Step 1:** Write `tests/connection_form_test.php` (static and render tests, pattern `setupstatus_test.php`): no secret value appears in rendered HTML; disabled switches appear for a fake driver whose capabilities are false; a POST without UPDATE right is refused; saving with an empty secret field keeps the stored secret.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the suite. Expected: `SUITES OK`.
- [ ] **Step 5:** Commit `feat: Setup lists and edits connections`.

### Task 10 (owner): release candidate

- [ ] Runbook `docs/runbook-1.0.0.md`: install next to opmanager, then the deploy order of spec §5.11 item 8; check the migration counts; cutover steps of spec §6.2 in order; checks of spec §6.3; the note on the Request template hiding observers.
- [ ] `CHANGELOG.md` entry `[1.0.0-rc1]`; zip `nmsv1.0.0-rc1.zip` with no `graphify-out/`, `CLAUDE*`, dotfiles or `docs/superpowers/`; tag `v1.0.0-rc1`. Report to the orchestrator.
