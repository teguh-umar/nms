# nms 1.2.0 PRTG Driver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a PRTG driver whose API (classic, or experimental read-only v2) the GLPI admin picks per connection, with no change for OpManager or Zabbix connections.

**Architecture:** `src/Driver/Prtg/` holds a `PrtgClient` interface with `ClassicClient` and `V2Client`. Each client normalises PRTG responses into plain rows. Pure `DeviceParser` / `SensorParser` turn those rows into the nms device/alarm shapes. `PrtgDriver` implements `Driver` and picks the client from the `prtg_api` setting. Everything outside the driver (matching, Coverage, outbox, tickets, hygiene) is reused from 1.1.0.

**Tech Stack:** PHP 8.2+, GLPI 11 plugin, ext-curl, offline PHP test harness (`tests/*_test.php`, `check()` / `done()`).

**Spec:** `docs/superpowers/specs/2026-11-22-nms-prtg-driver-design.md`

## Global Constraints

- No new tables. PRTG settings go in the connection `settings` JSON; credentials go in the encrypted `api_key` / `password` columns.
- OpManager and Zabbix tests stay green, with unchanged assertions about their behaviour.
- Secrets (API key, password, passhash, v2 session token) never appear in a page, log, exception or redirect. Every URL is redacted before it reaches a message. There is no previous-exception chaining. Secret parameters carry `#[\SensitiveParameter]`.
- An API key is sent as the `Authorization: Bearer <key>` header, never in the URL. Login mode makes one login (passhash or session) per run. Empty username or password gives a clear error and sends no request.
- API v2 is "experimental, read-only": it never writes.
- Alarm `entity_key` = sensor objid. This is the outbox write key; a seam test proves it.
- Fixtures: synthetic names only, RFC 5737 IPs (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24).
- Shared lib unchanged: ConfigTab `46FEAA6C…`, Menu `34F40CBB…`, AssetTab `F8629CCA…`, `front/pgnsuite.settings.php` `4BFF4A4B…`.
- Tests: `php -d extension_dir="C:/Users/teguh.umar/Downloads/php/ext" -d extension=openssl -d extension=curl -d extension=mbstring tests/run_all.php`; the last line must be `SUITES OK`.
- Commits are staged by name; never commit `docs/runbook-1.0.0.md`. The message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. No push. No tag until after the whole-branch review.
- Ask the orchestrator before any staging 2 write. No PRTG install on the laptop.

## Normalised rows (client output = parser input)

- Device: `array{id: string, name: string, host: string, group: string, status_raw: int, status: string, tags: string}`
- Sensor: `array{id: string, name: string, device_id: string, device: string, group: string, status_raw: int, status: string, message: string, type: string, lastcheck: ?float}`. `lastcheck` is the OLE automation date, or null.

## Review Focus

- A transport failure whose curl message contains the request URL with `passhash=` or `password=` (timeout, DNS). Expected: the exception message has the URL redacted. Test: Task 1.
- API v2 selected against a PRTG older than 24.3 (404 on `/api/v2`). Expected: Test connection fails with "API v2 is not available on this PRTG (needs 24.3 or later)", and syncs record the same error. Test: Task 2.
- A sensor whose parent device is not in the device list (other group, or deleted). Expected: the alarm row is still produced, with `ip_address` ''. Test: Task 3.
- PRTG messages that carry HTML (`<div class="status">…`) or entities. Expected: tags are stripped, entities decoded, and the text is plain. Test: Task 3.
- An outbox acknowledge row queued while write-back was on, then the connection is switched to API v2. Expected: no write call; the row fails as unsupported (permanent), not retried. Test: Task 4.

---

### Task 1: PrtgClient interface and ClassicClient

**Files:**
- Create: `src/Driver/Prtg/PrtgClient.php`, `src/Driver/Prtg/ClassicClient.php`, `src/Driver/Prtg/PrtgException.php` (extends `\RuntimeException`), `src/Driver/Prtg/PrtgApiError.php` (extends `PrtgException`)
- Test: `tests/prtg_classic_client_test.php`

