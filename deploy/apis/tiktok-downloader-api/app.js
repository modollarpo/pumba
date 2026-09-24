const express = require('express');
const puppeteer = require('puppeteer');
const crypto = require('crypto');
const app = express();
const PORT = process.env.PORT || 3000;

// --- API hardening: token auth + rate limiting ---
// Token is read from env (API_TOKEN). The PlayTube site sends it via the
// Authorization: Bearer header (connect_to_url 'bearer' option) — never in the
// URL or access logs. Compare with timingSafeEqual to avoid timing leaks.
const API_TOKEN = process.env.API_TOKEN || '';
if (!API_TOKEN) {
    console.warn('[SECURITY] API_TOKEN is not set. Requests will be rejected until you set it in .env and in the PlayTube admin (see playtube.sql config keys tiktok_api_token).');
}
const RATE_WINDOW_MS = parseInt(process.env.RATE_LIMIT_WINDOW_MS, 10) || 60000;
const RATE_LIMIT_MAX = parseInt(process.env.RATE_LIMIT_MAX, 10) || 60;
const hits = new Map();

function safeTokenEquals(a, b) {
    const ba = Buffer.from(String(a || ''));
    const bb = Buffer.from(String(b || ''));
    if (ba.length !== bb.length) return false;
    return crypto.timingSafeEqual(ba, bb);
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
            return res.status(401).json({ success: false, message: 'Unauthorized' });
        }
        return next();
    }
    // backward-compatible fallback: plain Bearer / query / header token
    let token = header.replace(/^Bearer\s+/i, '').trim();
    if (!token && req.query.token) token = String(req.query.token).trim();
    if (!token && req.get('x-api-token')) token = String(req.get('x-api-token')).trim();
    if (!API_TOKEN || !safeTokenEquals(token, API_TOKEN)) {
        return res.status(401).json({ success: false, message: 'Unauthorized' });
    }
    next();
}

function rateLimiter(req, res, next) {
    const key = clientKey(req);
    const now = Date.now();
    let rec = hits.get(key);
    if (!rec || now - rec.windowStart > RATE_WINDOW_MS) {
        rec = { windowStart: now, count: 0 };
        hits.set(key, rec);
    }
    rec.count += 1;
    if (rec.count > RATE_LIMIT_MAX) {
        return res.status(429).json({ success: false, message: 'Too many requests' });
    }
    next();
}

app.use((req, res, next) => {
    if (String(req.path).startsWith('/health')) return next();
    rateLimiter(req, res, () => authRequired(req, res, next));
});

// --- Ops: public health probe (no auth, no rate cap) -----------------------
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        service: 'tiktok-downloader',
        uptimeSeconds: Math.floor(process.uptime()),
        tokenSet: !!API_TOKEN
    });
});


function deepFindItemStructs(obj, results) {
    if (obj === null || typeof obj !== 'object') {
        return;
    }
    if (Array.isArray(obj)) {
        for (const item of obj) {
            deepFindItemStructs(item, results);
        }
        return;
    }
    if (obj.itemStruct && obj.itemStruct.video && obj.itemStruct.video.id) {
        results.push(obj.itemStruct);
    }
    for (const key of Object.keys(obj)) {
        deepFindItemStructs(obj[key], results);
    }
}

function secondsToIso(seconds) {
    const s = Math.max(0, parseInt(seconds, 10) || 0);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    return 'PT' + (h > 0 ? h + 'H' : '') + (m > 0 || h > 0 ? m + 'M' : '') + sec + 'S';
}

function mapItemStruct(itemStruct) {
    const v = itemStruct.video || {};
    const author = itemStruct.author || {};
    return {
        id: v.id || itemStruct.id || '',
        title: author.nickname || itemStruct.desc || '',
        desc: itemStruct.desc || '',
        thumbnail: (v.cover && typeof v.cover === 'object' ? v.cover.urlList[0] : v.cover) || '',
        duration: secondsToIso(v.duration),
        author: author.uniqueId || author.nickname || '',
        video_url: (v.downloadAddr && v.downloadAddr.urlList[0]) || v.downloadAddr || ''
    };
}

