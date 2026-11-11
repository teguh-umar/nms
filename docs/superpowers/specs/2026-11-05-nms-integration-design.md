# NMS Integration (`nms`) — design

Date: 2026-11-05, revised 2026-11-10 · Status: **approved by the user 2026-11-10** · Base: **opmanager 1.3.2** (`67118af`,
scrubbed history; final and last opmanager release, live on VM-HQ-GLPI per user screenshot 2026-11-10)

> Revision 2026-11-10: the first draft was based on 1.2.0 (`e9f8eaa`), which this folder was cloned from. opmanager
> moved on to 1.3.2 (D34 NMS hygiene, ticket lessons). The baseline, parity list, data model and migration below follow
> 1.3.2. Source for D34: opmanager `docs/superpowers/specs/2026-11-06-opmanager-unmanaged-hygiene-design.md` (§8 is the
> migration list); lessons: hub §7 entry 2026-11-10 "opmanager frozen at 1.3.2; lessons for nms".

## 1. Intent

PGN watches its own network with ManageEngine OpManager through the `opmanager` plugin. The subsidiaries
(anak perusahaan) run their own NMS: **PRTG, Zabbix, LibreNMS, SolarWinds**. PGN has no admin login to those systems.
PGN wants one GLPI plugin that brings every NMS into GLPI the same way: devices matched to GLPI assets, and alarms and
availability on the asset tab. It replaces `opmanager`. It is one plugin, not one per brand, so that an asset watched
by two NMS can never open two tickets.

## 2. Goals

1. One plugin `nms` ("NMS Integration") with a **driver per brand**. Release 1 brands: **OpManager, PRTG, Zabbix,
   LibreNMS, SolarWinds**. Nagios, OpenNMS and Motadata come later, as new drivers with no core change.
2. **API first.** Every brand is reached through its API, pulled by cron, as opmanager does today. Each subsidiary
   creates an API account or token for PGN and opens the network path from the GLPI VM to its NMS. A webhook is an
   optional extra per brand, never the main path.
3. **Connections per entity** (hub §4 rule 3). A connection has a brand, URL, credentials, verify-TLS and a GLPI
   entity. One entity may hold several connections.
4. **OpManager parity.** Everything opmanager 1.3.2 does keeps working, including D34 NMS hygiene (§5.10), for the PGN OpManager connection: device sync,
   AlarmSync, webhook receiver, ticket engine (filters, storms, relink window, production ranges, Closed is final
   (D30)), outbound note/ack/clear outbox (D24–D27), Coverage page, dashboard cards, asset-tab section, Setup,
   self-check, ICT Tools menu entry.
5. **Subsidiary alarms are shown, not ticketed.** For subsidiary connections, alarms appear on the asset tab (and in
   the alarm list or dashboard). **Ticket creation is a per-connection switch**: on for PGN OpManager, **off by
   default** for the others. The engine stays brand-neutral so turning it on later needs no release.
6. **Write-back is optional per connection.** Each connection is read-only by default. Note, ack and clear to the NMS
   can be switched on per connection, only when the brand's driver supports it and the subsidiary's token allows it.
7. **Match, and create only after approval.** Device sync matches NMS devices to existing GLPI assets in the
   connection's entity. An unmatched device goes into a **"waiting for approval" list**. An approver approves one by
   one or in bulk, picking the asset type (NetworkEquipment, Computer, or a GLPI 11 custom asset). The asset is then
   created in the connection's entity and linked. Rejected devices are remembered and stay hidden until the rejection
   is undone.
8. **Approval is a profile right** (e.g. `plugin_nms_approve`), entity-scoped. A central PGN profile with recursive
   entities approves for every subsidiary. A subsidiary user approves only for their own entity and sees only that
   entity's devices.
