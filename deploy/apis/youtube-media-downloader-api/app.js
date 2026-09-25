const express = require('express');
const axios = require('axios');
const { execFile, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
require('dotenv').config();

const app = express();

// The Google API key is restricted to specific HTTP referrers (see GCP
// Console → API keys). nodemailer/axios itself sends no Referer on
// server-to-server calls, so forward the configured site URL so those calls
// satisfy the key's referrer restriction. YT_REFERRER defaults to the
// deployed App Service URL.
axios.defaults.headers.common['Referer'] = process.env.YT_REFERRER || 'https://playtube-storegrill-free.azurewebsites.net';

const crypto = require('crypto');

// --- API hardening: token auth + rate limiting ---
// Token is read from env (API_TOKEN). The PlayTube site sends it via the
// Authorization: Bearer header (connect_to_url 'bearer' option) — never in the
// URL or access logs. Compare with timingSafeEqual to avoid timing leaks.
const API_TOKEN = process.env.API_TOKEN || '';
if (!API_TOKEN) {
    console.warn('[SECURITY] API_TOKEN is not set. Requests will be rejected until you set it in .env and in the PlayTube admin (see playtube.sql config keys yt_api_token).');
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

// Per-token rate limit. The bucket is keyed by the (already authenticated)
// token identity, so one consumer maxing out its quota never throttles another.
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
        return res.status(429).json({ success: false, message: 'Too many requests. Please try again later.' });
    }
    next();
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

// Static /files (downloaded media served to the site for playback) and the
// /health probe stay public; everything else is token gated + rate-limited
// per identity. Auth runs first so req.authToken is set before rateLimiter
// buckets by it (per-token quota); failAuth independently throttles bad creds.
app.use((req, res, next) => {
    const p = String(req.path);
    if (p.startsWith('/files') || p.startsWith('/health')) return next();
    authRequired(req, res, () => rateLimiter(req, res, next));
});

const API_KEY = process.env.YOUTUBE_API_KEY;
const YTDLP_BIN = process.env.YTDLP_BIN || 'yt-dlp';
// Extra yt-dlp args (space-separated) from env, e.g.
//   YTDLP_ARGS="--extractor-args youtube:player_client=tv --cookies /home/cookies.txt"
// Needed because YouTube blocks datacenter IPs / varies by player client.
const YTDLP_ARGS = String(process.env.YTDLP_ARGS || '').trim() ? String(process.env.YTDLP_ARGS).trim().split(/\s+/) : [];
const DOWNLOAD_DIR = process.env.DOWNLOAD_DIR || path.join(__dirname, 'downloads');
const GOOGLE = 'https://www.googleapis.com/youtube/v3';

// --- Ops: public health probe (no auth, no rate cap) -----------------------
// Reports process uptime plus whether the yt-dlp binary is reachable, so
// load balancers / uptime monitors have a real signal instead of 401s.
app.get('/health', (req, res) => {
    let binary = 'unknown';
    let binaryError = '';
    let binaryPath = 'unset';
    try {
        binaryPath = YTDLP_BIN;
        const r = execFileSync(YTDLP_BIN, ['--version'], { timeout: 5000, encoding: 'utf8' });
        binary = String(r).trim().split(/\r?\n/)[0] || 'unknown';
    } catch (e) {
        binary = 'unreachable';
        binaryError = String((e && e.message) || e);
    }
    res.json({
        status: 'ok',
        service: 'youtube-media-downloader',
        uptimeSeconds: Math.floor(process.uptime()),
        binary,
        binaryPath,
        binaryError,
        tokenSet: !!API_TOKEN
    });
});

fs.mkdirSync(DOWNLOAD_DIR, { recursive: true });
app.use('/files', express.static(DOWNLOAD_DIR));

function pickThumb(snippet) {
    const keys = ['maxres', 'standard', 'high', 'medium', 'default'];
    for (const k of keys) {
        if (snippet.thumbnails && snippet.thumbnails[k] && snippet.thumbnails[k].url) {
            return snippet.thumbnails[k].url;
        }
    }
    return '';
}

async function videosDetails(ids) {
    if (!ids.length) return {};
    const url = `${GOOGLE}/videos?part=contentDetails,snippet&id=${encodeURIComponent(ids.join(','))}&key=${API_KEY}`;
    const { data } = await axios.get(url);
    const map = {};
    for (const item of data.items || []) map[item.id] = item;
    return map;
}

async function doSearch({ query, channelId, limit, pageToken }) {
    const { data } = await axios.get(`${GOOGLE}/search`, {
        params: {
            part: 'snippet',
            type: 'video',
            q: query,
            channelId: channelId || undefined,
            maxResults: Math.min(parseInt(limit, 10) || 50, 50),
            pageToken: pageToken || undefined,
            key: API_KEY
        }
    });
    const items = data.items || [];
    const ids = items.map(i => i.id && i.id.videoId).filter(Boolean);
    const details = await videosDetails(ids);
    const results = items.map(i => {
        const id = i.id.videoId;
        const det = details[id];
        const snippet = (det && det.snippet) ? det.snippet : i.snippet;
        const content = (det && det.contentDetails) ? det.contentDetails : {};
        return {
            id,
            title: snippet.title,
            description: snippet.description,
            thumbnail: pickThumb(snippet),
            tags: (snippet.tags || []).slice(0, 12),
            duration: content.duration || '',
            channelTitle: snippet.channelTitle
        };
    });
    return {
        totalResults: (data.pageInfo && data.pageInfo.totalResults) || results.length,
        token: data.nextPageToken || '',
        results
    };
}

// API route to fetch videos from a YouTube channel
app.get('/api/channel/videos', async (req, res) => {
    const { channelId, sortBy } = req.query;
    const chId = String(channelId || '').trim();
    if (!chId) {
        return res.status(400).json({ success: false, message: 'channelId is required' });
    }
    const apiKey = process.env.YOUTUBE_API_KEY;

    try {
        const response = await axios.get(`${GOOGLE}/search`, {
            params: {
                part: 'snippet',
                channelId: chId,
                order: sortBy || 'date',
                key: apiKey
            }
        });

        res.json({ success: true, data: response.data });
    } catch (error) {
        res.status(500).json({ success: false, message: 'Error fetching videos', error: error.message });
    }
});

app.get('/api/videos/search', async (req, res) => {
    try {
        const { query, channelId, limit, pageToken } = req.query;
        const q = String(query || '').trim();
        const cid = String(channelId || '').trim();
        if (!q && !cid) {
            return res.status(400).json({ success: false, message: 'query or channelId is required' });
        }
        const isChannel = !!(cid && !q);
        const out = await doSearch({
            query: isChannel ? undefined : q,
            channelId: isChannel ? cid : undefined,
            limit,
            pageToken
        });
        res.json({ success: true, ...out });
    } catch (error) {
        res.status(500).json({ success: false, message: error.message });
    }
});

app.get('/api/video/info', async (req, res) => {
    try {
        const id = String(req.query.id || '').trim();
        if (!id) {
            return res.status(400).json({ success: false, message: 'id is required' });
        }
        const details = await videosDetails([id]);
        const det = details[id];
        if (!det) {
            return res.status(404).json({ success: false, message: 'Video not found' });
        }
        const snippet = det.snippet || {};
        const content = det.contentDetails || {};
        res.json({
            success: true,
            id,
            title: snippet.title || '',
            description: snippet.description || '',
            thumbnail: pickThumb(snippet),
            tags: snippet.tags || [],
            duration: content.duration || ''
        });
    } catch (error) {
        res.status(500).json({ success: false, message: error.message });
    }
});

app.get('/api/video/download', async (req, res) => {
    try {
        const rawId = String(req.query.id || '').trim();
        if (!rawId) {
            return res.status(400).json({ success: false, message: 'id is required' });
        }
        const id = rawId.replace(/[^a-zA-Z0-9_-]/g, '');
        if (!id) {
            return res.status(400).json({ success: false, message: 'invalid id' });
        }
        const filename = `${id}.mp4`;
        const outPath = path.join(DOWNLOAD_DIR, filename);

        if (!fs.existsSync(outPath)) {
            await new Promise((resolve, reject) => {
                execFile(YTDLP_BIN, [
                    '-f', 'bv*+ba/b',
                    '--merge-output-format', 'mp4',
                    '--no-playlist',
                    ...YTDLP_ARGS,
                    '-o', outPath,
                    `https://www.youtube.com/watch?v=${id}`
                ], { maxBuffer: 128 * 1024 * 1024, timeout: 1800000 }, (err) => {
                    if (err) reject(new Error(err.message));
                    else resolve();
                });
            });
        }

        const stat = fs.statSync(outPath);
        const base = `${req.protocol}://${req.get('host')}`;
        res.json({
            success: true,
            id,
            filename,
            downloadUrl: `${base}/files/${encodeURIComponent(filename)}`,
            size: stat.size,
            duration: 0
        });
    } catch (error) {
        res.status(500).json({ success: false, message: 'Download failed: ' + error.message });
    }
});

// Start the server
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`Server running on port ${PORT}`);
});