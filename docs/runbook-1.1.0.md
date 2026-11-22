---
date: 2026-11-22
tags: [nms, runbook, zabbix, upgrade]
status: draft
---

# nms 1.1.0-rc1: upgrade from 1.0.0 and add a Zabbix connection

Release 1.1.0-rc1 adds the Zabbix driver. It was built against the documented API of Zabbix 6.0 LTS to 7.4 (the newest line checked at build time; a newer line, e.g. 8.0, is verified before it is supported) with synthetic fixtures and is **not yet verified against a live Zabbix**: treat the first sync as a test.

Paths assume GLPI in `/data/www/glpi`, run as `nginx`. `<glpi>` is the GLPI base URL, `<zabbix-url>` the Zabbix web address of the subsidiary.

## 1. Upgrade from 1.0.0

```bash
cd /data/www/glpi/plugins && sudo rm -rf /data/www/glpi/plugins/nms && sudo -u nginx unzip -q /tmp/nmsv1.1.0-rc1.zip && sudo -u nginx php /data/www/glpi/bin/console glpi:plugin:install nms --force && sudo -u nginx php /data/www/glpi/bin/console glpi:plugin:activate nms && sudo -u nginx php /data/www/glpi/bin/console glpi:cache:clear && sudo systemctl reload php-fpm && sudo -u nginx php /data/www/glpi/plugins/nms/tools/selfcheck.php
```

- [ ] Selfcheck ends with `ALL PASS`.
- [ ] **Log out and log in again.** GLPI keeps the menu in the browser session.

## 2. Check that OpManager is unchanged

Before upgrading:

- [ ] Open the OpManager connection: its **Entity** must be **Root entity** with **Child entities included** ticked. That is the condition under which matching is unchanged. If it is not, **stop and report before upgrading**.

After upgrading:

- [ ] Coverage, **All connections**: the same numbers as before the upgrade.
- [ ] Run **DeviceSync** once (Setup > Automatic actions). The matching counts are unchanged.

## 3. For a subsidiary: prepare Zabbix

In the subsidiary's Zabbix, ask its administrator for:

- [ ] a user role with **API access** enabled and **read-only** permission on the subsidiary's host groups only;
- [ ] an **API token** for a user with that role (Zabbix 7.x: **Users > API tokens**; Zabbix 6.0: **Administration > General > API tokens**; or the user's own **User settings > API tokens**);
- [ ] the network path from the GLPI server to `<zabbix-url>` (HTTPS), tested with `curl -I <zabbix-url>` from the GLPI server.

## 4. Add the connection

nms > Connections > Add:

Order matters: the Zabbix filter block appears only after the connection is saved with brand zabbix.

- [ ] Add connection with brand **zabbix**, URL `<zabbix-url>`, the API token in the API key field, **Entity** = the subsidiary's entity. Tickets **off**. Write-back **off**. Keep **Active** off for now. **Save**.
- [ ] Reopen the connection and set the Zabbix filters: host groups (one per line), minimum severity, include suppressed. **Save**. An empty host-group list reads every host the token can see, so set the groups before turning Active on.
- [ ] **Test connection** reports the Zabbix version and the auth style.
- [ ] Only then tick **Active** and save.

## 5. First sync and Coverage per connection

- [ ] Run **DeviceSync** and **AlarmSync** once.
- [ ] Coverage > connection filter: the Zabbix connection shows its own devices, matching and Coverage %. **All connections** still shows OpManager's numbers plus the new ones.

## 6. Warning: entity isolation

**Do not give nms rights to subsidiary users before the entity-isolation release.** Views are not isolated by entity yet: a subsidiary user with nms rights could see other entities' devices and alarms.
