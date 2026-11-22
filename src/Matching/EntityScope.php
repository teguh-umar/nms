<?php

namespace GlpiPlugin\Nms\Matching;

use GlpiPlugin\Nms\Connection\Connection;

/**
 * The entities a connection may match assets in. Root + recursive is "every entity"
 * (null = no filter), so a connection that covers the whole GLPI behaves as before.
 */
final class EntityScope
{
    /**
     * Pure.
     *
     * @param callable(int): array<int|string, int|string> $sons entity id => ids of it and its descendants
     * @return list<int>|null null = every entity
     */
    public static function of(int $entities_id, int $is_recursive, callable $sons): ?array
    {
        if ($entities_id === 0 && $is_recursive === 1) {
            return null;
        }
        if ($is_recursive !== 1) {
            return [$entities_id];
        }
        return array_values(array_unique(array_map('intval', array_values($sons($entities_id)))));
    }

    /** @return list<int>|null */
    public static function forConnection(Connection $c): ?array
    {
        return self::of(
            $c->entities_id,
            $c->is_recursive,
            static fn (int $id): array => getSonsOf('glpi_entities', $id)
        );
    }
}
