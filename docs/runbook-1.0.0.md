---
date: 2026-11-12
tags: [nms, runbook, migration, cutover]
status: active
---

# nms 1.0.0: install next to opmanager, migrate, cut over

Spec: `docs/superpowers/specs/2026-11-05-nms-integration-design.md` §5.11 and §6.
Release 1.0.0 drives **OpManager only**. PRTG, Zabbix, LibreNMS and SolarWinds come in later releases.

Paths below assume GLPI in `/data/www/glpi`, run as `nginx`. `<glpi>` is the GLPI base URL.

## 1. Install next to opmanager (opmanager keeps running)

```bash
cd /data/www/glpi/plugins && sudo -u nginx unzip -q /tmp/nmsv1.0.0-rc1.zip && sudo -u nginx php /data/www/glpi/bin/console glpi:plugin:install nms --force && sudo -u nginx php /data/www/glpi/bin/console glpi:plugin:activate nms && sudo -u nginx php /data/www/glpi/bin/console glpi:cache:clear && sudo systemctl reload php-fpm && sudo -u nginx php /data/www/glpi/plugins/nms/tools/selfcheck.php
```

- The zip unpacks into `plugins/nms`. Never put a backup folder inside `plugins/`: GLPI would read it as another plugin.
- `plugin:install --force` leaves the plugin installed but **not activated**. Without `plugin:activate`, selfcheck fails with `Attempted to load class "SelfCheck"`.
- The installer copies opmanager into nms once, as the connection **OpManager (migrated)**, which starts **disabled**. Its crons and webhook do nothing until step 5d.

- [ ] Selfcheck ends with `ALL PASS`. The per-connection checks (`tickets/service-user`, `tickets/production-ranges`, `hygiene/groups`) only look at **active** connections, so before cutover they have nothing to check; they matter again in step 6.
- [ ] Setup > Automatic actions lists **DeviceSync**, **AlarmSync**, **OutboxSend** and **HygieneCheck** for nms, mode CLI.

## 2. Check the copy

Run the copy again from the CLI. It is idempotent: the second run must report 0 everywhere.

```bash
sudo -u nginx php /data/www/glpi/plugins/nms/tools/migrate_from_opmanager.php
```

- [ ] The run prints `NMS migration: connection id <n>`, then one line per table, `rights_copied`, and `done`. Every number is 0 (nothing new since install). Any other number means opmanager wrote rows since install; run it again: it must drop to 0.
- [ ] Row counts match opmanager. Run this in MySQL; each pair must be equal:

```sql
SELECT 'devices', (SELECT COUNT(*) FROM glpi_plugin_opmanager_devices), (SELECT COUNT(*) FROM glpi_plugin_nms_devices)
UNION ALL SELECT 'alarms', (SELECT COUNT(*) FROM glpi_plugin_opmanager_alarms), (SELECT COUNT(*) FROM glpi_plugin_nms_alarms)
UNION ALL SELECT 'outbox', (SELECT COUNT(*) FROM glpi_plugin_opmanager_outbox), (SELECT COUNT(*) FROM glpi_plugin_nms_outbox)
UNION ALL SELECT 'exceptions', (SELECT COUNT(*) FROM glpi_plugin_opmanager_deviceexceptions), (SELECT COUNT(*) FROM glpi_plugin_nms_deviceexceptions)
UNION ALL SELECT 'hygienetickets', (SELECT COUNT(*) FROM glpi_plugin_opmanager_hygienetickets), (SELECT COUNT(*) FROM glpi_plugin_nms_hygienetickets)
UNION ALL SELECT 'hygieneitems', (SELECT COUNT(*) FROM glpi_plugin_opmanager_hygieneitems), (SELECT COUNT(*) FROM glpi_plugin_nms_hygieneitems);
```

- [ ] Every copied alarm points at a copied device. This query must return 0:

```sql
SELECT COUNT(*) FROM glpi_plugin_nms_alarms a
LEFT JOIN glpi_plugin_nms_devices d ON d.id = a.plugin_nms_devices_id
WHERE a.plugin_nms_devices_id > 0 AND d.id IS NULL;
```

## 3. Check the migrated connection

Setup > General > PGN Suite > **NMS Integration** > **Manage connections** > **OpManager (migrated)**.

