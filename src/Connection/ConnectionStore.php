<?php

namespace GlpiPlugin\Nms\Connection;

use GlpiPlugin\Nms\Connection\Connection;

/**
 * Store of NMS connections. Every store, cron pass, webhook and setting is keyed
 * by connections_id.
 */
interface ConnectionStore
{
    /** @return list<Connection> only connections with is_active = 1 */
    public function active(): array;

    /** @return Connection|null */
    /** @return list<Connection> every connection, active or not */
    public function all(): array;

    public function get(int $id): ?Connection;

    /**
     * Resolve a connection by its decrypted webhook token.
     * Comparison is constant time; inactive connections are ignored.
     * @return Connection|null
     */
    public function byWebhookToken(string $token): ?Connection;

    /** @param array<string, mixed> $fields @return int new connection id */
    public function save(array $fields): int;

    /**
     * Record the result of a sync pass. $kind is 'device' or 'alarm'.
     */
    public function recordStatus(int $id, string $kind, string $status, string $error, string $now): void;
}
