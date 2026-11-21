<?php

namespace GlpiPlugin\Nms;

use GlpiPlugin\Nms\Connection\ConnectionForm;
use GlpiPlugin\Nms\Connection\DbConnectionStore;

/**
 * NMS Integration section in the shared suite settings page, Setup > ICT Tools (hub §5).
 * The global Setup holds only what is shared by every connection (asset_itemtypes,
 * stale_warning_minutes), the per-connection status strip and the link to the
 * connections; every other setting is per connection (front/connection.form.php).
 */
final class SuiteConfig
{
    public const SECTION = 'nms';

    public const ORDER = 75;

    public const CONFIG_PATH = '/plugins/nms/front/config.php';

    /** Hidden field marking a POST that came from the PGN Suite tab. */
    public const FROM_SUITE_FIELD = '_from_pgnsuite';

    public static function section(): ?array
    {
        if (!\Session::haveRight(RightDefinition::CONFIG, READ)) {
            return null;
        }
        return [
            'title'  => 'NMS Integration',
            'icon'   => 'ti ti-activity',
            'order'  => self::ORDER,
            'render' => self::class . '::render',
        ];
    }

    public static function render(): void
    {
        self::showForm(true);
    }

    public static function showForm(bool $from_suite = false): void
    {
        $canedit  = \Session::haveRight(RightDefinition::CONFIG, UPDATE);
        $disabled = $canedit ? '' : ' disabled';
        $sfx      = $from_suite ? '-suite' : '';
        $marker   = $from_suite ? \Html::hidden(self::FROM_SUITE_FIELD, ['value' => 1]) : '';

        self::statusStrip();
        // v1.3.0-rc4: the user manual (suite rule 2026-11-09).
        echo '<p class="mb-3"><a href="' . htmlescape(\Html::getPrefixedUrl('/plugins/nms/front/manual.php')) . '">'
            . '<i class="ti ti-book me-1"></i>' . __s('Manual', 'nms') . '</a></p>';

        echo '<div class="mb-3">';
        echo '<a href="' . htmlescape(\Html::getPrefixedUrl(ConnectionForm::LIST_PATH)) . '" class="btn btn-outline-primary">'
            . '<i class="ti ti-plug-connected me-1"></i>' . __s('Manage connections', 'nms') . '</a>';
        echo '<div class="form-hint">' . __s('URL, API key, webhook token, matching, tickets, write-back and hygiene are set per NMS connection.', 'nms') . '</div>';
        echo '</div>';

        echo '<form method="post" action="' . htmlescape(\Html::getPrefixedUrl(self::CONFIG_PATH)) . '">';
        echo $marker;
        echo '<div class="mb-2">' . __s('Asset types to match and to show the Monitoring section on', 'nms') . '</div>';
        $selected = Config::assetItemtypes();
        foreach (Config::offeredItemtypes() as $itemtype => $label) {
            $id = 'nms-asset-' . md5($itemtype) . $sfx;
            if (in_array($itemtype, Config::BASE_ASSET_ITEMTYPES, true) && is_a($itemtype, \CommonGLPI::class, true)) {
                $label = $itemtype::getTypeName(2);
            }
            echo sprintf(
                '<div class="form-check"><input class="form-check-input" type="checkbox" name="asset_itemtypes[]" value="%s" id="%s"%s%s><label class="form-check-label" for="%s">%s</label></div>',
                htmlescape($itemtype),
                htmlescape($id),
                in_array($itemtype, $selected, true) ? ' checked' : '',
                $disabled,
                htmlescape($id),
                htmlescape($label)
            );
        }
        echo '<div class="mb-2 mt-2"><label class="form-label" for="stale_warning_minutes' . $sfx . '">' . __s('Warn when device data is older than (minutes)', 'nms') . '</label>'
            . '<input type="number" class="form-control" id="stale_warning_minutes' . $sfx . '" name="stale_warning_minutes" value="' . htmlescape(Config::get('stale_warning_minutes')) . '"' . $disabled . '></div>';
        if ($canedit) {
            echo '<div class="mt-2">' . \Html::submit(_sx('button', 'Save'), ['name' => 'update']) . '</div>';
        }
        \Html::closeForm();

        // Task 8: one-click migration from opmanager (shown only when opmanager is installed).
        // Its own form, after the settings form: HTML forms do not nest.
        if ($canedit && \GlpiPlugin\Nms\Migration\FromOpmanager::isInstalled($GLOBALS['DB'])) {
            echo '<div class="mt-3">';
            echo '<form method="post" action="' . htmlescape(\Html::getPrefixedUrl(self::CONFIG_PATH)) . '">';
            echo $marker;
            echo \Html::submit(__('Copy from opmanager again', 'nms'), ['name' => 'migrate_from_opmanager', 'class' => 'btn btn-outline-warning']);
            echo '<div class="form-hint">' . __s('Copies devices, alarms and settings from opmanager into a disabled connection; safe to repeat.', 'nms') . '</div>';
            \Html::closeForm();
            echo '</div>';
        }
    }

    private static function statusStrip(): void
    {
        $now   = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $stale = (int) Config::get('stale_warning_minutes');
        $items = [];
        foreach ((new DbConnectionStore())->active() as $conn) {
            $box   = (new \GlpiPlugin\Nms\Outbound\DbOutboxStore((int) $conn->id))->summary();
            $items = array_merge($items, SetupStatus::forConnection($conn, $box, $stale, $now));
        }
        echo '<div class="nms-status d-flex flex-wrap gap-2 mb-3">';
        if ($items === []) {
            echo '<span class="badge bg-warning-lt fs-5 py-2 px-3"><i class="ti ti-alert-triangle me-1"></i>'
                . __s('No active NMS connection', 'nms') . '</span>';
        }
        $color = ['ok' => 'success', 'warn' => 'warning', 'error' => 'danger'];
        $icon  = ['ok' => 'ti-circle-check', 'warn' => 'ti-alert-triangle', 'error' => 'ti-circle-x'];
        foreach ($items as $it) {
            $text = strlen($it['text']) > 60 ? substr($it['text'], 0, 57) . '...' : $it['text'];
            echo '<span class="badge bg-' . $color[$it['level']] . '-lt fs-5 py-2 px-3" data-key="' . htmlescape($it['key']) . '" title="' . htmlescape($it['text']) . '">';
            echo '<i class="ti ' . $icon[$it['level']] . ' me-1"></i>' . htmlescape($it['label']) . ': ' . htmlescape($text) . '</span>';
        }
        echo '</div>';
    }
}
