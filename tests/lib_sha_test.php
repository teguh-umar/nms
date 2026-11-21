<?php

require __DIR__ . '/bootstrap.php';

// The shared PGN Suite classes must be byte-identical to the canonical copies
// in …/PGN GLPI Suite/shared/pgnsuite/ (hub §5). No line-ending normalisation
// here on purpose: the file itself must be exact.
$expected = [
    'lib/pgnsuite/ConfigTab.php' => '46FEAA6C01CB000722A496D0922892ED37FFB9CD0CF892F2851EC9423C2B59C8',
    'lib/pgnsuite/AssetTab.php'  => 'F8629CCA6D141B1E9271686C8D7F9D65E4FC84E39CF07E85B10FBC849A0B2466',
    'lib/pgnsuite/Menu.php'      => '34F40CBBFE14842972BB8806F6F0E9C8810D914FD57D2D1DD286033255CCCF88',
];
// ConfigTab r5 renders the suite settings on their own Setup page: every plugin ships this entry point.
$expected_front = ['front/pgnsuite.settings.php' => '4BFF4A4B9866CF7373DE2B45EB096BE4D72999E854E2C2887806F5573F6B49FA'];
foreach ($expected as $rel => $sha) {
    $path   = OPM_ROOT . '/' . $rel;
    $actual = is_file($path) ? strtoupper(hash_file('sha256', $path)) : 'missing';
    check("{$rel} is canonical", $actual === $sha, "got {$actual}");
}
foreach ($expected_front as $rel => $sha) {
    $path   = OPM_ROOT . '/' . $rel;
    $actual = is_file($path) ? strtoupper(hash_file('sha256', $path)) : 'missing';
    check("{$rel} is canonical", $actual === $sha, "got {$actual}");
}

// SelfCheck on the VM must expect the same SHAs as the shipped files (1.2.0 kept r3/r2 there).
$selfcheck = (string) file_get_contents(OPM_ROOT . '/src/SelfCheck.php');
foreach ($expected as $rel => $sha) {
    check('SelfCheck expects ' . basename($rel) . ' canonical SHA', str_contains($selfcheck, "'" . basename($rel) . "' => '{$sha}'") || str_contains($selfcheck, "'" . basename($rel) . "'  => '{$sha}'") || str_contains($selfcheck, "'" . basename($rel) . "'      => '{$sha}'"));
}

done();
