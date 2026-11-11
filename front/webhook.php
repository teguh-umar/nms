<?php

/**
 * NMS "Invoke a Webhook" receiver. Stateless (see plugin_nms_boot): no session,
 * no CSRF; authenticated by the X-NMS-Token header (X-OPM-Token also accepted for
 * OpManager). The body is a trigger {"alarm_id":"$alarmid","entity":"$entity"};
 * the alarm itself is fetched from the NMS API and run through the same engine
 * as the AlarmSync poll.
 */

use GlpiPlugin\Nms\Connection\Connection;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Matching\DbAssetLookup;
use GlpiPlugin\Nms\Matching\Matcher;
use GlpiPlugin\Nms\Ticketing\AddressingRanges;
use GlpiPlugin\Nms\Ticketing\AlarmAssetResolver;
use GlpiPlugin\Nms\Ticketing\AlarmSync;
use GlpiPlugin\Nms\Ticketing\DbAlarmStore;
use GlpiPlugin\Nms\Ticketing\DbDeviceIndex;
use GlpiPlugin\Nms\Ticketing\DbSyncLock;
use GlpiPlugin\Nms\Ticketing\DbTicketRequestStore;
use GlpiPlugin\Nms\Ticketing\GlpiTicketGateway;
use GlpiPlugin\Nms\Ticketing\RangeProductionCheck;
use GlpiPlugin\Nms\Webhook\Receiver;

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
$cfg    = Config::ticketingConfig();

$receiver = new Receiver(
    Config::webhookToken(),
    $driver,
    new DbSyncLock(),
    static function (array $alarm) use ($driver, $cfg): array {
        if ($cfg['service_user'] <= 0) {
            // Same guard as the AlarmSync cron: a ticket without an author is never created.
            \Toolbox::logInFile('nms', "Webhook: no service user configured in Setup; alarm left to AlarmSync\n");
            throw new \RuntimeException('no service user configured');
        }
        $sync = new AlarmSync(
            $driver,
            new DbAlarmStore(),
            new GlpiTicketGateway($cfg),
            new AlarmAssetResolver(new DbDeviceIndex(), new Matcher(new DbAssetLookup(Config::assetItemtypes())), Config::get('match_mode')),
            new RangeProductionCheck(AddressingRanges::load($cfg['production_addressings']), [AddressingRanges::class, 'assetIps']),
            $cfg,
            new DbTicketRequestStore()
        );
        // Same context as the cron: no profile here, so without this every haveRight() is
        // false and Ticket::add() drops template SLA/OLA and recomputes the priority.
        \Session::loadEntity(0, true);
        $stats = \Session::callAsSystem(static fn () => $sync->processOne($alarm, (string) \Session::getCurrentTime()));
        foreach ($sync->lastErrors() as $error) {
            \Toolbox::logInFile('nms', 'Webhook: ' . $error . "\n");
        }
        return $stats;
    },
    new DbTicketRequestStore()
);

$result = $receiver->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['HTTP_X_NMS_TOKEN'] ?? $_SERVER['HTTP_X_OPM_TOKEN'] ?? ''),
    (string) file_get_contents('php://input', false, null, 0, Receiver::MAX_BODY + 1)
);

// Every answer is logged (one line each), so a working webhook is visible too (0.4.1).
$summary = $result['body']['message'] ?? '';
if ($summary === '' && isset($result['body']['stats']) && is_array($result['body']['stats'])) {
    $done    = array_filter($result['body']['stats']);
    $summary = 'processed: ' . ($done === [] ? 'no change' : implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($done), $done)));
}
if (isset($result['body']['received'])) {
    $summary .= ' | received: ' . $result['body']['received'];
}
if (!empty($result['body']['request_not_saved'])) {
    $summary .= ' | request NOT saved';
}
\Toolbox::logInFile('nms', sprintf(
    "Webhook: HTTP %d %s (alarm %s) from %s\n",
    $result['status'],
    $summary,
    $result['body']['alarm_id'] ?? '-',
    $_SERVER['REMOTE_ADDR'] ?? '?'
));
http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['body']);
