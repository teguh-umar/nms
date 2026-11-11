<?php

use GlpiPlugin\Nms\Connection\ConnectionStore;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\Connection\ConnectionForm;
use GlpiPlugin\Nms\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

$store = new DbConnectionStore();
$form  = new ConnectionForm($store);

Html::header(__('NMS connections', 'nms'), '', 'config', 'commondropdown');
echo '<div class="container">';
echo '<h1>' . __('NMS connections', 'nms') . '</h1>';
echo '<p><a href="' . htmlescape(\Html::getPrefixedUrl('/plugins/opmanager/front/connection.form.php')) . '" class="btn btn-primary">'
    . __('Add connection', 'nms') . '</a></p>';
echo $form->renderList();
echo '</div>';
Html::footer();
