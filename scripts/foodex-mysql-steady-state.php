<?php

declare(strict_types=1);

$timeoutSeconds = 120;
$outputPath = null;

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--timeout-seconds' && isset($argv[$i + 1])) {
        $timeoutSeconds = max(1, (int) $argv[++$i]);
        continue;
    }

    if ($argv[$i] === '--output' && isset($argv[$i + 1])) {
        $outputPath = $argv[++$i];
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$database = getenv('DB_DATABASE') ?: 'foodex_perf';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);

$redoCapacity = (int) $pdo->query('SELECT @@GLOBAL.innodb_redo_log_capacity')->fetchColumn();
if ($redoCapacity <= 0) {
    throw new RuntimeException('Unable to resolve innodb_redo_log_capacity.');
}

// The benchmark measures steady-state application traffic, not the synthetic
// fixture loader. Wait until the seed burst is no longer consuming most of the
// redo window before starting timed HTTP requests.
$maxCheckpointLag = max(16 * 1024 * 1024, (int) floor($redoCapacity * 0.25));
$deadline = microtime(true) + $timeoutSeconds;
$lastSnapshot = null;

do {
    $rows = $pdo->query(
        "SHOW GLOBAL STATUS WHERE Variable_name IN (".
        "'Innodb_lsn_current',".
        "'Innodb_lsn_last_checkpoint',".
        "'Innodb_os_log_pending_writes',".
        "'Innodb_os_log_pending_fsyncs'".
        ')',
    )->fetchAll();

    $status = [];
    foreach ($rows as $row) {
        $status[(string) $row['Variable_name']] = (int) $row['Value'];
    }

    if (! isset($status['Innodb_lsn_current'], $status['Innodb_lsn_last_checkpoint'])) {
        throw new RuntimeException('Required InnoDB LSN status variables are unavailable.');
    }

    $checkpointLag = max(
        0,
        $status['Innodb_lsn_current'] - $status['Innodb_lsn_last_checkpoint'],
    );
    $pendingWrites = $status['Innodb_os_log_pending_writes'] ?? 0;
    $pendingFsyncs = $status['Innodb_os_log_pending_fsyncs'] ?? 0;

    $lastSnapshot = [
        'schema_version' => 1,
        'captured_at' => gmdate(DATE_ATOM),
        'redo_capacity_bytes' => $redoCapacity,
        'checkpoint_lag_bytes' => $checkpointLag,
        'checkpoint_lag_limit_bytes' => $maxCheckpointLag,
        'pending_log_writes' => $pendingWrites,
        'pending_log_fsyncs' => $pendingFsyncs,
        'steady_state' => $checkpointLag <= $maxCheckpointLag
            && $pendingWrites === 0
            && $pendingFsyncs === 0,
    ];

    if ($lastSnapshot['steady_state']) {
        $json = json_encode($lastSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        echo $json;

        if ($outputPath !== null) {
            $directory = dirname($outputPath);
            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create output directory: '.$directory);
            }
            file_put_contents($outputPath, $json);
        }

        exit(0);
    }

    usleep(500_000);
} while (microtime(true) < $deadline);

$lastSnapshot['timed_out'] = true;
$json = json_encode($lastSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
fwrite(STDERR, $json);

if ($outputPath !== null) {
    file_put_contents($outputPath, $json);
}

exit(1);
