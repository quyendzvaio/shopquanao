<?php

requireAdmin();

global $pdo;
$orders = (new OrderService($pdo))->allWithUsername();

foreach ($orders as &$o) {
    $o['id']         = (int)$o['id'];
    $o['user_id']    = (int)$o['user_id'];
    $o['total_price'] = (float)$o['total_price'];
}

jsonResponse(['orders' => $orders]);
