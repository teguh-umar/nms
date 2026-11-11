<?php

require __DIR__ . '/bootstrap.php';

class CommonGLPI {}
class CommonDBTM extends CommonGLPI {}
class Html
{
    public static function submit($label, $options = []): string { return ''; }
    public static function hidden($name, $options = []): string { return ''; }
    public static function closeForm(): void { }
    public static function getPrefixedUrl($url): string { return $url; }
}
class Dropdown
{
    public static function showFromArray($name, $labels, $options = []): void { }
}
class Entity
{
    public static function dropdown($options = []): void { }
}
class Group
{
    public static function dropdown($options = []): void { }
}
class User
{
    public static function dropdown($options = []): void { }
}
class TicketTemplate
{
    public static function dropdown($options = []): void { }
}
class ITILCategory
{
    public static function dropdown($options = []): void { }
}
class State
{
    public static function dropdown($options = []): void { }
}

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

/** Session stub. */
if (!class_exists('\Session', false)) {
    class Session
    {
        public static $rights = [];
        public static function haveRight($name, $mask)
        {
            return ((int) (self::$rights[$name] ?? 0) & $mask) === $mask;
        }
        public static function getLoginUserID(): int { return 0; }
        public static function isCron(): bool { return false; }
    }
}

require OPM_ROOT . '/src/Connection/Connection.php';
require OPM_ROOT . '/src/Connection/ConnectionStore.php';
require OPM_ROOT . '/src/Connection/DbConnectionStore.php';
require OPM_ROOT . '/src/Connection/Settings.php';
require OPM_ROOT . '/src/RightDefinition.php';
require OPM_ROOT . '/src/Driver/Driver.php';
require OPM_ROOT . '/src/Driver/DriverFactory.php';
require OPM_ROOT . '/src/Driver/Capabilities.php';
require OPM_ROOT . '/src/Connection/ConnectionForm.php';

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Connection\ConnectionForm;
use GlpiPlugin\Nms\Driver\Driver;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Driver\Capabilities;
use GlpiPlugin\Nms\RightDefinition;

/** Driver that reports capabilities per brand. */
final class FakeDriver implements Driver
{
    public function __construct(private Connection $conn) {}
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            $this->conn->brand === 'opmanager',
            $this->conn->brand === 'opmanager',
            false,
            false,
            false,
            false,
            false,
            false
        );
    }
    public function testConnection(): array { return ['ok' => true, 'message' => '']; }
    public function listDevices(): array { return []; }
    public function listActiveAlarms(): array { return []; }
    public function alarmByKey(string $key): ?array { return null; }
    public function addNote(string $alarm_key, string $text): void {}
    public function acknowledge(string $alarm_key): void {}
    public function clear(string $alarm_key): void {}
}

DriverFactory::setTestOverride(fn (Connection $c) => new FakeDriver($c));

$GLOBALS['DB'] = new class {
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];
    public int $insertId = 0;
    public function request(array $criteria): \ArrayIterator
    {
        $table = $criteria['FROM'] ?? '';
        $where = $criteria['WHERE'] ?? [];
        $out = [];
        foreach ($this->tables[$table] ?? [] as $row) {
            $ok = true;
            foreach ($where as $col => $val) {
                if (is_array($val)) {
                    foreach ($val as $op => $v) {
                        $rv = $row[$col] ?? null;
                        match ($op) {
                            '>'    => $ok = $ok && $rv > $v,
                            '<>'   => $ok = $ok && $rv !== $v,
                            'NOT'  => $ok = $ok && !in_array($rv, (array) $v, true),
                            default => $ok = $ok && $rv === $v,
                        };
                    }
                } elseif (!isset($row[$col]) || $row[$col] !== $val) {
                    $ok = false;
                }
            }
            if ($ok) {
                $out[] = $row;
            }
        }
        return new \ArrayIterator($out);
    }
    public function insert(string $table, array $fields): int
    {
        $this->insertId++;
        $row = $fields + ['id' => $this->insertId];
        $this->tables[$table][] = $row;
        return $this->insertId;
    }
    public function update(string $table, array $fields, array $where): void
    {
        if (!isset($this->tables[$table])) {
            return;
        }
        foreach ($this->tables[$table] as $i => $row) {
            $ok = true;
            foreach ($where as $col => $val) {
                if (!isset($row[$col]) || $row[$col] !== $val) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $this->tables[$table][$i] = array_merge($row, $fields);
            }
        }
    }
    public function delete(string $table, array $where): void
    {
        $this->tables[$table] = array_values(array_filter(
            $this->tables[$table] ?? [],
            fn (array $row) => !self::matches($row, $where)
        ));
    }
    public function tableExists(string $table): bool { return isset($this->tables[$table]); }
    public function fieldExists(string $table, string $field): bool { return false; }
    public function insertId(): int { return $this->insertId; }
    public function numrows(): int { return 0; }
    private static function matches(array $row, array $where): bool
    {
        foreach ($where as $col => $val) {
            if (!isset($row[$col]) || $row[$col] !== $val) {
                return false;
            }
        }
        return true;
    }
};

