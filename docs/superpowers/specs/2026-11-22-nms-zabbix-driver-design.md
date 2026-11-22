---
date: 2026-11-22
tags: [nms, zabbix, driver, coverage, design]
status: approved
---

# nms 1.1.0: Zabbix driver and per-connection Coverage

Parent spec: `2026-11-05-nms-integration-design.md` (goals 1-7, §5.3 capability matrix, §5.4 severity, §5.10 hygiene,
§5.11 lessons). This document narrows it to the first subsidiary brand.

## 1. Intent

PGN runs nms 1.0.0 in production with one connection: its own OpManager. Subsidiaries run other NMS, and Zabbix is
the first brand the user wants. No subsidiary Zabbix access exists yet (user, 2026-11-22), so the driver is built
from the public Zabbix API documentation and tested on synthetic responses, then verified live when a subsidiary
opens access.

The user pointed at the NexTool Zabbix module (nextoolsolutions.com) as proven prior art. Its source is not public:
the modules download only after registering a GLPI instance with NexTool's server, and the module's licence is not
stated. **The user decided (2026-11-22) to build our own**, taking NexTool's feature choices as the benchmark and
writing no code from it. nms is GPL-3.0-or-later.

The user also asked that **Coverage show which connection, and which driver, each device comes from**, so the page
reads at a glance once there is more than one NMS.

## 2. Goals (1.1.0)

1. A `zabbix` driver implementing `GlpiPlugin\Nms\Driver\Driver`, so DeviceSync, AlarmSync, tickets, hygiene, the
   asset tab and Coverage work for a Zabbix connection with no change to their code paths.
2. Zabbix **6.0 LTS through the newest release on the build date** (parent spec: N back to N-3; 6.0 LTS is kept as the
   floor because NexTool also starts there). The builder records the exact versions covered in the CHANGELOG.
3. Version and auth detected on their own: `apiinfo.version` first, then the auth style that version takes.
4. Per-connection filters, as NexTool proves useful: **host groups**, **minimum severity**, **suppressed problems**
   (maintenance) excluded by default.
5. Write-back **note / acknowledge / close** through `event.acknowledge`, available but **off by default** (parent
   goal 6). Tickets for a Zabbix connection are **off by default** (parent goal 5).
6. Coverage, as agreed with the user:
   - a **Connection** column (connection name · brand) on every device list (Not in GLPI, Ambiguous, Name differs,
     Stale) and in the CSV export;
   - the **Not monitored** list shows which connection(s) *should* see the asset, from the asset's entity;
   - neutral labels: "OpManager devices" -> **"NMS devices"**, "not in OpManager" -> **"not in any NMS"** (page,
     dashboard cards, cron descriptions);
   - the NMS devices KPI card shows a **per-connection breakdown** ("OpManager PGN 933 · Zabbix X 120");
   - a **Connection filter** ("All connections" default). Picking one connection **recomputes Coverage %** for that
     connection alone: its devices, and the GLPI assets in its entity (and children when the connection is
     recursive), with that connection's own monitored scope.

7. **Matching stays inside the connection's entity scope**: a device is matched only to GLPI assets in the
   connection's entity, and in its children when the connection is recursive (parent spec §5.6b). Subsidiaries
   likely reuse private address ranges (10.x, 192.168.x), so without this a subsidiary's Zabbix host could match a
   PGN asset by IP. The production OpManager connection is on the **root entity with children included** (user,
   2026-11-22), so its matching does not change. A test pins that: root + recursive gives the same candidates as
   1.0.0's unrestricted lookup.
8. Brand-neutral wording where a Zabbix connection would otherwise show "OpManager" in the UI: asset tab headings
   and the stale-data notice (`src/AssetSections.php`), the "OpManager identity / entity" fields of the Device and
   Alarm lists. Ticket and hygiene texts of the existing OpManager connection stay exactly as they are (see §3).
9. Defaults for a **new Zabbix connection** (stored in its own settings; existing connections untouched): ticket
   title `[ZBX-{alarm_key}] ...` and content naming Zabbix; ticket source **Plugin rules** (Zabbix has no webhook in
   1.1.0, and the webhook source would create no tickets from polled alarms); hygiene ticket titles naming the brand
   (`[ZBX-HYGIENE] ...`). Tickets stay off by default.

## 3. Non-goals (1.1.0) and later

