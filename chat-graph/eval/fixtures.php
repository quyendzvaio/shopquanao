<?php
/**
 * Live-eval fixtures, run INSIDE the app container (the image has src/ but no bind mount of the repo):
 *
 *   docker compose exec -T app php /dev/stdin '<json request>' < chat-graph/eval/fixtures.php
 *
 * `php /dev/stdin` reads this script from stdin (plain `php -` is not accepted) and passes the JSON request as $argv[1]. It talks only to
 * existing service classes (UserService, CartService), never raw SQL, and prints one JSON object.
 *
 *   {"action":"prepare","name":"eval_user_<run>_<n>","cart":[{"product_id":53,"quantity":1,"size":"M"}]}
 *       -> {"user_id":int,"token":string}   registers the user (fresh name per run: a bearer user
 *          reuses its latest chat session, so a reused user would inherit old history) and fills its cart
 *   {"action":"cleanup","user_ids":[int,...]}
 *       -> {"cleared":int}                  removes every cart line of those users
 *
 * Users have no delete path in the services; eval users stay in the table, clearly named eval_user_*.
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
        echo json_encode(['user_id' => $userId, 'token' => $registered['token']]);
        break;

    case 'cleanup':
        $cleared = 0;
        foreach ((array) ($request['user_ids'] ?? []) as $userId) {
            foreach ($carts->list((int) $userId)['cart'] as $line) {
                $carts->remove((int) $userId, (int) $line['cart_id']);
                $cleared++;
            }
        }
        echo json_encode(['cleared' => $cleared]);
        break;

    default:
        fwrite(STDERR, "fixtures: unknown action\n");
        exit(2);
}