**Interfaces:**
- Produces `interface PrtgClient`:
  - `version(): string`
  - `authMode(): string` — `'key'` or `'login'`
  - `devices(): array` — list of normalised devices
  - `sensors(array $statusCodes): array` — normalised sensors filtered by `status_raw`; `[]` means every status
  - `sensor(string $id): ?array`
  - `acknowledge(string $id, string $message): void`
- Produces `new ClassicClient(string $url, #[\SensitiveParameter] string $apiKey, string $username, #[\SensitiveParameter] string $password, callable $transport)`.
  - transport: `callable(string $url, array $headers): array{status: int, body: string}`. All classic calls are GET.
- Produces `ClassicClient::base(string $url): string` — strips trailing slashes.
- Produces `PrtgApiError`: thrown only for a PRTG application-level refusal (HTTP 400/401/403 with a PRTG error body, or `{"error": ...}`). HTTP 5xx, transport and non-JSON failures are a plain `PrtgException`.

- [ ] **Step 1: Write the failing test** (recording fake transport):
  - Key mode:
    - every request carries the header `Authorization: Bearer k-123`;
    - no URL contains `apitoken`, `k-123`, `passhash` or `password`.
  - Login mode:
    - `getpasshash.htm` is called once with `username`/`password`;
    - later calls carry `username=` and `passhash=<returned>`;
    - a second `devices()` call does not call `getpasshash.htm` again;
    - empty password: `PrtgException` with "PRTG username or password is not configured", and no request sent;
    - a failing passhash call: later calls rethrow without a second attempt.
  - `version()` reads `Version` from `status.json` and is cached.
  - `devices()` requests `content=devices`, the columns from spec §7.2 and `count=*`, and maps `objid`, `device`, `host`, `group`, `status_raw`, `status`, `tags` to the normalised device.
  - `sensors([5, 13, 14])` sends one `filter_status=` per code and maps to the normalised sensor:
    - `device_id` from `parentid`;
    - `lastcheck` from `lastcheck_raw` as float.
  - `sensor('2001')` uses `filter_objid=2001` and returns null on an empty table.
  - `acknowledge('2001', 'Acknowledged in GLPI')` calls `acknowledgealarm.htm` with `id=2001&ackmsg=…`.
  - HTTP 401 with a PRTG error body gives a `PrtgApiError`. HTTP 500 gives a plain `PrtgException`. A transport that throws `"Operation timed out for https://prtg.example/api/table.json?username=u&passhash=SECRETHASH"` gives a message without `SECRETHASH` or `passhash=`, and `getPrevious() === null`.
  - Positive redaction: the key, password and passhash never appear in any exception message or in `(string) $e`.
- [ ] **Step 2: Run** `tests/prtg_classic_client_test.php`. Expect FAIL (class missing).
- [ ] **Step 3: Implement.** Redaction replaces the key, password and passhash values, and the values of the query parameters `passhash`, `password`, `apitoken` and `username` in any URL inside a message, with `***`.
- [ ] **Step 4: Run** the test, then the full suite. Expect PASS.
- [ ] **Step 5: Commit** `feat(prtg): classic API client with key/login auth and redaction`.

### Task 2: V2Client (experimental, read-only)

**Files:**
- Create: `src/Driver/Prtg/V2Client.php`
- Test: `tests/prtg_v2_client_test.php`

**Interfaces:**
- Consumes `PrtgClient`, `PrtgException`, `PrtgApiError` (Task 1).
- Produces `new V2Client(string $url, #[\SensitiveParameter] string $apiKey, string $username, #[\SensitiveParameter] string $password, callable $transport)`.
  - transport: `callable(string $method, string $url, array $headers, ?string $body): array{status: int, body: string}`.
  - Base is `<url>/api/v2`.
  - Key mode sends `Authorization: Bearer <key>`.
  - Login mode calls `POST /session` once with `{"username","password"}` and uses the returned `token` as Bearer.
  - `acknowledge()` throws `\GlpiPlugin\Nms\Driver\UnsupportedOperation` ("acknowledge is not available with PRTG API v2").

