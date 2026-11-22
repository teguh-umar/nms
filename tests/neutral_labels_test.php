<?php

// Task 6: translated UI labels say "NMS", not "OpManager". Ticket texts and the hygiene
// strings that production OpManager tickets carry stay as they are (controller ruling R9).
require __DIR__ . '/bootstrap.php';

$read = static fn (string $rel): string => (string) file_get_contents(OPM_ROOT . '/' . $rel);

// Translated UI strings: each must appear verbatim, and no __()/__s() call may still say OpManager.
$neutral = [
    'src/AssetSections.php'   => ['NMS data has never been synced.', 'NMS data is older than %d minutes (last sync: %s).', 'NMS device', 'NMS alarms'],
    'src/Device.php'          => ['This asset type is not enabled in NMS Integration setup', 'NMS identity'],
    'src/Alarm.php'           => ['NMS entity'],
    'src/Cron.php'            => ['Sync NMS devices and match them to GLPI assets', 'Pull active NMS alarms and create/update tickets', 'Send queued notes and clears to the NMS'],
    'src/Coverage/Cards.php'  => ['NMS coverage of GLPI assets (%)', 'NMS devices', 'NMS devices not in GLPI', 'NMS device matching', 'NMS devices by category', 'Active NMS alarms by severity', 'Open tickets from NMS alarms'],
    'src/Coverage/View.php'   => ['NMS devices', 'registered but not in any NMS'],
    'src/Hygiene/HygieneRun.php' => ['NMS device data is stale (device sync failing); hygiene tickets postponed'],
];

foreach ($neutral as $rel => $labels) {
    $src = $read($rel);
    $missing = array_filter($labels, fn ($l) => !str_contains($src, "'{$l}'"));
    check("{$rel} carries the NMS labels", $missing === [], 'missing: ' . implode(' | ', $missing));
    check("{$rel} has no OpManager translated label", !preg_match("/__s?\\(\\s*'OpManager/", $src));
}
check('HygieneRun stale text no longer says OpManager', !str_contains($read('src/Hygiene/HygieneRun.php'), "'OpManager device data"));

// Kept on purpose: production OpManager ticket texts, hygiene report and classifier labels (R9).
$kept = [
    'src/Ticketing/Messages.php'   => ['OpManager alarm %s changed: %s - %s', 'All OpManager alarms on this ticket are Clear.'],
    'src/Hygiene/Report.php'       => ['[OPM-HYGIENE] Monitored in OpManager, not in GLPI - ', 'Open the OpManager device list', 'Dead - candidate for removal from OpManager'],
    'src/Hygiene/Classifier.php'   => ['Unmanaged, dead: remove from OpManager'],
];

foreach ($kept as $rel => $texts) {
    $src = $read($rel);
    $gone = array_filter($texts, fn ($t) => !str_contains($src, $t));
    check("{$rel} keeps its OpManager ticket/hygiene texts", $gone === [], 'changed: ' . implode(' | ', $gone));
}

done();
