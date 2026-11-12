<?php

namespace GlpiPlugin\Nms;

use GlpiPlugin\Nms\Connection\Settings;
use GlpiPlugin\Nms\Matching\DbAssetLookup;

/** Runtime checks against the real GLPI + DB. Run on the VM via tools/selfcheck.php. */
final class SelfCheck
{
    /** @return array<string, callable(): void> */
    public static function checks(): array
    {
        return [
            'schema/devices-table' => static function (): void {
                global $DB;
                self::assert($DB->tableExists(Device::getTable()), 'glpi_plugin_nms_devices missing');
            },
            'rights/registered' => static function (): void {
                $all = array_keys(\ProfileRight::getAllPossibleRights());
                self::assert(array_diff(RightDefinition::names(), $all) === [], 'plugin rights not registered');
            },
            'rights/super-admin-full' => static function (): void {
                global $DB;
                foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $row) {
                    if (((int) $row['rights'] & UPDATE) !== UPDATE) {
                        continue;
                    }
                    $mine = \ProfileRight::getProfileRights((int) $row['profiles_id'], RightDefinition::names());
                    foreach (RightDefinition::ALL as $name => $mask) {
                        self::assert((int) ($mine[$name] ?? 0) === $mask, "profile {$row['profiles_id']} lacks full {$name}");
                    }
                }
            },
            'cron/device-sync-cli' => static function (): void {
                $task = new \CronTask();
                self::assert($task->getFromDBbyName(Cron::class, 'DeviceSync'), 'DeviceSync cron not registered');
                self::assert((int) $task->fields['mode'] === \CronTask::MODE_EXTERNAL, 'DeviceSync is not in CLI mode');
            },
            'schema/alarm-tables' => static function (): void {
                global $DB;
                self::assert($DB->tableExists('glpi_plugin_nms_alarms') && $DB->tableExists('glpi_plugin_nms_storms'), 'alarm/storm tables missing');
            },
            'cron/alarm-sync-cli' => static function (): void {
                $task = new \CronTask();
                self::assert($task->getFromDBbyName(Cron::class, 'AlarmSync'), 'AlarmSync cron not registered');
                self::assert((int) $task->fields['mode'] === \CronTask::MODE_EXTERNAL, 'AlarmSync is not in CLI mode');
            },
            'tickets/service-user' => static function (): void {
                foreach (self::ticketConnections() as [$conn, $cfg]) {
                    $user = new \User();
                    self::assert(
                        $cfg['service_user'] > 0 && $user->getFromDB($cfg['service_user']) && !empty($user->fields['is_active']),
                        "connection {$conn->name}: choose an active GLPI service user for alarm tickets"
                    );
                }
            },
            'tickets/production-ranges' => static function (): void {
                foreach (self::ticketConnections() as [$conn, $cfg]) {
                    self::assert(
                        \GlpiPlugin\Nms\Ticketing\AddressingRanges::load($cfg['production_addressings']) !== [],
                        "connection {$conn->name}: select at least one valid production IP range, otherwise no ticket is created"
                    );
                }
            },
            'webhook/stateless' => static function (): void {
                $request = \Symfony\Component\HttpFoundation\Request::create(Config::WEBHOOK_PATH, 'POST');
                self::assert((new \Glpi\Http\SessionManager())->isResourceStateless($request), 'front/webhook.php is not registered as stateless (plugin_nms_boot)');
            },
            'webhook/token' => static function (): void {
                $store = new \GlpiPlugin\Nms\Connection\DbConnectionStore();
                foreach ($store->active() as $conn) {
                    $token = $conn->webhook_token;
                    if ($token !== '') {
                        $decrypted = (new \GLPIKey())->decrypt($token);
                        self::assert($decrypted !== false && $decrypted !== $token, "connection {$conn->name}: webhook token is stored in clear text");
                    }
                }
            },
            'webhook/lock' => static function (): void {
                $lock = new \GlpiPlugin\Nms\Ticketing\DbSyncLock();
                self::assert($lock->acquire(1), 'GET_LOCK opmanager_alarm_sync not obtainable');
                $lock->release();
            },
            'outbound/table' => static function (): void {
                global $DB;
                self::assert($DB->tableExists('glpi_plugin_nms_outbox'), 'outbox table missing - re-run plugin:install');
            },
            'outbound/cron' => static function (): void {
                self::assert((new \CronTask())->getFromDBbyName(Cron::class, 'OutboxSend'), 'OutboxSend cron not registered - re-run plugin:install');
            },
            'tickets/template-resolves' => static function (): void {
                $store = new \GlpiPlugin\Nms\Connection\DbConnectionStore();
                foreach ($store->active() as $conn) {
                    $settings = Settings::fromJson((string) $conn->settings);
                    $cfg = Config::ticketingConfigFromSettings($settings);
                    if ($cfg['template_id'] > 0) {
                        self::assert((new \TicketTemplate())->getFromDBWithData($cfg['template_id']), "ticket template {$cfg['template_id']} not found");
                    } else {
                        $tt = (new \Ticket())->getITILTemplateToUse(0, \Ticket::INCIDENT_TYPE, 0, $cfg['entity_default']);
                        self::assert($tt instanceof \TicketTemplate, 'no default ticket template resolved');
                    }
                }
            },
            'config/api-key-secured' => static function (): void {
                $store = new \GlpiPlugin\Nms\Connection\DbConnectionStore();
                foreach ($store->active() as $conn) {
                    $raw = $conn->api_key;
                    if ($raw !== '') {
                        $decrypted = (new \GLPIKey())->decrypt($raw);
                        self::assert($decrypted !== false && $decrypted !== $raw, "connection {$conn->name}: API key is stored in clear text");
                    }
                }
            },
            'suite/lib-shas' => static function (): void {
                $expected = [
                    'ConfigTab.php' => 'F94A80C1C813A951919A20D2521DE35B431F9C836BBCCE89BAC70E544D0B80FA',
                    'AssetTab.php'  => 'F8629CCA6D141B1E9271686C8D7F9D65E4FC84E39CF07E85B10FBC849A0B2466',
                    'Menu.php'      => 'FC0B96219DD489639DEECD720197BA6B9810F6303A28CE7DB891139E7913CBFE',
                ];
                foreach ($expected as $file => $sha) {
                    $actual = strtoupper((string) hash_file('sha256', dirname(__DIR__) . '/lib/pgnsuite/' . $file));
                    self::assert($actual === $sha, "lib/pgnsuite/{$file} is not canonical ({$actual})");
                }
            },
            'matching/itemtypes-loadable' => static function (): void {
                $missing = (new DbAssetLookup(Config::assetItemtypes()))->missingItemtypes();
                self::assert($missing === [], 'configured asset types not loadable here: ' . implode(', ', $missing));
            },
            'hygiene/schema' => static function (): void {
                global $DB;
                foreach (['glpi_plugin_nms_deviceexceptions', 'glpi_plugin_nms_hygienetickets', 'glpi_plugin_nms_hygieneitems'] as $t) {
                    self::assert($DB->tableExists($t), "{$t} missing - re-run plugin:install");
                }
                foreach (['liveness_state', 'liveness_source', 'liveness_detail', 'liveness_checked_at', 'hygiene_state'] as $col) {
                    self::assert($DB->fieldExists(Device::getTable(), $col), "devices.{$col} missing - re-run plugin:install");
                }
            },
            'hygiene/cron-cli' => static function (): void {
                $task = new \CronTask();
                self::assert($task->getFromDBbyName(Cron::class, 'HygieneCheck'), 'HygieneCheck cron not registered - re-run plugin:install');
                self::assert((int) $task->fields['mode'] === \CronTask::MODE_EXTERNAL, 'HygieneCheck is not in CLI mode');
            },
            'hygiene/groups' => static function (): void {
                foreach ((new \GlpiPlugin\Nms\Connection\DbConnectionStore())->active() as $conn) {
                    $cfg = Config::hygieneConfigFromSettings(Settings::fromJson((string) $conn->settings));
                    if (!$cfg['enabled']) {
                        continue;
                    }
                    foreach (array_merge($cfg['glpi_groups'], [$cfg['nms_group']]) as $gid) {
                        self::assert((new \Group())->getFromDB($gid), "connection {$conn->name}: hygiene group {$gid} not found");
                    }
                    // rc7: the asset technician(s) own the "not in GLPI" ticket.
                    self::assert($cfg['glpi_users'] !== [], "connection {$conn->name}: no asset technician set: the \"not in GLPI\" ticket would have no owner");
                    foreach ($cfg['glpi_users'] as $uid) {
                        self::assert((new \User())->getFromDB($uid), "connection {$conn->name}: asset technician {$uid} not found");
                    }
                }
            },
            'matching/lookup-smoke' => static function (): void {
                $lookup = new DbAssetLookup(Config::assetItemtypes());
                self::assert($lookup->byName('__opmanager_selfcheck_no_such_asset__') === [], 'name lookup returned rows for a nonsense name');
                self::assert($lookup->byIp('192.0.2.254') === [], 'IP lookup returned rows for TEST-NET-1');
            },
        ];
    }

    /**
     * Active connections that create tickets, with their ticketing config.
     *
     * @return list<array{0: \GlpiPlugin\Nms\Connection\Connection, 1: array<string, mixed>}>
     */
    private static function ticketConnections(): array
    {
        $out = [];
        foreach ((new \GlpiPlugin\Nms\Connection\DbConnectionStore())->active() as $conn) {
            if ((int) $conn->tickets_enabled === 1) {
                $out[] = [$conn, Config::ticketingConfigFromSettings(Settings::fromJson((string) $conn->settings))];
            }
        }
        return $out;
    }

    private static function assert(bool $cond, string $msg): void
    {
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }
}
