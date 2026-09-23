const express = require('express');
const axios = require('axios');
const { execFile } = require('child_process');
const fs = require('fs');
const path = require('path');
require('dotenv').config();

const app = express();

const API_KEY = process.env.YOUTUBE_API_KEY;
const YTDLP_BIN = process.env.YTDLP_BIN || 'yt-dlp';
const DOWNLOAD_DIR = process.env.DOWNLOAD_DIR || path.join(__dirname, 'downloads');
const GOOGLE = 'https://www.googleapis.com/youtube/v3';

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
    const apiKey = process.env.YOUTUBE_API_KEY;

    try {
        const response = await axios.get(`${GOOGLE}/search`, {
            params: {
                part: 'snippet',
                channelId: channelId,
                order: sortBy || 'date',
                key: apiKey
            }
        });

        res.json({ success: true, data: response.data });
    } catch (error) {
        res.json({ success: false, message: 'Error fetching videos', error: error.message });
    }
});

app.get('/api/videos/search', async (req, res) => {
    try {
        const { query, channelId, limit, pageToken } = req.query;
        const isChannel = !!(channelId && !query);
        const out = await doSearch({
            query: isChannel ? undefined : (query || ''),
            channelId: isChannel ? channelId : undefined,
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