- [ ] **Step 1: Write the failing test.** Verify the endpoint paths, query parameters and field names against Paessler's API v2 OpenAPI reference (`/support/prtg/api/v2/oas/index.html`) as you know it before writing it; where unsure, keep the names in one constant block and say so in the report.
  - `devices()` calls `/experimental/devices` and `sensors()` calls `/experimental/sensors`; both map to the normalised rows.
  - Login mode makes one `POST /session`.
  - Empty credentials give an error and send no request.
  - `acknowledge()` throws `UnsupportedOperation` and sends nothing.
  - HTTP 404 on the version or first call gives a `PrtgException` with "API v2 is not available on this PRTG (needs 24.3 or later)".
  - The key and token never appear in messages; no previous exception.
- [ ] **Step 2: Run.** Expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test and the full suite. Expect PASS.
- [ ] **Step 5: Commit** `feat(prtg): experimental read-only API v2 client`.

### Task 3: Parsers and fixtures

**Files:**
- Create: `src/Driver/Prtg/DeviceParser.php`, `src/Driver/Prtg/SensorParser.php`
- Create: `tests/fixtures/prtg/classic/<line>/{status.json,devices.json,sensors.json,sensor-2001.json}` for each 26.x line in scope (verify the lines; at least 26.4, 26.3, 26.2), and `tests/fixtures/prtg/v2/{devices.json,sensors.json,version.json}`
- Test: `tests/prtg_parsers_test.php`

**Interfaces:**
- Produces `DeviceParser::parse(array $devices): array` — the OpManager `DeviceParser` shape.
- Produces `SensorParser::parse(array $sensors, array $devicesById): array` — the OpManager `AlarmParser` shape. `$devicesById` is the normalised devices keyed by id, used for `ip_address`.
- Produces `SensorParser::current(array $sensor, array $devicesById): array`. An alarm state gives the row; any other state gives the row with severity 5 / `Clear`.
- Produces `SensorParser::ALARM_CODES = ['down' => [5, 13, 14], 'down+warning' => [5, 13, 14, 4], 'down+warning+unusual' => [5, 13, 14, 4, 10]]`.
- Produces `SensorParser::SEVERITY = [5 => 1, 13 => 1, 14 => 1, 4 => 3, 10 => 3]`.
- Produces `DeviceParser::PAUSED = [7, 8, 9, 11, 12]`.

- [ ] **Step 1: Write the failing test** from the fixtures.
  - Device mapping per spec §7.4:
    - every Paused code → availability `Paused`;
    - the first tag (tags are space-separated in PRTG) becomes `device_type`, '' when there are no tags;
    - category = group;
    - a DNS `host` is kept as is.
  - Sensor mapping:
    - `alarm_key` = `entity_key` = `alarm_code` = sensor id;
    - `event_type` = `type`;
    - severity from `SEVERITY`, label = `status`;
    - `message` = name + ": " + message, with tags stripped and entities decoded;
    - `mod_time` from the OLE date (days since 1899-12-30, fraction = time). If the docs say the raw date is UTC, convert from UTC to the PHP default timezone. Null gives the current time.
  - A sensor whose device is missing from `$devicesById` gives `ip_address` ''.
  - `current()` for status 3 gives severity 5 / `Clear`.
- [ ] **Step 2: Run.** Expect FAIL.
- [ ] **Step 3: Implement** both parsers (pure).
- [ ] **Step 4: Run.** Expect PASS.
- [ ] **Step 5: Commit** `feat(prtg): device and sensor parsers with classic/v2 fixtures`.

### Task 4: PrtgDriver, factory, settings defaults, seam test

**Files:**
- Create: `src/Driver/Prtg/PrtgDriver.php`, `src/Driver/Prtg/AckRefused.php` (extends `\DomainException`)
- Modify: `src/Driver/DriverFactory.php` (add `'prtg'` to `for()` and `supportedBrands()`)
- Modify: `src/Connection/Settings.php` (DEFAULTS gain `'prtg_api' => 'classic'`, `'prtg_groups' => ''`, `'prtg_alarm_states' => 'down+warning'`)
- Test: `tests/driver_prtg_test.php`, `tests/prtg_outbound_seam_test.php`

