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

    public function alarmByKey(string $key): ?array;

    public function addNote(string $alarm_key, string $text): void;

    public function acknowledge(string $alarm_key): void;

    public function clear(string $alarm_key): void;
}
