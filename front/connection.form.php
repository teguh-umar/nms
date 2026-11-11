<?php

use GlpiPlugin\Nms\Connection\ConnectionStore;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Connection\ConnectionForm;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

$canedit = Session::haveRight(RightDefinition::CONFIG, UPDATE);
$store   = new DbConnectionStore();
$form    = new ConnectionForm($store);

$id = (int) ($_GET['id'] ?? 0);

if ($canedit && isset($_POST['update_connection'])) {
    $result = $form->handleSave($id, $_POST);
    if ($result['ok']) {
        Session::addMessageAfterRedirect(__s($result['message'], 'nms'));
    } else {
        Session::addMessageAfterRedirect(__s($result['message'], 'nms'), false, ERROR);
    }
    Html::back();
}

if ($canedit && isset($_POST['test_connection'])) {
    $conn = $store->get($id);
    if ($conn !== null) {
        $driver = DriverFactory::for($conn);
        $res    = $driver->testConnection();
        Session::addMessageAfterRedirect(
            __s(($res['ok'] ? __('Connection test OK', 'nms') : __('Connection test failed', 'nms'))
                . ': ' . $res['message'], 'nms')
        );
    }
    Html::back();
}

Html::header(__('NMS connection', 'nms'), '', 'config', 'commondropdown');
echo '<div class="container">';
echo '<h1>' . ($id > 0 ? __('Edit connection', 'nms') : __('Add connection', 'nms')) . '</h1>';
echo $form->renderForm($id);
echo '</div>';
Html::footer();
