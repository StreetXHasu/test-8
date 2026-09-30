<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

date_default_timezone_set('UTC');

/*
 * PHPUnit's <env> entries only reach $_ENV and putenv(), but Laravel's env
 * repository reads $_SERVER first, so variables injected by docker compose
 * would silently win. Everything the suite must control is written to all
 * three places here instead.
 */
$appUrl = (string) (getenv('DB_URL') ?: getenv('DATABASE_URL') ?: '');
$testUrl = (string) (getenv('DB_TEST_URL') ?: '');
if ('' === $testUrl) {
    $testUrl = '' === $appUrl
        ? 'pgsql://app:app@127.0.0.1:54329/app_test'
        : (string) preg_replace('#/([^/?]*)(\?|$)#', '/app_test$2', $appUrl, 1);
}

$overrides = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'DB_CONNECTION' => 'pgsql',
    'DB_URL' => $testUrl,
    'DATABASE_URL' => $testUrl,
];

foreach ($overrides as $name => $value) {
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
    putenv($name.'='.$value);
}
