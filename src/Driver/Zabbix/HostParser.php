<?php

namespace GlpiPlugin\Nms\Driver\Zabbix;

/**
 * Zabbix host.get result -> the DeviceParser row shape (pure, no I/O).
 *
 * Input: the decoded `result` of host.get called with selectInterfaces
 * (extend) and selectHostGroups (6.2+) or selectGroups (6.0). Ids and numbers
 * are strings, as Zabbix returns them. Host groups are read from `hostgroups`
 * (6.2+) or `groups` (6.0).
 */
final class HostParser
{
    /** Interface types in preference order: Agent, SNMP, IPMI, JMX. */
    private const TYPE_ORDER = [1, 2, 3, 4];

    /** @return list<array{device_key: string, name: string, display_name: string, ip_address: string, device_type: string, device_category: string, availability: string}> */
    public static function parse(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $host) {
            if (!is_array($host)) {
                continue;
            }
            $key = self::str($host, 'hostid');
            if ($key === '') {
                continue;
            }
            $name  = self::str($host, 'host') ?: $key;
            $iface = self::mainInterface($host);
            $out[] = [
                'device_key'      => $key,
                'name'            => $name,
                'display_name'    => self::str($host, 'name') ?: $name,
                'ip_address'      => self::addressOf($iface),
                'device_type'     => '',
                'device_category' => self::category($host),
                'availability'    => self::availability($host, $iface),
            ];
        }
        return $out;
    }

    /** IP (or DNS name when the interface is set to DNS) of the host's main interface; '' if none. */
    public static function ipAddress(array $host): string
    {
        return self::addressOf(self::mainInterface($host));
    }

    /** First group name after sorting; '' if none. */
    public static function category(array $host): string
    {
        $groups = $host['hostgroups'] ?? $host['groups'] ?? [];
        $names  = [];
        foreach (is_array($groups) ? $groups : [] as $g) {
            if (is_array($g) && self::str($g, 'name') !== '') {
                $names[] = self::str($g, 'name');
            }
        }
        sort($names, SORT_STRING);
        return $names[0] ?? '';
    }

    /** Main interface by type order; falls back to any interface of the best type when none is flagged main. */
    private static function mainInterface(array $host): ?array
    {
        $ifaces = array_values(array_filter(
            is_array($host['interfaces'] ?? null) ? $host['interfaces'] : [],
            'is_array'
        ));
        foreach ([true, false] as $mainOnly) {
            foreach (self::TYPE_ORDER as $type) {
                foreach ($ifaces as $i) {
                    if ((int) self::str($i, 'type') === $type && (!$mainOnly || self::str($i, 'main') === '1')) {
                        return $i;
                    }
                }
            }
        }
        return null;
    }

    private static function addressOf(?array $iface): string
    {
        if ($iface === null) {
            return '';
        }
        return self::str($iface, 'useip') === '0' ? self::str($iface, 'dns') : self::str($iface, 'ip');
    }

    private static function availability(array $host, ?array $iface): string
    {
        if (self::str($host, 'status') === '1') {
            return 'Disabled';
        }
        if (self::str($host, 'maintenance_status') === '1') {
            return 'Maintenance';
        }
        if ($iface === null) {
            return 'Unknown';
        }
        return match (self::str($iface, 'available')) {
            '1'     => 'Available',
            '2'     => 'Unavailable',
            default => 'Unknown',
        };
    }

    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? '';
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
