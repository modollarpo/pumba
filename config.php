<?php
// +------------------------------------------------------------------------+
// | @author Deen Doughouz (DoughouzForest)
// | @author_url 1: http://www.playtubescript.com
// | @author_url 2: http://codecanyon.net/user/doughouzforest
// | @author_email: wowondersocial@gmail.com   
// +------------------------------------------------------------------------+
// | PlayTube - The Ultimate Video Sharing Platform
// | Copyright (c) 2026 PlayTube. All rights reserved.
// +------------------------------------------------------------------------+
// Settings come from environment variables (Azure App Service app settings /
// container env) so the same code runs locally and in the cloud. When no env
// is present the values stay empty and first-run visitors get the installer.
$sql_db_host = getenv('SQL_DB_HOST') ?: "";
$sql_db_user = getenv('SQL_DB_USER') ?: "";
$sql_db_pass = getenv('SQL_DB_PASS') ?: "";
$sql_db_name = getenv('SQL_DB_NAME') ?: "";

// Site URL
$site_url = getenv('SITE_URL') ?: ""; // e.g (http://example.com)

// Purchase code
$purchase_code = getenv('PURCHASE_CODE') ?: ""; // Your purchase code, don't give it to anyone.

// First run / clean install: redirect to the installer.
if (empty($sql_db_host) && empty($sql_db_user) && empty($sql_db_name)) {
    header("Location: ./install");
    exit;
}

// Connect to MySQL using TLS when a CA cert is available (Azure MySQL
// Flexible has require_secure_transport=ON). Path: env var SQL_CA_CERT or
// ./az_ca.pem next to this file. Falls back to a plain connection if the
// CA file does not exist so local/other hosts keep working unchanged.
function pt_connect_db() {
    global $sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name;
    $ca  = getenv('SQL_CA_CERT') ?: (dirname(__FILE__) . '/az_ca.pem');
    $mysqli = mysqli_init();
    if (!empty($ca) && file_exists($ca)) {
        mysqli_ssl_set($mysqli, NULL, NULL, $ca, NULL, NULL);
        @mysqli_real_connect($mysqli, $sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name, 3306, NULL, MYSQLI_CLIENT_SSL);
    } else {
        @mysqli_real_connect($mysqli, $sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);
    }
    return $mysqli;
}
?>