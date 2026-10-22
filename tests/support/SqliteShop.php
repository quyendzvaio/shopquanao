<?php

declare(strict_types=1);

/** Minimal in-memory shop schema for hermetic unit tests (no MariaDB). */
final class SqliteShop
{
    public static function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, category_id INTEGER, name TEXT, price REAL,
            stock INTEGER, description TEXT, image TEXT, style_tags TEXT NOT NULL DEFAULT '')");
        $pdo->exec('CREATE TABLE product_sizes (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, size_name TEXT)');
        $pdo->exec('CREATE TABLE size_guides (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, category_id INTEGER,
            size_name TEXT, height_from INTEGER, height_to INTEGER, weight_from INTEGER, weight_to INTEGER, description TEXT)');
        $pdo->exec('CREATE TABLE cart (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, product_id INTEGER,
            quantity INTEGER DEFAULT 1, size TEXT)');
        $pdo->exec('CREATE TABLE chat_session_memory (session_id INTEGER PRIMARY KEY, summary TEXT, slots TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $pdo;
    }

    /** @param list<string> $sizes */
    public static function product(
        PDO $pdo,
        int $id,
        int $categoryId,
        string $name,
        int $price,
        int $stock,
        string $tags,
        array $sizes = ['S', 'M', 'L', 'XL']
    ): void {
        $pdo->prepare('INSERT INTO products (id, category_id, name, price, stock, description, image, style_tags)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $categoryId, $name, $price, $stock, '', "p$id.jpg", $tags]);
        $insert = $pdo->prepare('INSERT INTO product_sizes (product_id, size_name) VALUES (?, ?)');
        foreach ($sizes as $size) {
            $insert->execute([$id, $size]);
        }
    }

    public static function sizeGuides(PDO $pdo): void
    {
        $insert = $pdo->prepare('INSERT INTO size_guides (category_id, size_name, height_from, height_to, weight_from, weight_to)
            VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([1, 2] as $category) {
            $insert->execute([$category, 'S', 155, 165, 45, 55]);
            $insert->execute([$category, 'M', 160, 170, 55, 65]);
            $insert->execute([$category, 'L', 165, 175, 60, 75]);
        }
        $insert->execute([3, 'M', 155, 165, 48, 58]);
        $insert->execute([3, 'L', 160, 170, 55, 68]);
    }
}
