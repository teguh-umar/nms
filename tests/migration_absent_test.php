<?php

require __DIR__ . '/bootstrap.php';

class CommonGLPI {}
class CommonDBTM extends CommonGLPI
{
    public static function getTable($classname = null): string
    {
        $class = $classname ?? static::class;
        $parts = explode('\\', $class);
        $short = end($parts);
        return 'glpi_' . strtolower($short) . 's';
    }
}

require OPM_ROOT . '/src/Connection/Connection.php';
require OPM_ROOT . '/src/Connection/ConnectionStore.php';
require OPM_ROOT . '/src/Connection/DbConnectionStore.php';
require OPM_ROOT . '/src/Connection/Settings.php';
require OPM_ROOT . '/src/Migration/FromOpmanager.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Migration\FromOpmanager;

/** Minimal GLPIKey stub. */
if (!class_exists('\GLPIKey', false)) {
    class GLPIKey
    {
        public function encrypt(string $value): string
        {
            return base64_encode('enc:' . $value);
        }
        public function decrypt($value)
        {
            if (!is_string($value)) {
                return false;
            }
            $dec = base64_decode($value, true);
            if ($dec === false || !str_starts_with($dec, 'enc:')) {
                return false;
            }
            return substr($dec, 4);
        }
    }
}

/** Fake $DB: NO opmanager tables exist. */
final class FakeDB
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];
    public int $insertId = 0;

    public function request(array $criteria): \ArrayIterator
    {
        return new \ArrayIterator($this->tables[$criteria['FROM'] ?? ''] ?? []);
    }

    public function insert(string $table, array $fields): int
    {
        $this->insertId++;
        $this->tables[$table][] = $fields + ['id' => $this->insertId];
        return $this->insertId;
    }

    public function update(string $table, array $fields, array $where): void { }
    public function delete(string $table, array $where): void { }
    public function tableExists(string $table): bool { return isset($this->tables[$table]); }
    public function fieldExists(string $table, string $field): bool { return false; }
    public function insertId(): int { return $this->insertId; }
    public function numrows(): int { return 0; }
}

$GLOBALS['DB'] = new FakeDB();

// Only nms tables exist (from a fresh install), no opmanager tables.
foreach (['glpi_plugin_nms_connections'] as $t) {
    $GLOBALS['DB']->tables[$t] = [];
}

// --- Migration without opmanager: skipped, no connection created ---
$result = FromOpmanager::run($GLOBALS['DB'], '2026-11-11 00:00:00');
check('run returns skipped', array_key_exists('skipped', $result));
check('skipped reason', $result['skipped'] === 'opmanager not installed');

// No connection exists
$store = new DbConnectionStore();
check('no connection exists', $store->byWebhookToken('anything') === null);
check('no active connections', $store->active() === []);
check('get missing returns null', $store->get(1) === null);

done();