async function extractFromUniversalData(videoUrl) {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: true,
            args: ['--no-sandbox', '--disable-setuid-sandbox']
        });
        const page = await browser.newPage();
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        await page.goto(videoUrl, { waitUntil: 'networkidle2', timeout: 60000 });

        const universal = await page.evaluate(() => {
            const script = document.querySelector('script[data-e2e="parser-data"]');
            if (script && script.innerText) {
                try {
                    const data = JSON.parse(script.innerText);
                    return data.__UNIVERSAL_DATA_FOR_REHYDRATION__ || null;
                } catch (e) {
                    return null;
                }
            }
            return null;
        });

        await browser.close();

        if (!universal) {
            return [];
        }

        const structs = [];
        deepFindItemStructs(universal, structs);
        const seen = new Set();
        const items = [];
        for (const struct of structs) {
            const id = (struct.video && struct.video.id) || struct.id;
            if (!id || seen.has(id)) {
                continue;
            }
            seen.add(id);
            items.push(mapItemStruct(struct));
        }
        return items;
    } catch (err) {
        if (browser) await browser.close();
        throw err;
    }
}

app.get('/api/tiktok/info', async (req, res) => {
    try {
        const videoUrl = req.query.video_url;
        if (!videoUrl) {
            return res.status(400).json({ success: false, message: 'video_url is required' });
        }

        let browser;
        try {
            browser = await puppeteer.launch({
                headless: true,
                args: ['--no-sandbox', '--disable-setuid-sandbox']
            });
            const page = await browser.newPage();
            await page.goto(videoUrl, { waitUntil: 'networkidle2', timeout: 60000 });

            // Try to extract __UNIVERSAL_DATA_FOR_REHYDRATION__ from parser-data script
            const videoInfo = await page.evaluate(() => {
                const script = document.querySelector('script[data-e2e="parser-data"]');
                if (script && script.innerText) {
                    try {
                        const data = JSON.parse(script.innerText);
                        return data.__UNIVERSAL_DATA_FOR_REHYDRATION__ || null;
                    } catch (e) {
                        return null;
                    }
                }
                return null;
            });

            await browser.close();

            if (videoInfo && videoInfo.videoData && videoInfo.videoData.itemStruct && videoInfo.videoData.itemStruct.video) {
                const mapped = mapItemStruct(videoInfo.videoData.itemStruct);
                res.json({
                    success: true,
                    video_url: mapped.video_url,
                    cover: mapped.thumbnail,
                    id: mapped.id,
                    title: mapped.title,
                    desc: mapped.desc
                });
            } else if (videoInfo) {
                const structs = [];
                deepFindItemStructs(videoInfo, structs);
                if (structs.length > 0) {
                    const mapped = mapItemStruct(structs[0]);
                    res.json({
                        success: true,
                        video_url: mapped.video_url,
                        cover: mapped.thumbnail,
                        id: mapped.id,
                        title: mapped.title,
                        desc: mapped.desc
                    });
                } else {
                    res.status(404).json({ success: false, message: 'Could not extract video info' });
                }
            } else {
                res.status(404).json({ success: false, message: 'Could not extract video info' });
            }
        } catch (err) {
            if (browser) await browser.close();
            res.status(500).json({ success: false, message: 'Error: ' + err.message });
        }
    } catch (error) {
        res.status(500).json({ success: false, message: error.message });
    }
});

app.get('/api/tiktok/search', async (req, res) => {
    try {
        const query = req.query.query;
        const limit = Math.min(parseInt(req.query.limit, 10) || 30, 60);
        if (!query) {
            return res.status(400).json({ success: false, message: 'query is required' });
        }
        const items = await extractFromUniversalData('https://www.tiktok.com/search?q=' + encodeURIComponent(query));
        res.json({ success: true, limit: limit, results: items.slice(0, limit) });
    } catch (error) {
        res.status(500).json({ success: false, message: error.message });
    }
});

app.get('/api/tiktok/user', async (req, res) => {
    try {
        let username = (req.query.username || '').trim().replace(/^@/, '');
        const limit = Math.min(parseInt(req.query.limit, 10) || 30, 60);
        if (!username) {
            return res.status(400).json({ success: false, message: 'username is required' });
        }
        const items = await extractFromUniversalData('https://www.tiktok.com/@' + encodeURIComponent(username));
        res.json({ success: true, username: username, limit: limit, results: items.slice(0, limit) });
    } catch (error) {
        res.status(500).json({ success: false, message: error.message });
    }
});

app.listen(PORT, () => {
    console.log(`TikTok downloader API running on port ${PORT}`);
});