<?php

use GlpiPlugin\Nms\Connection\ConnectionForm;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

$store = new DbConnectionStore();
$form  = new ConnectionForm($store);
$id    = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$self  = static fn (int $id): string => Html::getPrefixedUrl(ConnectionForm::FORM_PATH . '?id=' . $id);

if (isset($_POST['update_connection'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $result = $form->handleSave($id, $_POST);
    Session::addMessageAfterRedirect(htmlescape($result['message']), false, $result['ok'] ? INFO : ERROR);
    foreach ($result['errors'] as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    Html::redirect($self($result['ok'] ? $result['id'] : $id));
}

if (isset($_POST['generate_webhook_token'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $token = $form->generateToken($id);
    if ($token !== null) {
        // Shown exactly once, inline on the Webhook tab (a toast hides itself after 10 s).
        $_SESSION[ConnectionForm::NEW_TOKEN_KEY][$id] = $token;
    }
    Html::redirect($self($id));
}

if (isset($_POST['test_connection'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $conn = $store->get($id);
    if ($conn !== null) {
        try {
            $res = DriverFactory::for($conn)->testConnection();
        } catch (\Throwable $e) {
            // No driver for this brand yet; the message never holds a secret.
            $res = ['ok' => false, 'message' => $e->getMessage()];
        }
        Session::addMessageAfterRedirect(
            htmlescape(($res['ok'] ? __('Connection test OK', 'nms') : __('Connection test failed', 'nms')) . ($res['message'] !== '' ? ': ' . $res['message'] : '')),
            false,
            $res['ok'] ? INFO : ERROR
        );
    }
    Html::redirect($self($id));
}

Html::header(__('NMS connection', 'nms'), '', 'config', 'commondropdown');
echo '<div class="container-fluid">';
echo '<p><a href="' . htmlescape(Html::getPrefixedUrl(ConnectionForm::LIST_PATH)) . '"><i class="ti ti-arrow-left me-1"></i>' . __s('NMS connections', 'nms') . '</a></p>';
echo '<h2>' . ($id > 0 ? __s('Edit connection', 'nms') : __s('Add connection', 'nms')) . '</h2>';
echo $form->renderForm($id);
echo '</div>';
Html::footer();
