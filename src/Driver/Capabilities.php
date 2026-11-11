<?php

namespace GlpiPlugin\Nms\Driver;

/** Static capability matrix entry for one brand. */
final class Capabilities
{
    public function __construct(
        public readonly bool $devices,
        public readonly bool $alarms,
        public readonly bool $availability,
        public readonly bool $note,
        public readonly bool $ack,
        public readonly bool $clear,
        public readonly bool $webhook,
    ) {}
}
