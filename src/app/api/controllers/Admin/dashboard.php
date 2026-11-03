<?php

requireAdmin();

global $pdo;

jsonResponse(['dashboard' => (new AdminDashboardService($pdo))->stats()]);
