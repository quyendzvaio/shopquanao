<?php

/** Reads and writes support_tickets (a customer's order-support requests and the admin's handling of them). */
final class SupportTicketRepository
{
    private const COLUMNS = 'id, user_id, order_id, type, summary, status, admin_note, chat_session_id, created_at, updated_at';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return int the new ticket id; status starts as `moi` */
    public function insert(int $userId, ?int $orderId, string $type, string $summary, ?int $chatSessionId): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO support_tickets (user_id, order_id, type, summary, status, chat_session_id)
            VALUES (?, ?, ?, ?, 'moi', ?)");
        $stmt->execute([$userId, $orderId, $type, $summary, $chatSessionId]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<string> $openStatuses
     * @return array<string,mixed>|null the user's open ticket of this type on this order, if any
     */
    public function findOpenDuplicate(int $userId, int $orderId, string $type, array $openStatuses): ?array
    {
        $marks = implode(',', array_fill(0, count($openStatuses), '?'));
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM support_tickets
            WHERE user_id = ? AND order_id = ? AND type = ? AND status IN ($marks) ORDER BY id LIMIT 1");
        $stmt->execute(array_merge([$userId, $orderId, $type], $openStatuses));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param list<string> $openStatuses */
    public function countOpenForUser(int $userId, array $openStatuses): int
    {
        $marks = implode(',', array_fill(0, count($openStatuses), '?'));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status IN ($marks)");
        $stmt->execute(array_merge([$userId], $openStatuses));
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> the user's newest tickets first */
    public function listForUser(int $userId, int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM support_tickets
            WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit));
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null the ticket, only if it belongs to the user */
    public function findForUser(int $userId, int $ticketId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM support_tickets WHERE id = ? AND user_id = ?');
        $stmt->execute([$ticketId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param list<string> $statuses
     * @return list<array<string,mixed>> tickets of these statuses with the customer's username, newest first
     */
    public function listForAdmin(array $statuses): array
    {
        if ($statuses === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->pdo->prepare('SELECT t.id, t.user_id, t.order_id, t.type, t.summary, t.status, t.admin_note,
                t.chat_session_id, t.created_at, t.updated_at, u.username
            FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id
            WHERE t.status IN (' . $marks . ') ORDER BY t.created_at DESC, t.id DESC');
        $stmt->execute($statuses);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function findForAdmin(int $ticketId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT t.id, t.user_id, t.order_id, t.type, t.summary, t.status, t.admin_note,
                t.chat_session_id, t.created_at, t.updated_at, u.username
            FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?');
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function updateStatusAndNote(int $ticketId, string $status, string $note): void
    {
        $stmt = $this->pdo->prepare('UPDATE support_tickets SET status = ?, admin_note = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$status, $note, $ticketId]);
    }

    public function countByStatus(string $status): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM support_tickets WHERE status = ?');
        $stmt->execute([$status]);
        return (int) $stmt->fetchColumn();
    }
}
