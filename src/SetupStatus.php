<?php

namespace GlpiPlugin\Nms;

/** Pure: the health badges at the top of the Setup section (spec §15.2). */
final class SetupStatus
{
    /**
     * @param array{api_key_set: bool, last_device_sync: string, last_device_sync_error: string, stale_minutes: int,
     *   last_alarm_sync: string, last_alarm_sync_error: string, alarm_minutes: int, webhook_token_set: bool,
     *   outbox_pending: int, outbox_failed: int, ticket_source?: string} $f
     * @return list<array{key: string, label: string, level: string, text: string}>
     */
    public static function items(array $f, string $now): array
    {
        $age = static function (string $when) use ($now): ?int {
            return $when === '' ? null : intdiv(max(0, strtotime($now) - strtotime($when)), 60);
        };
        $sync = static function (string $key, string $label, string $last, string $error, int $limit) use ($age): array {
            $mins = $age($last);
            if ($error !== '') {
                return ['key' => $key, 'label' => $label, 'level' => 'error', 'text' => $error];
            }
            if ($mins === null) {
                return ['key' => $key, 'label' => $label, 'level' => 'warn', 'text' => __('never ran', 'nms')];
            }
            return ['key' => $key, 'label' => $label, 'level' => $mins > $limit ? 'warn' : 'ok', 'text' => sprintf(__('%d min ago', 'nms'), $mins)];
        };

        $outbox = $f['outbox_failed'] > 0 ? 'error' : ($f['outbox_pending'] > 0 ? 'warn' : 'ok');
        $items  = [
            ['key' => 'api', 'label' => __('API key', 'nms'), 'level' => $f['api_key_set'] ? 'ok' : 'error',
             'text' => $f['api_key_set'] ? __('stored', 'nms') : __('not set', 'nms')],
            $sync('devices', __('Device sync', 'nms'), $f['last_device_sync'], $f['last_device_sync_error'], $f['stale_minutes']),
            $sync('alarms', __('Alarm sync', 'nms'), $f['last_alarm_sync'], $f['last_alarm_sync_error'], 3 * $f['alarm_minutes']),
            self::webhook($f['webhook_token_set'], (string) ($f['ticket_source'] ?? 'rules')),
            ['key' => 'outbox', 'label' => __('To OpManager', 'nms'), 'level' => $outbox,
             'text' => sprintf(__('%d waiting, %d given up', 'nms'), $f['outbox_pending'], $f['outbox_failed'])],
        ];
        // D34: daily HygieneCheck; a run older than two days means the cron is not running.
        if (array_key_exists('hygiene_enabled', $f)) {
            $label = __('NMS hygiene', 'nms');
            $item  = $sync('hygiene', $label, (string) $f['last_hygiene_run'], (string) $f['last_hygiene_error'], 2 * 1440);
            if ($item['level'] === 'ok' && !$f['hygiene_enabled']) {
                $item = ['key' => 'hygiene', 'label' => $label, 'level' => 'warn', 'text' => __('tickets off', 'nms')];
            }
            $items[] = $item;
        }
        return $items;
    }

    /** D32: in webhook mode a missing token means no new ticket at all. */
    private static function webhook(bool $token_set, string $source): array
    {
        if ($token_set) {
            return ['key' => 'webhook', 'label' => __('Webhook', 'nms'), 'level' => 'ok', 'text' => __('token stored', 'nms')];
        }
        return $source === 'webhook'
            ? ['key' => 'webhook', 'label' => __('Webhook', 'nms'), 'level' => 'error', 'text' => __('no token - no new tickets', 'nms')]
            : ['key' => 'webhook', 'label' => __('Webhook', 'nms'), 'level' => 'warn', 'text' => __('no token - disabled', 'nms')];
    }
}
