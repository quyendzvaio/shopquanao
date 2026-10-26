<?php

/** public/profile.php: the signed-in user's orders (filtered by ?status=) and their completed-order totals. */
final class ProfilePage
{
    public function __construct(private OrderService $orders)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        // 1. Must be signed in
        if (!isset($session['user_id'])) {
            return PageResult::redirect('login.php');
        }

        $user_id = $session['user_id'];

        // 2. Status filter from the URL (default 'all'), bound raw
        $status_filter = $request->query['status'] ?? 'all';

        $my_orders = $this->orders->listForProfilePage($user_id, $status_filter);

        // 4. Totals always cover every completed order
        $stat = $this->orders->completedStats($user_id);

        return PageResult::view([
            'username' => $session['username'],
            'status_filter' => $status_filter,
            'my_orders' => $my_orders,
            'stat' => $stat,
        ]);
    }
}
