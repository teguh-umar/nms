# NMS Integration for GLPI (`nms`)

Connects network monitoring systems (NMS) to GLPI. Each monitoring system is a connection: the
plugin reads its devices and alarms, matches the devices to GLPI assets, opens tickets for alarms
when you want it to, writes notes and acknowledgements back, and measures how much of the GLPI
inventory is monitored. It replaces the older `opmanager` plugin and migrates its data.

GLPI 11.0 · PHP 8.2+ · GPL-3.0-or-later · Author: Teguh DHAMAR

## Supported monitoring systems

| Brand | How | Status |
|---|---|---|
| ManageEngine OpManager | REST API and webhook | in production use |
| Zabbix 6.0 LTS to 7.4 | JSON-RPC API (API token or login) | built on the documented API, tested against a mock |
| PRTG | REST API | in development |

## What it does

- **Devices.** An automatic action reads each active connection's devices and matches them to GLPI
  assets (by name, IP address and other identifiers), inside the connection's entity.
- **Alarms and tickets.** Alarms are read from the API or received by webhook. A connection can open
  a ticket per alarm (with the right category, entity and prefix), and closes or updates it when the
  alarm clears. A burst of alarms ("storm") is grouped instead of flooding the helpdesk.
- **Write-back.** Notes, acknowledgements and clears made on the ticket go back to the monitoring
  system the alarm came from, through an outbox that retries.
- **Coverage.** A page lists GLPI assets that are expected to be monitored and are not, per connection
  and overall, with a dashboard card.
- **Hygiene.** Optional comparison of the monitoring inventory with GLPI: devices the monitoring system
  reports as dead or unknown, GLPI assets missing from monitoring, and live devices nobody monitors
  (seen by the GLPI agent, another suite plugin or a TCP probe). Shown on its own page, with
  exceptions you can record and tickets proposed for follow-up.
- **On the asset.** A section on the shared **ICT Tools** tab of a matched asset shows its monitoring
  state and alarms.
- **Migration.** On install, the data of the `opmanager` plugin is copied into a disabled connection,
  "OpManager (migrated)", including display preferences, saved searches and dashboard cards.

## What it does not do

- It never changes the configuration of the monitoring system; write-back only adds notes,
  acknowledgements and clears, and only when the connection allows it.
- It does not create GLPI assets for unmatched devices.

## Requirements

- GLPI 11.0 and PHP 8.2 or later with the `curl` extension.
- For each monitoring system, an API account with the least rights it needs (read, plus
  acknowledge and note if write-back is on).
- The GLPI server must reach each monitoring system's API; the webhook needs the monitoring system to
  reach GLPI.

## Install and upgrade

1. Download `nmsvX.Y.Z.zip` from the release and check its SHA-256.
2. Extract it into GLPI's `plugins/` folder, so that `plugins/nms/setup.php` exists.
3. In GLPI, go to **Setup > Plugins**, then install and enable **NMS Integration**
   (or `php bin/console glpi:plugin:install nms` and `php bin/console glpi:plugin:activate nms`).
4. Log out and in again, so the menu shows the new entries.

An upgrade is the same: replace the folder, then run the install again. When moving from `opmanager`,
follow the runbook in `docs/` for the order of the cut-over steps.

## Configure

1. **Setup > ICT Tools > NMS Integration**: general settings.
2. **NMS connections**: add a connection, choose its brand, enter the URL and credentials (stored
   encrypted, never shown again), the entity, and switch on what it may do: Active, Tickets,
   Write-back. Use **Test connection**.
3. For a webhook (OpManager), generate the connection's webhook token and configure the monitoring
   system to send to `/plugins/nms/front/webhook.php` with that token in the request header.

## Rights

| Right | Gives |
|---|---|
| NMS configuration (`plugin_nms_config`) | connections, settings, webhook tokens |
| NMS monitoring (`plugin_nms_monitoring`) | devices, alarms, coverage and the asset section |
| NMS hygiene (`plugin_nms_hygiene`) | the hygiene page and its exceptions |

Users only see devices and alarms of the entities they can access.

## Automatic actions

| Action | What it does |
|---|---|
| `DeviceSync` | reads devices of every active connection and matches them to assets |
| `AlarmSync` | reads alarms and opens, updates or closes tickets |
| `OutboxSend` | sends queued notes, acknowledgements and clears back to the monitoring system |
| `HygieneCheck` | runs the optional hygiene checks |

## Data it stores

`glpi_plugin_nms_connections`, `_devices`, `_alarms`, `_storms`, `_ticketrequests`, `_outbox`,
`_deviceexceptions`, `_hygieneitems`, `_hygienetickets`. Uninstall removes them, with the plugin's
rights, automatic actions and settings. Tickets created by the plugin stay in GLPI.

## Security

- API keys, passwords and webhook tokens are stored with GLPI's secured configuration, sent once by
  POST and never shown again.
- The webhook only accepts requests carrying a known connection token; anything else is refused.
- Every form uses GLPI's CSRF token; every page checks the rights above and the user's entities.
- Calls to monitoring systems run only from automatic actions or an explicit button, with a timeout.

## Development

- Offline tests: `php tests/run_all.php`
- Release check (builds and verifies the zip): `python tools/release_check.py`
- Rules for contributors: [CHARTER.md](CHARTER.md) and [AGENTS.md](AGENTS.md).
- Changes: [CHANGELOG.md](CHANGELOG.md).

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE) and [NOTICE](NOTICE). To cite this plugin, see
[CITATION.cff](CITATION.cff).
