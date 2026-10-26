<?php

/** The counts and revenue shown on the admin dashboard. */
final class AdminDashboardService
{
    private ProductRepository $products;
    private OrderService $orders;
    private UserService $users;

    public function __construct(PDO $pdo)
    {
        $this->products = new ProductRepository($pdo);
        $this->orders = new OrderService($pdo);
        $this->users = new UserService($pdo);
    }

    /** @return array{total_products:int,total_orders:int,total_users:int,total_revenue:float} */
    public function stats(): array
    {
        $totalProducts = $this->products->count();
        $totalOrders   = $this->orders->count();
        $totalUsers    = $this->users->count();
        $totalRevenue  = $this->orders->completedRevenue();

        return [
            'total_products' => $totalProducts,
            'total_orders'   => $totalOrders,
            'total_users'    => $totalUsers,
            'total_revenue'  => $totalRevenue,
        ];
    }
}
