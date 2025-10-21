<?php
/**
 * Entry point for Luis's PG Rank Exam project
 * 
 * Loads the framework bootstrap (liber.php) which:
 *  - Parses the URI
 *  - Loads the controller (e.g., top.inc)
 *  - Handles RESTful API routes (/api/…)
 *  - Renders views (HTML templates in /views/)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // set to 1 if you want to debug

// Define project root
define('ROOT_DIR', __DIR__);

// Optional: timezone (to match your config)
date_default_timezone_set('Asia/Tokyo');

// Bootstrap the framework
require_once ROOT_DIR . '/liber.php';

// If no output (failsafe)
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
?>