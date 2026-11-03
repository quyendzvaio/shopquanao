<?php

/**
 * Order-support tickets: the rules for a customer's request (type, own order, no duplicate, at most
 * 5 open) and the admin's handling. `draft` only checks; `create` re-checks and writes.
 */
final class SupportTicketService
{
    public const TYPES = [
        'doi_tra' => 'Đổi size / đổi trả',
        'hang_loi' => 'Hàng lỗi / giao sai',
        'huy_don' => 'Huỷ đơn',
        'doi_dia_chi' => 'Đổi địa chỉ giao',
        'khac' => 'Khác',
    ];
    public const STATUSES = [
        'moi' => 'Mới',
        'dang_xu_ly' => 'Đang xử lý',
        'da_xong' => 'Đã xong',
        'tu_choi' => 'Từ chối',
    ];
    public const OPEN_STATUSES = ['moi', 'dang_xu_ly'];

    private const MAX_OPEN = 5;
    private const SUMMARY_MAX = 500;
    private const NOTE_MAX = 2000;
    public const ADMIN_PAGE_SIZE = 20;
    public const PROFILE_PAGE_SIZE = 10;

    private const LIST_LIMIT = 5;

    private SupportTicketRepository $tickets;
    private OrderRepository $orders;

    public function __construct(PDO $pdo)
    {
        $this->tickets = new SupportTicketRepository($pdo);
        $this->orders = new OrderRepository($pdo);
    }

    /**
     * Checks a request without writing.
     *
     * @param array<string,mixed> $args type, order_id (optional), summary
     * @return array<string,mixed> ['status'=>'draft','draft'=>{type,order_id,summary}] or ['status'=>refusal,'message'=>...]
     */
    public function draft(?int $userId, array $args): array
    {
        if ($userId === null) {
            return $this->refusal('requires_login', 'Bạn cần đăng nhập để gửi yêu cầu hỗ trợ.');
        }
        $type = is_string($args['type'] ?? null) ? $args['type'] : '';
        $summary = is_string($args['summary'] ?? null) ? trim($args['summary']) : '';
        $length = mb_strlen($summary);
        if (!isset(self::TYPES[$type]) || $length < 1 || $length > self::SUMMARY_MAX) {
            return $this->refusal('invalid', 'Yêu cầu chưa hợp lệ.');
        }
        $orderId = $this->orderIdFrom($args['order_id'] ?? null);
        if ($orderId === null && $type !== 'khac') {
            return $this->refusal('order_required', 'Bạn cho mình biết mã đơn cần hỗ trợ nhé.');
        }
        if ($orderId !== null) {
            if ($this->orders->findForUser($orderId, $userId) === null) {
                return $this->refusal('order_not_found', 'Không tìm thấy đơn này trong tài khoản của bạn.');
            }
            $duplicate = $this->tickets->findOpenDuplicate($userId, $orderId, $type, self::OPEN_STATUSES);
            if ($duplicate !== null) {
                $id = (int) $duplicate['id'];
                return $this->refusal('duplicate_open', "Bạn đã có yêu cầu #$id cho đơn này đang được xử lý.") + [
                    'ticket_id' => $id,
                    'ticket_status' => (string) $duplicate['status'],
                ];
            }
        }
        if ($this->tickets->countOpenForUser($userId, self::OPEN_STATUSES) >= self::MAX_OPEN) {
            return $this->refusal('too_many_open', 'Bạn đang có 5 yêu cầu chưa xử lý xong, shop sẽ phản hồi sớm.');
        }
        return ['status' => 'draft', 'draft' => ['type' => $type, 'order_id' => $orderId, 'summary' => $summary]];
    }

    /**
     * Re-checks the draft, then writes the ticket as `moi`.
     *
     * @param array<string,mixed> $draft the `draft` part returned by draft()
     * @return array<string,mixed> ['status'=>'created','ticket_id'=>int] or the refusal shape of draft()
     */
    public function create(int $userId, array $draft, ?int $sessionId): array
    {
        $checked = $this->draft($userId, $draft);
        if ($checked['status'] !== 'draft') {
            return $checked;
        }
        $d = $checked['draft'];
        $id = $this->tickets->insert($userId, $d['order_id'], $d['type'], $d['summary'], $sessionId);
        return ['status' => 'created', 'ticket_id' => $id];
    }

