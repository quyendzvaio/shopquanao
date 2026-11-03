<?php

global $pdo;
$categories = (new CategoryService($pdo))->all();

jsonResponse(['categories' => $categories]);
