<?php

/**
 * REST API accounts: token lookup, registration, login, logout and the admin user list.
 *
 * Failures are ApiError exceptions whose code is the HTTP status the Auth controllers answer with.
 */
final class UserService
{
    private UserRepository $users;

    public function __construct(PDO $pdo)
    {
        $this->users = new UserRepository($pdo);
    }

    /** @return array<int|string,mixed>|null id, username, email, role and status of the token's owner */
    public function userForApiToken(string $token): ?array
    {
        return $this->users->findByApiToken($token);
    }

    /**
     * @param array<string,mixed> $data request body: email, password and username (or fullname)
     * @return array<string,mixed> the 201 response body of POST /api/auth/register
     * @throws ApiError invalid input (400) or the email is already registered (409)
     */
    public function registerViaApi(array $data): array
    {
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $username = trim($data['username'] ?? $data['fullname'] ?? '');

        if (!$email || !$password || !$username) {
            throw new ApiError('Missing required fields: username, email, password', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiError('Invalid email format', 400);
        }

        if (strlen($password) < 6) {
            throw new ApiError('Password must be at least 6 characters', 400);
        }

        // Check email uniqueness
        if ($this->users->emailExists($email)) {
            throw new ApiError('Email already registered', 409);
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $token  = bin2hex(random_bytes(32));

        $userId = $this->users->createWithApiToken($username, $email, $hashed, $token);

        return [
            'message' => 'Registration successful',
            'user' => [
                'id'       => $userId,
                'username' => $username,
                'email'    => $email,
                'role'     => 'user',
            ],
            'token' => $token,
        ];
    }

    /**
     * @param array<string,mixed> $data request body: email (or username) and password
     * @return array<string,mixed> the 200 response body of POST /api/auth/login
     * @throws ApiError missing credentials (400), wrong credentials (401) or a locked account (403)
     */
    public function loginViaApi(array $data): array
    {
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (!$email || !$password) {
            throw new ApiError('Missing email or password', 400);
        }

        // Find user by email or username
        $user = $this->users->findByEmailOrUsername($email);

        if (!$user || !password_verify($password, $user['password'])) {
            throw new ApiError('Invalid email or password', 401);
        }

        if ((int)$user['status'] === 0) {
            throw new ApiError('Account is locked. Contact admin.', 403);
        }

        // Generate & store token
        $token = bin2hex(random_bytes(32));
        $this->users->setApiToken((int)$user['id'], $token);

        return [
            'message' => 'Login successful',
            'user' => [
                'id'       => (int)$user['id'],
                'username' => $user['username'],
                'email'    => $user['email'],
                'role'     => $user['role'],
            ],
            'token' => $token,
        ];
    }

    public function logout(int $userId): void
    {
        $this->users->clearApiToken($userId);
    }

    /** @return list<array<int|string,mixed>> every user, newest first */
    public function adminList(): array
    {
        return $this->users->listForAdmin();
    }

    /**
     * @param int $status 0 locks the account, 1 unlocks it
     * @return int affected rows
     */
    public function setStatus(int $id, int $status): int
    {
        return match ($status) {
            0 => $this->users->lock($id),
            1 => $this->users->unlock($id),
            default => throw new InvalidArgumentException('Status must be 0 or 1', 400),
        };
    }

    /** @return int affected rows */
    public function setRole(int $id, string $role): int
    {
        return $this->users->setRole($id, $role);
    }

    public function count(): int
    {
        return $this->users->count();
    }
}