- [ ] **Connection** tab: URL is the OpManager URL. API key shows "Stored - leave blank to keep". **Active** is not ticked. Tickets and Write-back are ticked. The entity is the lowest common entity of the assets OpManager is linked to.
- [ ] **Webhook** tab: the token shows "stored token". The URL shown is `<glpi>/plugins/nms/front/webhook.php`.
- [ ] **Tickets** tab: service account, production IP ranges, template, title and description patterns, storm settings: same as opmanager's Setup.
- [ ] **NMS hygiene** tab: same groups, technicians, category, segments and ports as opmanager. "Create hygiene tickets" is as opmanager had it.
- [ ] Do not press **Test connection** yet if the OpManager API is rate-limited; otherwise press it: "Connection test OK".
- [ ] The global section (Setup > General > PGN Suite > NMS Integration) shows only the asset types, the stale limit and one row of badges per **active** connection. Before cutover it shows "No active NMS connection": expected.

## 4. Rights

- [ ] Administration > Profiles: every profile that had **OpManager** rights now has the same **NMS Integration** rights (Setup, Monitoring, NMS hygiene). The copy only fills nms rights that were still empty, and never reads the core `config` right.

## 5. Cutover (no window needed, in this order)

a. **Deactivate opmanager** (its tables and settings stay; it can be activated again):

```bash
sudo -u nginx php /data/www/glpi/bin/console glpi:plugin:deactivate opmanager && sudo -u nginx php /data/www/glpi/bin/console glpi:cache:clear
```

Deactivating, not only stopping its crons: opmanager's ticket hooks send notes, acknowledges and clears to OpManager on every followup and solve. While both plugins are active and the nms connection is enabled, each note would be sent twice.

b. **Copy again**, to bring over what opmanager wrote since install: Setup > NMS Integration > **Copy from opmanager again**, or the CLI command of step 2. The message lists only the rows added or changed since install.

c. **OpManager**: Settings > Notification Profiles > the GLPI webhook profile > Invoke a Webhook: change the URL from `/plugins/opmanager/front/webhook.php` to `/plugins/nms/front/webhook.php`. Leave the header and the token as they are: nms accepts `X-OPM-Token` as well as `X-NMS-Token`, and the token was copied unchanged.

d. **Enable the connection**: Manage connections > OpManager (migrated) > tick **Active** > Save. Press **Test connection**: "Connection test OK".

e. Run the syncs once by hand:

```bash
sudo -u nginx php /data/www/glpi/front/cron.php --force DeviceSync && sudo -u nginx php /data/www/glpi/front/cron.php --force AlarmSync
```

## 6. Checks after cutover

- [ ] Selfcheck: `ALL PASS`.
- [ ] The NMS Integration section shows the badges of **OpManager (migrated)**: API key stored, device sync and alarm sync "x min ago" (green), webhook token stored, To OpManager "0 waiting, 0 given up".
- [ ] **Tickets still linked**: open an asset with an active alarm that had a ticket before cutover. Its Monitoring section lists the alarm with the same ticket.
- [ ] **A new alarm opens a ticket**: trigger a test alarm on a production device (or wait for the next real one). Within one AlarmSync run (or at once by webhook) a ticket appears, and `files/_log/nms.log` shows `Webhook: HTTP 200`.
- [ ] **Note sent**: add a followup to that ticket as a technician. The OpManager alarm shows the note within a minute. It is sent once (step 5a).
- [ ] **Clear sent**: solve the ticket. The OpManager alarm is cleared.
- [ ] **Hygiene**: run `front/cron.php --force HygieneCheck`. No new hygiene ticket when the open ones are still open: followups are added to the tickets opmanager created.

## 7. Request template and observers (lesson 4)

The default GLPI **Request** template hides the Observer field. Hygiene tickets do have their observer groups (GLPI team, asset team, NMS team), but the ticket form does not show them. To see them, open the template used for hygiene tickets (Setup > Dropdowns > Ticket templates) and remove **Observer** from its hidden fields, or look at the Actors in the ticket's Historical tab.

## 8. Remove opmanager (after the checks of step 6 pass)

- [ ] Wait at least one full hygiene ticket cycle and one working day of alarms with no problem.
- [ ] Uninstall opmanager in Setup > Plugins. **This drops the opmanager tables**: after it, the copy can no longer be repeated and rollback by reactivation is no longer possible.

## 9. Rollback (before step 8 only)

1. Manage connections > OpManager (migrated): untick **Active** > Save.
2. In OpManager, set the webhook URL back to `/plugins/opmanager/front/webhook.php`.
3. `glpi:plugin:activate opmanager`, `glpi:cache:clear`.

Tickets created by nms meanwhile stay in GLPI; opmanager does not know their alarms and creates its own ticket on the next poll.
