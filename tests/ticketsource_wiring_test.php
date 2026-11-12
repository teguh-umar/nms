<?php

require __DIR__ . '/bootstrap.php';
require OPM_ROOT . '/src/Connection/Settings.php';

// v1.1.0 (D32): Setup wiring of the ticket source, checked on the source text.
$form = (string) file_get_contents(OPM_ROOT . '/src/Connection/ConnectionForm.php');
check('Connection form offers the ticket source', str_contains($form, 'name="ticket_source"'));

// Form uses Settings::DEFAULTS for ticket source default.
$src = (string) file_get_contents(OPM_ROOT . '/src/Connection/Settings.php');
check('Settings::DEFAULTS has ticket_source', array_key_exists('ticket_source', \GlpiPlugin\Nms\Connection\Settings::DEFAULTS ?? []));

// Webhook mode does not disable severity/filter inputs.
$form2 = (string) file_get_contents(OPM_ROOT . '/src/Connection/ConnectionForm.php');
check('ticket source never disables severity/filter inputs', !str_contains($form2, "'disabled'"));

done();