- **Entity isolation of what users see** (parent goal 9: no user sees another subsidiary's devices or alarms).
  Not in 1.1.0 (user, 2026-11-22: subsidiary users do not get nms access yet). Device/Alarm lists, the asset tab,
  Coverage device lists and the connection filter, and dashboard cards keep showing every connection to whoever has
  the nms rights. The runbook and the rc report say plainly: **do not give nms rights to subsidiary users** until the
  isolation release. Tickets of a Zabbix connection, when switched on, go to the connection's entity (not root).

- Matching by **serial, MAC, FQDN** (NexTool does this). Deferred to 1.2.0 (user chose approach 1): the Matcher is
  shared, and 1.1.0 must not change matching for the production OpManager connection. Zabbix hosts match by IP and
  name, as OpManager devices do. The driver already reads inventory serial/MAC and interface DNS into the device
  row's spare fields only if that needs no schema change; otherwise they wait for 1.2.0 (builder's call).
- **Webhook** (Zabbix media type). Deferred to 1.2.0; 1.1.0 is poll-only (`webhook` capability false).
- Dashboard cards "alarms without a ticket" and "hosts without an asset", and a bell notice when a connection fails.
  Candidates for 1.2.0.
- PRTG, LibreNMS, SolarWinds drivers.
- No change to ticket follow-up texts (e.g. "All OpManager alarms on this ticket are Clear."): they run on production
  tickets today. Neutral wording there is a later, separate change.

## 4. Anti-goals

- Any change in behaviour for the existing OpManager connection: device rows, matching, alarms, tickets, outbox,
  hygiene, webhook. Its tests must pass unchanged; Coverage for "All connections" with only OpManager active must
  give the same numbers as 1.0.0.
- Any call that writes to Zabbix while write-back is off for the connection.
- A real Zabbix URL or token used on staging without the user's explicit go (orchestrator).
- The API token or password echoed back in any page, log, error message or redirect.

## 5. Constraints

- GLPI 11, PHP 8.x, `ext-curl`, existing plugin layout (`src/Driver/<Brand>/`), PSR-4 `GlpiPlugin\Nms\`.
- No new tables. Per-connection Zabbix settings live in the connection's `settings` JSON (`Connection\Settings`),
  credentials in the existing encrypted `api_key` / `password` columns.
- Suite rule 11 (WAF) for every new or changed endpoint: ids and short keys in parameters; the token is sent once by
  POST and never echoed. The rc report lists each new endpoint and parameter.
- Shared lib stays at ConfigTab r5 / Menu 34f40cbb / AssetTab f8629cca; no new local copies.
- MySQL strict mode: no DEFAULT on TEXT columns, no `''` into TIMESTAMP columns (1.0.0 lessons). FakeDB tests as
  strict as MySQL.
- Release flow as 1.0.0: rc -> staging 2 synthetic test (backup, marks, tagged seed, cleanup, CHECKSUM-all-tables
  restore check) -> user -> production.

## 6. Approaches considered

1. **Staged: read first, then the rest (chosen by the user, 2026-11-22).** 1.1.0 = Zabbix driver (read, filters,
   version/auth detection, optional write-back) + Coverage per connection. 1.2.0 = serial/MAC/FQDN matching and
   webhook. Small first release, nothing that production OpManager uses changes.
2. **NexTool parity at once.** Adds serial/MAC/FQDN matching to 1.1.0. Better matching for Zabbix from day one, but
   it changes the shared Matcher right after the OpManager cutover. Rejected for now.
3. **Generic JSON-RPC base first.** A shared client layer for Zabbix, LibreNMS, PRTG. Their APIs differ in shape
   (JSON-RPC vs REST vs query strings), so it delays Zabbix without saving real work. Rejected.

## 7. Design

### 7.1 Driver (`src/Driver/Zabbix/`)

Same structure as OpManager: `ZabbixDriver` (implements `Driver`, injectable transport as `OpManagerDriver` has),
`HostParser`, `ProblemParser`, a small `JsonRpc` client (request id, error object -> exception with a safe message).
`DriverFactory::for()` and `supportedBrands()` gain `'zabbix'`.

Capabilities: devices yes, alarms yes, availability yes, note/ack/clear yes, webhook **no**, writeback yes.

**Endpoint.** The connection URL may be the Zabbix front end base (`https://zbx.example/zabbix`) or the full
`.../api_jsonrpc.php`; the driver appends `/api_jsonrpc.php` when missing. JSON-RPC 2.0, `Content-Type:
application/json-rpc`.

