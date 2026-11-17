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

// Register the bundled adapters before sending or running the worker.
$email->registerBuiltInProviders();

$result = $email->sendNow(
    'user@example.com',
    'Reset your password',
    '<p>Use the code below to reset your password.</p>',
    [
        'template' => 'password-reset',
        'vars' => ['first_name' => 'Wanjiru', 'reset_code' => '482913'],
        'category' => 'transactional',
    ],
);

echo json_encode([
    'status' => $result->status->value,
    'local_id' => $result->localId,
    'provider_message_id' => $result->providerMessageId,
    'error' => $result->error,
], JSON_PRETTY_PRINT) . PHP_EOL;
