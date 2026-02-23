<?php
/**
 * Database configuration file.
 * 
 * In production, these values should be set via environment variables.
 * Fallback values are provided for development only.
 */

define('DB_SERVER', getenv('DB_SERVER') ?: 'HELIX');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'sa');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '1nfin1ty');
define('DB_NAME', getenv('DB_NAME') ?: 'NSI_BCG');

?>