$enc = new \GLPIKey();
$connFields = [
    'name'             => 'opm.example.test',
    'brand'            => 'opmanager',
    'entities_id'      => 1,
    'is_recursive'     => 1,
    'url'              => 'https://opm.example.test',
    'api_key'          => $enc->encrypt('key-xyz'),
    'username'         => 'pg-nms',
    'password'         => $enc->encrypt('token-xyz'),
    'verify_tls'       => 1,
    'is_active'        => 1,
    'writeback_enabled' => 1,
    'tickets_enabled'  => 1,
    'webhook_token'    => $enc->encrypt('token-xyz'),
    'settings'         => '{}',
    'last_device_sync' => '',
    'last_alarm_sync'  => '',
    'last_status'      => '',
    'last_error'       => '',
    'date_creation'    => '2026-11-11 00:00:00',
    'date_mod'         => '2026-11-11 00:00:00',
];

$store = new DbConnectionStore();
$id1 = $store->save(array_merge($connFields, ['name' => 'opm.example.test']));
$id2 = $store->save(array_merge($connFields, [
    'name' => 'core-sw-01',
    'brand' => 'zabbix',
    'is_active' => 0,
]));

// --- Static render tests ---
$form = new ConnectionForm($store);

// No secret value appears in rendered HTML.
$html = $form->renderList();
check('no secret in list HTML', strpos($html, 'key-xyz') === false && strpos($html, 'token-xyz') === false);

$html = $form->renderForm(1);
fwrite(STDERR, 'RENDER FORM HTML: ' . substr($html, 0, 1000) . PHP_EOL);
check('no secret in form HTML', strpos($html, 'key-xyz') === false && strpos($html, 'token-xyz') === false);
check('secret shown as set/not set', str_contains($html, 'Stored') || str_contains($html, 'Not set'), 'html=' . substr($html, 0, 500));

// Disabled switches appear for a fake driver whose capabilities are false.
// OpManager has all capabilities true, Zabbix has none (FakeDriver returns all false for zabbix).
$htmlZ = $form->renderForm(2);
check('webhook switch disabled for non-opmanager brand',
    str_contains($htmlZ, 'disabled'));

// --- POST without UPDATE right is refused ---
Session::$rights = ['plugin_nms_config' => READ];
$result = $form->handleSave(1, [
    'name' => 'hacked',
    'url' => 'https://evil.example.test',
]);
check('POST without UPDATE right refused', $result['ok'] === false);

// --- Saving with an empty secret field keeps the stored secret ---
Session::$rights = ['plugin_nms_config' => READ | UPDATE];
$result = $form->handleSave(1, [
    'name' => 'opm.example.test',
    'brand' => 'opmanager',
    'url' => 'https://opm.example.test',
    'api_key' => '', // empty = keep stored
    'webhook_token' => '', // empty = keep stored
    'is_active' => 1,
    'tickets_enabled' => 1,
    'writeback_enabled' => 1,
    'entities_id' => 1,
]);
check('save with empty secret keeps stored', $result['ok'] === true);
$c = $store->get(1);
check('api key unchanged', $c->secret('api_key') === 'key-xyz');
check('webhook token unchanged', $c->secret('webhook_token') === 'token-xyz');

// --- Brand select lists only brands that DriverFactory supports ---
$brands = ConnectionForm::supportedBrands();
check('supported brands excludes brands without driver', !in_array('zabbix', $brands, true));
check('supported brands includes opmanager', in_array('opmanager', $brands, true));

done();
