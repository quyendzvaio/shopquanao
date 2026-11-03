<?php

/**
 * public/register.php: the sign-up form and its POST. A successful sign-up returns a redirect result, which
 * public/register.php sends before it includes the header, so the response is a real 302.
 */
final class RegisterPage
{
    public function __construct(private UserService $users)
    {
    }

    public function handle(WebRequest $request): PageResult
    {
        $error = "";

        if ($request->method == 'POST') {
            $fullname = $request->post['fullname'];
            $email = $request->post['email'];
            $password = $request->post['password'];
            $confirm_password = $request->post['confirm_password'];

            if ($password !== $confirm_password) {
                $error = "Mật khẩu xác nhận không khớp!";
            } else {
                if ($this->users->emailTaken($email)) {
                    $error = "Email này đã được đăng ký!";
                } else {
                    if ($this->users->registerFromWeb($fullname, $email, $password)) {
                        return PageResult::redirect('login.php?register_success=1');
                    } else {
                        $error = "Có lỗi xảy ra, vui lòng thử lại.";
                    }
                }
            }
        }

        return PageResult::view(['error' => $error]);
    }
}
