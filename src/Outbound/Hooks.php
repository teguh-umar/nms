<?php

namespace GlpiPlugin\Nms\Outbound;

use Glpi\RichText\RichText;
use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Ticketing\DbAlarmStore;
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

    /** MySQL lock name shared by these hooks and the OutboxSend cron. */
    public const OUTBOX_LOCK = 'opmanager_outbox';

    public static function followupAdded(\ITILFollowup $followup): void
    {
        try {
            if (($followup->fields['itemtype'] ?? '') !== \Ticket::class) {
                return;
            }
            $cfg = Config::ticketingConfig();
            $uid = \Session::getLoginUserID();
            if (!Planner::isHuman($uid, \Session::isCron(), $cfg['service_user'], GlpiTicketGateway::isWriting())) {
                return;
            }
            if (!Planner::isOriginalFollowup((int) ($followup->fields['users_id'] ?? 0), $cfg['service_user'], (int) ($followup->fields['sourceitems_id'] ?? 0))) {
                return;
            }
            $tickets_id = (int) $followup->fields['items_id'];
            $store      = new DbOutboxStore();
            $rows = Planner::forFollowup(
                $tickets_id,
                (int) ($followup->fields['is_private'] ?? 0) === 1,
                RichText::getTextFromHtml((string) ($followup->fields['content'] ?? '')),
                (string) getUserName((int) $uid),
                DbAlarmStore::activeForTicket($tickets_id),
                (int) $uid,
                [$store, 'hasPendingAck']
            );
            self::queueAndSend($rows, $cfg);
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
            $cfg = Config::ticketingConfig();
            $uid = \Session::getLoginUserID();
            if (!Planner::isHuman($uid, \Session::isCron(), $cfg['service_user'], GlpiTicketGateway::isWriting())) {
                return;
            }
            global $DB;
            $tickets_id = (int) $ticket->getID();
            // The solution row is inserted before ITILSolution::post_addItem updates the
            // ticket status, so it is already readable here (CommonDBTM::add order).
            $sol = $DB->request([
                'SELECT' => ['content'],
                'FROM'   => \ITILSolution::getTable(),
                'WHERE'  => ['itemtype' => \Ticket::class, 'items_id' => $tickets_id, 'users_id' => $uid],
                'ORDER'  => 'id DESC',
                'LIMIT'  => 1,
            ])->current();
            $store = new DbOutboxStore();
            $rows  = Planner::forStatus(
                $tickets_id,
                (int) ($ticket->oldvalues['status'] ?? 0),
                (int) $ticket->fields['status'],
                $sol ? RichText::getTextFromHtml((string) $sol['content']) : null,
                (string) getUserName((int) $uid),
                DbAlarmStore::activeForTicket($tickets_id),
                [$store, 'hasClear'],
                (int) $uid
            );
            self::queueAndSend($rows, $cfg);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('nms', 'Outbound ticket hook: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * @param list<array{alarm_key: string, entity_key: string, tickets_id: int, users_id: int, action: string, payload: string}> $rows
     * @param array{outbound_max_attempts: int} $cfg
     */
    private static function queueAndSend(array $rows, array $cfg): void
    {
        if ($rows === []) {
            return;
        }
        $now   = (string) \Session::getCurrentTime();
        $store = new DbOutboxStore();
        foreach ($rows as $row) {
            $store->enqueue($row, $now);
        }
        // One sender at a time (hook or cron), or a row could be sent twice (final review
        // I-2). Busy = someone is sending right now; the cron picks these rows up.
        $lock = new DbSyncLock(Hooks::OUTBOX_LOCK);
        try {
            if (!$lock->acquire(0)) {
                return;
            }
            try {
                $conn = new Connection([
                    'name'           => 'opm.example.test',
                    'brand'          => 'opmanager',
                    'entities_id'    => 0,
                    'is_recursive'   => 1,
                    'url'            => Config::get('opm_url'),
                    'api_key'        => Config::get('opm_api_key'),
                    'username'       => '',
                    'password'       => '',
                    'verify_tls'     => (int) Config::get('opm_verify_ssl') === 1 ? 1 : 0,
                    'is_active'      => 1,
                    'writeback_enabled' => 1,
                    'tickets_enabled' => 1,
                    'webhook_token'  => Config::get('webhook_token'),
                    'settings'       => '{}',
                    'date_creation'  => '',
                    'date_mod'       => '',
                ]);
                $driver = DriverFactory::for($conn);
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
