<?php

/**
 * public/admin/manage_users.php: the member list with lock/unlock and role toggling, all through GET links.
 * The template reads the session's user_id itself (the `session` view variable) to hide the actions on one's
 * own row. An env admin has no user_id, so both places compare against null.
 */
final class AdminUsersPage
{
    public function __construct(private UserService $users)
    {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        $denied = AdminGuard::check($session, false, '../login.php');
        if ($denied !== null) {
            return $denied;
        }

        // 2. Lock and unlock
        if (isset($request->query['action']) && isset($request->query['id'])) {
            $id = (int)$request->query['id'];
            $action = $request->query['action'];
            $msg = ''; // an unknown action redirects to `?msg=` (the old page logged an undefined-variable warning there)

            if ($id != ($session['user_id'] ?? null)) {
                try {
                    if ($action === 'lock') {
                        $this->users->setStatus($id, 0);
                        $msg = "locked";
                    } elseif ($action === 'unlock') {
                        $this->users->setStatus($id, 1);
                        $msg = "unlocked";
                    }
                    return PageResult::redirect("manage_users.php?msg=$msg");
                } catch (PDOException $e) {
                    return PageResult::halt("Lỗi hệ thống: " . $e->getMessage());
                }
            }
        }

        // 3. Change the role (user <-> admin)
        if (isset($request->query['toggle_role']) && isset($request->query['id'])) {
            $id = (int)$request->query['id'];
            $new_role = ($request->query['toggle_role'] === 'admin') ? 'admin' : 'user';
            $this->users->setRole($id, $new_role);
            return PageResult::redirect('manage_users.php?msg=updated');
        }

        // 4. The member list
        return PageResult::view([
            'users' => $this->users->adminList(),
            'msg' => $request->query['msg'] ?? null,
            'session' => $session,
        ]);
    }
}
