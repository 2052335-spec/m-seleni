<?php
declare(strict_types=1);

// One shared connection to the PostgreSQL server on the database VM.
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = require __DIR__ . '/config.php';
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['host'], $cfg['port'], $cfg['name']);
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }
    return $pdo;
}