**Version and auth** (verify each version boundary against the official API docs before coding):
- `apiinfo.version` is called first, **without** credentials (Zabbix rejects auth on that method).
- API token (connection `api_key`) is preferred. From 6.4 it goes in the `Authorization: Bearer` header; before 6.4 in
  the request's `auth` property. From 7.2 the `auth` property is gone, so the header is the only way.
- Username/password (connection `username`/`password`) when no token: `user.login` with `username` (6.0+), the
  session id used like a token for the rest of the run, `user.logout` at the end of the run (best effort).
- Version-dependent parameters handled in one place (e.g. host groups: `selectHostGroups` from 6.2, `selectGroups`
  before).
- Test connection reports the detected version and auth mode, never the credential.

**Devices** (`listDevices`): `host.get` with interfaces, host groups and inventory, filtered by the connection's host
groups (names resolved to ids with `hostgroup.get`; empty list = all hosts the token can read). Row mapping, same shape
as `DeviceParser::parse()`:

| row field | Zabbix source |
|---|---|
| `device_key` | `hostid` |
| `name` | `host` (technical name) |
| `display_name` | `name` (visible name) |
| `ip_address` | main interface `ip` (first by type Agent, SNMP, IPMI, JMX), else `dns` |
| `device_type` | inventory `type` when filled, else the main interface type label |
| `device_category` | first host group name (sorted), for Coverage's category filter |
| `availability` | `Disabled` when `status` = 1; `Maintenance` when `maintenance_status` = 1; else main interface `available`: `Available` / `Unavailable` / `Unknown` |

Hygiene: a new Zabbix connection's "not monitored" availabilities default to `["Disabled"]` (parent §5.10: Zabbix
disabled host). Editable per connection as today.

**Problems** (`listActiveAlarms`): `problem.get` (unresolved, filtered by host groups, `severities` >= the
connection's minimum, `suppressed` false unless the connection includes suppressed), then the hosts for those
problems (`problem.get` returns no hosts: use `event.get` with `selectHosts`, or `trigger.get`; builder's call,
fewest calls). Row mapping, same shape as `AlarmParser::parse()`:

| row field | Zabbix source |
|---|---|
| `alarm_key` | `eventid` |
| `entity_key` | `eventid` (erratum 2026-11-22: the outbox writes to entity_key, so it must be the event id) |
| `device_name` / `display_name` | host `host` / `name` |
| `ip_address` | host main interface ip, when fetched cheaply; else '' |
| `category` | first host group name |
| `alarm_code` | `objectid` (trigger id) |
| `event_type` | the trigger description as defined (macros unexpanded), so one trigger gives one stable entry in the alarm filter's "event types" list |
| `severity` / `severity_label` | mapped number (below) / Zabbix label (`Disaster`, `High`, ...) |
| `message` | problem `name` (+ opdata when present) |
| `mod_time` | `clock` as `Y-m-d H:i:s` |

**Severity mapping** to the nms common scale (my call, parent §5.4; the ticket severity filter works on these
numbers): Disaster 5 -> 1 Critical; High 4 -> 1 Critical; Average 3 -> 2 Trouble; Warning 2 -> 3 Attention;
Information 1 and Not classified 0 -> 3 Attention (shown only when the minimum severity lets them through; the
default minimum is Warning). Clear (5) is used only for a resolved event. The label stays the Zabbix label so people
see Zabbix's own words.

**Current state** (`alarmByKey`): `event.get` by `eventid` with hosts and `r_eventid`. Resolved (`r_eventid` != 0)
-> the row with severity 5 / label `Clear`; not found -> null.

**Write-back** (only when the connection's write-back is on; otherwise the outbox never calls the driver, as today):
`event.acknowledge` with the action bitmask: note = add message (4), acknowledge = 2 (+4 when a message is given),
clear = close (1). Closing fails when the trigger does not allow manual close: the driver raises
`UnsupportedOperation` with that reason, which the outbox records as a permanent failure (no retry).

### 7.2 Connection form

- Brand `zabbix` is selectable (it already exists in `Connection::BRANDS`); switches the brand cannot do stay greyed
  out from `Capabilities` (webhook tab).
- Connection tab, Zabbix block (shown for brand zabbix): API token (preferred) or username/password; host groups (one
  per line, names); minimum severity (select, default Warning); "Include suppressed problems" (default off). The
  token/password inputs follow the existing pattern: empty = keep, value posted once, never rendered back.
- Defaults for a new Zabbix connection: inactive, tickets off, write-back off.

### 7.3 Coverage

- `DbCoverageSource::devices()` adds `connections_id` to each row; the page resolves names/brands from the
  connection store (no join on secrets).
- Connection filter: `connection` GET parameter (integer id, 0 = all); the CSV export carries it. Only active
  connections are offered.
- With one connection picked: devices of that connection; assets restricted to that connection's entity (+ children
  when `is_recursive`), intersected with the user's own entity rights; monitored scope (coverage states, production
  ranges) from that connection's settings.
