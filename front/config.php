<?php

use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\Migration\FromOpmanager;
use GlpiPlugin\Nms\RightDefinition;
use GlpiPlugin\Nms\SuiteConfig;
use GlpiPlugin\Pgnsuite\ConfigTab;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

if (isset($_POST['generate_webhook_token'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    $token = Config::newWebhookToken();
    Config::set(['webhook_token' => $token]);
    // Shown exactly once, inline in the Setup form (a toast hides itself after 10 s).
    $_SESSION[SuiteConfig::NEW_TOKEN_KEY] = $token;
    if (!empty($_POST[SuiteConfig::FROM_SUITE_FIELD])) {
        Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
    }
    Html::back();
}

if (isset($_POST['update'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);

    $result  = Config::sanitize($_POST, array_keys(Config::offeredItemtypes()));
    $tickets = Config::sanitizeTicketing($_POST);
    $hygiene = Config::sanitizeHygiene($_POST);
    $result  = [
        'values' => $result['values'] + $tickets['values'] + $hygiene['values'],
        'errors' => array_merge($result['errors'], $tickets['errors'], $hygiene['errors']),
    ];
    Config::set($result['values']);
    foreach ($result['errors'] as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    if ($result['errors'] === []) {
        Session::addMessageAfterRedirect(__s('Settings saved', 'nms'));
    }

    if (!empty($_POST[SuiteConfig::FROM_SUITE_FIELD])) {
        Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
    }
    Html::back();
}

if (isset($_POST['migrate_from_opmanager'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    global $DB;
    $result = FromOpmanager::run($DB, date('Y-m-d H:i:s'));
    if (array_key_exists('skipped', $result)) {
        Session::addMessageAfterRedirect(__s('OpManager not installed: ' . $result['skipped'], 'nms'), false, ERROR);
    } else {
        $msg = __s('Migration complete: connection id ', 'nms') . (int) $result['connections_id'];
        foreach ($result['counts'] as $table => $n) {
            $msg .= '; ' . $table . ': ' . $n;
        }
        Session::addMessageAfterRedirect($msg);
    }
    if (!empty($_POST[SuiteConfig::FROM_SUITE_FIELD])) {
        Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
    }
    Html::back();
}

// Users who can open Setup > General get the PGN Suite tab instead (hub §5).
if (Session::haveRight('config', READ)) {
    Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
}

// Standalone page for OpManager admins without core `config` READ. No menu entry.
Html::header('OpManager', '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'nms', 'device');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('nms', 'device');
SuiteConfig::showForm();
Html::footer();