    /**
     * The user's newest tickets, or one of their own by ticket_id.
     *
     * @param array<string,mixed> $args optional ticket_id
     * @return array<string,mixed>
     */
    public function listForUser(?int $userId, array $args): array
    {
        if ($userId === null) {
            return ['requires_login' => true, 'message' => 'Bạn cần đăng nhập để xem yêu cầu hỗ trợ.'];
        }
        $ticketId = $this->orderIdFrom($args['ticket_id'] ?? null);
        if ($ticketId !== null) {
            $row = $this->tickets->findForUser($userId, $ticketId);
            $rows = $row === null ? [] : [$row];
        } else {
            $rows = $this->tickets->listForUser($userId, self::LIST_LIMIT);
        }
        return ['tickets' => array_map([$this, 'present'], $rows)];
    }

    /**
     * One page of the admin list, newest first, across the chosen statuses in a single query.
     *
     * @param string|null $status null = the open ones (moi, dang_xu_ly); `all` = every status; an unknown status lists nothing
     * @return array{tickets:list<array<string,mixed>>,page:int,pages:int,total:int} tickets carry the customer's username
     */
    public function adminList(?string $status, int $page): array
    {
        if ($status === null) {
            $statuses = self::OPEN_STATUSES;
        } elseif ($status === 'all') {
            $statuses = array_keys(self::STATUSES);
        } elseif (isset(self::STATUSES[$status])) {
            $statuses = [$status];
        } else {
            return $this->emptyPage();
        }
        $total = $this->tickets->countForAdmin($statuses);
        [$page, $pages] = $this->clampPage($page, $total, self::ADMIN_PAGE_SIZE);
        $rows = $total === 0 ? [] : $this->tickets->listForAdmin($statuses, self::ADMIN_PAGE_SIZE, ($page - 1) * self::ADMIN_PAGE_SIZE);
        return ['tickets' => array_map([$this, 'present'], $rows), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /**
     * One page of the customer's own tickets for the profile, newest first.
     *
     * @return array{tickets:list<array<string,mixed>>,page:int,pages:int,total:int}
     */
    public function profileList(int $userId, int $page): array
    {
        $total = $this->tickets->countForUser($userId);
        [$page, $pages] = $this->clampPage($page, $total, self::PROFILE_PAGE_SIZE);
        $rows = $total === 0 ? [] : $this->tickets->listForUserPage($userId, self::PROFILE_PAGE_SIZE, ($page - 1) * self::PROFILE_PAGE_SIZE);
        return ['tickets' => array_map([$this, 'present'], $rows), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function adminDetail(int $id): ?array
    {
        $row = $this->tickets->findForAdmin($id);
        return $row === null ? null : $this->present($row);
    }

    /**
     * The open-ticket cap applies only when a customer files a request; an admin reopening a closed ticket is deliberate and is not capped.
     *
     * @return bool false for an unknown status or ticket id
     */
    public function adminUpdate(int $id, string $status, string $note): bool
    {
        if (!isset(self::STATUSES[$status]) || $this->tickets->findForAdmin($id) === null) {
            return false;
        }
        $this->tickets->updateStatusAndNote($id, $status, mb_substr(trim($note), 0, self::NOTE_MAX));
        return true;
    }

    public function countNew(): int
    {
        return $this->tickets->countByStatus('moi');
    }

    /** @return array{tickets:list<array<string,mixed>>,page:int,pages:int,total:int} */
    private function emptyPage(): array
    {
        return ['tickets' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
    }

    /** @return array{0:int,1:int} the page clamped into 1..pages, and the page count (at least 1) */
    private function clampPage(int $page, int $total, int $size): array
    {
        $pages = max(1, intdiv($total + $size - 1, $size));
        return [min(max(1, $page), $pages), $pages];
    }

    /** @return array{status:string,message:string} */
    private function refusal(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }

    /** A positive integer id from a tool argument (int or digit string), else null. */
    private function orderIdFrom(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            $id = (int) trim($value);
            return $id > 0 ? $id : null;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $out = [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'type_label' => self::TYPES[$row['type']] ?? (string) $row['type'],
            'order_id' => $row['order_id'] === null ? null : (int) $row['order_id'],
            'summary' => (string) $row['summary'],
            'status' => (string) $row['status'],
            'status_label' => self::STATUSES[$row['status']] ?? (string) $row['status'],
            'admin_note' => $row['admin_note'] === null ? null : (string) $row['admin_note'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
        if (array_key_exists('username', $row)) {
            $out['username'] = $row['username'] === null ? null : (string) $row['username'];
        }
        return $out;
    }
}
