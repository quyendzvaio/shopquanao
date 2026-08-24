<?php
if (PHP_SAPI!=='cli') exit(2);
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../api/services/Fashion/ProactiveStylingStateMachine.php';
require_once __DIR__.'/../api/services/Fashion/ProactiveStylingStateStore.php';
require_once __DIR__.'/../api/services/Fashion/CartItemAddedConsumer.php';
if(!class_exists('Redis')) throw new RuntimeException('PHP Redis extension is unavailable');
$redis=new Redis(); $redis->connect((string)(getenv('REDIS_HOST')?:'redis'),(int)(getenv('REDIS_PORT')?:6379),(float)(getenv('REDIS_TIMEOUT')?:1));
$stream=(string)(getenv('FASHION_EVENT_STREAM')?:'fashion:events'); $group=(string)(getenv('FASHION_EVENT_GROUP')?:'proactive-styling'); $name=gethostname().'-'.getmypid();
try{$redis->xGroup('CREATE',$stream,$group,'0',true);}catch(RedisException $e){if(!str_contains($e->getMessage(),'BUSYGROUP'))throw $e;}
$consumer=new CartItemAddedConsumer($pdo,new ProactiveStylingStateStore($pdo),new ProactiveStylingStateMachine(),$group); $once=in_array('--once',$argv,true);
do {
    $messages=$redis->xReadGroup($group,$name,[$stream=>'>'],10,$once?100:5000)?:[];
    foreach(($messages[$stream]??[]) as $id=>$fields){
        try{$event=json_decode((string)($fields['payload']??''),true,512,JSON_THROW_ON_ERROR);$consumer->consume($event);$redis->xAck($stream,$group,[$id]);}
        catch(Throwable $e){error_log('Fashion event consumer failure: '.$e->getMessage());}
    }
}while(!$once);
