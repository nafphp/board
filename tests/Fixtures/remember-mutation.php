<?php

declare(strict_types=1);

$connection = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
    (string) getenv('DB_USERNAME'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$connection->exec('SET innodb_lock_wait_timeout=3');
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
$connection->prepare('UPDATE users SET password_hash=?, security_version=security_version+1 WHERE id=?')
    ->execute([$argv[2], (int) $argv[1]]);
fwrite(STDOUT, "reset committed\n");
