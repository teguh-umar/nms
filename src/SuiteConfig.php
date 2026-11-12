<?php

namespace GlpiPlugin\Nms;

use GlpiPlugin\Nms\Connection\DbConnectionStore;

/**
 * NMS Integration section in the shared "PGN Suite" tab on Setup > General (hub §5).
 * The global Setup keeps only `asset_itemtypes`, `stale_warning_minutes`, the
 * connection list and the status strip; per-connection settings live on
 * front/connection.php and front/connection.form.php.
 */
final class SuiteConfig
{
    public const SECTION = 'nms';

    public const ORDER = 75;

    public const TABS = ['connection', 'matching', 'tickets', 'filter', 'webhook', 'outbound', 'hygiene'];

    /** Hidden field marking a POST that came from the PGN Suite tab. */
    public const FROM_SUITE_FIELD = '_from_pgnsuite';

    /** Session key holding a freshly generated webhook token until the form shows it once. */
    public const NEW_TOKEN_KEY = 'plugin_nms_new_webhook_token';

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

        echo '<form method="post" action="' . htmlescape(\Html::getPrefixedUrl('/plugins/opmanager/front/config.php')) . '">';
        if ($from_suite) {
            echo \Html::hidden(self::FROM_SUITE_FIELD, ['value' => 1]);
        }

        self::statusStrip();
        // v1.3.0-rc4: the user manual (suite rule 2026-11-09).
        echo '<p class="mb-3"><a href="' . htmlescape(\Html::getPrefixedUrl('/plugins/opmanager/front/manual.php')) . '">'
            . '<i class="ti ti-book me-1"></i>' . __s('Manual', 'nms') . '</a></p>';

        echo '<div class="mt-3">';
        echo '<a href="' . htmlescape(\Html::getPrefixedUrl('/plugins/opmanager/front/connection.php')) . '" class="btn btn-outline-primary">';
        echo __('Manage connections', 'nms');
        echo '</a>';
        echo '<div class="form-hint">' . __s('List and edit all NMS connections.', 'nms') . '</div>';
        echo '</div>';

        // Task 8: one-click migration from opmanager (shown only when opmanager tables exist).
        if (\GlpiPlugin\Nms\Migration\FromOpmanager::isInstalled($GLOBALS['DB'])) {
            echo '<div class="mt-3">';
            echo '<form method="post" action="' . htmlescape(\Html::getPrefixedUrl('/plugins/opmanager/front/config.php')) . '">';
            echo \Html::hidden(self::FROM_SUITE_FIELD, ['value' => 1]);
            echo \Html::submit(__('Copy from opmanager again', 'nms'), [
                'name' => 'migrate_from_opmanager',
                'class' => 'btn btn-outline-warning',
            ] . $disabled);
            echo '<div class="form-hint">' . __s('Copies devices, alarms and settings from opmanager into a disabled connection; safe to repeat.', 'nms') . '</div>';
            \Html::closeForm();
            echo '</div>';
        }

        if ($canedit) {
            echo '<div class="mt-2">' . \Html::submit(_sx('button', 'Save'), ['name' => 'update']) . '</div>';
        }
        \Html::closeForm();
    }

    private static function statusStrip(): void
    {
        $store = new \GlpiPlugin\Nms\Connection\DbConnectionStore();
        $connections = $store->active();
        $items = SetupStatus::items([
            'last_device_sync'       => '',
            'last_device_sync_error' => '',
            'stale_minutes'          => max(1, (int) Config::DEFAULTS['stale_warning_minutes']),
            'last_alarm_sync'        => '',
            'last_alarm_sync_error'  => '',
            'alarm_minutes'          => Config::ALARM_SYNC_MINUTES,
            'webhook_token_set'      => false,
            'outbox_pending'         => 0,
            'outbox_failed'          => 0,
        ], (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')), $connections);
        $color = ['ok' => 'success', 'warn' => 'warning', 'error' => 'danger'];
        $icon  = ['ok' => 'ti-circle-check', 'warn' => 'ti-alert-triangle', 'error' => 'ti-circle-x'];
        echo '<div class="opm-status d-flex flex-wrap gap-2 mb-3">';
        foreach ($items as $it) {
            $text = strlen($it['text']) > 60 ? substr($it['text'], 0, 57) . '...' : $it['text'];
            echo '<span class="badge bg-' . $color[$it['level']] . '-lt fs-5 py-2 px-3" title="' . htmlescape($it['text']) . '">';
            echo '<i class="ti ' . $icon[$it['level']] . ' me-1"></i>' . htmlescape($it['label']) . ': ' . htmlescape($text) . '</span>';
        }
        echo '</div>';
    }

    private static function textRow(string $id, string $name, string $label, string $value, string $type, string $disabled): string
    {
        return '<div class="mb-2"><label class="form-label" for="' . htmlescape($id) . '">' . htmlescape($label) . '</label>'
            . '<input type="' . $type . '" class="form-control" id="' . htmlescape($id) . '" name="' . htmlescape($name) . '" value="' . htmlescape($value) . '"' . $disabled . '></div>';
    }
}
