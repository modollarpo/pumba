const express = require('express');
const crypto = require('crypto');
const { execFile } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');
const app = express();

const PORT = process.env.PORT || 3000;
const YTDLP = process.env.YTDLP_BIN || '/usr/local/bin/yt-dlp';
const MEDIA_DIR = process.env.MEDIA_DIR || os.tmpdir();
const MEDIA_TTL_MS = parseInt(process.env.MEDIA_TTL_MS, 10) || 10 * 60 * 1000;
const YTDLP_TIMEOUT = parseInt(process.env.YTDLP_TIMEOUT, 10) || 15 * 60 * 1000;
const TIKTOK_URL_RE = /^https?:\/\/(?:www\.|vm\.|vt\.|m\.)?tiktok\.com\//i;

// --- API hardening: token auth + rate limiting ---
const API_TOKEN = process.env.API_TOKEN || '';
if (!API_TOKEN) {
    console.warn('[SECURITY] API_TOKEN is not set. Requests will be rejected until you set it in .env and in the PlayTube admin (see playtube.sql config keys tiktok_api_token).');
}
const RATE_WINDOW_MS = parseInt(process.env.RATE_LIMIT_WINDOW_MS, 10) || 60000;
const RATE_LIMIT_MAX = parseInt(process.env.RATE_LIMIT_MAX, 10) || 60;
// Optional extra consumer tokens (comma-separated) and per-token quota
// overrides "token:limit,token:limit" — useful when several consumers share
// one API (self-hosted media bots, cron importers) and each needs its own cap.
const EXTRA_TOKENS = String(process.env.API_TOKENS || '').split(',').map(s => s.trim()).filter(Boolean);
const ALL_TOKENS = [API_TOKEN].concat(EXTRA_TOKENS).filter(Boolean);
const TOKEN_QUOTAS = {};
for (const pair of String(process.env.TOKEN_QUOTAS || '').split(',')) {
    const m = /^(.+):(\d+)$/.exec(pair.trim());
    if (m) TOKEN_QUOTAS[m[1]] = parseInt(m[2], 10);
}
const AUTH_FAIL_WINDOW_MS = parseInt(process.env.AUTH_FAIL_WINDOW_MS, 10) || 60000;
const AUTH_FAIL_LIMIT = parseInt(process.env.AUTH_FAIL_LIMIT, 10) || 20;
const hits = new Map();
const authFails = new Map();

function safeTokenEquals(a, b) {
    const ba = Buffer.from(String(a || ''));
    const bb = Buffer.from(String(b || ''));
    if (ba.length !== bb.length) return false;
    return crypto.timingSafeEqual(ba, bb);
}

function isValidToken(token) {
    return ALL_TOKENS.some(t => safeTokenEquals(token, t));
}

function clientKey(req) {
    const fwd = String(req.headers['x-forwarded-for'] || '');
    const ip = fwd ? fwd.split(',')[0].trim() : (req.connection && req.connection.remoteAddress) || req.ip || '';
    return (ip || 'unknown').toLowerCase();
}

// --- HMAC request signing (preferred) with Bearer fallback ----------------
// The PlayTube site signs each request with a per-request HMAC
// (Authorization: HMAC <sig>, X-Auth-Timestamp, X-Auth-Nonce) derived from
// API_TOKEN, so the raw token never travels on the wire. A timestamp window
// (+-5 min) plus a nonce replay cache block captured-request re-use.
const AUTH_CLOCK_SKEW_MS = 5 * 60 * 1000;
const seenNonces = new Map();

function hmacSig(req) {
    const ts = String(req.get('x-auth-timestamp') || '').trim();
    const nonce = String(req.get('x-auth-nonce') || '').trim();
    if (!/^\d{9,11}$/.test(ts) || !/^[0-9a-f]{8,64}$/.test(nonce)) return null;
    if (Math.abs(Date.now() - parseInt(ts, 10) * 1000) > AUTH_CLOCK_SKEW_MS) return null;
    if (seenNonces.has(nonce)) return null; // replay
    // bind signature to method + path + query exactly as received
    const raw = req.method + ' ' + req.originalUrl + '\n' + ts + '\n' + nonce;
    const sig = crypto.createHmac('sha256', API_TOKEN).update(raw, 'utf8').digest('hex');
    seenNonces.set(nonce, Date.now());
    if (seenNonces.size > 20000) {
        for (const [k, v] of seenNonces) {
            if (Date.now() - v > AUTH_CLOCK_SKEW_MS) seenNonces.delete(k);
        }
    }
    return sig;
}

