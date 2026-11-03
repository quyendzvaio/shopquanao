<?php

/**
 * public/admin/manage_tickets.php: the support-ticket list (?status= one of the 4 statuses or `all`, default the open
 * ones), paged 20 at a time with ?page=, one ticket's detail (?id=) and the status/note update (POST with a CSRF token). A bad token or an unknown
 * status redirects without a write. The detail view and every redirect keep the list state (status filter and page): the
 * POST form carries it in the hidden fields list_status / list_page, re-validated here before it goes into a URL.
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
            $state = self::listState($request->post['list_status'] ?? null, $request->post['list_page'] ?? null);

            if (
                !Csrf::valid($session, $request->post[Csrf::FIELD] ?? null)
                || !is_string($status)
                || !isset(SupportTicketService::STATUSES[$status])
                || !is_string($note)
                || !$this->tickets->adminUpdate($id, $status, $note)
            ) {
                return PageResult::redirect('manage_tickets.php' . ($state === [] ? '' : '?' . http_build_query($state)));
            }

            return PageResult::redirect('manage_tickets.php?' . http_build_query(['id' => $id] + $state + ['msg' => 'updated']));
        }

        // 2. The list (default: open tickets) and, with ?id=, the detail
        $filter = self::statusParam($request->query['status'] ?? null);
        $list = $this->tickets->adminList($filter, self::pageParam($request->query['page'] ?? null));

        $ticket = null;
        $id = (int) ($request->query['id'] ?? 0);
        if ($id > 0) {
            $ticket = $this->tickets->adminDetail($id);
        }

        return PageResult::view([
            'tickets' => $list['tickets'],
            'page' => $list['page'],
            'pages' => $list['pages'],
            'total' => $list['total'],
            'ticket' => $ticket,
            'filter' => $filter ?? 'open',
            // The list state to keep: status (absent for the default open filter) and page (absent for page 1).
            'list_query' => self::listState($filter, (string) $list['page']),
            'msg' => $request->query['msg'] ?? null,
        ]);
    }

    /** A status filter: one of the ticket statuses or `all`; anything else (including the default `open`) is null. */
    private static function statusParam(mixed $value): ?string
    {
        return is_string($value) && ($value === 'all' || isset(SupportTicketService::STATUSES[$value])) ? $value : null;
    }

    /**
     * The validated list state as URL params, in a fixed order: status (only a valid filter), page (only when > 1).
     *
     * @return array<string,string|int>
     */
    private static function listState(mixed $status, mixed $page): array
    {
        $state = [];
        $status = self::statusParam($status);
        if ($status !== null) {
            $state['status'] = $status;
        }
        $pageNo = self::pageParam($page);
        if ($pageNo > 1) {
            $state['page'] = $pageNo;
        }

        return $state;
    }

    /** A page number from the query: digits only, anything else is page 1 (the service clamps the upper end). */
    private static function pageParam(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) ? max(1, (int) $value) : 1;
    }
}
