<?php

/**
 * Configure .env to use SQLite with absolute path
 */
$envPath = __DIR__.'/../.env';
$dbPath = __DIR__.'/../monica.db';

if (! file_exists($envPath)) {
    echo "Error: .env file not found\n";
    exit(1);
}

if (! file_exists($dbPath)) {
    echo "Error: monica.db file not found\n";
    exit(1);
}

$env = file_get_contents($envPath);
$dbAbsolutePath = realpath($dbPath);

// Update DB_CONNECTION
$env = preg_replace('/^DB_CONNECTION=.*/m', 'DB_CONNECTION=sqlite', $env);

// Update DB_DATABASE — match both set and commented-out lines, then append if not found
$replaced = 0;
$env = preg_replace('/^#?\s*DB_DATABASE=.*/m', 'DB_DATABASE='.$dbAbsolutePath, $env, -1, $replaced);
if ($replaced === 0) {
    $env .= "\nDB_DATABASE=".$dbAbsolutePath."\n";
}

file_put_contents($envPath, $env);

echo "✓ Configured SQLite with database at: $dbAbsolutePath\n";
