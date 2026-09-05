<?php
final class ProactiveChatTurnService
{
    public function __construct(private ProactiveStylingStateStore $states, private ProactiveStylingStateMachine $machine, private ChatbotToolGateway $tools) {}

    /** @return array<string,mixed> */
    public function handle(int $userId, string $sessionId, string $primaryIntent): array
    {
        $state=$this->states->get($userId,$sessionId); $productId=(int)($state['pending_product_id']??0);
        if($productId<=0 || empty($state['eligible'])) return ['status'=>'not_armed'];
        $suitable=$this->isSuitable($primaryIntent);
        if((int)($state['remaining_user_turns']??0)>1){
            $transition=$this->machine->onUserTurn($state,$suitable,false); $this->states->put($userId,$sessionId,$transition['state']); return ['status'=>$transition['state']['status']??'waiting_for_turn'];
        }
        if((int)($state['remaining_user_turns']??0)===1 && !$suitable){$transition=$this->machine->onUserTurn($state,false,false);$this->states->put($userId,$sessionId,$transition['state']);return ['status'=>$transition['state']['status']??'waiting_for_suitable_context'];}
        if(!$suitable)return ['status'=>'waiting_for_suitable_context'];
        try {
            $arguments=['product_id'=>$productId];
            if (($state['pending_variant_id'] ?? null) !== null) $arguments['variant_id']=(int)$state['pending_variant_id'];
            $result=$this->tools->execute('suggest_complementary_products',$arguments);
        } catch (Throwable $error) {
            error_log(json_encode(['operation'=>'proactive_cart_styling','success'=>false,'error_category'=>'styling_pipeline_failure'],JSON_UNESCAPED_SLASHES));
            $state['status']='tool_retryable_failure'; $state['failure_reason']=$error->getMessage();
            $state['retry_count']=(int)($state['retry_count']??0)+1; $state['last_attempt_at']=gmdate('c');
            $this->states->put($userId,$sessionId,$state);
            return ['status'=>'tool_retryable_failure'];
        }
        $transition=$this->machine->onUserTurn($state,true,!empty($result['products'])); $this->states->put($userId,$sessionId,$transition['state']);
        return $transition['action']==='suggest' ? array_merge($result,['status'=>'shown']) : ['status'=>$transition['state']['status']??'no_private_catalog_match'];
    }

    private function isSuitable(string $intent): bool
    {
        return !in_array($intent,['return_exchange','shipping','policy','order_status','unsupported_checkout','suggest_complementary_products'],true);
    }
}