function authRequired(req, res, next) {
    const header = String(req.get('authorization') || '');
    if (header.startsWith('HMAC ')) {
        const provided = header.slice(5).trim();
        const expected = hmacSig(req);
        if (!API_TOKEN || !expected || !safeTokenEquals(provided, expected)) {
            return failAuth(req, res);
        }
        req.authToken = API_TOKEN;
        return next();
    }
    // backward-compatible fallback: plain Bearer / query / header token
    let token = header.replace(/^Bearer\s+/i, '').trim();
    if (!token && req.query.token) token = String(req.query.token).trim();
    if (!token && req.get('x-api-token')) token = String(req.get('x-api-token')).trim();
    if (!isValidToken(token)) {
        return failAuth(req, res);
    }
    req.authToken = token;
    next();
}

// Throttle authentication failures per client (brute-force protection around
// the HMAC/Bearer verification itself — it sits before the per-token limiter).
function failAuth(req, res) {
    const key = clientKey(req);
    const now = Date.now();
    let rec = authFails.get(key);
    if (!rec || now - rec.windowStart > AUTH_FAIL_WINDOW_MS) {
        rec = { windowStart: now, count: 0 };
        authFails.set(key, rec);
    }
    rec.count += 1;
    if (rec.count > AUTH_FAIL_LIMIT) {
        return res.status(429).json({ success: false, message: 'Too many failed attempts. Please try again later.' });
    }
    return res.status(401).json({ success: false, message: 'Unauthorized' });
}

function rateLimiter(req, res, next) {
    const key = req.authToken ? 't:' + crypto.createHash('sha256').update(req.authToken).digest('hex') : clientKey(req);
    const limit = req.authToken && TOKEN_QUOTAS[req.authToken] ? TOKEN_QUOTAS[req.authToken] : RATE_LIMIT_MAX;
    const now = Date.now();
    let rec = hits.get(key);
    if (!rec || now - rec.windowStart > RATE_WINDOW_MS) {
        rec = { windowStart: now, count: 0 };
        hits.set(key, rec);
    }
    rec.count += 1;
    if (rec.count > limit) {
        return res.status(429).json({ success: false, message: 'Too many requests' });
    }
    next();
}

// Public paths: health probe and the media proxy (media has its own limiter).
app.use((req, res, next) => {
    const p = String(req.path);
    if (p.startsWith('/health') || p.startsWith('/api/tiktok/media')) return next();
    authRequired(req, res, () => rateLimiter(req, res, next));
});

// --- Ops: public health probe (no auth, no rate cap) -----------------------
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'tiktok-downloader',
        uptimeSeconds: Math.floor(process.uptime()),
        binary: path.basename(YTDLP),
        tokenSet: !!API_TOKEN
    });
});

// --- yt-dlp helpers ----------------------------------------------------------
function runYtdlp(args, timeoutMs) {
    return new Promise((resolve, reject) => {
        execFile(YTDLP, args, { maxBuffer: 128 * 1024 * 1024, timeout: timeoutMs || YTDLP_TIMEOUT }, (err, stdout, stderr) => {
            if (err) {
                let msg = String(stderr || err.message || '').trim();
                if (!msg) msg = err.message;
                const lines = msg.split('\n').filter(Boolean);
                reject(new Error(lines[lines.length - 1] || msg));
                return;
            }
            resolve(stdout);
        });
    });
}

function secondsToIso(seconds) {
    const s = Math.max(0, parseInt(seconds, 10) || 0);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    return 'PT' + (h > 0 ? h + 'H' : '') + (m > 0 || h > 0 ? m + 'M' : '') + sec + 'S';
}

