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
        $fields = array_merge([
            'date_creation' => $now,
            'date_mod'      => $now,
        ], $fields);

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
        $DB->insert(self::TABLE, $fields);
        return (int) $DB->insertId();
    }

    public function recordStatus(int $id, string $kind, string $status, string $error, string $now): void
    {
        global $DB;

        $syncCol = $kind === 'device' ? 'last_device_sync' : 'last_alarm_sync';
        $DB->update(self::TABLE, [
            'last_status' => $status,
            'last_error'  => $error,
            $syncCol      => $now,
            'date_mod'    => $now,
        ], ['id' => $id]);
    }
}