**Interfaces:**
- Consumes the Task 1–3 classes.
- Produces `new PrtgDriver(Connection $c, ?PrtgClient $client = null, ?int $timeout = null)`. A null client builds `ClassicClient` or `V2Client` from `prtg_api` with a curl transport (`verify_tls`, no redirects, timeout, as `ZabbixDriver`). Any other `prtg_api` value gives a `PrtgException`.
- Produces `capabilities()`:
  - classic: `new Capabilities(true, true, true, false, true, false, false, true)`;
  - v2: `new Capabilities(true, true, true, false, false, false, false, false)`.

- [ ] **Step 1: Write the failing tests.**
  - `DriverFactory` supports `'prtg'`.
  - `listDevices()` keeps only devices whose group is in `prtg_groups`, exact and case-sensitive; an empty setting keeps all.
  - `listActiveAlarms()`:
    - calls `sensors(SensorParser::ALARM_CODES[<setting>])`;
    - filters by group;
    - uses the same run's devices for IPs, with one `devices()` call per run.
  - `alarmByKey('2001')` uses `sensor()` and ignores the group filter: alarm state gives the row, status 3 gives Clear, a missing sensor gives null.
  - `acknowledge('2001')`:
    - reads the sensor;
    - status 5 or 14 calls `acknowledge('2001', 'Acknowledged in GLPI')`;
    - status 13, 4, 10 or 3 makes no write call and returns normally;
    - a `PrtgApiError` becomes `AckRefused`; a plain `PrtgException` propagates.
  - `addNote()` and `clear()` throw `UnsupportedOperation`.
  - With `prtg_api` = `v2`: capabilities have ack false, and `acknowledge()` throws `UnsupportedOperation` with no write call (Review Focus 5).
  - Read methods never call `acknowledge` on the client.
  - `testConnection()` reports version and auth mode (with "API v2 (experimental, read-only)" for v2), never a secret.
  - **Seam test:** a fixture sensor in status 5, parsed by `SensorParser`, goes through `Outbound\Planner` (as in `tests/zabbix_outbound_seam_test.php`), then a fake outbox store, the real `Sender`, and the real `PrtgDriver` with a recording `ClassicClient` transport. Assert `acknowledgealarm.htm` gets `id=<sensor id>`, and that `entity_key === alarm_key`.
- [ ] **Step 2: Run.** Expect FAIL.
- [ ] **Step 3: Implement.** Adapt existing tests that assert the exact DEFAULTS list or the supported brands only by adding the new keys or brand.
- [ ] **Step 4: Run** both tests and the full suite. Expect PASS.
- [ ] **Step 5: Commit** `feat(prtg): PrtgDriver registered; per-API capabilities; outbox seam test`.

### Task 5: Form, new-connection defaults, hygiene titles

**Files:**
- Modify: `src/Connection/Settings.php` (`forNewConnection('prtg', $eid)`), `src/Config.php` (`sanitizePrtg()` next to `sanitizeZabbix()`), `src/Connection/ConnectionForm.php` (a PRTG block shown for saved brand `prtg`; save of the three keys), `src/Hygiene/Report.php` (brand `prtg`)
- Test: `tests/prtg_settings_test.php`; extend `tests/connection_form_test.php` (the "form edits every Settings key" check covers the prtg keys)

**Interfaces:**
- Produces `Settings::forNewConnection('prtg', $eid)`, which overrides exactly:
  - `ticket_title_pattern` `[PRTG-{alarm_key}] {severity}: {message} - {device}`;
  - `ticket_content_pattern` = the OpManager pattern with "OpManager alarm" → "PRTG alarm";
  - `ticket_source` `rules`;
  - `ticket_entity_default` `(string) $eid`;
  - `hygiene_unmanaged_availabilities` `["Paused"]`;
  - `prtg_api` `classic`, `prtg_groups` '', `prtg_alarm_states` `down+warning`.
- Produces `Config::sanitizePrtg(array $post): array`:
  - `prtg_api` ∈ {classic, v2}, else classic;
  - `prtg_alarm_states` ∈ the three keys, else `down+warning`;
  - groups trimmed per line, empty lines dropped, newline-joined.
