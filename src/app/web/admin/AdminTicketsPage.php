<?php

/**
 * public/admin/manage_tickets.php: the support-ticket list (?status= one of the 4 statuses or `all`, default the open
 * ones), one ticket's detail (?id=) and the status/note update (POST with a CSRF token). A bad token or an unknown
 * status redirects without a write.
 */
final class AdminTicketsPage
{
    public function __construct(private SupportTicketService $tickets)
    {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        $denied = AdminGuard::check($session);
        if ($denied !== null) {
            return $denied;
        }

        // 1. Update the status and note
        if ($request->method === 'POST' && isset($request->post['update_ticket'])) {
            $id = (int) ($request->post['ticket_id'] ?? 0);
            $status = $request->post['status'] ?? null;
            $note = $request->post['admin_note'] ?? '';

            if (
                !Csrf::valid($session, $request->post[Csrf::FIELD] ?? null)
                || !is_string($status)
                || !isset(SupportTicketService::STATUSES[$status])
                || !is_string($note)
                || !$this->tickets->adminUpdate($id, $status, $note)
            ) {
                return PageResult::redirect('manage_tickets.php');
            }

            return PageResult::redirect('manage_tickets.php?id=' . $id . '&msg=updated');
        }

        // 2. The list (default: open tickets) and, with ?id=, the detail
        $filter = $request->query['status'] ?? null;
        if (!is_string($filter) || ($filter !== 'all' && !isset(SupportTicketService::STATUSES[$filter]))) {
            $filter = null;
        }
        $list = $filter === 'all' ? $this->allTickets() : $this->tickets->adminList($filter);

        $ticket = null;
        $id = (int) ($request->query['id'] ?? 0);
        if ($id > 0) {
            $ticket = $this->tickets->adminDetail($id);
        }

        return PageResult::view([
            'tickets' => $list,
            'ticket' => $ticket,
            'filter' => $filter ?? 'open',
            'msg' => $request->query['msg'] ?? null,
        ]);
    }

    /** @return list<array<string,mixed>> every status, newest first */
    private function allTickets(): array
    {
        $all = [];
        foreach (array_keys(SupportTicketService::STATUSES) as $status) {
            $all = array_merge($all, $this->tickets->adminList($status));
        }
        usort($all, static fn(array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return $all;
    }
}
