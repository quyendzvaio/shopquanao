<?php

/** public/admin/index.php: the dashboard counts and the revenue of completed orders. */
final class AdminDashboardPage
{
    public function __construct(private AdminDashboardService $dashboard)
    {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        $denied = AdminGuard::check($session);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $stats = $this->dashboard->stats();
        } catch (PDOException $e) {
            return PageResult::halt("Lỗi kết nối dữ liệu: " . $e->getMessage());
        }

        return PageResult::view($stats);
    }
}
