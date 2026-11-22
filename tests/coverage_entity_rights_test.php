<?php

// Task 7 review C1: a connection's entity scope must narrow the user's entity rights, never replace them.

namespace GlpiPlugin\Nms {
    if (!class_exists(Device::class, false)) {
        class Device { public static function getTable(): string { return 'glpi_plugin_nms_devices'; } }
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';
    require OPM_ROOT . '/src/Coverage/DbCoverageSource.php';

    use GlpiPlugin\Nms\Coverage\DbCoverageSource;

    class CommonDBTM
    {
        public function maybeRecursive(): bool { return false; }
        public function maybeDeleted(): bool { return false; }
        public function maybeTemplate(): bool { return false; }
    }
    class Computer extends CommonDBTM
    {
        public static function getTable(): string { return 'glpi_computers'; }
        public static function getSystemSQLCriteria(): array { return []; }
    }
    function getItemForItemtype($t) { return new $t(); }
    function getEntitiesRestrictCriteria($table, $f = '', $v = '', $r = false) { return [$table . '.entities_id' => [5]]; }

    final class RightsFakeDB
    {
        public array $criteria = [];
        public function fieldExists(string $t, string $f): bool { return false; }
        public function request(array $c): \ArrayIterator { $this->criteria[] = $c; return new \ArrayIterator([]); }
    }
    global $DB;
    $DB = new RightsFakeDB();

    $holds = static function (array $where, array $want): bool {
        // the criterion is present either as a key of the WHERE or inside one of its AND groups
        foreach ($where as $k => $v) {
            if ($k === array_key_first($want) && $v === reset($want)) {
                return true;
            }
            if (is_int($k) && is_array($v) && array_key_exists(array_key_first($want), $v) && $v[array_key_first($want)] === reset($want)) {
                return true;
            }
        }
        return false;
    };

    (new DbCoverageSource([2]))->assets(['Computer'], [], [81, 82]);
    $where = $DB->criteria[0]['WHERE'];
    check('rights criterion kept', $holds($where, ['glpi_computers.entities_id' => [5]]), json_encode($where));
    check('connection scope added', $holds($where, ['glpi_computers.entities_id' => [81, 82]]), json_encode($where));

    $DB->criteria = [];
    (new DbCoverageSource([1]))->assets(['Computer'], []);
    check('no scope: only the rights criterion', $DB->criteria[0]['WHERE'] === ['glpi_computers.entities_id' => [5]], json_encode($DB->criteria[0]['WHERE']));

    done();
}
