<?php

namespace GlpiPlugin\Nms\Driver\OpManager;

/** Any failure talking to OpManager. Messages are always redacted (no apiKey). */
final class ClientException extends \RuntimeException
{
}
