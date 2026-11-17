<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Support\DatabaseLogger;
use ExcelleInsights\Email\Support\DefaultHttpClient;

$envPath = is_file(getcwd() . '/.env') ? getcwd() : __DIR__ . '/..';
Dotenv::createImmutable($envPath)->safeLoad();

$config = require __DIR__ . '/../config/email.php';

$pdo = new PDO(
    $config['db_dsn'],
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$logger = new DatabaseLogger($pdo, $config['table_prefix']);
$email = new EmailManager($config, $pdo, new DefaultHttpClient(), $logger);

// Register the same bundled adapters used by the application before running
// the worker.
$email->registerBuiltInProviders();

$lockFile = $config['worker_lock_file'] ?? __DIR__ . '/../storage/email-worker.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "email-worker is already running.\n");
    exit(1);
}

$runFor = (int) ($config['worker_run_seconds'] ?? 55);
$batchSize = (int) ($config['worker_batch_size'] ?? 100);
$sleepSeconds = (int) ($config['worker_sleep_seconds'] ?? 5);
$stopAt = time() + $runFor;

$logger->info('email-worker started', ['run_seconds' => $runFor, 'batch_size' => $batchSize]);

while (time() < $stopAt) {
    $dispatched = $email->worker()->processBatch($batchSize);
    $logger->info('email-worker batch complete', ['dispatched' => $dispatched]);

    if (time() < $stopAt) {
        sleep($sleepSeconds);
    }
}

$logger->info('email-worker finished');
