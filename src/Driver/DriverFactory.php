<?php

namespace GlpiPlugin\Nms\Driver;

use InvalidArgumentException;
use GlpiPlugin\Nms\Connection\Connection;

/** Factory that builds a Driver for a Connection's brand. */
final class DriverFactory
{
    /**
     * @param Connection $c
     * @return Driver
     * @throws InvalidArgumentException when the brand has no driver yet
     */
    public static function for(Connection $c): Driver
    {
        return match ($c->brand) {
            'opmanager' => new \GlpiPlugin\Nms\Driver\OpManager\OpManagerDriver($c),
            default     => throw new InvalidArgumentException('No NMS driver for brand: ' . $c->brand),
        };
    }
}
