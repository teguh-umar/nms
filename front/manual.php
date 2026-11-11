<?php

use GlpiPlugin\Nms\Manual;
use GlpiPlugin\Nms\RightDefinition;

include('../../../inc/includes.php');

// v1.3.0-rc4: the user manual (suite rule 2026-11-09). Anyone who may see the plugin may read it.
Session::checkRight(RightDefinition::MONITORING, READ);

Html::header(__('Manual', 'nms'), '', \GlpiPlugin\Pgnsuite\Menu::SECTOR, 'nms', 'manual');
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('nms', 'manual');

echo '<div class="card"><div class="card-body">';
echo Manual::body(Manual::read(), PLUGIN_NMS_VERSION);
echo '</div></div>';

Html::footer();
