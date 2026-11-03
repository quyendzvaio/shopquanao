<?php

/** public/profile.php: the signed-in user's orders (filtered by ?status=), their completed-order totals and support tickets, 10 per page (?ticket_page=). */
final class ProfilePage
{
    public function __construct(private OrderService $orders, private SupportTicketService $tickets)
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

        // 5. One page of the user's support tickets (newest first)
        $rawPage = $request->query['ticket_page'] ?? null;
        $ticketPage = $this->tickets->profileList(
            (int) $user_id,
            is_string($rawPage) && ctype_digit($rawPage) ? max(1, (int) $rawPage) : 1
        );

        return PageResult::view([
            'username' => $session['username'],
            'status_filter' => $status_filter,
            'my_orders' => $my_orders,
            'stat' => $stat,
            'tickets' => $ticketPage['tickets'],
            'ticket_page' => $ticketPage['page'],
            'ticket_pages' => $ticketPage['pages'],
        ]);
    }
}
