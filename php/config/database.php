<?php

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'capstone';
const DB_USER = 'root';
const DB_PASSWORD = '';
const DB_CHARSET = 'utf8mb4';

function getDatabaseConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    $connection = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}
