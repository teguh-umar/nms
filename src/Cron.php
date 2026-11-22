<?php

namespace GlpiPlugin\Nms;

use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Connection\Settings;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Matching\DbAssetLookup;
use GlpiPlugin\Nms\Matching\EntityScope;
use GlpiPlugin\Nms\Matching\Matcher;
use GlpiPlugin\Nms\Outbound\DbOutboxStore;
use GlpiPlugin\Nms\Outbound\Hooks;
use GlpiPlugin\Nms\Outbound\Sender;
use GlpiPlugin\Nms\Ticketing\AddressingRanges;
use GlpiPlugin\Nms\Ticketing\AlarmAssetResolver;
use GlpiPlugin\Nms\Ticketing\AlarmSync;
use GlpiPlugin\Nms\Ticketing\DbAlarmStore;
use GlpiPlugin\Nms\Ticketing\DbDeviceIndex;
use GlpiPlugin\Nms\Ticketing\DbSyncLock;
use GlpiPlugin\Nms\Ticketing\DbTicketRequestStore;
use GlpiPlugin\Nms\Ticketing\GlpiTicketGateway;
use GlpiPlugin\Nms\Ticketing\RangeProductionCheck;

final class Cron extends \CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'NMS Integration';
    }

    public static function cronInfo($name)
    {
        return match ($name) {
            'DeviceSync' => ['description' => __('Sync NMS devices and match them to GLPI assets', 'nms')],
            'AlarmSync'  => ['description' => __('Pull active NMS alarms and create/update tickets', 'nms')],
            'OutboxSend' => ['description' => __('Send queued notes and clears to the NMS', 'nms')],
            'HygieneCheck' => ['description' => __('NMS hygiene: liveness of unmanaged devices, classification, weekly tickets', 'nms')],
            default      => [],
        };
    }

    /** Sanitise a driver error so no secret leaks into logs / task output. */
    private static function sanitizeError(object $conn, string $msg): string
    {
        $key = $conn->secret('api_key');
        if ($key !== '') {
            $msg = str_replace($key, '***', $msg);
        }
        return $msg;
    }

    public static function cronDeviceSync(\CronTask $task): int
    {
        $now    = (string) \Session::getCurrentTime();
        $store  = new DbConnectionStore();
        $total  = 0;
        foreach ($store->active() as $conn) {
            try {
                $lookup = new DbAssetLookup(Config::assetItemtypes(), EntityScope::forConnection($conn));
                $missing = $lookup->missingItemtypes();

                $status = DeviceSync::statusMessage($missing);
                if ($status !== '') {
                    $task->log('conn ' . (int) $conn->id . ': ' . $status);
                    \Toolbox::logInFile('nms', 'DeviceSync conn ' . (int) $conn->id . ': ' . $status . "\n");
                }

                $driver    = DriverFactory::for($conn);
                $settings  = Settings::fromJson((string) $conn->settings);
                $dbStore   = new DbDeviceStore((int) $conn->id);
                $stats     = DeviceSync::run($driver, $dbStore, new Matcher($lookup), $settings->get('match_mode'), $now, $missing === []);


                $store->recordStatus((int) $conn->id, 'device', 'ok', '', $now);
                $task->addVolume($stats['fetched']);
                $task->log(sprintf(
                    'conn %d: fetched %d, matched %d, review %d, unmatched %d, pinned %d, skipped %d',
                    (int) $conn->id, $stats['fetched'], $stats['matched'], $stats['review'],
                    $stats['unmatched'], $stats['manual'], $stats['skipped']
                ));
                $total += $stats['fetched'];
            } catch (\Throwable $e) {
                $msg = self::sanitizeError($conn, $e->getMessage());
                $store->recordStatus((int) $conn->id, 'device', 'error', $msg, $now);
                \Toolbox::logInFile('nms', 'DeviceSync conn ' . (int) $conn->id . ': ' . $msg . "\n");
                $task->log($msg);
            }
        }
        return $total > 0 ? 1 : 0;
    }

    public static function cronAlarmSync(\CronTask $task): int
    {
        $now    = (string) \Session::getCurrentTime();
        $store  = new DbConnectionStore();
        $total  = 0;
        foreach ($store->active() as $conn) {
            try {
                $settings = Settings::fromJson((string) $conn->settings);
                $cfg = Config::ticketingConfigFromSettings($settings);
                $cfg['tickets_enabled'] = (int) $conn->tickets_enabled;
                $cfg['writeback_enabled'] = (int) $conn->writeback_enabled;
                $cfg['connections_id'] = (int) $conn->id;

                // A connection without tickets (subsidiary default) still syncs alarms for the
                // asset tab; only a ticket needs an author.
                if ($cfg['tickets_enabled'] === 1 && $cfg['service_user'] <= 0) {
                    $msg = 'no service user configured on this connection; alarm tickets would have no author';
                    $store->recordStatus((int) $conn->id, 'alarm', 'error', $msg, $now);
                    $task->log('conn ' . (int) $conn->id . ': ' . $msg);
                    continue;
                }

                $ranges = AddressingRanges::load($cfg['production_addressings']);

                $driver = DriverFactory::for($conn);
                $sync   = new AlarmSync(
                    $driver,
                    new DbAlarmStore((int) $conn->id),
                    new GlpiTicketGateway($cfg),
                    new AlarmAssetResolver(new DbDeviceIndex((int) $conn->id), new Matcher(new DbAssetLookup(Config::assetItemtypes(), EntityScope::forConnection($conn))), $settings->get('match_mode')),
                    new RangeProductionCheck($ranges, [AddressingRanges::class, 'assetIps']),
                    $cfg,
                    new DbTicketRequestStore((int) $conn->id)
                );
                $lock = new DbSyncLock(DbSyncLock::lockName((int) $conn->id));
                if (!$lock->acquire(60)) {
                    $task->log('conn ' . (int) $conn->id . ': another alarm sync (webhook) is running; skipped this run');
                    continue;
                }
                try {
                    $stats = $sync->run($now);
                } finally {
                    $lock->release();
                }

                $store->recordStatus((int) $conn->id, 'alarm', 'ok', '', $now);
                $task->addVolume($stats['fetched']);
                $task->log('conn ' . (int) $conn->id . ': ' . implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($stats), $stats)));
                $total += $stats['fetched'];
            } catch (\Throwable $e) {
                $msg = self::sanitizeError($conn, $e->getMessage());
                $store->recordStatus((int) $conn->id, 'alarm', 'error', $msg, $now);
                \Toolbox::logInFile('nms', 'AlarmSync conn ' . (int) $conn->id . ': ' . $msg . "\n");
                $task->log($msg);
            }
        }

        // Every problem stays visible per connection in Setup (last_status / last_error).
        return $total > 0 ? 1 : 0;
    }

    /** Device data younger than the stale threshold; '' (never synced) is never fresh. Pure. */
    public static function deviceDataFresh(string $last_device_sync, string $now, int $stale_minutes): bool
    {
        return $last_device_sync !== ''
            && strtotime($now) - strtotime($last_device_sync) <= 60 * max(1, $stale_minutes);
    }

    /** D34: daily NMS hygiene; tickets every hygiene_frequency_days (spec §7). */
    public static function cronHygieneCheck(\CronTask $task): int
    {
        $now    = (string) \Session::getCurrentTime();
        $store  = new DbConnectionStore();
        $total  = 0;
        foreach ($store->active() as $conn) {
            try {
                $settings = Settings::fromJson((string) $conn->settings);
                $cfg = Config::hygieneConfigFromSettings($settings);
                $tcf = Config::ticketingConfigFromSettings($settings);
                $cfg['tickets_enabled'] = (int) $conn->tickets_enabled;
                $cfg['connections_id'] = (int) $conn->id;

                $last = $settings->get('last_hygiene_ticket_run');
                $due  = $last === '' || strtotime($now) >= strtotime($last) + $cfg['frequency_days'] * 86400;
                if ($due && $cfg['enabled'] && $tcf['service_user'] <= 0) {
                    $msg = 'no service user configured in Setup; hygiene tickets would have no author';
                    $store->recordStatus((int) $conn->id, 'hygiene', 'error', $msg, $now);
                    $store->saveSettings((int) $conn->id, $settings->with(['last_hygiene_error' => $msg]), $now);
                    $task->log('conn ' . (int) $conn->id . ': ' . $msg);
                    continue;
                }

                $run = new \GlpiPlugin\Nms\Hygiene\HygieneRun(
                    new \GlpiPlugin\Nms\Hygiene\DbHygieneStore((int) $conn->id),
                    \GlpiPlugin\Nms\Hygiene\Providers::all($cfg),
                    new \GlpiPlugin\Nms\Hygiene\GlpiHygieneGateway([
                        'service_user'   => $tcf['service_user'],
                        'entity_default' => $tcf['entity_default'],
                        'itil_category'  => $cfg['itil_category'],
                    ]),
                    $cfg + [
                        'service_user'   => $tcf['service_user'],
                        'entity_default' => $tcf['entity_default'],
                        'list_url'       => Device::getSearchURL(true),
                        'brand'          => $conn->brand,
                        // Final review #3: no ticket run on stale NMS data. last_device_sync
                        // moves only on a successful device sync of this connection.
                        'device_data_fresh' => self::deviceDataFresh($conn->last_device_sync, $now, (int) Config::get('stale_warning_minutes')),
                    ]
                );
                $stats = $run->run($now, $due);

                $done = ['last_hygiene_run' => $now, 'last_hygiene_error' => ''];
                if ($due && $cfg['enabled'] && $stats['ticket_errors'] === []) {
                    $done['last_hygiene_ticket_run'] = $now;
                }
                $store->saveSettings((int) $conn->id, $settings->with($done), $now);
                foreach ($stats['errors'] as $error) {
                    \Toolbox::logInFile('nms', 'HygieneCheck conn ' . (int) $conn->id . ': ' . $error . "\n");
                }
                $task->addVolume($stats['classified']);
                $task->log('conn ' . (int) $conn->id . ': classified ' . $stats['classified'] . ', liveness checked ' . $stats['probed'] . ', tickets created ' . $stats['tickets_created'] . ', followups ' . $stats['followups'] . ', errors ' . count($stats['errors']));
                $total += $stats['classified'];
            } catch (\Throwable $e) {
                $msg = self::sanitizeError($conn, $e->getMessage());
                $store->recordStatus((int) $conn->id, 'hygiene', 'error', $msg, $now);
                $store->saveSettings((int) $conn->id, Settings::fromJson((string) $conn->settings)->with(['last_hygiene_error' => $msg]), $now);
                \Toolbox::logInFile('nms', 'HygieneCheck conn ' . (int) $conn->id . ': ' . $msg . "\n");
                $task->log($msg);
            }
        }
        return $total > 0 ? 1 : 0;
    }

    /** Plan 3: retries what the GLPI hooks could not send at once (spec §8). */
    public static function cronOutboxSend(\CronTask $task): int
    {
        $now    = (string) \Session::getCurrentTime();
        $store  = new DbConnectionStore();
        $total  = 0;
        foreach ($store->active() as $conn) {
            if ((int) $conn->writeback_enabled === 0) {
                continue;
            }
            try {
                $cfg    = Config::ticketingConfigFromSettings(Settings::fromJson((string) $conn->settings));
                $driver = DriverFactory::for($conn);
                $sender = new Sender(new DbOutboxStore((int) $conn->id), $driver, $cfg['outbound_max_attempts']);
                $lock   = new DbSyncLock(DbSyncLock::outboxLockName((int) $conn->id));
                if (!$lock->acquire(0)) {
                    $task->log('conn ' . (int) $conn->id . ': a technician save is sending to the NMS; skipped this run');
                    continue;
                }
                try {
                    $stats = $sender->run($now, 200);
                } finally {
                    $lock->release();
                }
                foreach ($sender->lastErrors() as $error) {
                    $task->log('Outbound conn ' . (int) $conn->id . ': ' . $error);
                }
                $task->addVolume($stats['sent']);
                $total += $stats['sent'] + $stats['retried'] + $stats['failed'];
            } catch (\Throwable $e) {
                $msg = self::sanitizeError($conn, $e->getMessage());
                $store->recordStatus((int) $conn->id, 'outbox', 'error', $msg, $now);
                \Toolbox::logInFile('nms', 'Outbound conn ' . (int) $conn->id . ': ' . $msg . "\n");
                $task->log($msg);
            }
        }
        return $total > 0 ? 1 : 0;
    }
}
