<?php

use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\Migration\FromOpmanager;
use GlpiPlugin\Nms\RightDefinition;
use GlpiPlugin\Nms\SuiteConfig;
use GlpiPlugin\Pgnsuite\ConfigTab;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

$back = static function (): void {
    if (!empty($_POST[SuiteConfig::FROM_SUITE_FIELD])) {
        Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
    }
    Html::back();
};

if (isset($_POST['update'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    // Only the keys shared by every connection are saved here; the rest is per connection.
    $result = Config::sanitizeGlobal($_POST, array_keys(Config::offeredItemtypes()));
    $values = $result['values'];
    $errors = $result['errors'];
    Config::set($values);
    foreach ($errors as $error) {
        Session::addMessageAfterRedirect(htmlescape($error), false, ERROR);
    }
    if ($errors === []) {
        Session::addMessageAfterRedirect(__s('Settings saved', 'nms'));
    }
    $back();
}

if (isset($_POST['migrate_from_opmanager'])) {
    Session::checkRight(RightDefinition::CONFIG, UPDATE);
    global $DB;
    $result = FromOpmanager::run($DB, date('Y-m-d H:i:s'));
    if (array_key_exists('skipped', $result)) {
        Session::addMessageAfterRedirect(htmlescape(__('OpManager not installed', 'nms') . ': ' . $result['skipped']), false, ERROR);
    } else {
        $msg = __('Migration complete: connection id', 'nms') . ' ' . (int) $result['connections_id'];
        foreach ($result['counts'] + ['rights_copied' => $result['rights_copied']] + $result['core_refs'] as $table => $n) {
            $msg .= '; ' . $table . ': ' . (int) $n;
        }
        Session::addMessageAfterRedirect(htmlescape($msg));
    }
    $back();
}

// Users who can open Setup > General get the PGN Suite tab instead (hub §5).
if (Session::haveRight('config', READ)) {
    Html::redirect(ConfigTab::getReturnUrl(SuiteConfig::SECTION));
}

// Standalone page for NMS admins without core `config` READ. No menu entry.
Html::header('NMS Integration', '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'nms', 'device');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('nms', 'device');
SuiteConfig::showForm();
Html::footer();
