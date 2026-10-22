<?php

/**
 * Shared entry point for storefront pages, the REST front controller and CLI
 * scripts: Composer's classmap autoloader first, then the PDO connection.
 * Defines $pdo in the including scope.
 */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
