<?php

/**
 * NMS "Invoke a Webhook" receiver. Stateless (see plugin_nms_boot): no session,
 * no CSRF; authenticated by the X-NMS-Token header (X-OPM-Token also accepted for
 * OpManager). The body is a trigger {"alarm_id":"$alarmid","entity":"$entity"};
 * the alarm itself is fetched from the NMS API and run through the same engine
 * as the AlarmSync poll.
 */

use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Connection\Settings;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\Matching\DbAssetLookup;
use GlpiPlugin\Nms\Matching\EntityScope;
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

header('Content-Type: application/json');

$method  = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$headerToken = (string) ($_SERVER['HTTP_X_NMS_TOKEN'] ?? $_SERVER['HTTP_X_OPM_TOKEN'] ?? '');
$rawBody = (string) file_get_contents('php://input', false, null, 0, Receiver::MAX_BODY + 1);

$remote = $_SERVER['REMOTE_ADDR'] ?? '?';

$connStore = new DbConnectionStore();
$conn      = $connStore->byWebhookToken($headerToken);
if ($conn === null) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    \Toolbox::logInFile('nms', sprintf(
        "Webhook: HTTP 403 unauthorized (unknown or inactive token) from %s\n",
        $remote
    ));
    exit;
}

$driver    = DriverFactory::for($conn);
$settings  = Settings::fromJson((string) $conn->settings);
$cfg       = Config::ticketingConfigFromSettings($settings);
$cfg['tickets_enabled']  = (int) $conn->tickets_enabled;
$cfg['writeback_enabled']= (int) $conn->writeback_enabled;
$cfg['connections_id']   = (int) $conn->id;

$cid = (int) $conn->id;
$scope = EntityScope::forConnection($conn);
$receiver = new Receiver(
    $conn,
    $driver,
    new DbSyncLock(DbSyncLock::lockName($cid)),
    static function (array $alarm) use ($driver, $cfg, $cid, $settings, $scope): array {
        if ($cfg['tickets_enabled'] === 1 && $cfg['service_user'] <= 0) {
            // Same guard as the AlarmSync cron: a ticket without an author is never created.
            \Toolbox::logInFile('nms', "Webhook: no service user configured in Setup; alarm left to AlarmSync\n");
            throw new \RuntimeException('no service user configured');
        }
        $sync = new AlarmSync(
            $driver,
            new DbAlarmStore($cid),
            new GlpiTicketGateway($cfg),
            new AlarmAssetResolver(new DbDeviceIndex($cid), new Matcher(new DbAssetLookup(Config::assetItemtypes(), $scope)), $settings->get('match_mode')),
            new RangeProductionCheck(AddressingRanges::load($cfg['production_addressings']), [AddressingRanges::class, 'assetIps']),
            $cfg,
            new DbTicketRequestStore($cid)
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
    new DbTicketRequestStore($cid)
);

$result = $receiver->handle($method, $headerToken, $rawBody);

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
    $remote
));
http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['body']);
