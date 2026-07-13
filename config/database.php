<?php
/**
 * Database connection for the Mbarara DLG Staff Attendance System.
 * Adjust these constants to match your MySQL/XAMPP setup.
 */

require_once __DIR__ . '/secrets.php';

define('DB_HOST', getenv('MBR_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('MBR_DB_NAME') ?: 'mbarara_attendance');
define('DB_USER', getenv('MBR_DB_USER') ?: 'root');
define('DB_PASS', getenv('MBR_DB_PASS') ?: '');
define('DB_PORT', getenv('MBR_DB_PORT') ?: '3306');

function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT);
        $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        die('Database connection failed. Confirm MySQL is running and the "mbarara_attendance" '
            . 'database has been imported from database/schema.sql. (' . $e->getMessage() . ')');
    }

    return $conn;
}
