<?php

namespace GlpiPlugin\Nms\Driver;

/**
 * One NMS brand driver. listDevices() and listActiveAlarms() return the same
 * array shapes that the 1.3.2 DeviceParser/AlarmParser return, so DeviceSync,
 * AlarmSync and Hygiene do not change shape.
 */
interface Driver
{
    public function capabilities(): Capabilities;

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array;

    /** @return list<array<string, mixed>> same shape as DeviceParser::parse() */
    public function listDevices(): array;

    /** @return list<array<string, mixed>> same shape as AlarmParser::parse() */
    public function listActiveAlarms(): array;

    /**
     * The alarm in its CURRENT state, active or cleared, or null when the NMS no longer
     * knows it. Must not be limited to active alarms: AlarmSync and the webhook use it
     * to see a Clear. OpManager looks the alarm up by its entity (as 1.3.2 did).
     */
    public function alarmByKey(string $alarm_key, string $entity_key = ''): ?array;

    public function addNote(string $alarm_key, string $text): void;

    public function acknowledge(string $alarm_key): void;

    public function clear(string $alarm_key): void;
}
