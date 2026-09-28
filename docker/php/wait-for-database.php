<?php

declare(strict_types=1);

$url = getenv('DATABASE_URL');
if (!is_string($url) || '' === $url) {
    fwrite(STDERR, "DATABASE_URL is missing\n");
    exit(1);
}

$parts = parse_url($url);
$host = $parts['host'] ?? '127.0.0.1';
$port = (int) ($parts['port'] ?? 5432);
$database = ltrim((string) ($parts['path'] ?? '/app'), '/');
$user = rawurldecode((string) ($parts['user'] ?? ''));
$password = rawurldecode((string) ($parts['pass'] ?? ''));

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
