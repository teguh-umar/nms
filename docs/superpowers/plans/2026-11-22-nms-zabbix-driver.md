# nms 1.1.0 Zabbix Driver and Per-Connection Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a poll-based Zabbix driver (6.0 LTS to newest) and make Coverage show, filter and compute per connection, without changing anything the production OpManager connection does.

**Architecture:** A new `src/Driver/Zabbix/` package implements the existing `Driver` interface (JSON-RPC client, two pure parsers, the driver), registered in `DriverFactory`. Matching gets an entity scope taken from the connection (root + recursive = unrestricted, as today). Coverage carries `connections_id` / `entities_id` through its rows and gains a connection filter.

**Tech Stack:** PHP 8.x, GLPI 11 plugin API, ext-curl, offline PHP test harness (`tests/*_test.php`, `check()` / `done()`).

**Spec:** `docs/superpowers/specs/2026-11-22-nms-zabbix-driver-design.md`

## Global Constraints

- No new tables. Zabbix settings in the connection `settings` JSON; credentials in the encrypted `api_key` / `password` columns.
- Existing OpManager tests stay green **unchanged**; OpManager ticket titles/contents and `[OPM-HYGIENE]` texts stay byte-identical.
- Shared lib stays ConfigTab r5 `46FEAA6C…`, Menu `34F40CBB…`, AssetTab `F8629CCA…`, `front/pgnsuite.settings.php` `4BFF4A4B…`; no new copies.
- Suite rule 11 (WAF): ids and short keys in GET parameters; secrets only by POST, never rendered back, never in logs, exceptions or redirects.
- Fixtures: synthetic names only, IPs from RFC 5737 (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`).
- MySQL-strict lessons: no DEFAULT on TEXT; no `''` into TIMESTAMP.
- Tests: `php -d extension_dir="C:/Users/teguh.umar/Downloads/php/ext" -d extension=openssl -d extension=curl -d extension=mbstring tests/run_all.php`, last line `SUITES OK`. Test files are named `*_test.php`.
- **Machine hold:** before running the suite, a build or anything on staging 2, check that the orchestrator has no hold in force; tell the orchestrator before any staging 2 write.
- Commit per task, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit the user's `[x]` ticks in `docs/runbook-1.0.0.md`. No push.

## Review Focus

- Zabbix answers HTTP 200 with a JSON-RPC `error` object (bad token, no permission). Expected: a `ZabbixException` with Zabbix's `message`/`data`, the token never in it; the sync records the error on the connection. Test: Task 2.
- A host whose only interface is DNS-based (`useip` = 0, `ip` empty). Expected: `ip_address` = the DNS name, the device still listed. Test: Task 3.
- A host with no interfaces and no groups (e.g. an HTTP-agent-only host). Expected: listed with `ip_address` '', `device_category` '', availability `Unknown`, never skipped. Test: Task 3.
- Write-back disabled on the connection while the outbox still holds a row from before. Expected: no `event.acknowledge` call (the existing Outbound gate decides; the driver adds no bypass). Test: Task 4.
- Two active connections whose entities overlap (OpManager root + Zabbix in a child). Expected: "All connections" counts each asset once; a picked Zabbix connection counts only its entity's assets. Test: Task 7.

---

### Task 1: Entity scope for matching

**Files:**
- Create: `src/Matching/EntityScope.php`
- Modify: `src/Matching/DbAssetLookup.php` (constructor, `byName()`, `byIp()`)
- Modify: `src/Cron.php:58`, `src/Cron.php:120`, `front/webhook.php:70` (pass the scope)
- Test: `tests/entity_scope_test.php`

**Interfaces:**
- Produces: `EntityScope::of(int $entities_id, int $is_recursive, callable $sons): ?array` — `null` means "every entity" (exactly when `$entities_id === 0 && $is_recursive === 1`); otherwise `list<int>`: `[$entities_id]`, or `$sons($entities_id)` (ids including itself) when recursive. `EntityScope::forConnection(Connection $c): ?array` calls it with `getSonsOf('glpi_entities', ...)`.
- Produces: `new DbAssetLookup(array $itemtypes, ?array $entities = null)`; `DbAssetLookup::inScope(?array $entities, int $entities_id): bool` (pure, null = true).

- [ ] **Step 1: Write the failing test** `tests/entity_scope_test.php`:
  - `EntityScope::of(0, 1, $sons) === null` and `$sons` never called.
  - `EntityScope::of(0, 0, $sons) === [0]`.
  - `EntityScope::of(81, 1, fn ($id) => [81, 82, 90]) === [81, 82, 90]`.
  - `EntityScope::of(81, 0, $sons) === [81]`.
  - `DbAssetLookup::inScope(null, 5) === true`, `inScope([81, 82], 82) === true`, `inScope([81], 0) === false`.
  - Source pins: `src/Cron.php` and `front/webhook.php` build `new DbAssetLookup(Config::assetItemtypes(), EntityScope::forConnection(` (regex), twice in Cron, once in webhook.
- [ ] **Step 2: Run** `php … tests/entity_scope_test.php` — expect FAIL (class not found).
- [ ] **Step 3: Implement.** `byName()`: when the scope is a list, add `$table.entities_id => $scope` to the WHERE. `byIp()`: after `getFromDB()`, skip owners whose `fields['entities_id']` is out of scope; an owner whose class cannot be loaded is kept, as today (conservative: it can only make a match ambiguous). SelfCheck keeps the unscoped lookup.
- [ ] **Step 4: Run** the test and the full suite — PASS, `matcher_test.php` and `dbassetlookup_test.php` unchanged.
- [ ] **Step 5: Commit** `feat(matching): limit matching to the connection's entity scope (root+recursive = all)`.

### Task 2: Zabbix JSON-RPC client, version and auth

**Files:**
- Create: `src/Driver/Zabbix/JsonRpc.php`, `src/Driver/Zabbix/ZabbixException.php` (extends `\RuntimeException`)
- Test: `tests/zabbix_jsonrpc_test.php`

**Interfaces:**
- Produces: `new JsonRpc(string $url, string $token, string $username, string $password, callable $transport)`; transport: `callable(string $url, string $body, array<string,string> $headers): array{status: int, body: string}`.
- Produces: `JsonRpc::endpoint(string $url): string` (pure; appends `/api_jsonrpc.php` unless the path already ends with it; strips a trailing `/`).
- Produces: `JsonRpc::version(): string` (cached), `JsonRpc::authMode(): string` (`'token'` or `'login'`), `JsonRpc::call(string $method, array $params): mixed` (the `result`), `JsonRpc::logout(): void` (no-op for token mode).
- Version rules (verify each boundary in the official API docs before coding, and fix the constant if the docs differ): `apiinfo.version` is sent with no auth at all; token mode: `version >= 6.4` → header `Authorization: Bearer <token>`, otherwise `"auth": <token>` in the body; login mode: `user.login` with `{"username","password"}`, the returned session used the same way as a token; `const HEADER_AUTH_FROM = '6.4'`.

- [ ] **Step 1: Write the failing test** with a recording fake transport:
  - `endpoint('https://zbx.example/zabbix') === 'https://zbx.example/zabbix/api_jsonrpc.php'`, and unchanged for `…/api_jsonrpc.php` and `…/api_jsonrpc.php/`→ without the slash.
  - Version 6.0 + token: second request body has `"auth":"tok"`, no `Authorization` header; the first (`apiinfo.version`) has neither.
  - Version 7.0 + token: `Authorization: Bearer tok` header, no `auth` key in the body.
  - Login mode (token ''): `user.login` called once with `username`/`password`, later calls carry the session; `logout()` calls `user.logout`.
  - JSON-RPC `error` with HTTP 200 → `ZabbixException` whose message contains the Zabbix `data` text and not `tok`, not the password.
  - HTTP 500 → `ZabbixException` naming the status.
  - Request ids increase; `Content-Type: application/json-rpc`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** `JsonRpc`. The curl transport (the driver's, Task 4) honours `verify_tls` and has no redirects, as `OpManagerDriver::curlTransport()` does.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat(zabbix): JSON-RPC client with version and auth detection`.

### Task 3: Host and problem parsers with fixtures

**Files:**
- Create: `src/Driver/Zabbix/HostParser.php`, `src/Driver/Zabbix/ProblemParser.php`
- Create: `tests/fixtures/zabbix/6.0/{host.get,problem.get,event.get}.json`, the same for `7.0/` and the newest line (`7.4/`, or newer if released on the build date; record the versions in the CHANGELOG)
- Test: `tests/zabbix_parsers_test.php`

**Interfaces:**
- Produces: `HostParser::parse(array $hosts): array` — `list<array{device_key, name, display_name, ip_address, device_type, device_category, availability}>`, the `DeviceParser::parse()` shape.
- Produces: `ProblemParser::parse(array $problems, array $hostsByEvent, array $hostDetails): array` — `list<…>` in the `AlarmParser::parse()` shape; `ProblemParser::fromEvent(array $event, array $hostDetails): ?array` for `alarmByKey`.
- Produces: `ProblemParser::SEVERITY_MAP = [5 => 1, 4 => 1, 3 => 2, 2 => 3, 1 => 3, 0 => 3]`, `ProblemParser::LABELS = [0 => 'Not classified', 1 => 'Information', 2 => 'Warning', 3 => 'Average', 4 => 'High', 5 => 'Disaster']`.

- [ ] **Step 1: Write the failing test** from the fixtures (each version):
  - Host mapping per spec §7.1 table: `device_key` = `hostid`, `name` = `host`, `display_name` = `name`, main-interface `ip` chosen by type order Agent(1), SNMP(2), IPMI(3), JMX(4), `dns` when `useip` = 0, `device_category` = first group name after sorting, availability `Disabled` (status 1) > `Maintenance` (maintenance_status 1) > interface `available` 1/2/other → `Available`/`Unavailable`/`Unknown`.
  - 6.0 fixture uses `groups`, 6.2+ uses `hostgroups`: both parse to the same category.
  - Host with no interfaces and no groups → listed, `ip_address` '', category '', `Unknown`.
  - Problem mapping: `alarm_key` = `eventid`, `entity_key` = `hostid`, `alarm_code` = `objectid`, `severity` via `SEVERITY_MAP`, `severity_label` = Zabbix label, `message` = `name` plus ` (opdata)` when `opdata` non-empty, `mod_time` = `date('Y-m-d H:i:s', clock)`, `event_type` = the trigger description from `hostDetails`/event (as defined, macros unexpanded).
  - `fromEvent()` with `r_eventid` ≠ '0' → severity 5, label `Clear`; event list empty → `null`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** both parsers (pure, no I/O).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat(zabbix): host and problem parsers with 6.0/7.0/newest fixtures`.

### Task 4: ZabbixDriver and factory registration

**Files:**
- Create: `src/Driver/Zabbix/ZabbixDriver.php`, `src/Driver/Zabbix/CloseRefused.php` (extends `\DomainException`, so `Outbound\Sender` marks it failed without retry)
- Modify: `src/Driver/DriverFactory.php` (`'zabbix'` in `for()` and `supportedBrands()`)
- Test: `tests/driver_zabbix_test.php`

**Interfaces:**
- Consumes: `JsonRpc` (Task 2), `HostParser` / `ProblemParser` (Task 3), `Settings` keys `zabbix_host_groups` (newline list of names), `zabbix_min_severity` ('0'..'5', default '2'), `zabbix_include_suppressed` ('0'/'1') (defaults added in Task 5; the driver reads them through `Settings::fromJson($c->settings)`).
- Produces: `new ZabbixDriver(Connection $c, ?callable $transport = null, ?int $timeout = null)` implementing `Driver`; `capabilities()` = `new Capabilities(true, true, true, true, true, true, false, true)`.

- [ ] **Step 1: Write the failing test** (fake transport answering from the fixtures by method):
  - `DriverFactory::supportedBrands()` contains `'zabbix'`; `DriverFactory::for(<zabbix conn>)` is a `ZabbixDriver`.
  - `listDevices()` with host groups "Servers\nSwitches" calls `hostgroup.get` once with `filter.name`, then `host.get` with those `groupids`; empty setting → no `groupids`.
  - `listActiveAlarms()`: `problem.get` has `severities` = [min..5], `suppressed` = false unless included, `recent` false; then one `event.get` (`selectHosts`) and one `host.get` (interfaces) for the batch: 3 calls in total.
  - `alarmByKey('77')` → `event.get` with `eventids` ['77']; resolved fixture → severity 5.
  - `addNote('77','x')` → `event.acknowledge` `{eventids:['77'], action:4, message:'x'}`; `acknowledge('77')` → `action:2`; `clear('77')` → `action:1`.
  - Zabbix error "Cannot close problem: trigger does not allow manual closing" on `clear` → `CloseRefused`.
  - `testConnection()` ok → message contains the version and `token`/`login`, never the secret; failure → `ok` false with the safe message.
  - The driver never calls `event.acknowledge` from read methods (recorded calls).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** `ZabbixDriver` (logout in `__destruct()`, errors swallowed there).
- [ ] **Step 4: Run** test + full suite — PASS.
- [ ] **Step 5: Commit** `feat(zabbix): ZabbixDriver registered in DriverFactory`.

### Task 5: Settings, brand defaults, connection form, hygiene titles

**Files:**
- Modify: `src/Connection/Settings.php` (DEFAULTS gain `zabbix_host_groups` '', `zabbix_min_severity` '2', `zabbix_include_suppressed` '0'; new `Settings::forNewConnection(string $brand, int $entities_id): self`)
- Modify: `src/Connection/ConnectionForm.php` (`handleSave()` for id 0 applies `forNewConnection`; Zabbix block in `tabConnection()`; save of the three keys)
- Modify: `src/Hygiene/Report.php` (`title(string $flow, string $now, string $brand = 'opmanager')`, `body()` likewise) and its caller(s)
- Test: `tests/zabbix_settings_test.php`

**Interfaces:**
- Produces: `Settings::forNewConnection('zabbix', $eid)` overrides exactly: `ticket_title_pattern` `[ZBX-{alarm_key}] {severity}: {message} - {device}`, `ticket_content_pattern` = the OpManager pattern with "OpManager alarm" → "Zabbix problem", `ticket_source` `rules`, `ticket_entity_default` `(string) $eid`, `hygiene_unmanaged_availabilities` `["Disabled"]`. For `'opmanager'` it returns `DEFAULTS` unchanged.
- Produces: `HygieneReport::title()` for `opmanager` byte-identical to today; for `zabbix` `[ZBX-HYGIENE] Monitored in Zabbix, not in GLPI - <date>` / `[ZBX-HYGIENE] Unmanaged in Zabbix: remove or check - <date>`.

- [ ] **Step 1: Write the failing test:** the overrides above; `forNewConnection('opmanager', 0)->toJson()` equals `Settings::fromJson('{}')->toJson()`; hygiene titles for both brands; form source pins: the Zabbix block renders only for brand zabbix, the min-severity `<select>` has values 0..5, token/password inputs keep `value=""`; `handleSave` on an existing connection never calls `forNewConnection` (regex on the id-0 branch).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** Host groups are a textarea (one name per line, trimmed, empty lines dropped). Values are validated: min severity clamped 0..5, suppressed '0'/'1'.
- [ ] **Step 4: Run** test + full suite — PASS (hygiene and connection-form tests unchanged).
- [ ] **Step 5: Commit** `feat(zabbix): settings, new-connection defaults, form block, brand hygiene titles`.

### Task 6: Brand-neutral UI labels

**Files:**
- Modify: `src/AssetSections.php:91-123`, `src/Device.php:69,205`, `src/Alarm.php:93`, `src/Cron.php:33-35`, `src/Coverage/Cards.php:33-119`, `src/Coverage/View.php:28,30`
- Modify: `tests/cards_test.php` (expected labels)
- Test: `tests/neutral_labels_test.php`

**Interfaces:**
- Produces (exact strings): "NMS data has never been synced.", "NMS data is older than %d minutes (last sync: %s).", "NMS device", "NMS alarms", "This asset type is not enabled in NMS Integration setup", "NMS identity", "NMS entity", "Sync NMS devices and match them to GLPI assets", "Pull active NMS alarms and create/update tickets", "Send queued notes and clears to the NMS", "NMS coverage of GLPI assets (%)", "NMS devices", "NMS devices not in GLPI", "NMS device matching", "NMS devices by category", "Active NMS alarms by severity", "Open tickets from NMS alarms", "registered but not in any NMS".

- [ ] **Step 1: Write the failing test:** none of the listed files contains `__('OpManager` or `__s('OpManager`; `src/Ticketing/` and `src/Hygiene/Report.php` still contain their OpManager texts (pin: unchanged).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Replace** the strings.
- [ ] **Step 4: Run** full suite — PASS.
- [ ] **Step 5: Commit** `feat: brand-neutral labels on asset tab, lists, crons and Coverage`.

### Task 7: Coverage per connection

**Files:**
- Modify: `src/Coverage/DbCoverageSource.php` (`devices()` rows gain `connections_id`; `assets(array $itemtypes, array $states, ?array $entities = null)` rows gain `entities_id`, WHERE gains `entities_id` IN when a list)
- Modify: `src/Coverage/Report.php` (`build()` adds `kpi['by_connection']` = `array<int,int>` count per `connections_id` of the listed devices; asset rows pass through)
- Modify: `src/Coverage/View.php` (Connection column and CSV column; "Expected from" on Not monitored; KPI hint line; connection `<select name="connection">`)
- Modify: `front/coverage.php` (read `connection`; pick scope)
- Test: `tests/coverage_per_connection_test.php`

**Interfaces:**
- Consumes: `EntityScope::of()` / `forConnection()` (Task 1).
- Produces: `View::render(array $report, array $state, string $base_url, array $connections = [])` where `$connections` = `array<int, array{name: string, brand: string, scope: ?list<int>}>` of active connections; `$state['connection']` int (0 = all). `View::expectedFrom(int $entities_id, array $connections): string` (pure: names whose scope is null or contains the id, `", "`-joined, `'none'` when empty).

- [ ] **Step 1: Write the failing test:**
  - `Report::build()` with devices from connections 1 and 2 → `kpi['by_connection'] === [1 => n1, 2 => n2]`.
  - Column header lists contain "Connection" for not_in_glpi/ambiguous/name_differs/stale and "Expected from" for not_monitored; a cell shows `OpManager PGN · opmanager`; CSV rows carry the same plain text.
  - `expectedFrom(82, [1 => [… scope null], 2 => [… scope [81, 82]]])` = `'OpManager PGN, Zabbix X'`; `expectedFrom(5, [2 => [… scope [81]]])` = `'none'`.
  - Overlap (Review Focus): an asset in entity 82 with OpManager root + Zabbix [81, 82] → counted once under "All connections".
  - Equivalence: only OpManager active, `connection` = 0 → `Report::build()` output for `kpi` and `lists` equals the 1.0.0 call's output on the same fixture rows (the added fields excluded).
  - `front/coverage.php` source pins: `(int) ($_GET['connection'] ?? 0)`; an id not among the active connections falls back to 0; the picked connection's settings drive `coverageStatesOf([$conn])`, `productionAddressingsOf([$conn])` and `assets(..., EntityScope::forConnection($conn))`; export links keep `connection` (via `$state`).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** full suite — PASS (`coverage_*` and `cards_test.php` green).
- [ ] **Step 5: Commit** `feat(coverage): connection column, filter and per-connection Coverage %`.

### Task 8: Mock Zabbix endpoint, runbook, release rc1

**Files:**
- Create: `tests/mock_zabbix/index.php` (serves `tests/fixtures/zabbix/<ver>/<method>.json` by JSON-RPC `method`; `apiinfo.version` from `?v=`; `event.acknowledge` echoes ids; rejects a request without auth except `apiinfo.version`)
- Create: `docs/runbook-1.1.0.md`
- Modify: `CHANGELOG.md`, `setup.php` (`PLUGIN_NMS_VERSION` `'1.1.0-rc1'`), `tools/release_check.py` (usage names `nmsv1.1.0-rc1.zip`)
- Test: `tests/mock_zabbix_test.php` (drives `ZabbixDriver` through an in-process transport calling the mock's handler function, for versions 6.0 and 7.0)

**Interfaces:**
- Consumes: everything above.
- Produces: runbook sections: 1 upgrade from 1.0.0 (`rm -rf plugins/nms`, unzip `/tmp/nmsv1.1.0-rc1.zip`, install `--force`, activate, cache clear, php-fpm reload, selfcheck, **log out and in**); 2 checks that OpManager is unchanged (Coverage "All connections" same numbers as before, matching counts unchanged after one DeviceSync); 3 for a subsidiary: create a Zabbix user role with API access and read-only on its host groups, an API token, the network path; 4 add the connection (brand zabbix, entity = the subsidiary's, tickets off, write-back off), Test connection; 5 first sync and Coverage per connection; **6 warning: do not give nms rights to subsidiary users before the entity-isolation release**. Endpoints list for the rc report: `front/coverage.php?connection=<id>` (+ `&export=csv`); connection form Zabbix fields (POST).

- [ ] **Step 1: Write the failing test** `tests/mock_zabbix_test.php`: devices and problems from the mock equal the parser outputs on the same fixtures; a call without auth is refused.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the mock, the runbook, CHANGELOG (`## 1.1.0-rc1 (nms)`: Zabbix driver with the exact versions tested, Coverage per connection, entity-scoped matching, neutral labels, new-connection defaults; deferred: serial/MAC/FQDN matching, webhook, entity isolation), version, usage line.
- [ ] **Step 4: Run** full suite — `SUITES OK`; then `git tag v1.1.0-rc1`, `git archive --format=zip --prefix=nms/ -o nmsv1.1.0-rc1.zip v1.1.0-rc1`, `python tools/release_check.py nmsv1.1.0-rc1.zip --install C:/Users/teguh.umar/Downloads/glpi/plugins` → `RELEASE OK` (the zip must not contain `tests/`), `graphify update .`.
- [ ] **Step 5: Commit** (before the tag) `release: 1.1.0-rc1`; report tag, zip sha256, file count, shared-lib hashes and the endpoint list to the orchestrator. Staging 2 test only after the orchestrator's go (backup, marks, tagged seed `nmsrc11test`, mock connection, cleanup, CHECKSUM-all-tables restore check).
