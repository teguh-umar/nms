---
date: 2026-11-23
tags: [nms, runbook, prtg, upgrade]
status: draft
---

# nms 1.2.0: upgrade from 1.1.0 and add a PRTG connection

Release 1.2.0 adds the PRTG driver. It was built from Paessler's documented API with synthetic fixtures for PRTG 26.4, 26.3 and 26.2, and is **not verified against a live PRTG**: the 26.3 and 26.2 build numbers and the timezone of the classic `lastcheck_raw` field (assumed UTC) are unverified. Treat the first sync as a test.

Paths assume GLPI in `/data/www/glpi`, run as `nginx`. `<glpi>` is the GLPI base URL, `<prtg-url>` the PRTG web address of the subsidiary.

## 1. Upgrade from 1.1.0

Nothing on the VM needs to be set aside.

```bash
cd /data/www/glpi/plugins && sudo rm -rf /data/www/glpi/plugins/nms && sudo -u nginx unzip -q /tmp/nmsv1.2.0-rc1.zip && sudo -u nginx php /data/www/glpi/bin/console glpi:plugin:install nms --force && sudo -u nginx php /data/www/glpi/bin/console glpi:plugin:activate nms && sudo -u nginx php /data/www/glpi/bin/console glpi:cache:clear && sudo systemctl reload php-fpm && sudo -u nginx php /data/www/glpi/plugins/nms/tools/selfcheck.php
```

- [ ] Selfcheck ends with `ALL PASS`.
- [ ] **Log out and log in again.** GLPI keeps the menu in the browser session.

## 2. OpManager and Zabbix are unchanged

- [ ] Coverage, **All connections**: the same numbers as before the upgrade.
- [ ] The OpManager and Zabbix connections still pass **Test connection**.
- [ ] Their write-back behaves as before. The outbox now drops actions a brand cannot do and fails unsupported ones without retry; for OpManager and Zabbix nothing changes.

## 3. For a subsidiary: prepare PRTG

In the subsidiary's PRTG, ask its administrator for:

- [ ] a **read-only** PRTG user limited to the groups to be monitored, and an **API key** for that user (the user's own account page in PRTG);
- [ ] the **acknowledge** right on that user only if write-back is wanted. Write-back is acknowledge only, on Down sensors;
- [ ] for **API v2** (optional, experimental, read-only): PRTG 24.3 or later and, before PRTG 26.4, the **Application Server** enabled on the core server;
- [ ] the network path from the GLPI server to `<prtg-url>` (HTTPS), tested with `curl -I <prtg-url>` from the GLPI server.

## 4. Add the connection

nms > Connections > Add:

Order matters: the PRTG block appears only after the connection is saved with brand prtg.

- [ ] Add connection with brand **prtg**, URL `<prtg-url>`, the API key in the API key field, **Entity** = the subsidiary's entity. Tickets **off**. Write-back **off**. Keep **Active** off for now. **Save**.
- [ ] Reopen the connection and set the PRTG block: **API** (classic is the default; v2 is experimental and read-only), **groups** (one per line; empty reads every group the key can see, so set them before turning Active on), **alarm states**. **Save**.
- [ ] **Test connection** reports the PRTG version and the auth style.
- [ ] Only then tick **Active** and save.

## 5. First sync and Coverage per connection

- [ ] Run **DeviceSync** and **AlarmSync** once, as a test.
- [ ] Coverage > connection filter: the PRTG connection shows its own devices, matching and Coverage %. **All connections** still shows the other connections' numbers plus the new ones.
- [ ] Check the alarm timestamps against PRTG: if they are shifted by a whole number of hours, report it (the `lastcheck_raw` timezone is assumed UTC).

## 6. Warning: entity isolation

**Do not give nms rights to subsidiary users before the entity-isolation release.** Views are not isolated by entity yet: a subsidiary user with nms rights could see other entities' devices and alarms.
