<?php

use GlpiPlugin\Nms\Device;
use GlpiPlugin\Nms\Hygiene\DeviceException;
use GlpiPlugin\Nms\RightDefinition;

include('../../../inc/includes.php');

$device = new Device();

if (isset($_POST['pin'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $error = $device->pin((int) $_POST['id'], (string) ($_POST['itemtype'] ?? ''), (int) ($_POST['items_id'] ?? 0));
    if ($error !== null) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(__s('Device pinned to the asset', 'nms'));
    }
    Html::back();
}

if (isset($_POST['unpin'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $device->unpin((int) $_POST['id']);
    Session::addMessageAfterRedirect(__s('Manual pin removed; the next device sync matches it automatically', 'nms'));
    Html::back();
}

// D34: exclude a device from the hygiene tickets, or cancel that (spec §5).
if (isset($_POST['except']) || isset($_POST['unexcept'])) {
    Session::checkRight(RightDefinition::HYGIENE, UPDATE);
    if (!$device->getFromDB((int) ($_POST['id'] ?? 0))) {
        Html::back();
    }
    $key = (string) $device->fields['device_key'];
    if (isset($_POST['except'])) {
        $error = DeviceException::except($key, (string) ($_POST['reason'] ?? ''), (int) Session::getLoginUserID());
        if ($error !== null) {
            Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
        } else {
            Session::addMessageAfterRedirect(__s('Device excluded from hygiene tickets', 'nms'));
        }
    } elseif (DeviceException::revoke($key, (int) Session::getLoginUserID())) {
        Session::addMessageAfterRedirect(__s('Exclusion cancelled; the next hygiene run classifies the device again', 'nms'));
    }
    Html::back();
}

Session::checkRight(RightDefinition::MONITORING, READ);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    // Devices are created by the sync only - there is no add form.
    Html::redirect(Device::getSearchURL());
}

$menus = ['central' => [\GlpiPlugin\Pgnsuite\Menu::SECTOR, 'nms', 'device'], 'helpdesk' => []];
Device::displayFullPageForItem($id, $menus, []);
