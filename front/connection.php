<?php

use GlpiPlugin\Nms\Connection\ConnectionForm;
use GlpiPlugin\Nms\Connection\DbConnectionStore;
use GlpiPlugin\Nms\RightDefinition;

include('../../../inc/includes.php');

Session::checkRight(RightDefinition::CONFIG, READ);

$form = new ConnectionForm(new DbConnectionStore());

Html::header(__('NMS connections', 'nms'), '', 'config', 'commondropdown');
echo '<div class="container-fluid">';
echo '<h2>' . __s('NMS connections', 'nms') . '</h2>';
if (Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
    echo '<p><a href="' . htmlescape(Html::getPrefixedUrl(ConnectionForm::FORM_PATH . '?id=0')) . '" class="btn btn-primary">'
        . '<i class="ti ti-plus me-1"></i>' . __s('Add connection', 'nms') . '</a></p>';
}
echo $form->renderList();
echo '</div>';
Html::footer();
