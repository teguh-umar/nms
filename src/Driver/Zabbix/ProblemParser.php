<?php

namespace GlpiPlugin\Nms\Driver\Zabbix;

/**
 * Zabbix problems -> the AlarmParser row shape (pure, no I/O).
 *
 * Inputs (all decoded `result` arrays, Zabbix string-typed values):
 *  - $problems:     problem.get rows (eventid, objectid, name, severity, clock, opdata, ...).
 *  - $hostsByEvent: keyed by eventid; what event.get returned for that event
 *                   called with selectHosts and selectRelatedObject:
 *                   ['hosts' => [['hostid','host','name'], ...],
 *                    'relatedObject' => ['description' => <trigger description, macros unexpanded>]].
 *  - $hostDetails:  keyed by hostid; host.get rows with interfaces (ip_address)
 *                   and hostgroups/groups (category).
 */
final class ProblemParser
{
    /** Zabbix severity -> the plugin's severity scale (1 Critical, 2 Trouble, 3 Attention, 5 Clear). */
    public const SEVERITY_MAP = [5 => 1, 4 => 1, 3 => 2, 2 => 3, 1 => 3, 0 => 3];

    public const LABELS = [0 => 'Not classified', 1 => 'Information', 2 => 'Warning', 3 => 'Average', 4 => 'High', 5 => 'Disaster'];

    /** @return list<array<string, mixed>> */
    public static function parse(array $problems, array $hostsByEvent, array $hostDetails): array
    {
        $out = [];
        foreach ($problems as $p) {
            if (!is_array($p)) {
                continue;
            }
            $info = $hostsByEvent[self::str($p, 'eventid')] ?? [];
            $row  = self::row($p, is_array($info) ? $info : [], $hostDetails, false);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * One event.get row (with selectHosts, selectRelatedObject) -> alarm row.
     * A recovered event (r_eventid other than '0') becomes severity 5 / 'Clear'.
     * An empty event (event.get found nothing) gives null.
     *
     * @return array<string, mixed>|null
     */
    public static function fromEvent(array $event, array $hostDetails): ?array
    {
        if ($event === []) {
            return null;
        }
        $recovery = self::str($event, 'r_eventid');
        return self::row($event, $event, $hostDetails, $recovery !== '' && $recovery !== '0');
    }

    /** @return array<string, mixed>|null */
    private static function row(array $p, array $info, array $hostDetails, bool $clear): ?array
    {
        $eventId = self::str($p, 'eventid');
        if ($eventId === '') {
            return null;
        }
        $zbxSeverity = (int) self::str($p, 'severity');
        if (!isset(self::SEVERITY_MAP[$zbxSeverity])) {
            return null;
        }

        $hosts  = is_array($info['hosts'] ?? null) ? $info['hosts'] : [];
        $host   = is_array($hosts[0] ?? null) ? $hosts[0] : [];
        $hostId = self::str($host, 'hostid');
        $detail = is_array($hostDetails[$hostId] ?? null) ? $hostDetails[$hostId] : [];

        $hostName = self::str($host, 'host') ?: self::str($detail, 'host');
        $display  = self::str($host, 'name') ?: self::str($detail, 'name') ?: $hostName;
        $related  = is_array($info['relatedObject'] ?? null) ? $info['relatedObject'] : [];

        $message = self::str($p, 'name');
        $opdata  = self::str($p, 'opdata');
        if ($opdata !== '') {
            $message .= ' (' . $opdata . ')';
        }
        $clock = self::str($p, 'clock');

        return [
            'alarm_key'      => $eventId,
            'entity_key'     => $eventId,
            'device_name'    => $hostName,
            'display_name'   => $display,
            'ip_address'     => HostParser::ipAddress($detail),
            'category'       => HostParser::category($detail),
            'alarm_code'     => self::str($p, 'objectid'),
            'event_type'     => self::str($related, 'description'),
            'severity'       => $clear ? 5 : self::SEVERITY_MAP[$zbxSeverity],
            'severity_label' => $clear ? 'Clear' : self::LABELS[$zbxSeverity],
            'message'        => $message,
            'mod_time'       => ctype_digit($clock) ? date('Y-m-d H:i:s', (int) $clock) : '',
        ];
    }

    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? '';
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
