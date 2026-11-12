<?php

namespace GlpiPlugin\Nms;

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\Settings;

/**
 * Pure: the health badges at the top of the Setup section (spec §15.2).
 */
final class SetupStatus
{
    /**
     * @param array{api_key_set: bool, last_device_sync: string, last_device_sync_error: string, stale_minutes: int,
     *   last_alarm_sync: string, last_alarm_sync_error: string, alarm_minutes: int, webhook_token_set: bool,
     *   outbox_pending: int, outbox_failed: int, ticket_source?: string} $f
     * @param list<Connection> $connections per-connection status lines (Task 9)
     * @return list<array{key: string, label: string, level: string, text: string}> */
    public static function items(array $f, string $now, array $connections = []): array
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
        // Task 9: one status line per connection.
        foreach ($connections as $conn) {
            $label = __('Connection', 'nms') . ' — ' . htmlescape($conn->name);
            $level = $conn->last_status === 'error' ? 'error' : ($conn->last_status === 'ok' ? 'ok' : 'warn');
            $text  = $conn->last_status !== '' ? $conn->last_status : __('never synced', 'nms');
            if ($conn->last_error !== '') {
                $text .= ' — ' . htmlescape($conn->last_error);
            }
            $items[] = ['key' => 'conn_' . (int) $conn->id, 'label' => $label, 'level' => $level, 'text' => $text];
        }
        return $items;
    }

    /**
     * The health badges of one connection, labelled with its name. The row keeps one error
     * slot; recordStatus() prefixes it with the kind ("device: ...", "alarm: ...").
     *
     * @param array{pending: int, failed: int} $outbox
     * @return list<array{key: string, label: string, level: string, text: string}>
     */
    public static function forConnection(Connection $c, array $outbox, int $stale_minutes, string $now): array
    {
        $s     = Settings::fromJson($c->settings);
        $error = static fn (string $kind): string => $c->last_status === 'error' && str_starts_with($c->last_error, $kind . ': ')
            ? substr($c->last_error, strlen($kind) + 2) : '';
        $items = self::items([
            'api_key_set'            => $c->api_key !== '',
            'last_device_sync'       => $c->last_device_sync,
            'last_device_sync_error' => $error('device'),
            'stale_minutes'          => max(1, $stale_minutes),
            'last_alarm_sync'        => $c->last_alarm_sync,
            'last_alarm_sync_error'  => $error('alarm'),
            'alarm_minutes'          => Config::ALARM_SYNC_MINUTES,
            'webhook_token_set'      => $c->webhook_token !== '',
            'ticket_source'          => $s->get('ticket_source'),
            'outbox_pending'         => (int) $outbox['pending'],
            'outbox_failed'          => (int) $outbox['failed'],
            'hygiene_enabled'        => $s->get('hygiene_enabled') === '1',
            'last_hygiene_run'       => $s->get('last_hygiene_run'),
            'last_hygiene_error'     => $s->get('last_hygiene_error'),
        ], $now);
        if ((int) $c->tickets_enabled !== 1) {
            // Tickets off (subsidiary default): the webhook only feeds the asset tab.
            $items = array_values(array_filter($items, static fn (array $i): bool => $i['key'] !== 'webhook'));
        }
        foreach ($items as &$item) {
            $item['key']   = 'conn_' . (int) $c->id . '_' . $item['key'];
            $item['label'] = $c->name . ' - ' . $item['label'];
        }
        unset($item);
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
