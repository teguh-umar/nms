<?php

/**
 * OpManager - ManageEngine OpManager <-> GLPI 11 integration (PGN GLPI Suite plugin #7)
 *
 * @author  ICT
 * @license GPL-3.0-or-later
 */

define('PLUGIN_NMS_VERSION', '1.0.0-rc1');
define('PLUGIN_NMS_MIN_GLPI', '11.0.0');
define('PLUGIN_NMS_MAX_GLPI', '11.99.99');

include_once(__DIR__ . '/install/install.php');
include_once(__DIR__ . '/install/uninstall.php');

function plugin_version_nms()
{
    return [
        'name'         => 'NMS Integration',
        'version'      => PLUGIN_NMS_VERSION,
        'author'       => 'ICT',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_NMS_MIN_GLPI,
                'max' => PLUGIN_NMS_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_nms_check_prerequisites()
{
    return true;
}

function plugin_nms_check_config()
{
    return true;
}

/**
 * Runs from Plugin::bootPlugins(), before SessionStart decides whether a request is
 * stateless - plugin_init is too late for this (11.0.5; same as core glpiinventory).
 */
function plugin_nms_boot(): void
{
    // Machine-to-machine webhook from OpManager: no session, no CSRF, own token auth.
    \Glpi\Http\SessionManager::registerPluginStatelessPath('nms', '#^/front/webhook\.php$#');
}

function plugin_init_nms()
{
    global $PLUGIN_HOOKS;

    // GLPI 11's CheckCsrfListener validates + consumes CSRF tokens before a
    // legacy front file runs - never call Session::checkCSRF() ourselves.
    $PLUGIN_HOOKS['csrf_compliant']['nms'] = true;

    // Core encrypts these on write (GLPIKey::getConfigs maps them to plugin:nms).
    $PLUGIN_HOOKS['secured_configs']['nms'] = \GlpiPlugin\Nms\Config::SECURED;

    // Per-connection table secrets are encrypted/decrypted by the plugin with GLPIKey,
    // listed here so core re-encrypts them on GLPI key rotation.
    $PLUGIN_HOOKS['secured_fields']['nms'] = [
        'glpi_plugin_nms_connections.api_key',
        'glpi_plugin_nms_connections.password',
        'glpi_plugin_nms_connections.webhook_token',
    ];

    // GLPI -> OpManager (Plan 3): human actions only, decided in Outbound\Planner (D24).
    $PLUGIN_HOOKS['item_add']['nms'] = [\ITILFollowup::class => [\GlpiPlugin\Nms\Outbound\Hooks::class, 'followupAdded']];
    $PLUGIN_HOOKS['item_update']['nms'] = [\Ticket::class => [\GlpiPlugin\Nms\Outbound\Hooks::class, 'ticketUpdated']];

    // Native dashboard cards (v0.6.0). Provider strings are exploded by core (hub §4).
    $PLUGIN_HOOKS['dashboard_cards']['nms'] = 'GlpiPlugin\\Nms\\Coverage\\Cards::cards';

    \Plugin::registerClass(\GlpiPlugin\Nms\Profile::class, ['addtabon' => ['Profile']]);

    // Shared suite sidebar menu, one entry per plugin (hub §5, v1.2.0). Same loading
    // rule as ConfigTab below: the first plugin that loads supplies the class.
    if (!class_exists(\GlpiPlugin\Pgnsuite\Menu::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/Menu.php';
    }
    \GlpiPlugin\Pgnsuite\Menu::register('nms');
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\Menu::HOOK]['nms'] = \GlpiPlugin\Nms\Menu::class . '::entry';

    // Shared "PGN Suite" tab on Setup > General (hub §5). class_exists(..., false)
    // skips the autoloader, which would look for a nonexistent plugins/pgnsuite/.
    if (!class_exists(\GlpiPlugin\Pgnsuite\ConfigTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/ConfigTab.php';
    }
    \GlpiPlugin\Pgnsuite\ConfigTab::register();
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\ConfigTab::HOOK]['nms'] =
        \GlpiPlugin\Nms\SuiteConfig::class . '::section';

    // Shared "PGN Suite" tab on asset forms (hub §5). The itemtype list is plain
    // strings read from config + glpi_assets_assetdefinitions: custom asset classes
    // are not loaded yet here, so NEVER filter it with class_exists().
    if (!class_exists(\GlpiPlugin\Pgnsuite\AssetTab::class, false)) {
        require_once __DIR__ . '/lib/pgnsuite/AssetTab.php';
    }
    \GlpiPlugin\Pgnsuite\AssetTab::register(\GlpiPlugin\Nms\Config::assetItemtypes());
    $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\AssetTab::HOOK]['nms'] =
        \GlpiPlugin\Nms\AssetSections::class . '::sections';
}
