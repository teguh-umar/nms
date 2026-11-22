<?php

namespace GlpiPlugin\Nms\Driver\Prtg;

/**
 * Normalised PRTG sensor rows (PrtgClient::sensors()) -> the OpManager AlarmParser row shape (pure, no I/O).
 * The sensor id is the alarm key, the entity key and the alarm code (entity_key is the outbox write key).
 */
final class SensorParser
{
    /** Which status_raw codes count as an alarm, per the "alarm states" setting. */
    public const ALARM_CODES = [
        'down'                 => [5, 13, 14],
        'down+warning'         => [5, 13, 14, 4],
        'down+warning+unusual' => [5, 13, 14, 4, 10],
    ];

    /** status_raw -> nms severity (1 Critical, 3 Attention). */
    public const SEVERITY = [5 => 1, 13 => 1, 14 => 1, 4 => 3, 10 => 3];

    /**
     * Whether PRTG's raw dates (OLE automation days) are UTC. UNVERIFIED against the manual (no network when
     * written): the v2 client emits UTC, so UTC is assumed. Set false if a live classic server shows local time.
     */
    public const OLE_IS_UTC = true;

    /** Days between 1899-12-30 and 1970-01-01. */
    private const OLE_EPOCH_DAYS = 25569;

    /**
     * Alarm rows only: a sensor whose status_raw is not a SEVERITY key (Up, Paused, Unknown) is skipped.
     * @param list<array<string, mixed>> $sensors
     * @param array<string, array<string, mixed>> $devicesById normalised devices keyed by id
     * @return list<array<string, mixed>>
     */
    public static function parse(array $sensors, array $devicesById): array
    {
        $out = [];
        foreach ($sensors as $s) {
            $row = is_array($s) && isset(self::SEVERITY[(int) ($s['status_raw'] ?? 0)]) ? self::row($s, $devicesById) : null;
            if ($row !== null) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** The alarm row for an alarm state; any other state gives the same row with severity 5 / Clear. */
    public static function current(array $sensor, array $devicesById): array
    {
        $row = self::row($sensor, $devicesById) ?? [];
        if (!isset(self::SEVERITY[(int) ($sensor['status_raw'] ?? 0)])) {
            $row['severity']       = 5;
            $row['severity_label'] = 'Clear';
        }
        return $row;
    }

    /** @return array<string, mixed>|null */
    private static function row(array $s, array $devicesById): ?array
    {
        $id = self::str($s, 'id');
        if ($id === '') {
            return null;
        }
        $code     = (int) ($s['status_raw'] ?? 0);
        $severity = self::SEVERITY[$code] ?? 5;
        $device   = is_array($devicesById[self::str($s, 'device_id')] ?? null) ? $devicesById[self::str($s, 'device_id')] : [];
        $name     = self::str($s, 'name');
        $dname    = self::str($s, 'device') ?: self::str($device, 'name');
        $text     = self::clean(self::str($s, 'message'));

        return [
            'alarm_key'      => $id,
            'entity_key'     => $id,
            'device_name'    => $dname,
            'display_name'   => $dname,
            'ip_address'     => self::str($device, 'host'),
            'category'       => self::str($s, 'group'),
            'alarm_code'     => $id,
            'event_type'     => self::str($s, 'type'),
            'severity'       => $severity,
            'severity_label' => self::str($s, 'status') ?: ($severity === 5 ? 'Clear' : 'Down'),
            'message'        => $name . ': ' . $text,
            'mod_time'       => self::modTime($s['lastcheck'] ?? null),
        ];
    }

    /** Strip real tags only (a "<" before a space or digit is text), decode entities once, collapse whitespace. */
    private static function clean(string $message): string
    {
        $text = (string) preg_replace('/<!--.*?-->|<\/?[A-Za-z][^<>]*>/s', '', $message);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function modTime(mixed $ole): string
    {
        if (!is_int($ole) && !is_float($ole)) {
            return date('Y-m-d H:i:s');
        }
        $unix = (int) round(($ole - self::OLE_EPOCH_DAYS) * 86400);
        if (self::OLE_IS_UTC) {
            return date('Y-m-d H:i:s', $unix); // PHP default timezone
        }
        // Server local time: keep the wall-clock components as they are.
        return gmdate('Y-m-d H:i:s', $unix);
    }

    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? '';
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
