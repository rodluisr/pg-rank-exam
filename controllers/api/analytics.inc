<?php

function get($q) {
    $date = trim((string)($q['date'] ?? ''));
    if ($date === '') $date = date('Y-m-d'); // default: today

    $logFile = __DIR__ . '/../../logs/access.log';
    if (!is_file($logFile)) {
        render_json(['result' => ['labels' => [], 'counts' => []]]);
        return;
    }

    $fh = @fopen($logFile, 'r');
    if (!$fh) {
        render_json(['error' => 'log_unreadable'], 500);
        return;
    }

    // minute => set of ips
    $bucket = [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;

        if (strncmp($line, $date, 10) !== 0) continue;

        // split first two fields
        $parts = explode("\t", $line);
        if (count($parts) < 2) continue;
        // timestamp and ip
        $ts = substr($parts[0], 11, 5); // "HH:MM"
        $ip = $parts[1];

        if (!isset($bucket[$ts])) $bucket[$ts] = [];
        $bucket[$ts][$ip] = 1;
    }
    fclose($fh);

    // Build labels and counts
    $labels = array_keys($bucket);
    sort($labels);
    $counts = [];
    foreach ($labels as $m) $counts[] = count($bucket[$m]);

    render_json(['result' => [
        'date'   => $date,
        'labels' => $labels,
        'counts' => $counts
    ]]);
}
