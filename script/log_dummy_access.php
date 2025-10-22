<?php

require_once __DIR__ . '/../liber.php';

$lines = isset($argv[1]) ? max(1, (int)$argv[1]) : 500;
$date  = isset($argv[2]) ? $argv[2] : date('Y-m-d');
$out   = __DIR__ . '/../logs/access.log';

$paths = ['/', '/top', '/api/products', '/api/categories', '/analytics', '/search'];
$fh = fopen($out, 'a');
if (!$fh) { echo "Cannot open $out\n"; exit(1); }

for ($i = 0; $i < $lines; $i++) {
    $h = str_pad((string)rand(0, 23), 2, '0', STR_PAD_LEFT);
    $m = str_pad((string)rand(0, 59), 2, '0', STR_PAD_LEFT);
    $s = str_pad((string)rand(0, 59), 2, '0', STR_PAD_LEFT);
    $ts = "$date $h:$m:$s";

    $ip = rand_ip();

    $path = $paths[array_rand($paths)];
    fwrite($fh, "$ts\t$ip\t$path\n");
}

fclose($fh);
echo "Wrote $lines lines to $out for date $date\n";

function rand_ip() {
    static $pool = [];
    if (!$pool) {
        $n = 120; // pool size
        for ($i = 0; $i < $n; $i++) {
            $pool[] = rand(11, 250) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 254);
        }
    }
    return $pool[array_rand($pool)];
}
