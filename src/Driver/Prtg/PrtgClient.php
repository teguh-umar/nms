<?php

namespace GlpiPlugin\Nms\Driver\Prtg;

/**
 * Transport-level PRTG client. Rows are normalised:
 *   device: array{id: string, name: string, host: string, group: string, status_raw: int, status: string, tags: string}
 *   sensor: array{id: string, name: string, device_id: string, device: string, group: string, status_raw: int,
 *                 status: string, message: string, type: string, lastcheck: ?float}
 * lastcheck is the PRTG raw date (OLE automation days), null when PRTG gave none.
 */
interface PrtgClient
{
    public function version(): string;

    /** 'key' or 'login'. */
    public function authMode(): string;

    /** @return list<array<string, mixed>> */
    public function devices(): array;

    /**
     * @param list<int> $statusCodes status_raw values to keep; [] means every status
     * @return list<array<string, mixed>>
     */
    public function sensors(array $statusCodes): array;

    /** @return array<string, mixed>|null null when PRTG has no such sensor */
    public function sensor(string $id): ?array;

    public function acknowledge(string $id, string $message): void;
}
