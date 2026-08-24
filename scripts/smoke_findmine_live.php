<?php

require_once __DIR__ . '/../api/services/Fashion/FindMineConfig.php';
$config = FindMineConfig::fromEnvironment();
if (!$config->enabled || !$config->configured()) {
    fwrite(STDOUT, "LIVE_FINDMINE_STATUS=BLOCKED\n");
    fwrite(STDOUT, "BLOCKER=FINDMINE_ENABLED/FINDMINE_APP_ID/tenant catalog mapping unavailable\n");
    exit(2);
}
fwrite(STDOUT, "LIVE_FINDMINE_STATUS=READY\n");
fwrite(STDOUT, "NOTE=run the authenticated connectivity and known-good-anchor smoke only with provisioned tenant credentials and mapping\n");
