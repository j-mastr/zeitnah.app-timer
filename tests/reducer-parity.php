<?php

// Counterpart of tests/reducer-parity.mjs: replays the generated cases through the PHP
// reducer and compares the outcome with what the JavaScript reducer produced.

use App\Race\InvalidOperationException;
use App\Race\OperationReducer;

require dirname(__DIR__).'/vendor/autoload.php';

$cases = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$reducer = new OperationReducer();
$failures = 0;

foreach ($cases as $n => $case) {
    $state = OperationReducer::emptyState();
    $results = [];
    foreach ($case['ops'] as $op) {
        try {
            $state = $reducer->apply($state, $op);
            $results[] = 'ok';
        } catch (InvalidOperationException $e) {
            $results[] = $e->getMessage();
        }
    }
    $php = json_encode(['state' => $state, 'results' => $results], JSON_UNESCAPED_UNICODE);
    $js = json_encode($case['expected'], JSON_UNESCAPED_UNICODE);
    if ($php !== $js) {
        ++$failures;
        if ($failures <= 3) {
            echo "Case $n differs\n  PHP: $php\n  JS:  $js\n";
        }
    }
}

printf("%d/%d cases identical\n", count($cases) - $failures, count($cases));
exit($failures > 0 ? 1 : 0);
