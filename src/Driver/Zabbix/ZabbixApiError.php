<?php

namespace GlpiPlugin\Nms\Driver\Zabbix;

/**
 * The Zabbix API answered (HTTP 200) with a JSON-RPC `error` object: the request
 * reached Zabbix and was refused. Unlike transport, HTTP or decode failures, repeating
 * it cannot succeed, so callers may treat it as permanent. Locale-independent: it is
 * the error object that marks it, never the message text.
 */
final class ZabbixApiError extends ZabbixException
{
}
