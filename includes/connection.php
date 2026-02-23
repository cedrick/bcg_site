<?php

require_once(__DIR__ . '/config.php');

Class Connection {

    protected $connection;

    function connectdb($database) {
        try {
            $dsn = "sqlsrv:Server=" . DB_SERVER . ";Database=" . $database;
            $this->connection = new PDO($dsn, DB_USERNAME, DB_PASSWORD, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
            return TRUE;
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            return FALSE;
        }
    }

    function getConnection() {
        return $this->connection;
    }

    function closedb() {
        $this->connection = null;
        return;
    }
}

?>
