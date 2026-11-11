<?php

use GlpiPlugin\Nms\Alarm;
use GlpiPlugin\Nms\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::MONITORING, READ);

Html::header(Alarm::getTypeName(2), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'nms', 'alarm');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('nms', 'alarm');
Search::show(Alarm::class);
Html::footer();
