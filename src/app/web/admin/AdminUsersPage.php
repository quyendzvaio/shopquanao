<?php

/**
 * public/admin/manage_users.php: the member list with lock/unlock and role toggling, all through POST forms.
 * The view gets only `current_user_id` (the session's user_id) to hide the actions on one's own row, never the
 * whole session with its CSRF and API tokens. An env admin has no user_id, so both places compare against null.
 */
final class AdminUsersPage
{
    public function __construct(private UserService $users)
    {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        $denied = AdminGuard::check($session);
        if ($denied !== null) {
            return $denied;
        }

        // Lock, unlock and role changes are POSTs with the session's CSRF token; otherwise the page is just the list
        $trusted = $request->method === 'POST' && Csrf::valid($session, $request->post[Csrf::FIELD] ?? null);

        // 2. Lock and unlock
        if ($trusted && isset($request->post['action']) && isset($request->post['id'])) {
            $id = (int)$request->post['id'];
            $action = $request->post['action'];
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
                    return PageResult::systemError($e, self::class);
                }
            }
        }

        // 3. Change the role (user <-> admin)
        if ($trusted && isset($request->post['toggle_role']) && isset($request->post['id'])) {
            $id = (int)$request->post['id'];
            $new_role = ($request->post['toggle_role'] === 'admin') ? 'admin' : 'user';
            $this->users->setRole($id, $new_role);
            return PageResult::redirect('manage_users.php?msg=updated');
        }

        // 4. The member list
        return PageResult::view([
            'users' => $this->users->adminList(),
            'msg' => $request->query['msg'] ?? null,
            'current_user_id' => $session['user_id'] ?? null,
        ]);
    }
}
