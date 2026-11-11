<?php

use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\RightDefinition;

function plugin_nms_uninstall()
{
    global $DB;

    foreach (['glpi_plugin_nms_hygieneitems', 'glpi_plugin_nms_hygienetickets', 'glpi_plugin_nms_deviceexceptions', 'glpi_plugin_nms_ticketrequests', 'glpi_plugin_nms_outbox', 'glpi_plugin_nms_storms', 'glpi_plugin_nms_alarms', 'glpi_plugin_nms_devices'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    ProfileRight::deleteProfileRights(RightDefinition::names());
    \Config::deleteConfigurationValues(Config::CONTEXT, array_keys(Config::DEFAULTS));
    CronTask::unregister('nms');

    return true;
}
