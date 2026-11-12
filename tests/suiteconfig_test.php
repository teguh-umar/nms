<?php

namespace Glpi\Asset {
    class AssetDefinition
    {
        public static function getTable() { return 'glpi_assets_assetdefinitions'; }
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';

    $GLOBALS['RIGHTS'] = [];
    $GLOBALS['CFG_VALUES'] = [];
    $GLOBALS['CFG_GLPI'] = ['url_base' => 'https://glpi.example.com'];

    class CommonGLPI {}
    class Session
    {
        public static $rights = [];
        public static function haveRight($name, $mask)
        {
            return ((int) ($GLOBALS['RIGHTS'][$name] ?? 0) & $mask) === $mask;
        }
    }

    class Html
    {
        public static function getPrefixedUrl($url) { return $url; }
        public static function submit($label, $options = []) { return '<input type="submit" name="' . ($options['name'] ?? '') . '" value="' . $label . '">'; }
        public static function closeForm() { return ''; }
        public static function hidden($name, $options = []) { return '<input type="hidden" name="' . $name . '" value="' . ($options['value'] ?? '') . '">'; }
        public static function scriptBlock($js) { return '<script>' . $js . '</script>'; }
    }

    class Config
    {
        public static function getConfigurationValues($context, $keys = []) { return $GLOBALS['CFG_VALUES']; }
    }

    class ConfigTab
    {
        public static function getReturnUrl($section) { return '/'; }
    }

    class FakeDB { public function tableExists($t) { return false; } public function request($c) { return new \ArrayIterator([]); } }
    $GLOBALS['DB'] = new FakeDB();

    require OPM_ROOT . '/src/Config.php';
    require OPM_ROOT . '/src/Connection/ConnectionStore.php';
    require OPM_ROOT . '/src/Connection/DbConnectionStore.php';
    require OPM_ROOT . '/src/RightDefinition.php';
    require OPM_ROOT . '/src/Ticketing/ProductionCheck.php';
    require OPM_ROOT . '/src/Ticketing/RangeProductionCheck.php';
    require OPM_ROOT . '/src/Ticketing/AddressingRanges.php';
    require OPM_ROOT . '/src/Ticketing/AlarmFilter.php';
    require OPM_ROOT . '/src/Outbound/OutboxStore.php';
    require OPM_ROOT . '/src/Outbound/DbOutboxStore.php';
    require OPM_ROOT . '/src/SetupStatus.php';
    require OPM_ROOT . '/src/Connection/Connection.php';
    require OPM_ROOT . '/src/Connection/Settings.php';
    require OPM_ROOT . '/src/Connection/ConnectionForm.php';
    require OPM_ROOT . '/src/CustomAssets.php';
    require OPM_ROOT . '/src/SuiteConfig.php';
    require OPM_ROOT . '/src/Migration/FromOpmanager.php';

    use GlpiPlugin\Nms\RightDefinition as R;
    use GlpiPlugin\Nms\SuiteConfig;

    check('no config right: section hidden', SuiteConfig::section() === null);

    $GLOBALS['RIGHTS'] = [R::CONFIG => READ];
    $section = SuiteConfig::section();
    check('config READ: section shown', is_array($section));
    check('render is a Class::method string', ($section['render'] ?? null) === 'GlpiPlugin\\Nms\\SuiteConfig::render');

    $GLOBALS['CFG_VALUES'] = ['opm_api_key' => 'ENCRYPTED-SECRET-VALUE', 'opm_url' => 'https://opm'];
    ob_start();
    SuiteConfig::showForm(true);
    $html = (string) ob_get_clean();
    check('api key never rendered', !str_contains($html, 'ENCRYPTED-SECRET-VALUE'));
    check('read-only user sees no save button', !str_contains($html, 'name="update"'));
    check('suite marker present when rendered in the tab', str_contains($html, 'name="_from_pgnsuite"'));
    check('posts to own controller', str_contains($html, 'action="/plugins/nms/front/config.php"'));
    check('manual link present', str_contains($html, 'front/manual.php'));
    check('manage connections link present', str_contains($html, 'front/connection.php'));
    check('manage connections label', str_contains($html, 'Manage connections'));

    // Migration button present when opmanager tables exist (FakeDB::tableExists returns false, so not present).
    check('migration button not present when no opmanager tables', !str_contains($html, 'migrate_from_opmanager'));

    $GLOBALS['RIGHTS'] = [R::CONFIG => READ | UPDATE];
    ob_start();
    SuiteConfig::showForm(false);
    $html = (string) ob_get_clean();
    check('editor sees save button', str_contains($html, 'name="update"'));
    check('standalone page has no suite marker', !str_contains($html, '_from_pgnsuite'));

    // Status strip present.
    check('status strip present', str_contains($html, 'nms-status'));
    check('no active connection is reported', str_contains($html, 'No active NMS connection'));

    // Review R2: the global Setup edits only the keys shared by every connection.
    preg_match_all('/name="([^"]+)"/', $html, $m);
    $fields = array_values(array_diff(array_unique(array_map(static fn ($n) => rtrim($n, '[]'), $m[1])), ['update']));
    sort($fields);
    check('global Setup edits only asset_itemtypes and stale_warning_minutes', $fields === ['asset_itemtypes', 'stale_warning_minutes'], implode(',', $fields));
    check('no nested form', substr_count($html, '<form') === 1);

    // Per-connection status lines not present (no active connections).
    check('no per-connection status when no connections', !str_contains($html, 'conn_'));

    done();
}
