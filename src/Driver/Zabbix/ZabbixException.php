<?php

namespace GlpiPlugin\Nms\Driver\Zabbix;

/** Any failure talking to Zabbix. Messages are always redacted (no token, no password). */
final class ZabbixException extends \RuntimeException
{
}
