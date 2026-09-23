# TikTok API Documentation

## Overview
A self-hosted Node.js (Express + Puppeteer) wrapper that extracts TikTok video metadata
and direct download URLs. It is a drop-in replacement for the third-party TikTok download
APIs previously used by PlayTube (no RapidAPI dependency). Uses the page's own
`__UNIVERSAL_DATA_FOR_REHYDRATION__` payload via headless Chrome, so **no API key is required**.

> **Note:** Because it scrapes the public TikTok web page, TikTok may occasionally serve
> a captcha or change markup. The parser recursively locates `itemStruct` objects, which
> tolerates many page-structure changes. Keep `limit` values modest to avoid rate limits.

---

## Table of Contents
- [Setup](#setup)
- [Base URL](#base-url)
- [API Endpoints](#api-endpoints)
  - [Get Video Info](#get-video-info)
  - [Get User Videos](#get-user-videos)
  - [Search Videos](#search-videos)
- [Error Handling](#error-handling)
- [Rate Limits](#rate-limits)

---

## Setup
1. `npm install` (installs Puppeteer; first run downloads Chromium).
2. (Windows) If Chrome is installed in a non-standard place, set `PUPPETEER_EXECUTABLE_PATH` to the chrome.exe path, or run `npx puppeteer browsers install chrome`.
3. Start with `node app.js` (default port `3000`; override with `PORT` env var).

Example:

```bash
PORT=3001 node app.js
```

---

## Base URL
```
http://yourserver:PORT
```

---

## API Endpoints

### Get Video Info
Resolves a TikTok video URL to its metadata and direct download URL.

- **Endpoint**: `GET /api/tiktok/info`
- **Query Parameters**:
  - `video_url` (string, required): A TikTok video URL, e.g. `https://www.tiktok.com/@user/video/1234567890`.

#### Example Request
```http
GET http://yourserver/api/tiktok/info?video_url=https%3A%2F%2Fwww.tiktok.com%2F%40user%2Fvideo%2F1234567890
```

#### Example Response
```json
{
  "success": true,
  "video_url": "https://v16-web.tiktok.com/.../play/tiktok/video_123.mp4?...",
  "cover": "https://p16-sign-va.tiktokcdn.com/.../obj/...?x-expires=...",
  "id": "1234567890",
  "title": "@user",
  "desc": "This is the video description"
}
```

---

### Get User Videos
Lists recent videos from a TikTok user profile.

- **Endpoint**: `GET /api/tiktok/user`
- **Query Parameters**:
  - `username` (string, required): TikTok username with or without the leading `@`.
  - `limit` (integer, optional): Max results to return. Default `30`, maximum `60`.

#### Example Request
```http
GET http://yourserver/api/tiktok/user?username=therock&limit=10
```

#### Example Response
```json
{
  "success": true,
  "username": "therock",
  "limit": 10,
  "results": [
    {
      "id": "1234567890",
      "title": "@therock",
      "desc": "Video description",
      "thumbnail": "https://p16-sign-va.tiktokcdn.com/.../obj/...?.jpg",
      "duration": "PT89S",
      "author": "therock",
      "video_url": "https://v16-web.tiktok.com/.../play/tiktok/video_123.mp4?..."
    }
  ]
}
```

`duration` is ISO-8601 (`PT#H#M#S`); PlayTube's `covtime()` converts it for display. On
profile pages `video_url` may be empty for newer items — use `/api/tiktok/info` for a
specific video to obtain the direct download link.

---

### Search Videos
Searches TikTok for a keyword and returns matching videos.

- **Endpoint**: `GET /api/tiktok/search`
- **Query Parameters**:
  - `query` (string, required): Search term.
  - `limit` (integer, optional): Max results. Default `30`, maximum `60`.

#### Example Request
```http
GET http://yourserver/api/tiktok/search?query=cats&limit=10
```

#### Example Response
```json
{
  "success": true,
  "limit": 10,
  "results": [
    {
      "id": "1234567890",
      "title": "@catlover",
      "desc": "Cute cat compilation",
      "thumbnail": "https://p16-sign-va.tiktokcdn.com/.../obj/...?.jpg",
      "duration": "PT45S",
      "author": "catlover",
      "video_url": ""
    }
  ]
}
```

Search-result pages (`/search?q=`) rarely expose `downloadAddr`, so `video_url` is often
empty; the PlayTube admin import page calls `/api/tiktok/info` per selected video to fetch
the actual download URL before importing.

---

## Error Handling
Common error responses:

- **400 Bad Request**: Missing required parameter (`video_url`, `query`, `username`).
- **404 Not Found**: Video info could not be extracted (page blocked or markup changed).
- **500 Internal Server Error**: Puppeteer launch failure or unexpected error.

### Example Error Response
```json
{
  "success": false,
  "message": "Could not extract video info"
}
```

---

## Rate Limits
There are no API-key limits, but TikTok rate-limits page views from a single IP. Using
headless Chrome for every request is heavy; keep `limit` low and spread imports over time.
On captcha/block pages, `/api/tiktok/info` returns `404` — retrying later usually works.