<?php

namespace GlpiPlugin\Nms\Outbound;

use Glpi\RichText\RichText;
use GlpiPlugin\Nms\Connection\ConnectionStore;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Connection\Settings;
use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Ticketing\DbAlarmStore;
use GlpiPlugin\Nms\Ticketing\DbOutboxStore;
use GlpiPlugin\Nms\Ticketing\DbSyncLock;
use GlpiPlugin\Nms\Ticketing\GlpiTicketGateway;

/**
 * GLPI -> OpManager (Plan 3). Runs inside the technician's save: decide (Planner), queue,
 * try to send once with a short timeout, and never let an error reach GLPI. What is not
 * sent here is retried by the OutboxSend cron.
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
            $alarms     = (new DbAlarmStore())->activeForTicket($tickets_id);
            if ($alarms === []) {
                return;
            }
            // Group active alarms by connection.
            $byConn = [];
            foreach ($alarms as $alarm) {
                $byConn[(int) $alarm['connections_id']][] = $alarm;
            }
            $connStore = new DbConnectionStore();
            foreach ($byConn as $cid => $connAlarms) {
                $conn = $connStore->get($cid);
                if ($conn === null || (int) $conn->is_active !== 1 || (int) $conn->writeback_enabled !== 1) {
                    continue;
                }
                $settings = Settings::fromJson((string) $conn->settings);
                $cfg      = [
                    'service_user'          => (int) $settings->get('service_user'),
                    'outbound_max_attempts' => (int) $settings->get('outbound_max_attempts'),
                ];
                $uid = \Session::getLoginUserID();
                if (!Planner::isHuman($uid, \Session::isCron(), $cfg['service_user'], GlpiTicketGateway::isWriting())) {
                    continue;
                }
                if (!Planner::isOriginalFollowup((int) ($followup->fields['users_id'] ?? 0), $cfg['service_user'], (int) ($followup->fields['sourceitems_id'] ?? 0))) {
                    continue;
                }
                $outbox = new DbOutboxStore($cid);
                $rows = Planner::forFollowup(
                    $tickets_id,
                    (int) ($followup->fields['is_private'] ?? 0) === 1,
                    RichText::getTextFromHtml((string) ($followup->fields['content'] ?? '')),
                    (string) getUserName((int) $uid),
                    $connAlarms,
                    (int) $uid,
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
            $tickets_id = (int) $ticket->getID();
            $alarms     = (new DbAlarmStore())->activeForTicket($tickets_id);
            if ($alarms === []) {
                return;
            }
            $byConn = [];
            foreach ($alarms as $alarm) {
                $byConn[(int) $alarm['connections_id']][] = $alarm;
            }
            $connStore = new DbConnectionStore();
            foreach ($byConn as $cid => $connAlarms) {
                $conn = $connStore->get($cid);
                if ($conn === null || (int) $conn->is_active !== 1 || (int) $conn->writeback_enabled !== 1) {
                    continue;
                }
                $settings = Settings::fromJson((string) $conn->settings);
                $cfg      = [
                    'service_user'          => (int) $settings->get('service_user'),
                    'outbound_max_attempts' => (int) $settings->get('outbound_max_attempts'),
                ];
                $uid = \Session::getLoginUserID();
                if (!Planner::isHuman($uid, \Session::isCron(), $cfg['service_user'], GlpiTicketGateway::isWriting())) {
                    continue;
                }
                global $DB;
                $sol = $DB->request([
                    'SELECT' => ['content'],
                    'FROM'   => \ITILSolution::getTable(),
                    'WHERE'  => ['itemtype' => \Ticket::class, 'items_id' => $tickets_id, 'users_id' => $uid],
                    'ORDER'  => 'id DESC',
                    'LIMIT'  => 1,
                ])->current();
                $outbox = new DbOutboxStore($cid);
                $rows = Planner::forStatus(
                    $tickets_id,
                    (int) ($ticket->oldvalues['status'] ?? 0),
                    (int) $ticket->fields['status'],
                    $sol ? RichText::getTextFromHtml((string) $sol['content']) : null,
                    (string) getUserName((int) $uid),
                    $connAlarms,
                    [$outbox, 'hasClear'],
                    (int) $uid
                );
                self::queueAndSend($rows, $outbox, $conn, $cfg);
            }
        } catch (\Throwable $e) {
            \Toolbox::logInFile('nms', 'Outbound ticket hook: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * @param list<array{alarm_key: string, entity_key: string, tickets_id: int, users_id: int, action: string, payload: string}> $rows
     * @param DbOutboxStore $store
     * @param \GlpiPlugin\Nms\Connection\Connection $conn
     * @param array{service_user: int, outbound_max_attempts: int} $cfg
     */
    private static function queueAndSend(array $rows, DbOutboxStore $store, \GlpiPlugin\Nms\Connection\Connection $conn, array $cfg): void
    {
        if ($rows === []) {
            return;
        }
        $now   = (string) \Session::getCurrentTime();
        foreach ($rows as $row) {
            $store->enqueue($row, $now);
        }
        // One sender at a time (hook or cron), or a row could be sent twice (final review
        // I-2). Busy = someone is sending right now; the cron picks these rows up.
        $lock = new DbSyncLock(DbSyncLock::lockName((int) $conn->id) . '_outbox');
        try {
            if (!$lock->acquire(0)) {
                return;
            }
            try {
                $driver = DriverFactory::for($conn, Hooks::SEND_TIMEOUT);
                $sender = new Sender($store, $driver, $cfg['outbound_max_attempts']);
                $sender->run($now, 20, true);
                foreach ($sender->lastErrors() as $error) {
                    \Toolbox::logInFile('nms', 'Outbound: ' . $error . " (will retry)\n");
                }
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            \Toolbox::logInFile('nms', 'Outbound send: ' . $e->getMessage() . " (queued for cron)\n");
        }
    }
}
