<?php

namespace GlpiPlugin\Nms\Driver\Zabbix;

/**
 * Zabbix refused to close a problem (the trigger does not allow manual closing).
 * A DomainException, so Outbound\Sender marks the outbox row failed without retry.
 */
final class CloseRefused extends \DomainException
{
}
