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

$email = new EmailManager(
    $config,
    $pdo,
    new DefaultHttpClient(),
    new DatabaseLogger($pdo, $config['table_prefix']),
);

$result = $email->send(
    'user@example.com',
    'Welcome to Excelle',
    '<p>Hello {{first_name}}, welcome aboard.</p>',
    [
        'vars' => ['first_name' => 'Wanjiru'],
        'category' => 'transactional',
        'idempotency_key' => 'welcome-1001',
    ],
);

echo json_encode([
    'status' => $result->status->value,
    'local_id' => $result->localId,
], JSON_PRETTY_PRINT) . PHP_EOL;
