<?php

function get($q) {
    $date = trim((string)($q['date'] ?? ''));
    if ($date === '') $date = date('Y-m-d'); // default: today
    $logFile = __DIR__ . '/../../logs/access.log';

	// recent logs list request
    if (isset($q['recent'])) {
        $n = max(1, min(1000, (int)$q['recent'])); // small safety cap
        $rows = analytics_recent_logs($logFile, $n);
        render_json(['result' => $rows]);
        return;
    }

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

function analytics_recent_logs($logFile, $limit = 100) {
    if (!is_readable($logFile)) return [];

    $lines = tail_file_lines($logFile, $limit);
    $out = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

		// "YYYY-MM-DD HH:MM:SS\tIP\tMETHOD\tPATH\tSTATUS\tUA"
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\t([^\t]+)\t([A-Z]+)\t([^\t]+)\t(\d{3})\t(.*)$/', $line, $m)) {
            $out[] = ['time'=>$m[1],'ip'=>$m[2],'method'=>$m[3],'path'=>$m[4],'status'=>$m[5],'ua'=>$m[6]];
            continue;
        }

		// "YYYY-MM-DD HH:MM:SS IP METHOD PATH [STATUS] [UA]"
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(\S+)\s+([A-Z]+)\s+(\S+)(?:\s+(\d{3}))?(?:\s+(.*))?$/', $line, $m)) {
            $out[] = [
                'time'=>$m[1],'ip'=>$m[2],'method'=>$m[3],'path'=>$m[4],
                'status'=>isset($m[5]) ? $m[5] : '', 'ua'=>isset($m[6]) ? $m[6] : ''
            ];
            continue;
        }

		// "YYYY-MM-DD HH:MM:SS IP /path"
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(\S+)\s+(\/\S*)$/', $line, $m)) {
            $out[] = ['time'=>$m[1],'ip'=>$m[2],'method'=>'','path'=>$m[3],'status'=>'','ua'=>''];
            continue;
        }

		// Common log format
        if (preg_match('~^(\S+)\s+\S+\s+\S+\s+\[([^\]]+)\]\s+"([A-Z]+)\s+([^"]+?)\s+HTTP/[0-9.]+"\s+(\d+)\s+\d+\s+"[^"]*"\s+"([^"]*)"~', $line, $m)) {
            $out[] = ['time'=>$m[2],'ip'=>$m[1],'method'=>$m[3],'path'=>$m[4],'status'=>$m[5],'ua'=>$m[6]];
            continue;
        }

        // 5) Fallback: raw line into UA
        $out[] = ['time'=>'','ip'=>'','method'=>'','path'=>'','status'=>'','ua'=>$line];
    }

    return $out;
}

function tail_file_lines($filepath, $lines = 100) {
    $f = @fopen($filepath, 'rb');
    if (!$f) return [];
    $buffer = '';
    $chunk  = 8192;
    $lineCount = 0;

    fseek($f, 0, SEEK_END);
    $filesize = ftell($f);

    while ($filesize > 0 && $lineCount <= $lines) {
        $step = min($chunk, $filesize);
        $filesize -= $step;
        fseek($f, $filesize);
        $buffer = fread($f, $step) . $buffer;
        $lineCount = substr_count($buffer, "\n");
        if ($filesize === 0) break;
    }
    fclose($f);

    $arr = explode("\n", trim($buffer));
    return array_slice($arr, -$lines);
}