9. **Entity isolation everywhere** (closes opmanager's open entity audit A–G, hub §4 rule 16). Device, alarm,
   approval and Coverage lists all restrict by entity. No user sees another subsidiary's devices or alarms.
10. **Migration from opmanager, then retirement.** See §6.

## 3. Non-goals

- Tickets from subsidiary alarms in release 1 (the switch exists, the decision is the user's later).
- Nagios, OpenNMS, Motadata drivers in release 1.
- Admin of the subsidiaries' NMS (creating monitors, thresholds) from GLPI.
- A riskregister feed (unchanged from opmanager, user 2026-09-23).

## 4. Anti-goals (a failure even if it works)

- Two tickets for one asset because two connections see the same problem.
- A subsidiary user seeing another subsidiary's devices, alarms or pending approvals.
- GLPI creating assets without someone approving them.
- Any write to a subsidiary NMS that its connection did not switch on.
- `opmanager` and `nms` both acting on the same OpManager alarm at any moment of the cutover.
- One failing connection stopping the sync of the others.

## 5. Architecture

### 5.1 Identity (claimed in hub §2, 2026-11-05)

Key/dir `nms`, PSR-4 `GlpiPlugin\Nms\` → `src/`, tables `glpi_plugin_nms_*` (new; not opmanager's), rights and config
context `plugin:nms`, display name "NMS Integration". **Search option IDs, AssetTab order and Menu order are requested
from the orchestrator**; opmanager keeps its own until uninstalled. **Allocated by the orchestrator (hub §7
2026-11-10):** search option IDs **8380–8389** on core asset types, **AssetTab order 65**, **Menu order 65**, **PGN
Suite config tab order 75**. The cloned code still carries opmanager's 60/60/70; they must change to these values,
since opmanager and nms run side by side until cutover.

### 5.2 Driver interface

Each brand is one driver class under `src/Driver/<Brand>/`, implementing one interface. Indicative shape (the builder
fixes names):

- `capabilities(): Capabilities` — static matrix entry (below).
- `testConnection(Connection): Result`
- `fetchDevices(Connection): iterable<NmsDevice>` — brand-neutral device: external id, name, IPs, serial, type/category,
  status.
- `fetchActiveAlarms(Connection, ?since): iterable<NmsAlarm>` — brand-neutral alarm: external id, device external id,
  severity mapped to a common scale, message, category, raised/cleared time.
- `fetchAvailability(Connection, device, period)` where supported.
- optional `addNote`, `acknowledge`, `clear` — called only if capability and connection switch allow.
- optional `parseWebhook(request): NmsAlarm[]` for brands with a webhook.

The existing OpManager code (`src/OpManager/*`: Client, parsers, AlarmSource, AlarmWriter, ResponseGuard) becomes the
OpManager driver. The HTTP layer stays on ext-curl (opmanager 0.2.2: telegrambot's Guzzle 6 breaks Guzzle).

### 5.3 Capability matrix (to verify per brand during build)

| | Devices | Alarms | Availability | Note | Ack | Clear | Webhook |
|---|---|---|---|---|---|---|---|
| OpManager | yes | yes | yes | yes | yes | yes | yes (verified, live) |
| PRTG | yes (`table.json` devices) | yes (sensors down/warning) | yes (historic data) | no | yes (acknowledge alarm) | no | yes (HTTP notification) |
| Zabbix | yes (`host.get`) | yes (`problem.get`) | yes (SLA / history) | yes (`event.acknowledge` message) | yes | yes (close problem, if trigger allows) | yes (media type) |
| LibreNMS | yes (`/devices`) | yes (`/alerts`) | yes (availability) | yes (alert note) | yes (`/alerts/:id` ack) | no | yes (API transport) |
| SolarWinds Orion | yes (SWIS `Orion.Nodes`) | yes (`Orion.AlertActive`) | yes (`ResponseTime`/availability) | yes (`AppendNote`) | yes (`Acknowledge`) | yes (`ClearAlert`) | yes (HTTP alert action) |

**No subsidiary API access exists yet (user, 2026-11-05).** Each non-OpManager driver is built from the vendor's
API documentation and recorded sample responses (fixtures), and supports **the current major version N back to N-3**
of its brand (user). Where auth or endpoints differ across that range (e.g. Zabbix `user.login` vs API tokens), the
driver detects the version (or takes it from the connection) and handles each. Tests run on fixtures per supported
version. Live verification happens per subsidiary when its access opens, via the connection's Test button and a
runbook. The builder records which exact versions N..N-3 are on the build date for each brand. The UI greys out a
switch the brand cannot do.

### 5.4 Severity

Each driver maps its brand's levels to one common scale (Critical, Major/Trouble, Warning/Attention, Down,
Info/Clear). Ticket severity filters work on the common scale. The OpManager mapping must reproduce today's
`ticket_severities` behaviour exactly.

### 5.5 Data model (indicative)

- `connections`: brand, name, entity, url, secured credentials (GLPIKey, `secured_fields`), verify_tls, enabled,
  read_only/write-back switch, tickets switch, webhook token, last sync times, status and error.
- `devices`: connection, external id, name, IPs, serial, type, status, linked itemtype/items_id, match state
  (`matched`, `pending`, `approved`, `rejected`, `manual`).
- `alarms`, `outbox`, `storms`, `ticketrequests`: as opmanager's, plus `connections_id`.
- Ticket settings (template, title/content pattern, service user, filters, storms, production ranges) move from global
  config to **per connection**, with the PGN OpManager connection carrying today's values.

### 5.6 Cross-NMS dedup

Ticket dedup keys on the **GLPI asset** (itemtype + id), not on the NMS alarm id: an open ticket for an asset absorbs
an alarm on that asset from any connection (as a followup and link). Designed and unit-tested now, exercised in
production only when a second connection turns tickets on. **My call:** dedup and absorb sit behind the tickets switch.
An alarm from a connection with tickets off never touches any ticket (no followup on PGN's ticket), so one
subsidiary's alarm text never lands in another entity's ticket.

### 5.6b Entity scope of a connection (my call)

A connection has an entity and an "include sub-entities" flag. Matching, lists and approval cover that scope. The
migrated PGN OpManager connection takes the entity that opmanager 1.3.2 effectively covers (its devices' and assets'
entities; the root entity, recursive, if they span several), so no existing link falls outside it. A subsidiary
connection defaults to its own entity, recursive.

### 5.6c Approval applies to every connection (my call)

opmanager 1.3.2 never creates assets (it only matches; D34 reports unregistered devices in a ticket). So the approval gate is new for all connections, PGN
OpManager included, and changes no existing behaviour.

### 5.7 UI

- **Setup:** list of connections (add, test, enable, switches), per-connection ticket settings; global settings that
  stay global (asset itemtypes, stale warning).
- **Pending approval page:** per connection, entity-restricted, approve (pick asset type) / reject / undo reject,
  bulk actions.
- **Asset tab section:** alarms and availability from every connection linked to the asset, brand shown.
- Device, alarm, Coverage pages and dashboard cards gain a connection/brand filter and entity restriction.
- One ICT Tools menu entry "NMS Integration".

### 5.8 Cron

`DeviceSync`, `AlarmSync`, `OutboxSend` loop over enabled connections. One connection failing is logged and recorded
on that connection; the others continue. Locks per connection.

### 5.9 Rights

`plugin_nms_config` (Setup and connections), `plugin_nms_monitoring` (view devices, alarms, tab, cards),
`plugin_nms_approve` (approval page). Migration copies each profile's opmanager rights onto the matching nms rights;
`approve` is given to profiles that held opmanager `config` UPDATE.

### 5.10 NMS hygiene (D34, from opmanager 1.3.x)

Carried over as specified in the opmanager D34 spec, made per connection: classification A/A'/B/C/D/E/X from each
device's monitoring status, liveness check (agent, Mist, TCP probe, `liveness` hook renamed `nms_liveness`), device
exceptions, weekly hygiene tickets (GLPI flow and NMS flow) with "new / resolved" followups, `HygieneCheck` cron,
`plugin_nms_hygiene` right. Each driver maps its brand's "not monitored" states (OpManager: `UnManaged`,
`Device Not Monitored.`; PRTG paused; Zabbix disabled host; LibreNMS disabled/ignored; SolarWinds unmanaged) to the
common "not monitored" flag; the per-connection list stays editable as in `hygiene_unmanaged_availabilities`.

My calls to fit D34 with this spec:
- Class A ("monitored, not in GLPI") devices are exactly the approval list of goal 7. Approving one creates the asset
  and clears it from the next hygiene ticket; rejecting it does **not** create an exception (exceptions stay a separate,
  explicit action with a reason).
- Hygiene **tickets** follow the per-connection tickets switch: on for PGN OpManager (today's settings migrated), off by
  default for subsidiaries. Classification and the hygiene block still show for every connection.
- Hygiene settings (groups, users, ITIL category, frequency, probe CIDRs) move to per connection.

### 5.11 Lessons from opmanager production (hub 2026-11-10, binding)

1. Hygiene/report tickets are created with `_skip_rules => true`.
2. Technicians are added after Ticket add via `Ticket_User` ASSIGN, never `_users_id_assign` in the add input
   (Behaviors "technician's group = First").
3. Assign at most ONE group (Escalade removes earlier assigned groups); other groups go in as observers.
4. The default Request template hides observers; the runbook tells the admin.
5. Optional ITIL category per connection on hygiene tickets (Behaviors may require one before Solve).
6. Ticket lists split by domain: Server/VM/storage (Computer), Network (NetworkEquipment), Unknown.
7. Coverage counts only what each NMS is meant to monitor: OpManager monitors PRODUCTION only, so dev/staging GLPI
   assets are never a gap. Per connection, a "monitored scope" setting (production states, as opmanager's
   `coverage_states`) excludes or labels the rest. **User, 2026-11-10:** the subsidiary NMS also monitor production;
the open-source brands (Zabbix, LibreNMS) also monitor part of dev. So: a GLPI asset outside a connection's
production scope is never a coverage gap; a dev device that an NMS does monitor is matched and shown normally,
labelled "dev" (from the GLPI asset state), and counted outside the production coverage figures.
8. Deploy runbook: `glpi:plugin:install --force`, then `glpi:plugin:activate`, `glpi:cache:clear`, php-fpm reload,
   selfcheck.
9. `RightDefinition::label()` keeps one row per right in the Profile matrix; a test locks the SelfCheck lib SHAs.

### 5.12 Code baseline and history (orchestrator, 2026-11-10)

This folder was cloned from the OLD, unscrubbed opmanager history at `e9f8eaa` (1.2.0). Before any code work, re-sync the
tree with opmanager 1.3.2 (`67118af`, scrubbed). Before any push, scrub this history as opmanager was, or restart it
(my call: restart history from the 1.3.2 tree, one initial commit, since nms has no published history to keep). Use
only fictional fixtures (no real IPs, hostnames, subnets or names).

## 6. Migration and cutover

1. Install `nms` next to `opmanager`. The installer, when `glpi_plugin_opmanager_*` exists, creates one OpManager
   connection in the PGN entity from opmanager's config (URL, API key, webhook token re-encrypted under nms) and copies
   devices (with the D34 columns `liveness_*`, `hygiene_state`), alarms, ticket links, ticket requests, storms, outbox,
   `deviceexceptions`, `hygienetickets`, `hygieneitems`, the `hygiene_*` config keys and profile rights (incl.
   `plugin_opmanager_hygiene` → `plugin_nms_hygiene`); the full list of 1.3.x additions is opmanager D34 spec §8.
   **That connection starts
   disabled.** The copy is idempotent (re-running does not duplicate).
2. Cutover day (no window or extra conditions, user 2026-11-05), in this order (my call): disable opmanager's three
   crons; re-run the idempotent copy (a Setup button "Copy from opmanager again", also callable as a CLI command) to
   bring over everything opmanager wrote since install; change the OpManager webhook URL to
   `/plugins/nms/front/webhook.php` (header name decided by the builder; keeping `X-OPM-Token` for OpManager is
   acceptable); enable the nms connection.
3. Check with a runbook (tickets still linked, a new alarm opens a ticket, note/clear sent), then uninstall opmanager.

Open tickets keep working because ticket links are copied and alarm ids keep their OpManager ids.

## 7. Constraints

- GLPI 11; suite rules in `_pgn-glpi-suite.md` §4 apply (entity rules 3 and 16, secrets via GLPIKey, CHANGELOG
  rule 8, user-facing message rule 9, implementation by OpenCode under rule 10).
- Shared `lib/pgnsuite/` revisions as shipped in opmanager 1.3.2, or later ones per hub INTEGRATIONS.md.
- Version line: **my call** — `nms` starts at **1.0.0** (its own line; release candidates `1.0.0-rcN`).
- No external libraries that clash with other plugins (no Guzzle).

## 8. Left to the builder

Class and table names, exact API endpoints per brand and their auth (verified against each subsidiary's version),
polling intervals per brand, the common severity table, page layouts, and test structure.

## 9. Decisions log

| Decision | By |
|---|---|
| Brands release 1: OpManager, PRTG, Zabbix, LibreNMS, SolarWinds | user |
| API first, pull | user |
| Subsidiary alarms shown on the asset tab; tickets undecided → per-connection switch, off by default | user (switch: approved) |
| Match, create only after approval; approver = central PGN or the subsidiary's own user | user |
| Cutover: no extra conditions | user |
| Read-only by default, write-back switch per connection | Claude (agreed in chat) |
| Dedup on GLPI asset | Claude |
| Version line 1.0.0 | Claude |
| Baseline opmanager 1.3.2 incl. D34 hygiene and the nine production lessons | user (opmanager frozen 2026-11-10) |
| Class A devices = approval list; hygiene tickets behind the tickets switch; hygiene settings per connection | Claude |
| Restart git history from the 1.3.2 tree before any push | Claude |
| Ticket settings per connection | Claude |
| Subsidiary NMS monitor production; Zabbix/LibreNMS also part of dev → dev labelled, outside production coverage | user (2026-11-10) |
