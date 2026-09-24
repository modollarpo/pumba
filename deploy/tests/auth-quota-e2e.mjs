// E2E auth + quota test for the downloader APIs (Node >= 16, stdlib only).
// Usage (from deploy/tests):  set PORT / TOKEN env, start the API with API_TOKEN
// set (plus optional API_TOKENS/TOKEN_QUOTAS for the quota section), then:
//   node hmac-e2e.mjs
// Exit code 0 = pass. The API's upstream (YouTube/TikTok) need not be reachable:
// the routes 400/404/500 when their key is missing, which still proves auth
// status on "pass" assertions (rejected codes are 401/403/429).
import http from 'node:http';
import crypto from 'node:crypto';

const PORT = process.env.PORT;
const TOKEN = process.env.TOKEN || process.env.API_TOKEN;
const BASE = `http://127.0.0.1:${PORT}`;
const PATH = '/api/video/info?id=dQw4w9WgXcQ';

function hmacSig(pathQuery, ts, nonce) {
    const raw = `GET ${pathQuery}\n${ts}\n${nonce}`;
    return crypto.createHmac('sha256', TOKEN).update(raw, 'utf8').digest('hex');
}

function request({ pathQuery = PATH, ts = Math.floor(Date.now() / 1000), nonce = crypto.randomBytes(8).toString('hex'), sigOverride = null, withAuth = true, token = TOKEN } = {}) {
    const sig = sigOverride !== null ? sigOverride : hmacSig(pathQuery, ts, nonce);
    const headers = withAuth ? {
        Authorization: `HMAC ${sig}`,
        'X-Auth-Timestamp': String(ts),
        'X-Auth-Nonce': nonce
    } : {};
    return new Promise((resolve) => {
        const req = http.request(`${BASE}${pathQuery}`, { method: 'GET', headers }, (res) => {
            res.resume();
            res.on('end', () => resolve(res.statusCode));
        });
        req.on('error', (e) => resolve('ERR:' + e.message));
        req.end();
    });
}

function bearer(token, pathQuery = PATH) {
    return new Promise((resolve) => {
        const req = http.request(`${BASE}${pathQuery}`, { method: 'GET', headers: { Authorization: `Bearer ${token}` } }, (res) => {
            res.resume();
            res.on('end', () => resolve(res.statusCode));
        });
        req.on('error', (e) => resolve('ERR:' + e.message));
        req.end();
    });
}

const authPassed = (code) => !String(code).startsWith('ERR') && ![401, 403, 429].includes(code);
const authRejected = (code) => code === 401 || code === 403;

const results = [];
async function check(name, code, okFn) {
    const ok = okFn(code);
    results.push(ok);
    console.log(`${ok ? 'PASS' : 'FAIL'} [${code}] ${name}`);
}

await check('valid signature passes auth', await request({}), authPassed);
await check('wrong signature rejected', await request({ sigOverride: '0'.repeat(64) }), authRejected);
const ts = Math.floor(Date.now() / 1000);
await check('old timestamp rejected (10 min)', await request({ ts: ts - 600 }), authRejected);
await check('future timestamp rejected', await request({ ts: ts + 3600 }), authRejected);
await check('empty nonce rejected', await request({ nonce: '' }), authRejected);
await check('no auth headers -> 401', await request({ withAuth: false }), authRejected);

const nonce = crypto.randomBytes(8).toString('hex');
const t0 = Math.floor(Date.now() / 1000);
const c1 = await request({ ts: t0, nonce });
const c2 = await request({ ts: t0, nonce });
const replayOk = authPassed(c1) && authRejected(c2);
results.push(replayOk);
console.log(`${replayOk ? 'PASS' : 'FAIL'} [${c1}->${c2}] replay: first passes, second rejected`);

// Per-token quota: if an extra token with a small quota is configured, assert
// it gets 429 after exhausting its bucket while the primary token does not.
const quoted = process.env.QUOTED_TOKEN;
if (quoted) {
    const codes = [];
    for (let i = 0; i < 5; i++) codes.push(await bearer(quoted));
    const primaryAfter = await bearer(TOKEN);
    const hitQuota = codes.some((c) => c === 429);
    results.push(hitQuota);
    console.log(`${hitQuota ? 'PASS' : 'FAIL'} [${codes.join(',')}] quoted token hits quota`);
    results.push(authPassed(primaryAfter));
    console.log(`${authPassed(primaryAfter) ? 'PASS' : 'FAIL'} [${primaryAfter}] primary unaffected after quoted exhausted`);
} else {
    console.log('[SKIP] quota section (set QUOTED_TOKEN + TOKEN_QUOTAS/API_TOKENS on the server)');
}

const pass = results.filter(Boolean).length;
console.log(`\n=== E2E auth+quota: ${pass}/${results.length} passed ===`);
process.exit(pass === results.length ? 0 : 1);