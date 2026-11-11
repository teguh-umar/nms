<?php

use GlpiPlugin\Nms\Device;
use GlpiPlugin\Nms\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::MONITORING, READ);

Html::header(Device::getTypeName(2), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'nms', 'device');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('nms', 'device');
Search::show(Device::class);
Html::footer();