- "All connections": devices of every active connection; assets as in 1.0.0. With only the OpManager connection
  active this must produce exactly the 1.0.0 numbers (test).
- Not monitored list: column "Expected from" = the active connections whose entity scope contains the asset's
  entity (names, comma separated; "none" when no connection covers it).
- KPI card "NMS devices": total, plus one line with the count per connection.
- Dashboard cards: labels neutral; cards keep counting every active connection (no per-card connection option in
  1.1.0).

### 7.4 Tests

- Fixture JSON per Zabbix major line (6.0 LTS, 7.0 LTS, newest), written from the documented response shapes, with
  synthetic names and RFC 5737 / private test IPs only. Fake transport records each request so tests assert method,
  params and where auth went (header vs `auth`).
- Driver tests: version detection, both auth modes, host and problem mapping, host-group and severity filters,
  suppressed excluded by default, `alarmByKey` resolved/missing, each write-back bitmask, close refused -> permanent
  failure, error object -> safe message without the token.
- Coverage tests: connection column, filter recompute per connection, "All connections" with only OpManager equals
  1.0.0 output, "Expected from" on Not monitored, CSV carries the connection.
- Existing OpManager tests unchanged and green.

### 7.5 Staging and release

- 1.1.0-rc1 on staging 2 with the usual procedure. To run the real cron path without a real Zabbix, a mock JSON-RPC
  endpoint serving the fixtures (PHP built-in server on localhost, kept under `tests/`, never shipped) backs a
  synthetic Zabbix connection. My call; the builder may choose another way to exercise sync end to end.
- Runbook `docs/runbook-1.1.0.md`: upgrade from 1.0.0 (no cutover), how a subsidiary creates a read-only API token
  (user role with API access and read on its host groups), how to add the connection, Test connection, first sync,
  what to check on Coverage.
- The rc report lists new or changed endpoints: `front/coverage.php?connection=<id>` (and its CSV export);
  connection form fields for Zabbix (POST only).

## 8. Decided by the user

- Build our own driver; NexTool is a feature benchmark only (2026-11-22).
- Approach 1: staged, matching by serial/MAC/FQDN and webhook in 1.2.0 (2026-11-22).
- Coverage: connection column and filter, neutral labels, per-connection breakdown, Coverage % recomputed for a
  picked connection (2026-11-22).
- No entity isolation of views in 1.1.0; subsidiary users get no nms access yet (2026-11-22).
- The production OpManager connection is root entity, children included (2026-11-22), which lets matching be
  limited to each connection's entity scope in 1.1.0.

## 8b. My calls (for the user to check)

- Severity mapping Disaster/High -> Critical, Average -> Trouble, Warning/Information/Not classified -> Attention;
  default minimum severity Warning; suppressed problems excluded by default.
- Neutral UI labels (goal 8); OpManager ticket and hygiene texts unchanged.
- New Zabbix connection defaults: `[ZBX-...]` titles, ticket source Plugin rules, tickets to the connection's entity.
- Staging test through a local mock Zabbix endpoint serving the synthetic fixtures.

## 9. Left to the builder

- Exact Zabbix versions on the build date, and the exact boundary for each version-dependent parameter (from the
  official API docs).
- How problems get their hosts (fewest API calls).
- Class split inside `src/Driver/Zabbix/`, fixture layout, mock endpoint details.
- Whether inventory serial/MAC/DNS are kept anywhere in 1.1.0 without a schema change.
