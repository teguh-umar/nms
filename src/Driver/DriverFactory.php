<?php

namespace GlpiPlugin\Nms\Driver;

use InvalidArgumentException;
use GlpiPlugin\Nms\Connection\Connection;

/** Factory that builds a Driver for a Connection's brand. */
final class DriverFactory
{
    /** @var callable(Connection): Driver|null test override (consumed once), null = normal brand lookup */
    private static $testOverride = null;

    /** Test-only: inject a factory that the next for() call uses to build a Driver per Connection. */
    public static function setTestOverride(?callable $factory): void
    {
        self::$testOverride = $factory;
    }

    /**
     * @param Connection $c
     * @return Driver
     * @throws InvalidArgumentException when the brand has no driver yet
     */
    public static function for(Connection $c): Driver
    {
        if (self::$testOverride !== null) {
            return (self::$testOverride)($c);
        }
        return match ($c->brand) {
            'opmanager' => new \GlpiPlugin\Nms\Driver\OpManager\OpManagerDriver($c),
            default     => throw new InvalidArgumentException('No NMS driver for brand: ' . $c->brand),
        };
    }

    /** @return list<string> brands that have a Driver implementation. */
    public static function supportedBrands(): array
    {
        return ['opmanager'];
    }
}
