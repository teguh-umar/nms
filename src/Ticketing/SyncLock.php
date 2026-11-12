<?php

namespace GlpiPlugin\Nms\Ticketing;

/** One lock shared by the AlarmSync cron and the webhook, so one alarm is never ticketed twice. */
interface SyncLock
{
    public const NAME = 'nms_alarm_sync';

    public function acquire(int $timeout_seconds): bool;

    public function release(): void;
}
