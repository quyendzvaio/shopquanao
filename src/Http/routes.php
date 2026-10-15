<?php

/**
 * REST routing table: 'METHOD /regex' => controller file under Http/Controllers.
 * The front controller (public/api/index.php) matches in declaration order.
 */
return [
    // Auth
    'POST /auth/register'     => 'Auth/register.php',
    'POST /auth/login'        => 'Auth/login.php',
    'POST /auth/logout'       => 'Auth/logout.php',
    'GET  /auth/me'           => 'Auth/me.php',

    // Categories
    'GET  /categories'        => 'Categories/list.php',

    // Products
    'GET  /products'          => 'Products/list.php',
    'GET  /products/\d+'      => 'Products/detail.php',
    'GET  /products/\d+/sizes'  => 'Products/sizes.php',
    'GET  /products/\d+/reviews' => 'Products/reviews.php',
    'POST /products/\d+/reviews' => 'Products/add_review.php',

    // Cart
    'GET  /cart'              => 'Cart/list.php',
    'POST /cart'              => 'Cart/add.php',
    'PUT  /cart/\d+'          => 'Cart/update.php',
    'DELETE /cart/\d+'        => 'Cart/remove.php',

    // Orders
    'POST /orders'            => 'Orders/create.php',
    'GET  /orders'            => 'Orders/list.php',
    'GET  /orders/\d+'        => 'Orders/detail.php',

    // Chatbot
    'POST /chatbot'           => 'Chatbot/index.php',
    'POST /chatbot/stream'    => 'Chatbot/stream.php',
    'GET  /chatbot/history'   => 'Chatbot/history.php',

    // Chatbot support endpoints
    'GET  /size-guide'        => 'SizeGuide/index.php',
    'GET  /faq'               => 'Faq/index.php',
    'GET  /outfit'            => 'Outfit/index.php',
    'GET  /knowledge/search'  => 'Knowledge/search.php',

    // Service-to-service MCP bridge (blocked at the public Nginx layer)
    'POST /internal/mcp'      => 'Internal/mcp.php',

    // Admin
    'GET  /admin/dashboard'   => 'Admin/dashboard.php',
    'GET  /admin/products'    => 'Admin/products.php',
    'POST /admin/products'    => 'Admin/product_create.php',
    'PUT  /admin/products/\d+' => 'Admin/product_update.php',
    'DELETE /admin/products/\d+' => 'Admin/product_delete.php',
    'GET  /admin/orders'      => 'Admin/orders.php',
    'PUT  /admin/orders/\d+'  => 'Admin/order_status.php',
    'GET  /admin/users'       => 'Admin/users.php',
    'PUT  /admin/users/\d+'   => 'Admin/user_action.php',
];
