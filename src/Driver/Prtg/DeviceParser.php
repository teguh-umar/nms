<?php

namespace GlpiPlugin\Nms\Driver\Prtg;

/**
 * Normalised PRTG device rows (PrtgClient::devices()) -> the OpManager DeviceParser row shape (pure, no I/O).
 */
final class DeviceParser
{
    /** status_raw codes that mean Paused (by user, dependency, schedule, license, until). */
    public const PAUSED = [7, 8, 9, 11, 12];

    /** @return list<array{device_key: string, name: string, display_name: string, ip_address: string, device_type: string, device_category: string, availability: string}> */
    public static function parse(array $devices): array
    {
        $out = [];
        foreach ($devices as $d) {
            if (!is_array($d)) {
                continue;
            }
            $key = self::str($d, 'id');
            if ($key === '') {
                continue;
            }
            $name = self::str($d, 'name') ?: $key;
            $tags = preg_split('/\s+/', self::str($d, 'tags'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $out[] = [
                'device_key'      => $key,
                'name'            => $name,
                'display_name'    => $name,
                'ip_address'      => self::str($d, 'host'),
                'device_type'     => $tags[0] ?? '',
                'device_category' => self::str($d, 'group'),
                'availability'    => in_array((int) ($d['status_raw'] ?? 0), self::PAUSED, true) ? 'Paused' : self::str($d, 'status'),
            ];
        }
        return $out;
    }

    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? '';
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
