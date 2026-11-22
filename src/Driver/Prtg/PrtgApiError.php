<?php

namespace GlpiPlugin\Nms\Driver\Prtg;

/** A PRTG application-level refusal (HTTP 400/401/403 with a PRTG error body, or an {"error": ...} body). */
class PrtgApiError extends PrtgException
{
}
