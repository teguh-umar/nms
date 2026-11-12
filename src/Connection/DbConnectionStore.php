<?php

namespace GlpiPlugin\Nms\Connection;

use GlpiPlugin\Nms\Connection\Connection;
use InvalidArgumentException;

/**
 * ConnectionStore backed by glpi_plugin_nms_connections.
 */
final class DbConnectionStore implements ConnectionStore
{
    public const TABLE = 'glpi_plugin_nms_connections';

    public function active(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['is_active' => 1],
        ]) as $row) {
            $out[] = new Connection($row);
        }
        return $out;
    }

    /** Every connection, active or not, by name (the list page: a disabled one must stay editable). */
    public function all(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'ORDER' => 'name']) as $row) {
            $out[] = new Connection($row);
        }
        return $out;
    }

    public function get(int $id): ?Connection
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['*'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['id' => $id],
            'LIMIT'  => 1,
        ])->current();
        return $row ? new Connection((array) $row) : null;
    }

    public function byWebhookToken(string $token): ?Connection
    {
        global $DB;

        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['is_active' => 1],
        ]) as $row) {
            $c = new Connection($row);
            $stored = $c->secret('webhook_token');
            if ($stored !== '' && hash_equals($stored, $token)) {
                return $c;
            }
        }
        return null;
    }

    public function save(array $fields): int
    {
        global $DB;

        $brand = (string) ($fields['brand'] ?? '');
        if (!in_array($brand, Connection::BRANDS, true)) {
            throw new InvalidArgumentException('Unknown NMS brand: ' . $brand);
        }

        $now = date('Y-m-d H:i:s');
        $fields += ['date_mod' => $now];

        $id = (int) ($fields['id'] ?? 0);
        if ($id > 0) {
            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['id' => $id],
                'LIMIT'  => 1,
            ])->current();
            if ($existing) {
                $DB->update(self::TABLE, $fields, ['id' => $id]);
                return $id;
            }
        }
        unset($fields['id']);
        $DB->insert(self::TABLE, $fields + ['date_creation' => $now]);
        return (int) $DB->insertId();
    }

    /** Update only the settings column (a cron must not rewrite the status it just recorded). */
    public function saveSettings(int $id, Settings $settings, string $now): void
    {
        global $DB;

        $DB->update(self::TABLE, ['settings' => $settings->toJson(), 'date_mod' => $now], ['id' => $id]);
    }

    /**
     * $kind 'device' or 'alarm': a success stamps that sync column and clears the error.
     * Any other kind (hygiene, outbox) only records a failure, so its success never hides a
     * device or alarm error. A failure never moves a sync column: a failed sync must not
     * make old data look fresh.
     */
    public function recordStatus(int $id, string $kind, string $status, string $error, string $now): void
    {
        global $DB;

        $syncCol = ['device' => 'last_device_sync', 'alarm' => 'last_alarm_sync'][$kind] ?? null;
        if ($status === 'ok') {
            if ($syncCol === null) {
                return;
            }
            $fields = ['last_status' => 'ok', 'last_error' => '', $syncCol => $now];
        } else {
            $fields = ['last_status' => 'error', 'last_error' => substr($kind . ': ' . $error, 0, 1000)];
        }
        $DB->update(self::TABLE, $fields + ['date_mod' => $now], ['id' => $id]);
    }

    /**
     * The oldest successful device sync over the given connections (all active ones by
     * default), for pages that span connections: '' when one of them never synced.
     *
     * @param list<int>|null $ids
     */
    public function oldestDeviceSync(?array $ids = null): string
    {
        $oldest = null;
        foreach ($this->active() as $c) {
            if ($ids !== null && !in_array((int) $c->id, $ids, true)) {
                continue;
            }
            if ($c->last_device_sync === '') {
                return '';
            }
            $oldest = $oldest === null ? $c->last_device_sync : min($oldest, $c->last_device_sync);
        }
        return $oldest ?? '';
    }
}
