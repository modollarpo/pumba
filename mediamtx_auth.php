<?php
// +------------------------------------------------------------------------+
// | | @author Deen Doughouz (DoughouzForest)
// | | @author_url 1: http://www.playtubescript.com
// | | @author_url 2: http://codecanyon.net/user/doughouzforest
// | | @author_email: wowondersocial@gmail.com
// +------------------------------------------------------------------------+
// | PlayTube - The Ultimate Video Sharing Platform
// | Copyright (c) 2026 PlayTube. All rights reserved.
// +------------------------------------------------------------------------+
//
// MediaMTX external HTTP auth callback (authMethod: http).
//
// MediaMTX POSTs a JSON body to this file for every publish/read/record
// attempt on a `stream_*` path. A 200 response allows the request, any
// other status denies it. When the `mediamtx_auth_secret` config value is
// empty (default) every request is allowed, so existing installations keep
// working untouched. Once the admin configures a secret, live playback and
// publishing require a signed, expiring per-stream token:
//
//   token = <expUnix>.<hex(hmac_sha256("<action>|<path>|<expUnix>", secret))>
//
// Reads are additionally revoked server-side: the token is only honoured
// while the stream row has live_ended = 0 and a recent live_time, so ending
// a stream immediately kills every signed HLS session.
// +------------------------------------------------------------------------+

require_once('config.php');

$mysqli = @new mysqli($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);
if ($mysqli->connect_error) {
    // DB down: fail open so an existing stream is not killed by a DB outage.
    http_response_code(200);
    exit();
}
$mysqli->set_charset('utf8mb4');

function pt_auth_allow() {
    http_response_code(200);
    exit();
}

function pt_auth_deny() {
    http_response_code(404);
    exit();
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = array();
}

$action = isset($body['action']) ? (string) $body['action'] : '';
$path   = isset($body['path']) ? (string) $body['path'] : '';

// Only stream_* paths are covered by this token scheme.
if (strpos($path, 'stream_') !== 0) {
    pt_auth_allow();
}

// Load the signing secret.
$secret = '';
$res = $mysqli->query("SELECT `value` FROM `config` WHERE `name` = 'mediamtx_auth_secret' LIMIT 1");
if ($res !== false && $row = $res->fetch_row()) {
    $secret = (string) $row[0];
}
if ($res !== false) {
    $res->free();
}

// Auth disabled; keep behaving like the previous un-tokened setup.
if (empty($secret)) {
    pt_auth_allow();
}

$token = isset($body['token']) ? (string) $body['token'] : '';
if ($token === '' && !empty($body['query'])) {
    parse_str(ltrim((string) $body['query'], '?'), $query_params);
    if (!empty($query_params['token'])) {
        $token = (string) $query_params['token'];
    }
}
if ($token === '') {
    pt_auth_deny();
}
$token = urldecode($token);

$parts = explode('.', $token);
if (count($parts) !== 2 || !is_numeric($parts[0]) || strlen($parts[1]) !== 64) {
    pt_auth_deny();
}

$exp = (int) $parts[0];
if ($exp < time()) {
    pt_auth_deny();
}

$valid = false;
$verify_actions = array($action);
if ($action === 'record') {
    // MediaMTX records with the credentials of the active publisher.
    $verify_actions[] = 'publish';
}
foreach ($verify_actions as $verify_action) {
    $sig = hash_hmac('sha256', $verify_action . '|' . $path . '|' . $exp, $secret);
    if (hash_equals($sig, $parts[1])) {
        $valid = true;
        break;
    }
}
if (!$valid) {
    pt_auth_deny();
}

// Revocation gate for reads: the stream must currently be live.
if ($action === 'read') {
    $stmt = $mysqli->prepare('SELECT COUNT(*) FROM `videos` WHERE `stream_name` = ? AND `live_ended` = 0 AND `live_time` >= ' . (time() - 30));
    if (!$stmt) {
        pt_auth_deny();
    }
    $stmt->bind_param('s', $path);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    if ((int) $cnt < 1) {
        pt_auth_deny();
    }
}

pt_auth_allow();