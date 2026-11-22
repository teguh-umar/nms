<?php

namespace GlpiPlugin\Nms\Matching;

/**
 * AssetLookup against the GLPI database, across every configured asset itemtype.
 * Does not rely on the session's active entities (cron has none: core would fall back
 * to the root entity, hub §4); it is limited only by the explicit $entities scope,
 * which a connection derives from its own entity (EntityScope).
 */
final class DbAssetLookup implements AssetLookup
{
    /** @var list<string> */
    private array $itemtypes;

    /** @var list<string> */
    private array $missing;

    /** @var list<int>|null entities matching is limited to; null = every entity */
    private ?array $entities;

    /**
     * @param string[]       $itemtypes
     * @param list<int>|null $entities  see EntityScope; null = no entity filter
     */
    public function __construct(array $itemtypes, ?array $entities = null)
    {
        $this->entities  = $entities;
        $resolved        = self::resolvable($itemtypes, static fn (string $t) => getItemForItemtype($t) ?: null);
        $this->itemtypes = $resolved['ok'];
        $this->missing   = $resolved['missing'];
    }

    /**
     * Pure split of configured itemtypes into resolvable and missing.
     *
     * @param string[]                  $itemtypes
     * @param callable(string): ?object $factory
     * @return array{ok: list<string>, missing: list<string>}
     */
    public static function resolvable(array $itemtypes, callable $factory): array
    {
        $ok = $missing = [];
        foreach ($itemtypes as $itemtype) {
            if ($factory($itemtype) === null) {
                $missing[] = $itemtype;
            } else {
                $ok[] = $itemtype;
            }
        }
        return ['ok' => $ok, 'missing' => $missing];
    }

    /** @return list<string> configured itemtypes this process could not load */
    public function missingItemtypes(): array
    {
        return $this->missing;
    }

    public function byName(string $name): array
    {
        global $DB;

        $hits = [];
        foreach ($this->itemtypes as $itemtype) {
            $item = getItemForItemtype($itemtype);
            if (!$item instanceof \CommonDBTM) {
                continue;
            }
            $table = $itemtype::getTable();
            // find() does not add getSystemSQLCriteria(): without it a custom asset
            // query would search every custom asset definition's rows.
            $where = [$table . '.name' => $name] + self::liveCriteria($item, $table) + $itemtype::getSystemSQLCriteria();
            if ($this->entities !== null) {
                $where[$table . '.entities_id'] = $this->entities;
            }
            foreach ($DB->request(['SELECT' => [$table . '.id'], 'FROM' => $table, 'WHERE' => $where]) as $row) {
                $hits[] = ['itemtype' => $itemtype, 'items_id' => (int) $row['id']];
            }
        }
        return $hits;
    }

    /**
     * Every live owner of the IP is counted, whatever its type - an IP a Computer
     * shares with a Printer is ambiguous even if only Computer is configured
     * (spec §5). An owner whose class cannot be loaded here is still counted: its
     * liveness is unknown, and guessing it away could create a false unique match.
     */
    public function byIp(string $ip): array
    {
        global $DB;

        if ($this->itemtypes === []) {
            return [];
        }
        $owners = [];
        foreach ($DB->request([
            'SELECT' => ['mainitemtype', 'mainitems_id'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => [
                'name'         => $ip,
                'is_deleted'   => 0,
                'mainitemtype' => ['<>', ''],
                'mainitems_id' => ['>', 0],
            ],
        ]) as $row) {
            $itemtype = (string) $row['mainitemtype'];
            $item     = getItemForItemtype($itemtype);
            if ($item instanceof \CommonDBTM) {
                if (!$item->getFromDB((int) $row['mainitems_id'])) {
                    continue;
                }
                if (!empty($item->fields['is_deleted']) || !empty($item->fields['is_template'])) {
                    continue;
                }
                if (!self::inScope($this->entities, (int) ($item->fields['entities_id'] ?? 0))) {
                    continue;
                }
            }
            $owners[] = ['itemtype' => $itemtype, 'items_id' => (int) $row['mainitems_id']];
        }
        return self::ipHits($owners, $this->itemtypes);
    }

    /** Pure. null = every entity. */
    public static function inScope(?array $entities, int $entities_id): bool
    {
        return $entities === null || in_array($entities_id, $entities, true);
    }

    /**
     * Pure. Several distinct owners: all of them (the Matcher treats >1 as ambiguous).
     * Exactly one owner: it, if its type is configured, else nothing.
     *
     * @param list<array{itemtype: string, items_id: int}> $owners
     * @param string[]                                      $configured
     * @return list<array{itemtype: string, items_id: int}>
     */
    public static function ipHits(array $owners, array $configured): array
    {
        $distinct = [];
        foreach ($owners as $owner) {
            $distinct[$owner['itemtype'] . '#' . $owner['items_id']] = $owner;
        }
        $distinct = array_values($distinct);
        if (count($distinct) !== 1) {
            return $distinct;
        }
        return in_array($distinct[0]['itemtype'], $configured, true) ? $distinct : [];
    }

    /** @return array<string, int> */
    private static function liveCriteria(\CommonDBTM $item, string $table): array
    {
        $criteria = [];
        if ($item->maybeDeleted()) {
            $criteria[$table . '.is_deleted'] = 0;
        }
        if ($item->maybeTemplate()) {
            $criteria[$table . '.is_template'] = 0;
        }
        return $criteria;
    }
}
