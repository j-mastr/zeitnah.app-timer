<?php

// Counterpart of tests/access-parity.mjs: evaluates the generated cases with the PHP access
// rules and compares the outcome with what the JavaScript mirror produced.

use App\Access\Permissions;

require dirname(__DIR__).'/vendor/autoload.php';

$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$failures = 0;
foreach ($data['cases'] as $n => $case) {
    $path = Permissions::operationPath($data['state'], $case['op']);
    $result = [
        'path' => $path, 'allowed' => null === $path ? null : Permissions::allows($case['rules'], $path),
        'view' => Permissions::allows($case['rules'], $case['expected']['viewPath']), 'viewPath' => $case['expected']['viewPath'],
        'restricted' => Permissions::restrictedToWorksets($case['rules']),
    ];
    $php = json_encode($result, JSON_UNESCAPED_UNICODE);
    $js = json_encode($case['expected'], JSON_UNESCAPED_UNICODE);
    if ($php !== $js) {
        ++$failures;
        if ($failures <= 3) {
            echo "Case $n differs (rules ".json_encode($case['rules']).', op '.json_encode($case['op']).")\n  PHP: $php\n  JS:  $js\n";
        }
    }
}

printf("%d/%d access cases identical\n", count($data['cases']) - $failures, count($data['cases']));
exit($failures > 0 ? 1 : 0);
