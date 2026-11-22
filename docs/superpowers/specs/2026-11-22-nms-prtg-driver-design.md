---
date: 2026-11-22
tags: [nms, prtg, driver, design]
status: approved
---

# nms 1.2.0: PRTG driver (classic API, experimental read-only API v2)

Parent specs: `2026-11-05-nms-integration-design.md` (§5.3 capability matrix, §5.4 severity, §5.10 hygiene) and
`2026-11-22-nms-zabbix-driver-design.md` (the first subsidiary driver; everything it built is reused here).

## 1. Intent

Subsidiaries run PRTG as well as Zabbix. nms 1.1.0 already has what a second subsidiary brand needs outside the
driver: entity-scoped matching, Coverage per connection (column, filter, per-connection %), neutral labels, per-brand
new-connection defaults, an offline mock for staging tests, and the release flow. 1.2.0 adds the **PRTG driver**.

No subsidiary PRTG access exists. **The user decided (2026-11-22): no PRTG install on the laptop** (the orchestrator
advised against it: 0.4 GB free RAM, corporate-laptop policy, PRTG auto-discovery). The driver is built from Paessler's
documented API and synthetic fixtures, tested through an offline mock, and needs **no live verification before the
final** (same decision as 1.1.0).

PRTG has two APIs. **The user decided (2026-11-22): the GLPI admin picks the API per connection.** Both clients are
built in 1.2.0:
- **classic** (the HTTP API `/api/...`, every PRTG in use) — the default, full feature set;
- **v2** (REST `/api/v2`, from PRTG 24.3, enabled automatically only from 26.4; its device/sensor lists are still
  under `/experimental/`, which Paessler says may change between releases, and no documented alarm-acknowledge
  endpoint was found) — offered as **"API v2 (experimental, read-only)"**: devices and alarms only, no write-back.

## 2. Goals (1.2.0)

1. A `prtg` driver implementing `GlpiPlugin\Nms\Driver\Driver`, so DeviceSync, AlarmSync, tickets, hygiene, the asset
   tab and Coverage work for a PRTG connection with no change to their code paths.
2. The driver uses an **API client chosen per connection** (setting `prtg_api`: `classic` default, `v2`). Both share
   the parsers' output shapes.
3. PRTG **26.x lines N back to N-3 on the build date**. Latest stable found: 26.4.126.1824 (2026-11-12); earlier
   26.3, 26.2 lines. The builder verifies the exact lines and records them in the CHANGELOG.
4. **Auth** (both APIs): an **API key** in the existing `api_key` secret field, sent as `Authorization: Bearer <key>`
   (header, never in the URL), is the documented and preferred way. Without a key: **username + password**
   (`username` and the `password` secret field): classic turns it into a passhash once per run (`getpasshash.htm`) and
   sends `username`+`passhash` as query parameters; v2 gets a bearer token once per run (`POST /api/v2/session`). The
   discriminator is simply: `api_key` non-empty → key mode; else login mode, which requires both username and
   password (empty → a clear error, no request). One login attempt per run (the Zabbix lockout lesson).
5. Per-connection filters: **groups** (PRTG group names, one per line, exact match on a device's or sensor's immediate
   group, case-sensitive; empty = everything the account can read) and **alarm states** (`down`, `down+warning`
   default, `down+warning+unusual`).
6. Write-back (classic only): **acknowledge**. Note and clear are not offered (capabilities `note` false, `clear`
   false). Write-back **off by default**; tickets **off by default**. With `prtg_api` = `v2`, the driver reports
   `ack` false too, so the UI greys write-back out and the outbox never queues it.
7. Webhook (PRTG HTTP notification) is **not** in 1.2.0.

## 3. Non-goals and later

- Write-back over API v2 (until Paessler documents a stable acknowledge endpoint); v2 leaving "experimental".
- Webhook; serial/MAC/FQDN matching; entity isolation of views (still: no nms rights to subsidiary users).
- A PRTG install on the laptop (user, 2026-11-22).

## 4. Anti-goals

- Any behaviour change for the OpManager or Zabbix connections (their tests stay green unchanged).
- A write to PRTG while write-back is off, or over API v2 at all.
- The API key, password, passhash or v2 session token echoed back anywhere: page, log, exception, redirect. Classic
  login mode carries `passhash` in the query string, so **every URL that can reach an error message, a log line or an
  exception is redacted** before it leaves the client (transport errors included); no previous-exception chaining
  (the Zabbix lesson); `#[\SensitiveParameter]` on secret parameters.
- A real PRTG URL or credential on staging without the user's explicit go.

## 5. Constraints

- No new tables. PRTG settings in the connection `settings` JSON; credentials in the encrypted `api_key` / `password`
  columns.
