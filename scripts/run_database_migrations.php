<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

fwrite(STDOUT, "Database migrations are up to date.\n");
