<?php

/**
 * public/admin/login.php: the admin sign-in form and its POST. Credentials come from the environment (through
 * AppConfig) or from a database user with role admin. Writes the session through the reference it is given,
 * with the keys, values and order the page always used (the env admin gets no user_id).
 */
final class AdminLoginPage
{
    public function __construct(private UserService $users, private AppConfig $config)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        $error = '';

        if (isset($request->post['login'])) {
            $username = trim($request->post['username'] ?? '');
            $password = $request->post['password'] ?? '';

            // 1. Admin credentials from the environment (production)
            $adminUser = $this->config->adminUser;
            $adminPass = $this->config->adminPassword;
            if ($adminUser !== '' && $adminPass !== '') {
                if ($username === $adminUser && $password === $adminPass) {
                    $session['admin_logged_in'] = true;
                    $session['role'] = 'admin';
                    $session['username'] = 'Admin Shop';
                    // The env admin has no user row: a customer session in the same browser must not survive into it
                    unset($session['user_id'], $session['api_token']);

                    return PageResult::redirect('index.php');
                }
            }

            // 2. Admin in the database (users table with role=admin)
            try {
                $user = $this->users->findAdminForAdminLogin($username);

                if ($user && password_verify($password, $user['password'])) {
                    $session['admin_logged_in'] = true;
                    $session['role'] = 'admin';
                    $session['user_id'] = (int)$user['id'];
                    $session['username'] = $user['username'];

                    return PageResult::redirect('index.php');
                }
            } catch (Exception $e) {
                // DB not available
            }

            $error = 'Sai tài khoản hoặc mật khẩu!';
        }

        return PageResult::view(['error' => $error]);
    }
}