- Suite rule 11 (WAF): no new front endpoint; the connection form's PRTG fields go by POST only; secrets never echoed.
- Same release flow and staging procedure as 1.1.0 (orchestrator's go first; backup, marks, mock connection,
  cleanup, CHECKSUM-all-tables restore check).
- Shared lib unchanged (ConfigTab r5, Menu 34f40cbb, AssetTab f8629cca).

## 6. Approaches considered

1. **Classic only.** Works on every PRTG in use; no v2 path for subsidiaries on a recent PRTG.
2. **v2 only.** Cleaner JSON, but recent PRTG only, lists still experimental, no acknowledge endpoint found.
3. **Admin chooses per connection; classic default, v2 experimental read-only (chosen by the user, 2026-11-22).**

## 7. Design

### 7.1 Classes (`src/Driver/Prtg/`)

`PrtgDriver` (implements `Driver`), `PrtgClient` (interface: `version(): string`, `authMode(): string`,
`devices(): array`, `alarmSensors(array $statusCodes): array`, `sensor(string $objid): ?array`,
`acknowledge(string $objid, string $message): void`), `ClassicClient`, `V2Client` (acknowledge throws
`UnsupportedOperation`), pure parsers `DeviceParser` / `SensorParser` (normalised rows in, nms rows out; each client
normalises its own response first), `PrtgException` + `PrtgApiError` (API-level refusal), `AckRefused`
(`\DomainException`, permanent in the outbox). `DriverFactory` gains `'prtg'`, building the client from `prtg_api`.

Capabilities: classic `(devices, alarms, availability, note=false, ack=true, clear=false, webhook=false,
writeback=true)`; v2 the same with `ack=false`, `writeback=false`.

### 7.2 Classic API (verify every name against the PRTG manual for the lines in scope)

- version: `/api/status.json` (`Version`); if absent in a line, the version from the `table.json` envelope
  (`prtg-version`).
- devices: `/api/table.json?content=devices&columns=objid,device,host,group,probe,status,status_raw,tags&count=*`.
- alarm sensors: `/api/table.json?content=sensors&columns=objid,sensor,device,parentid,group,probe,status,status_raw,message,lastcheck,type&filter_status=<codes>&count=*`.
- one sensor: the same table with `filter_objid=<id>` (no status filter).
- acknowledge: `/api/acknowledgealarm.htm?id=<objid>&ackmsg=<text>`.
- passhash: `/api/getpasshash.htm?username=&password=` (login mode only, once per run).

**Status codes** (`status_raw`; verify): Up 3, Warning 4, Down 5, Paused (7 by user, 8 by dependency, 9 by schedule,
11 by license, 12 until), Unusual 10, Down (Acknowledged) 13, Down (Partial) 14, Unknown 1. Alarm-state filter:
`down` = 5, 13, 14; `down+warning` adds 4; `down+warning+unusual` adds 10.

**Times**: `lastcheck_raw` (OLE automation date) converted to `Y-m-d H:i:s`; the builder confirms from the manual
whether raw dates are UTC and converts to GLPI's timezone; when missing, the sync time is used.

### 7.3 API v2 (experimental)

