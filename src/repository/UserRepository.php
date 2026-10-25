<?php

/** Reads and writes the users table (REST API accounts and the admin user list). */
final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int|string,mixed>|null the auth columns of the user holding this API token (PDO default fetch mode) */
    public function findByApiToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, username, email, role, status FROM users WHERE api_token = ?");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return int|null id of the user holding this API token (the chatbot session lookup) */
    public function idByApiToken(string $token): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE api_token = ?');
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        return $user ? (int) $user['id'] : null;
    }

    /** @return array<int|string,mixed>|null the full users row matching an email or a username (PDO default fetch mode) */
    public function findByEmailOrUsername(string $login): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
        $stmt->execute([$login, $login]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function setApiToken(int|string $id, string $token): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
        $stmt->execute([$token, $id]);
    }

    public function clearApiToken(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET api_token = NULL WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return (bool)$stmt->fetch();
    }

    /** Inserts a regular, active user with an API token and returns the new id. */
    public function createWithApiToken(string $username, string $email, string $passwordHash, string $token): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password, api_token, role, status) VALUES (?, ?, ?, ?, 'user', 1)");
        $stmt->execute([$username, $email, $passwordHash, $token]);
        return (int)$this->pdo->lastInsertId();
    }

    public function count(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    }

    /** @return list<array<int|string,mixed>> every user, newest first (PDO default fetch mode) */
    public function listForAdmin(): array
    {
        $stmt = $this->pdo->query("SELECT id, username, email, role, status, created_at FROM users ORDER BY id DESC");
        return $stmt->fetchAll();
    }

    /** @return int affected rows */
    public function lock(int $id): int
    {
        $stmt = $this->pdo->prepare("UPDATE users SET status = 0 WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount();
    }

    /** @return int affected rows */
    public function unlock(int $id): int
    {
        $stmt = $this->pdo->prepare("UPDATE users SET status = 1 WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount();
    }

    /** @return int affected rows */
    public function setRole(int $id, string $role): int
    {
        $stmt = $this->pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->execute([$role, $id]);
        return $stmt->rowCount();
    }

    /**
     * public/login.php: the admin account matching an email or a username (the database-admin path).
     *
     * @return array<int|string,mixed>|false the users row, false when none (PDO default fetch mode)
     */
    public function findAdminByLogin(string $login): array|false
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE (email = ? OR username = ?) AND role = 'admin' LIMIT 1");
        $stmt->execute([$login, $login]);
        return $stmt->fetch();
    }

    /**
     * public/admin/login.php: the admin account matching a username or an email (the database path of the admin sign-in).
     *
     * @return array<int|string,mixed>|false the users row, false when none (PDO default fetch mode)
     */
    public function findAdminForAdminLogin(string $username): array|false
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND role = 'admin' LIMIT 1");
        $stmt->execute([$username, $username]);
        return $stmt->fetch();
    }

    /**
     * public/login.php: the users row with this email (any role).
     *
     * @return array<int|string,mixed>|false the users row, false when none (PDO default fetch mode)
     */
    public function findByEmail(string $email): array|false
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch();
    }

    /**
     * public/register.php: is the email taken. Counts rows with rowCount() as the page always did.
     * The value is bound raw, whatever the form sent.
     */
    public function emailTakenForRegisterPage(mixed $email): bool
    {
        $check = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        return $check->rowCount() > 0;
    }

    /**
     * public/register.php: inserts the user without role/status (column defaults apply), values bound raw.
     *
     * @return bool what PDOStatement::execute returned
     */
    public function insertFromRegisterPage(mixed $username, mixed $email, string $passwordHash, string $token): bool
    {
        $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password, api_token) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$username, $email, $passwordHash, $token]);
    }
}
