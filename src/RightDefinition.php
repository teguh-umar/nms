<?php

namespace GlpiPlugin\Nms;

/**
 * Single source of truth for the plugin's profile rights.
 *
 * | right                       | mask          | gates                                   |
 * |-----------------------------|---------------|-----------------------------------------|
 * | plugin_nms_config     | READ | UPDATE | Setup section, manual pin/unpin         |
 * | plugin_nms_monitoring | READ          | Monitoring section, Devices list/form   |
 * | plugin_nms_hygiene    | READ | UPDATE | NMS hygiene view; UPDATE = exceptions   |
 */
final class RightDefinition
{
    public const CONFIG     = 'plugin_nms_config';
    public const MONITORING = 'plugin_nms_monitoring';
    public const HYGIENE    = 'plugin_nms_hygiene';

    /** right name => allowed mask */
    public const ALL = [
        self::CONFIG     => READ | UPDATE,
        self::MONITORING => READ,
        self::HYGIENE    => READ | UPDATE,
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ALL);
    }

    /** @return array<int, string> column labels for Profile::displayRightsChoiceMatrix */
    public static function rightsMatrix(string $name): array
    {
        return match ($name) {
            self::CONFIG     => [READ => __('Read'), UPDATE => __('Update')],
            self::MONITORING => [READ => __('Read')],
            self::HYGIENE    => [READ => __('Read'), UPDATE => __('Update')],
            default          => [],
        };
    }

    /** Row label in the Profile rights matrix; one per right. */
    public static function label(string $name): string
    {
        return match ($name) {
            self::CONFIG     => __('Configuration'),
            self::MONITORING => __('Monitoring', 'nms'),
            self::HYGIENE    => __('NMS hygiene', 'nms'),
            default          => $name,
        };
    }
}
