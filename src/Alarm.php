<?php

namespace GlpiPlugin\Nms;

use CommonDBTM;

/** Local copy of one OpManager alarm (read-only for users). */
class Alarm extends CommonDBTM
{
    public static $rightname = RightDefinition::MONITORING;

    public static function getTypeName($nb = 0)
    {
        return _n('NMS alarm', 'NMS alarms', $nb, 'nms');
    }

    public static function getIcon()
    {
        return 'ti ti-bell';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    /** @return list<array<string, mixed>> active first, then newest change */
    public static function forAsset(string $itemtype, int $items_id, int $limit): array
    {
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'ORDER' => ['state ASC', 'last_change DESC'],
            'LIMIT' => $limit,
        ]), false);
    }

    public static function countForAsset(string $itemtype, int $items_id): int
    {
        global $DB;

        return (int) $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
        ])->current()['cpt'];
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => 8380, 'table' => $table, 'field' => 'alarm_key', 'name' => __('Alarm ID', 'nms'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 8381, 'table' => $table, 'field' => 'display_name', 'name' => __('Device', 'nms'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 8382, 'table' => $table, 'field' => 'severity_label', 'name' => __('Severity', 'nms'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 8383, 'table' => $table, 'field' => 'message', 'name' => __('Message', 'nms'), 'datatype' => 'text', 'massiveaction' => false],
            ['id' => 8384, 'table' => $table, 'field' => 'state', 'name' => __('State', 'nms'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 8385, 'table' => $table, 'field' => 'tickets_id', 'name' => __('Ticket'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 8386, 'table' => $table, 'field' => 'last_change', 'name' => __('Last change', 'nms'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 8387, 'table' => $table, 'field' => 'ip_address', 'name' => __('IP address'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 8388, 'table' => $table, 'field' => 'ticket_pending', 'name' => __('Ticket pending', 'nms'), 'datatype' => 'bool', 'massiveaction' => false],
        ];
    }

    /** The table has no `name` column: without this GLPI titles the page "N/A". */
    public function getName($options = [])
    {
        $name = trim(($this->fields['display_name'] ?? '') . ' - ' . ($this->fields['alarm_key'] ?? ''), ' -');
        return $name !== '' ? $name : parent::getName($options);
    }

    public function showForm($ID, array $options = [])
    {
        if (!$this->getFromDB((int) $ID)) {
            return false;
        }
        $f    = $this->fields;
        $rows = [
            __('Alarm ID', 'nms')    => htmlescape($f['alarm_key']),
            __('NMS entity', 'nms') => htmlescape($f['entity_key']),
            __('Device', 'nms')      => htmlescape($f['display_name'] ?: $f['device_name']),
            __('IP address')               => htmlescape($f['ip_address']),
            __('Severity', 'nms')    => htmlescape($f['severity_label']),
            __('Alarm type', 'nms')  => htmlescape($f['alarm_code']),
            __('Message', 'nms')     => htmlescape((string) $f['message']),
            __('State', 'nms')       => htmlescape($f['state']),
            __('Ticket')                   => $f['tickets_id'] > 0
                ? '<a href="' . htmlescape(\Ticket::getFormURLWithID((int) $f['tickets_id'])) . '">#' . (int) $f['tickets_id'] . '</a>'
                : ($f['ticket_pending'] ? __s('pending', 'nms') : '-'),
            __('Last change', 'nms') => htmlescape((string) $f['last_change']),
        ];
        echo "<div class='card'><div class='card-body'><table class='table table-sm'>";
        foreach ($rows as $label => $value) {
            echo '<tr><th class="w-25">' . htmlescape($label) . '</th><td>' . $value . '</td></tr>';
        }
        echo '</table></div></div>';
        return true;
    }
}
