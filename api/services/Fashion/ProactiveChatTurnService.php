<?php
final class ProactiveChatTurnService
{
    public function __construct(private ProactiveStylingStateStore $states, private ProactiveStylingStateMachine $machine, private ChatbotToolGateway $tools) {}

    /** @return array<string,mixed> */
    public function handle(int $userId, string $sessionId, string $primaryIntent): array
    {
        $state=$this->states->get($userId,$sessionId); $productId=(int)($state['pending_product_id']??0);
        if($productId<=0 || empty($state['eligible'])) return ['status'=>'silent'];
        $suitable=$this->isSuitable($primaryIntent);
        if((int)($state['remaining_user_turns']??0)>1){
            $transition=$this->machine->onUserTurn($state,$suitable,false); $this->states->put($userId,$sessionId,$transition['state']); return ['status'=>'silent'];
        }
        if((int)($state['remaining_user_turns']??0)===1 && !$suitable){$transition=$this->machine->onUserTurn($state,false,false);$this->states->put($userId,$sessionId,$transition['state']);return ['status'=>'silent'];}
        if(!$suitable)return ['status'=>'silent'];
        try {
            $arguments=['product_id'=>$productId];
            if (($state['pending_variant_id'] ?? null) !== null) $arguments['variant_id']=(int)$state['pending_variant_id'];
            $result=$this->tools->execute('suggest_complementary_products',$arguments);
        } catch (Throwable) {
            error_log(json_encode(['operation'=>'proactive_cart_styling','success'=>false,'error_category'=>'styling_pipeline_failure'],JSON_UNESCAPED_SLASHES));
            return ['status'=>'silent'];
        }
        $transition=$this->machine->onUserTurn($state,true,!empty($result['products'])); $this->states->put($userId,$sessionId,$transition['state']);
        return $transition['action']==='suggest' ? array_merge($result,['status'=>'suggest']) : ['status'=>'silent'];
    }

    private function isSuitable(string $intent): bool
    {
        return !in_array($intent,['return_exchange','shipping','policy','order_status','unsupported_checkout','suggest_complementary_products'],true);
    }
}
