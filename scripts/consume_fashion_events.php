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
$minIdleMs=(int)(getenv('FASHION_EVENT_CLAIM_IDLE_MS')?:30000);
$claim=function()use($redis,$stream,$group,$name,$consumer,$minIdleMs){
    // Reclaim entries left pending by a crashed consumer (idempotent re-delivery).
    // ponytail: xPending idle/consumer filter is broken in this ext-redis (always false) -> use XAUTOCLAIM.
    $cursor='0-0';
    do{
        $res=$redis->xAutoClaim($stream,$group,$name,$minIdleMs,$cursor,10);
        if(!is_array($res)||count($res)<2){break;}
        $cursor=(string)$res[0];
        foreach((array)$res[1] as$id=>$fields){
            if(!is_array($fields)){continue;} // entry deleted/acked meanwhile
            try{$event=json_decode((string)($fields['payload']??''),true,512,JSON_THROW_ON_ERROR);$consumer->consume($event);$redis->xAck($stream,$group,[$id]);}
            catch(Throwable$e){error_log('Fashion event consumer claim failure: '.$e->getMessage());}
        }
    }while($cursor!=='0-0');
};
do {
    $claim();
    $messages=$redis->xReadGroup($group,$name,[$stream=>'>'],10,$once?100:5000)?:[];
    foreach(($messages[$stream]??[]) as $id=>$fields){
        try{$event=json_decode((string)($fields['payload']??''),true,512,JSON_THROW_ON_ERROR);$consumer->consume($event);$redis->xAck($stream,$group,[$id]);}
        catch(Throwable $e){error_log('Fashion event consumer failure: '.$e->getMessage());}
    }
}while(!$once);