Base `<url>/api/v2`, `Authorization: Bearer <key or session token>`. Devices and sensors from the documented
`/experimental/devices` and `/experimental/sensors` (with their documented filters for status). Version from the
documented settings/info endpoint (builder's choice from the OpenAPI reference). No write calls. The UI and the
Test connection message say "API v2 (experimental, read-only)".

### 7.4 Mapping (both APIs, via the parsers)

Devices (same shape as the OpManager `DeviceParser`):

| row field | PRTG source |
|---|---|
| `device_key` | device objid / id |
| `name` / `display_name` | device name |
| `ip_address` | `host` (IP or DNS name, as PRTG holds it) |
| `device_type` | first tag when tags are set, else '' |
| `device_category` | immediate group name |
| `availability` | status text (Up, Down, Warning, ...); every Paused variant -> `Paused` |

Hygiene: a new PRTG connection's not-monitored availabilities default to `["Paused"]`.

Alarms (same shape as the OpManager `AlarmParser`), one row per sensor in an alarm state:

| row field | PRTG source |
|---|---|
| `alarm_key` | sensor objid |
| `entity_key` | sensor objid — **the outbox write key** (1.1.0 lesson: `Outbound\Sender` writes to `entity_key`; acknowledge targets the sensor id). A seam test from day one proves it. |
| `device_name` / `display_name` | parent device name |
| `ip_address` | the parent device's `host` (from the device list of the same run; '' when unknown) |
| `category` | immediate group name |
| `alarm_code` | sensor objid |
| `event_type` | sensor type (e.g. "Ping", "SNMP Traffic") — stable per sensor kind, for the alarm filter list |
| `severity` / `severity_label` | mapped (below) / PRTG status text |
| `message` | sensor name + ": " + PRTG message (HTML tags stripped) |
| `mod_time` | last check time (§7.2 Times) |

**Severity**: Down, Down (Partial), Down (Acknowledged) -> 1 Critical; Warning, Unusual -> 3 Attention. Clear (5)
only from `alarmByKey`.

**`alarmByKey`** (current state of one sensor, no group filter): in an alarm state -> the row; any other state
(Up, Paused, ...) -> the row with severity 5 / `Clear`; not found (deleted) -> null (existing AlarmSync behaviour).

**Acknowledge** (classic): only a sensor in a Down state (5, 14) can be acknowledged in PRTG. `acknowledge()` reads
the sensor first: Down/Down (Partial) -> `acknowledgealarm.htm` with "Acknowledged in GLPI"; already Down
(Acknowledged), Warning, Unusual or any other state -> no API call, success (a no-op, logged in the driver's message
as "nothing to acknowledge"). An API-level refusal -> `AckRefused` (permanent); transport/HTTP errors retry. Nothing
re-acknowledges on its own: the outbox only carries human actions (Planner).

### 7.5 Connection form and defaults

- PRTG block (shown for a saved brand `prtg`, as Zabbix): API select (`classic` "Classic API", `v2` "API v2
  (experimental, read-only)"), groups (one per line; help: exact, immediate group, case-sensitive, empty = all),
  alarm states (select). Help text on credentials: API key (preferred, sent as a header) or username + password.
- `Settings::forNewConnection('prtg', $eid)`: ticket title `[PRTG-{alarm_key}] {severity}: {message} - {device}`,
  content naming PRTG, ticket source `rules`, ticket entity = the connection's entity, hygiene unmanaged
  availabilities `["Paused"]`, `prtg_api` `classic`, `prtg_groups` '', `prtg_alarm_states` `down+warning`. Hygiene
  ticket titles `[PRTG-HYGIENE] ...` (brand parameter exists).
- Settings DEFAULTS gain `prtg_api` 'classic', `prtg_groups` '', `prtg_alarm_states` 'down+warning'.

### 7.6 Tests

- Fixtures per 26.x line in scope (classic) and one v2 set, synthetic names, RFC 5737 IPs, from the documented shapes.
- Clients: key in the header (never in the URL); login mode -> one passhash/session call per run, empty credentials ->
  error and no request; **every message free of key, password, passhash, session and of any URL carrying them**
  (positive tests); no previous exception; version detection.
- Parsers: the tables above, every Paused variant, a DNS host, a sensor whose device is unknown, HTML in messages.
- Driver: group and alarm-state filters, `alarmByKey` alarm/clear/missing, acknowledge on Down vs no-op on others,
  refusal -> permanent, note/clear refused, v2 has no write path, reads never write.
- **Seam test**: a parsed PRTG alarm through Planner -> Sender -> PrtgDriver acknowledges `id=<sensor objid>`.
- Mock: `tests/mock_prtg/` (in-process handler + thin `php -S 127.0.0.1` wrapper; classic and v2 routes), refusing
  calls without credentials.
- OpManager and Zabbix tests unchanged.

### 7.7 Release

1.2.0-rc1 -> staging 2 (orchestrator's go; mock PRTG connection, cleanup, restore check) -> 1.2.0 final (no live
verification). Runbook `docs/runbook-1.2.0.md`: upgrade from 1.1.0; for a subsidiary: a read-only PRTG user and an
API key (acknowledge right only if write-back is wanted; v2 needs PRTG ≥ 24.3 and the Application Server enabled
before 26.4); add the connection, Test connection, first sync as a test; the standing warning on subsidiary users'
nms rights.

## 8. Decided by the user (2026-11-22)

- The GLPI admin picks classic or v2 per connection; v2 experimental and read-only; classic default.
- No PRTG install on the laptop; build from documentation and a mock; no live verification before final.
- Every proposal in the design conversation accepted (including the calls below).

## 9. Calls made by the designer (from the builder check)

- Auth discriminator: `api_key` non-empty = key mode; else username + password; no stored passhash.
- Acknowledge only Down sensors; other states are a no-op success.
- Status-code sets per alarm-state option as in §7.2; Down (Acknowledged) stays listed as an active alarm.
- Times from `lastcheck_raw`, converted; sync time when missing.
- Groups: exact, immediate, case-sensitive, post-filtered in PHP.
- `alarmByKey` ignores the group filter; Paused -> Clear; deleted -> null.

## 10. Left to the builder

- Exact PRTG lines and parameter/column/endpoint names (classic and v2 OpenAPI) on the build date.
- Whether raw dates are UTC.
- Class split details, fixture layout, mock details.
