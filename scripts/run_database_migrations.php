<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

/** @var PDO $pdo */
$applied = (new MigrationRunner($pdo, dirname(__DIR__) . '/sql/migrations'))->run();
fwrite(STDOUT, $applied === []
    ? "Database migrations are up to date.\n"
    : 'Applied migrations: ' . implode(', ', $applied) . "\n");
