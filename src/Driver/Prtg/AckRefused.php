<?php

namespace GlpiPlugin\Nms\Driver\Prtg;

/**
 * PRTG answered and refused an acknowledge (or the sensor is gone): permanent.
 * A DomainException, so Outbound\Sender marks the outbox row failed without retry.
 */
final class AckRefused extends \DomainException
{
}
