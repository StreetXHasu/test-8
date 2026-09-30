<?php

declare(strict_types=1);

$url = getenv('DB_URL');
if (!is_string($url) || '' === $url) {
    $url = getenv('DATABASE_URL');
}

if (is_string($url) && '' !== $url) {
    $parts = parse_url($url);
    $host = $parts['host'] ?? '127.0.0.1';
    $port = (int) ($parts['port'] ?? 5432);
    $database = ltrim((string) ($parts['path'] ?? '/app'), '/');
    $user = rawurldecode((string) ($parts['user'] ?? 'app'));
    $password = rawurldecode((string) ($parts['pass'] ?? 'app'));
} else {
    $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('DB_PORT') ?: 5432);
    $database = (string) (getenv('DB_DATABASE') ?: 'app');
    $user = (string) (getenv('DB_USERNAME') ?: 'app');
    $password = (string) (getenv('DB_PASSWORD') ?: 'app');
}

for ($attempt = 0; $attempt < 40; ++$attempt) {
    try {
        new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database), $user, $password);
        exit(0);
    } catch (Throwable) {
        sleep(1);
    }
}

fwrite(STDERR, "Database is not ready\n");
exit(1);
