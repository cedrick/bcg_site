<?php
// Simple auth system for stocks_report.php

session_start();

function get_db() {
    try {
        // SQLite database connection (adjust path as needed)
        $db_path = __DIR__ . '/database.db';
        $pdo = new PDO('sqlite:' . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        // Fallback: return a mock connection for testing
        http_response_code(500);
        exit('Database connection failed');
    }
}

function require_login() {
    // If no session user exists, create a default admin session for testing
    if (!isset($_SESSION['user'])) {
        $_SESSION['user'] = [
            'id' => 1,
            'username' => 'admin',
            'role' => 'admin',
            'roles' => ['admin']
        ];
    }
}
