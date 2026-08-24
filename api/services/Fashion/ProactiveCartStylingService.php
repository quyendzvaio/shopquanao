<?php
final class ProactiveCartStylingService
{
    public function __construct(private ProactiveStylingStateStore $states, private ProactiveStylingStateMachine $machine, private ComplementaryProductFinder $finder) {}
    public function consumeCartItemAdded(int $userId, string $sessionId, int $productId, ?int $variantId, string $eventId): void
    {
        $this->states->put($userId, $sessionId, $this->machine->onCartItemAdded($this->states->get($userId,$sessionId),$productId,$variantId,$eventId));
    }
    public function onUserTurn(int $userId, string $sessionId, bool $suitable): array
    {
        $state=$this->states->get($userId,$sessionId); $productId=(int)($state['pending_product_id']??0);
        if ($productId<=0) return ['status'=>'silent'];
        if ((int)($state['remaining_user_turns']??0)>1) {
            $transition=$this->machine->onUserTurn($state,$suitable,false); $this->states->put($userId,$sessionId,$transition['state']);
            return ['status'=>'silent'];
        }
        if ((int)($state['remaining_user_turns']??0)===1 && !$suitable) {$transition=$this->machine->onUserTurn($state,false,false);$this->states->put($userId,$sessionId,$transition['state']);return ['status'=>'silent'];}
        if (!$suitable) return ['status'=>'silent'];
        $preview=$this->finder->find($productId,isset($state['pending_variant_id'])?(int)$state['pending_variant_id']:null);
        $transition=$this->machine->onUserTurn($state,true,!empty($preview['products'])); $this->states->put($userId,$sessionId,$transition['state']);
        return $transition['action']==='suggest' ? $preview+['status'=>'suggest'] : ['status'=>'silent'];
    }
}
