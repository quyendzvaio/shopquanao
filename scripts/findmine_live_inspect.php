<?php
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../config/db.php';
foreach(['AnchorProductRef','ComplementaryItemRequirement','ComplementaryPlan','FashionProviderProductMapping','FashionProviderMappingRepository','FindMineConfig','FindMineMcpClientContract','FindMineProviderException','FindMineMcpClient','FindMineV3ResponseAdapter'] as $class)require_once __DIR__.'/../api/services/Fashion/'.$class.'.php';
$options=getopt('',['shop-product-id:','shop-variant-id::','save-sanitized']); $productId=(int)($options['shop-product-id']??0); $variantId=isset($options['shop-variant-id'])?(int)$options['shop-variant-id']:null;
$config=FindMineConfig::fromEnvironment();
if(!$config->ready() || $productId<=0){fwrite(STDERR,"BLOCKED: real FINDMINE_APP_ID, pinned MCP runtime, and --shop-product-id are required\n");exit(2);}
$mapping=(new FashionProviderMappingRepository($pdo))->findSynced('findmine',$productId,$variantId);
if($mapping===null){fwrite(STDERR,"BLOCKED: no exact synced FindMine mapping for requested shop anchor\n");exit(2);}
$client=new FindMineMcpClient($config); $started=microtime(true); $initialize=$client->initialize(); $tools=$client->listTools();
$complete=null; foreach($tools as $tool)if(($tool['name']??'')==='get_complete_the_look')$complete=$tool;
if($complete===null)throw new RuntimeException('get_complete_the_look is not discoverable');
$args=[$config->productIdentifierKey=>$mapping->providerProductId,'in_stock'=>true,'on_sale'=>false,'return_pdp_item'=>true];
if($mapping->providerColorId!==null)$args[$config->colorIdentifierKey]=$mapping->providerColorId;
$args['product_identifiers']=$mapping->providerIdentifiers!==[]?$mapping->providerIdentifiers:array_filter([$config->productIdentifierKey=>$mapping->providerProductId,$config->colorIdentifierKey=>$mapping->providerColorId],fn($value)=>$value!==null&&$value!=='');
$raw=$client->call('get_complete_the_look',$args); $plan=(new FindMineV3ResponseAdapter())->toPlan($raw,$productId);
$safe=['artifact_sha'=>'28a15b86ac0a7b212336748005393f88bcbfdad1','node_env'=>'production','protocol_version'=>$initialize['protocolVersion']??null,'tool_schema'=>$complete['inputSchema']??null,'duration_ms'=>(int)((microtime(true)-$started)*1000),'raw'=>$raw,'normalized'=>$plan->toArray()];
fwrite(STDOUT,json_encode($safe,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL);
if(array_key_exists('save-sanitized',$options)){fwrite(STDERR,"Refusing automatic capture: inspect customer/provider data and save a manually sanitized fixture instead.\n");}
