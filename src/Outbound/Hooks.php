<?php

namespace GlpiPlugin\Nms\Outbound;

use Glpi\RichText\RichText;
use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Connection\Settings;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Ticketing\DbAlarmStore;
use GlpiPlugin\Nms\Ticketing\DbSyncLock;
use GlpiPlugin\Nms\Ticketing\GlpiTicketGateway;

/**
 * GLPI -> NMS write-back. Runs inside the technician's save: decide (Planner), queue,
 * try to send once with a short timeout, and never let an error reach GLPI. What is not
 * sent here is retried by the OutboxSend cron.
 *
 * A ticket can carry alarms of several connections: each connection is handled on its own,
 * with its own service user, outbox rows and lock (the cron's lock name).
 */
final class Hooks
{
    public const SEND_TIMEOUT = 5;

    public static function followupAdded(\ITILFollowup $followup): void
    {
        try {
            if (($followup->fields['itemtype'] ?? '') !== \Ticket::class) {
                return;
            }
            $tickets_id = (int) ($followup->fields['items_id'] ?? 0);
            $uid        = (int) \Session::getLoginUserID();
            foreach (self::connectionsOfTicket($tickets_id) as [$conn, $cfg, $alarms]) {
                if (!Planner::isHuman($uid, \Session::isCron(), $cfg['service_user'], GlpiTicketGateway::isWriting())) {
                    continue;
                }
                if (!Planner::isOriginalFollowup((int) ($followup->fields['users_id'] ?? 0), $cfg['service_user'], (int) ($followup->fields['sourceitems_id'] ?? 0))) {
                    continue;
                }
                $outbox = new DbOutboxStore((int) $conn->id);
                $rows   = Planner::forFollowup(
                    $tickets_id,
                    (int) ($followup->fields['is_private'] ?? 0) === 1,
                    RichText::getTextFromHtml((string) ($followup->fields['content'] ?? '')),
                    (string) getUserName($uid),
                    $alarms,
                    $uid,
                    [$outbox, 'hasPendingAck']
                );
                self::queueAndSend($rows, $outbox, $conn, $cfg);
            }
        } catch (\Throwable $e) {
            \Toolbox::logInFile('nms', 'Outbound followup hook: ' . $e->getMessage() . "\n");
        }
    }

    public static function ticketUpdated(\Ticket $ticket): void
    {
        try {
            if (!in_array('status', $ticket->updates ?? [], true)) {
                return;
            }
            global $DB;
            $tickets_id = (int) $ticket->getID();
            $uid        = (int) \Session::getLoginUserID();
            $solution   = null;
            foreach (self::connectionsOfTicket($tickets_id) as [$conn, $cfg, $alarms]) {
                if (!Planner::isHuman($uid, \Session::isCron(), $cfg['service_user'], GlpiTicketGateway::isWriting())) {
                    continue;
                }
                if ($solution === null) {
                    // The solution row is inserted before ITILSolution::post_addItem updates the
                    // ticket status, so it is already readable here (CommonDBTM::add order).
                    $sol = $DB->request([
                        'SELECT' => ['content'],
                        'FROM'   => \ITILSolution::getTable(),
                        'WHERE'  => ['itemtype' => \Ticket::class, 'items_id' => $tickets_id, 'users_id' => $uid],
                        'ORDER'  => 'id DESC',
                        'LIMIT'  => 1,
                    ])->current();
                    $solution = [$sol ? RichText::getTextFromHtml((string) $sol['content']) : null];
                }
                $outbox = new DbOutboxStore((int) $conn->id);
                $rows   = Planner::forStatus(
                    $tickets_id,
                    (int) ($ticket->oldvalues['status'] ?? 0),
                    (int) $ticket->fields['status'],
                    $solution[0],
                    (string) getUserName($uid),
                    $alarms,
                    [$outbox, 'hasClear'],
                    $uid
                );
                self::queueAndSend($rows, $outbox, $conn, $cfg);
            }
        } catch (\Throwable $e) {
            \Toolbox::logInFile('nms', 'Outbound ticket hook: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * The ticket's active alarms grouped by connection, for the connections that are active
     * and allow write-back.
     *
     * @return list<array{0: Connection, 1: array<string, mixed>, 2: list<array{alarm_key: string, entity_key: string, connections_id: int}>}>
     */
    private static function connectionsOfTicket(int $tickets_id): array
    {
        $byConn = [];
        foreach (DbAlarmStore::activeForTicket($tickets_id) as $alarm) {
            $byConn[(int) $alarm['connections_id']][] = $alarm;
        }
        $out   = [];
        $store = new DbConnectionStore();
        foreach ($byConn as $cid => $alarms) {
            $conn = $store->get((int) $cid);
            if ($conn === null || (int) $conn->is_active !== 1 || (int) $conn->writeback_enabled !== 1) {
                continue;
            }
            $out[] = [$conn, Config::ticketingConfigFromSettings(Settings::fromJson((string) $conn->settings)), $alarms];
        }
        return $out;
    }

    /**
     * @param list<array{alarm_key: string, entity_key: string, tickets_id: int, users_id: int, action: string, payload: string}> $rows
     * @param array{outbound_max_attempts: int} $cfg
     */
    private static function queueAndSend(array $rows, DbOutboxStore $store, Connection $conn, array $cfg): void
    {
        if ($rows === []) {
            return;
        }
        $now = (string) \Session::getCurrentTime();
        foreach ($rows as $row) {
            $store->enqueue($row, $now);
        }
        // One sender at a time per connection (hook or cron), or a row could be sent twice
        // (final review I-2). Busy = someone is sending right now; the cron picks these rows up.
        $lock = new DbSyncLock(DbSyncLock::outboxLockName((int) $conn->id));
        try {
            if (!$lock->acquire(0)) {
                return;
            }
            try {
                $driver = DriverFactory::for($conn, self::SEND_TIMEOUT);
                $sender = new Sender($store, $driver, $cfg['outbound_max_attempts']);
                $sender->run($now, 20, true);
                foreach ($sender->lastErrors() as $error) {
                    \Toolbox::logInFile('nms', 'Outbound conn ' . (int) $conn->id . ': ' . $error . " (will retry)\n");
                }
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            \Toolbox::logInFile('nms', 'Outbound send conn ' . (int) $conn->id . ': ' . $e->getMessage() . " (queued for cron)\n");
        }
    }
}