function pickThumb(info) {
    if (info && info.thumbnail) return info.thumbnail;
    const ts = (info && Array.isArray(info.thumbnails)) ? info.thumbnails : [];
    return ts.length ? ts[ts.length - 1].url : '';
}

function videoIdFromUrl(url) {
    const m = /\/video\/(\d+)/.exec(String(url || ''));
    return m ? m[1] : '';
}

function mediaEndpoint(req, videoUrl) {
    // TLS terminates at the platform (App Service/ingress), so req.protocol is
    // "http" on the inside — always advertise https for the public URL.
    return 'https://' + req.get('host') + '/api/tiktok/media?video_url=' + encodeURIComponent(videoUrl);
}

async function mapUserItems(username, limit) {
    const url = 'https://www.tiktok.com/@' + encodeURIComponent(username);
    const raw = await runYtdlp(['--flat-playlist', '-I', '1:' + limit, '-J', '--no-warnings', '--no-check-certificates', '--no-playlist', url]);
    const data = JSON.parse(raw);
    const entries = (data && Array.isArray(data.entries)) ? data.entries : [];
    const results = [];
    for (const e of entries) {
        if (!e || !e.id) continue;
        const id = String(e.id);
        const dur = parseInt(e.duration || 0, 10) || 0;
        const title = String(e.title || '').replace(new RegExp('^' + username.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\d*\\s*', 'i'), '');
        results.push({
            id,
            title: title.slice(0, 200) || (e.description || '').slice(0, 200) || '',
            desc: '',
            thumbnail: pickThumb(e),
            author: username,
            duration: secondsToIso(dur),
            duration_seconds: dur,
            video_url: 'https://www.tiktok.com/@' + username + '/video/' + id
        });
    }
    return results;
}

// --- API: resolve a single TikTok video -------------------------------------
app.get('/api/tiktok/info', async (req, res) => {
    try {
        const videoUrl = String(req.query.video_url || '').trim();
        if (!videoUrl || !TIKTOK_URL_RE.test(videoUrl)) {
            return res.status(400).json({ success: false, message: 'A valid tiktok.com URL is required' });
        }
        const raw = await runYtdlp(['-J', '--no-warnings', '--no-check-certificates', '--no-playlist', videoUrl]);
        const info = JSON.parse(raw);
        const dur = parseInt(info.duration || 0, 10) || 0;
        res.json({
            success: true,
            video_url: mediaEndpoint(req, videoUrl),
            cover: pickThumb(info),
            id: String(info.id || videoIdFromUrl(videoUrl)),
            title: String(info.title || info.description || '').slice(0, 300),
            desc: String(info.description || '').slice(0, 2000),
            author: String(info.uploader || info.channel || '').replace(/^@/, ''),
            duration: secondsToIso(dur),
            duration_seconds: dur
        });
    } catch (e) {
        res.status(500).json({ success: false, message: e.message });
    }
});

// --- API: latest videos for a TikTok username --------------------------------
app.get('/api/tiktok/user', async (req, res) => {
    try {
        let username = String(req.query.username || '').trim().replace(/^@/, '');
        const limit = Math.min(parseInt(req.query.limit, 10) || 30, 60);
        if (!username) {
            return res.status(400).json({ success: false, message: 'username is required' });
        }
        const results = await mapUserItems(username, limit);
        if (results.length === 0) {
            return res.status(404).json({ success: false, message: 'No videos found for this username (private or blocked?).' });
        }
        res.json({ success: true, username, limit, results });
    } catch (e) {
        res.status(500).json({ success: false, message: e.message });
    }
});

// --- API: keyword search ------------------------------------------------------
// TikTok hides keyword search behind a signed-in session (login wall), so it
// cannot be scraped anonymously. When the query looks like a username we route
// straight to the user profile listing; otherwise we return a helpful error.
app.get('/api/tiktok/search', async (req, res) => {
    try {
        const query = String(req.query.query || '').trim();
        const limit = Math.min(parseInt(req.query.limit, 10) || 30, 60);
        if (!query) {
            return res.status(400).json({ success: false, message: 'query is required' });
        }
        if (/^@?[\w.]{2,24}$/.test(query)) {
            const username = query.replace(/^@/, '');
            const results = await mapUserItems(username, limit);
            return res.json({ success: true, limit, results });
        }
        res.json({
            success: false,
            message: 'TikTok does not allow anonymous keyword search. Use "Import From User" with a TikTok username (e.g. @user) or paste a TikTok video URL from the Import Video page instead.'
        });
    } catch (e) {
        res.status(500).json({ success: false, message: e.message });
    }
});

async function downloadTikTokMedia(videoUrl, base) {
    // TikTok CDN links are transient and occasionally answer Range/resume
    // requests with 416. Retry with fresh attempts (cleaning any partial
    // output between tries) and fall back to a single-format download if the
    // merge keeps failing.
    const mergeFlags = ['-f', 'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/b', '--merge-output-format', 'mp4'];
    const singleFlags = ['-f', 'b', '--merge-output-format', 'mp4'];
    let lastErr = null;
    for (let attempt = 0; attempt < 3; attempt++) {
        if (attempt > 0) {
            await new Promise((r) => setTimeout(r, 1500 * attempt));
        }
        for (const flags of [mergeFlags, ...(attempt >= 2 ? [singleFlags] : [])]) {
            try {
                await runYtdlp([
                    ...flags,
                    '--no-playlist', '--no-part', '--no-mtime',
                    '--no-warnings', '--no-check-certificates',
                    '-o', base, videoUrl
                ]);
                return;
            } catch (e) {
                lastErr = e;
                try {
                    const stem = path.basename(base).replace('.%(ext)s', '');
                    for (const f of fs.readdirSync(MEDIA_DIR)) {
                        if (f === stem || f.startsWith(stem + '.')) {
                            fs.rmSync(path.join(MEDIA_DIR, f), { force: true });
                        }
                    }
                } catch (_) {}
            }
        }
    }
    throw lastErr || new Error('media download failed');
}

// --- API: muxed MP4 proxy (public, rate-limited) ------------------------------
// The site streams the no-watermark video+audio here; the merged file is cached
// briefly so repeated downloads (multiple queue attempts) are cheap.
app.get('/api/tiktok/media', (req, res) => {
    rateLimiter(req, res, async () => {
        try {
            const videoUrl = String(req.query.video_url || '').trim();
            if (!videoUrl || !TIKTOK_URL_RE.test(videoUrl)) {
                return res.status(400).json({ success: false, message: 'video_url is required' });
            }
            if (!fs.existsSync(MEDIA_DIR)) {
                fs.mkdirSync(MEDIA_DIR, { recursive: true });
            }
            const key = crypto.createHash('sha256').update(videoUrl).digest('hex');
            const finalPath = path.join(MEDIA_DIR, key + '.mp4');
            if (!fs.existsSync(finalPath) || Date.now() - fs.statSync(finalPath).mtimeMs > MEDIA_TTL_MS) {
                const base = path.join(MEDIA_DIR, key + '.%(ext)s');
                await downloadTikTokMedia(videoUrl, base);
                const match = fs.readdirSync(MEDIA_DIR).find(f => f.startsWith(key + '.') && f.toLowerCase().endsWith('.mp4'));
                if (!match) {
                    return res.status(502).json({ success: false, message: 'Could not render media' });
                }
                const tmpMerged = path.join(MEDIA_DIR, match);
                if (tmpMerged !== finalPath) {
                    fs.renameSync(tmpMerged, finalPath);
                }
            }
            res.setHeader('Content-Type', 'video/mp4');
            res.setHeader('Content-Disposition', 'inline; filename="video.mp4"');
            res.setHeader('Cache-Control', 'private, max-age=' + Math.floor(MEDIA_TTL_MS / 1000));
            fs.createReadStream(finalPath).pipe(res);
        } catch (e) {
            res.status(500).json({ success: false, message: e.message });
        }
    });
});

app.listen(PORT, () => {
    console.log(`TikTok downloader API running on port ${PORT} (yt-dlp: ${YTDLP})`);
});