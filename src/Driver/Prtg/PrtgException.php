<?php

namespace GlpiPlugin\Nms\Driver\Prtg;

/** Any failure talking to PRTG. Messages are always redacted (no key, password or passhash). */
class PrtgException extends \RuntimeException
{
}
