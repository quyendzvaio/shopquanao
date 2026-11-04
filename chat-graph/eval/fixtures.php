<?php
/**
 * Live-eval fixtures, run INSIDE the app container (the image has src/ but no bind mount of the repo):
 *
 *   docker compose exec -T app php /dev/stdin '<json request>' < chat-graph/eval/fixtures.php
 *
 * `php /dev/stdin` reads this script from stdin (plain `php -` is not accepted) and passes the JSON request as $argv[1]. It talks only to
 * existing service/repository classes (UserService, CartService, ProductService, OrderRepository, OrderService), never raw SQL, and prints one JSON object.
 *
 *   {"action":"prepare","name":"eval_user_<run>_<n>","cart":[{"product_id":53,"quantity":1,"size":"M"}]}
 *       -> {"user_id":int,"token":string}   registers the user (fresh name per run: a bearer user
 *          reuses its latest chat session, so a reused user would inherit old history) and fills its cart
 *       optional "orders":[{"total":415000,"status":"Đang giao","items":[{"product_id":53,"quantity":1}]}] also creates those
 *       orders for the user (OrderRepository::insertOrder/insertItem + OrderService::updateStatus; items at the product's
 *       current price, which must add up to "total"; no stock change) and adds "order_ids":[int,...] to the result
 *   {"action":"cleanup","user_ids":[int,...]}
 *       -> {"cleared":int,"skipped":[int]}  removes every cart line of those users, but only users whose
 *          username matches ^eval_user_ (looked up via UserService::adminList); any other id is skipped
 *          with a note on stderr, so a wrong id can never empty a real customer's cart
 *
 * Users have no delete path in the services; eval users stay in the table, clearly named eval_user_*. Their orders and
 * support tickets stay too (cleanup only empties carts).
 */

require '/var/www/html/src/bootstrap.php';

/** @var PDO $pdo */
$request = json_decode((string) ($argv[1] ?? ''), true);
if (!is_array($request) || !isset($request['action'])) {
    fwrite(STDERR, "fixtures: expected a JSON request as the first argument\n");
    exit(2);
}

$carts = new CartService($pdo);

switch ($request['action']) {
    case 'prepare':
        $name = (string) ($request['name'] ?? '');
        if (!preg_match('/^eval_user_[a-z0-9_]{1,40}$/', $name)) {
            fwrite(STDERR, "fixtures: name must match eval_user_[a-z0-9_]+\n");
            exit(2);
        }
        $registered = (new UserService($pdo))->registerViaApi([
            'username' => $name,
            'email' => $name . '@eval.invalid',
            'password' => bin2hex(random_bytes(12)),
        ]);
        $userId = (int) $registered['user']['id'];
        foreach ((array) ($request['cart'] ?? []) as $line) {
            $carts->add($userId, [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) ($line['quantity'] ?? 1),
                'size' => (string) ($line['size'] ?? ''),
            ]);
        }
        $orderIds = [];
        $orderRepo = new OrderRepository($pdo);
        $orderService = new OrderService($pdo);
        $products = new ProductService($pdo);
        foreach ((array) ($request['orders'] ?? []) as $order) {
            $status = (string) ($order['status'] ?? 'Chờ xử lý');
            if (!in_array($status, OrderService::STATUSES, true)) {
                fwrite(STDERR, "fixtures: unknown order status {$status}\n");
                exit(2);
            }
            // Items are priced and checked before the order exists, so a bad fixture never leaves a half-built order.
            $total = (float) ($order['total'] ?? 0);
            $lines = [];
            foreach ((array) ($order['items'] ?? []) as $item) {
                $product = $products->pageRow((int) ($item['product_id'] ?? 0));
                if ($product === null) {
                    fwrite(STDERR, "fixtures: unknown product in order items\n");
                    exit(2);
                }
                $lines[] = [(int) $product['id'], (int) ($item['quantity'] ?? 1), (float) $product['price']];
            }
            $sum = array_sum(array_map(static fn (array $l): float => $l[1] * $l[2], $lines));
            if ($lines !== [] && abs($sum - $total) > 0.001) {
                fwrite(STDERR, "fixtures: order items add up to {$sum}, not the order total {$total}\n");
                exit(2);
            }
            $orderId = $orderRepo->insertOrder($userId, $total);
            foreach ($lines as [$productId, $quantity, $price]) {
                $orderRepo->insertItem($orderId, $productId, $quantity, $price);
            }
            $orderService->updateStatus($orderId, $status);
            $orderIds[] = $orderId;
        }
        echo json_encode(['user_id' => $userId, 'token' => $registered['token'], 'order_ids' => $orderIds]);
        break;

    case 'cleanup':
        $cleared = 0;
        $skipped = [];
        $names = [];
        foreach ((new UserService($pdo))->adminList() as $user) {
            $names[(int) $user['id']] = (string) $user['username'];
        }
        foreach ((array) ($request['user_ids'] ?? []) as $userId) {
            if (!preg_match('/^eval_user_/', $names[(int) $userId] ?? '')) {
                fwrite(STDERR, "fixtures: refusing to clean user {$userId}: not an eval_user_* account\n");
                $skipped[] = (int) $userId;
                continue;
            }
            foreach ($carts->list((int) $userId)['cart'] as $line) {
                $carts->remove((int) $userId, (int) $line['cart_id']);
                $cleared++;
            }
        }
        echo json_encode(['cleared' => $cleared, 'skipped' => $skipped]);
        break;

    default:
        fwrite(STDERR, "fixtures: unknown action\n");
        exit(2);
}
