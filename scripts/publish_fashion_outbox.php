<?php
if (PHP_SAPI!=='cli') exit(2);
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../api/services/Fashion/FashionEventBus.php';
require_once __DIR__.'/../api/services/Fashion/RedisFashionEventBus.php';
require_once __DIR__.'/../api/services/Fashion/FashionOutboxPublisher.php';
$once=in_array('--once',$argv,true); $publisher=new FashionOutboxPublisher($pdo,RedisFashionEventBus::fromEnvironment());
do { $report=$publisher->runBatch(); fwrite(STDOUT,json_encode($report,JSON_UNESCAPED_SLASHES).PHP_EOL); if(!$once)sleep(1); } while(!$once);