- Produces hygiene titles for `prtg`: `[PRTG-HYGIENE] Monitored in PRTG, not in GLPI - <date>` and `[PRTG-HYGIENE] Unmanaged in PRTG: remove or check - <date>`. The body names PRTG the way the Zabbix body names Zabbix. OpManager and Zabbix output stays byte-identical.

- [ ] **Step 1: Write the failing test.**
  - The overrides above; `forNewConnection('opmanager')` and `forNewConnection('zabbix')` are unchanged.
  - The sanitizer cases.
  - Form pins: the PRTG block renders only for brand `prtg`. The API select has `classic` "Classic API" and `v2` "API v2 (experimental, read-only)". The help texts say:
    - groups: exact, immediate group, case-sensitive, empty = all;
    - credentials: API key preferred, sent as a header, or username + password.
  - Secret inputs keep `value=""`.
  - Prtg keys are saved only when the brand is `prtg` and the block was posted.
  - Hygiene titles for all three brands.
- [ ] **Step 2: Run.** Expect FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the test and the full suite. Expect PASS.
- [ ] **Step 5: Commit** `feat(prtg): form block, new-connection defaults, hygiene titles`.

### Task 6: Mock PRTG, runbook, CHANGELOG, version

**Files:**
- Create: `tests/mock_prtg/handler.php` (in-process: `nms_mock_prtg_handle(string $method, string $path, array $query, array $headers, ?string $body, string $line): array{status: int, body: string}`), `tests/mock_prtg/index.php` (thin `php -S 127.0.0.1:<port>` router), `tests/mock_prtg_test.php`, `docs/runbook-1.2.0.md`
- Modify: `CHANGELOG.md`, `setup.php` (`PLUGIN_NMS_VERSION` `'1.2.0-rc1'`), `tools/release_check.py` (usage `nmsv1.2.0-rc1.zip`)

**Interfaces:**
- The mock serves the classic routes (`/api/status.json`, `/api/table.json` by `content` and `filter_objid`, `/api/getpasshash.htm`, `/api/acknowledgealarm.htm`) and the v2 routes (`/api/v2/session`, `/api/v2/experimental/devices`, `/api/v2/experimental/sensors`) from the Task 3 fixtures. A request without a Bearer header or `username`+`passhash` (except `getpasshash.htm` and `POST /session`) gets HTTP 401 with an error body.

- [ ] **Step 1: Write the failing test.** `PrtgDriver` with the in-process mock transport, both APIs:
  - devices and alarms equal the parser outputs on the same fixtures;
  - a call without credentials is refused;
  - acknowledge on classic reaches the mock with the sensor id.
- [ ] **Step 2: Run.** Expect FAIL.
- [ ] **Step 3: Implement** the mock.
- [ ] **Step 4: Write the runbook and CHANGELOG.**
  - Runbook sections:
    1. Upgrade from 1.1.0: set aside nothing on the VM; `rm -rf plugins/nms`; unzip `/tmp/nmsv1.2.0-rc1.zip`; install `--force`; activate; cache clear; php-fpm reload; selfcheck; log out and in.
    2. OpManager and Zabbix unchanged.
    3. For a subsidiary: a read-only PRTG user and an API key (acknowledge right only if write-back is wanted); v2 needs PRTG 24.3+ and, before 26.4, the Application Server enabled.
    4. Add the connection: brand prtg → Save → API, groups, alarm states → Save → Test connection → tick Active.
    5. First sync as a test; Coverage per connection.
    6. Standing warning: no nms rights to subsidiary users.
  - `## 1.2.0-rc1 (nms)`:
    - the PRTG driver, with the lines covered (built from Paessler's documented API with synthetic fixtures, not verified against a live PRTG);
    - classic default; v2 experimental and read-only;
    - acknowledge only, on Down sensors;
    - deferred: v2 write-back, webhook.
  - Version bump and usage line.
- [ ] **Step 5: Run** the test and the full suite (`SUITES OK`), then commit `release: 1.2.0-rc1`. No tag: the controller tags after the whole-branch review.
