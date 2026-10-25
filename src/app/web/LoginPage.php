<?php

/**
 * public/login.php: the sign-in form and its POST. Writes the session through the reference it is given, with the
 * keys, values and order the page always used (the env/DB admin gets user_id 0).
 */
final class LoginPage
{
    public function __construct(private UserService $users, private AppConfig $config)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        $error = "";

        if ($request->method == 'POST') {
            $email = trim($request->post['email']);
            $password = $request->post['password'];

            if (!empty($email) && !empty($password)) {
                // 1. Admin: credentials from the environment, or the database
                $adminEmail = $this->config->adminEmail;
                $adminPass = $this->config->adminPassword;
                if ($adminPass !== '') {
                    $isAdmin = ($email === $adminEmail || $email === 'admin') && $password === $adminPass;
                } else {
                    // Verify admin against database with password_verify
                    $adminUser = $this->users->findStorefrontAdmin($email);
                    $isAdmin = $adminUser && password_verify($password, $adminUser['password']);
                }
                if ($isAdmin) {
                    $session['user_id'] = 0;
                    $session['username'] = 'Admin Shop';
                    $session['role'] = 'admin';
                    $session['admin_logged_in'] = true;

                    return PageResult::redirect('admin/index.php');
                }

                // 2. Customer in the database
                $user = $this->users->customerByEmail($email);

                if ($user && password_verify($password, $user['password'])) {
                    // A locked account (status = 0) cannot sign in
                    if (isset($user['status']) && $user['status'] == 0) {
                        $error = "Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin!";
                    } else {
                        $session['user_id'] = $user['id'];
                        $session['username'] = $user['username'];
                        $session['role'] = 'user';

                        // Generate + store API token for chatbot
                        $session['api_token'] = $this->users->rotateApiToken($user['id']);

                        return PageResult::redirect('index.php');
                    }
                } else {
                    $error = "Tài khoản hoặc mật khẩu không chính xác!";
                }
            }
        }

        return PageResult::view([
            'error' => $error,
            'registerSuccess' => isset($request->query['register_success']),
        ]);
    }
}
