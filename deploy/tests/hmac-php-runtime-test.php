<?php
// Cross-language HMAC contract test: the PHP site's connect_to_url() signing
// (assets/includes/functions_general.php) vs the Node downloader API verifier.
// Usage: PORT=3100 TOKEN=secret php hmac-php-runtime-test.php  (server must be up)
// Exit 0 = pass. Auth-pass = response NOT in {401,403,429} (routes may 400/404/500
// without a live upstream key, which still proves the signature verified).

$port  = getenv('PORT') ?: '3100';
$token = getenv('TOKEN') ?: 'test-secret';
$base  = "http://127.0.0.1:{$port}";

function pt_sign($url, $token) {
    // Mirrors connect_to_url()'s 'hmac' branch exactly (functions_general.php):
    $ts    = time();
    $nonce = bin2hex(random_bytes(8));
    $p     = parse_url($url);
    $path  = isset($p['path']) ? $p['path'] : '/';
    $query = isset($p['query']) ? $p['query'] : '';
    $raw   = 'GET ' . $path . ($query !== '' ? '?' . $query : '') . "\n" . $ts . "\n" . $nonce;
    $sig   = hash_hmac('sha256', $raw, $token);
    return array($ts, $nonce, $sig);
}

function pt_request($url, $token, $tweak = null) {
    list($ts, $nonce, $sig) = pt_sign($url, $token);
    if ($tweak === 'sig')     $sig .= 'dead';
    if ($tweak === 'nonce')   $nonce = '';
    if ($tweak === 'old')     $ts = $ts - 600;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: HMAC ' . $sig,
        'X-Auth-Timestamp: ' . $ts,
        'X-Auth-Nonce: ' . $nonce
    ));
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status;
}

function auth_passed($code) { return !in_array($code, array(401, 403, 429), true); }
function auth_rejected($code) { return in_array($code, array(401, 403), true); }

$cases = array();
function check($name, $ok, $code) {
    global $cases;
    $cases[] = $ok;
    echo ($ok ? 'PASS' : 'FAIL') . " [$code] $name\n";
}

$url = $base . '/api/video/info?id=dQw4w9WgXcQ';

$c = pt_request($url, $token);                      check('PHP-signed request passes auth', auth_passed($c), $c);
$c = pt_request($url, $token, 'sig');               check('PHP sig tampered -> rejected',    auth_rejected($c), $c);
$c = pt_request($url, $token, 'nonce');             check('PHP empty nonce -> rejected',     auth_rejected($c), $c);
$c = pt_request($url, $token, 'old');               check('PHP stale ts -> rejected',        auth_rejected($c), $c);
$c = pt_request($base . '/api/videos/search?query=test%20band&limit=3', $token);
check('PHP-signed encoded query passes auth', auth_passed($c), $c);

$pass = count(array_filter($cases, function ($v) { return $v; }));
echo "\n=== PHP->Node HMAC contract: {$pass}/" . count($cases) . " passed ===\n";
exit($pass === count($cases) ? 0 : 1);