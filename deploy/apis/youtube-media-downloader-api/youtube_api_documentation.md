# YouTube Downloader API Documentation

## Overview
A self-hosted Node.js (Express) wrapper around the Google YouTube Data API v3. It provides search and video metadata endpoints (using a Google API key) plus real media download via `yt-dlp`. Serves as a drop-in replacement for third-party download APIs used by PlayTube.

---

## Table of Contents
- [Setup](#setup)
- [Base URL](#base-url)
- [API Endpoints](#api-endpoints)
  - [Get Channel Videos](#get-channel-videos)
  - [Search Videos](#search-videos)
  - [Get Video Info](#get-video-info)
  - [Download Video](#download-video)
- [Error Handling](#error-handling)
- [Rate Limits](#rate-limits)

---

## Setup
1. `npm install`
2. Create a `.env` file next to `app.js`:
   - `YOUTUBE_API_KEY` (required) — Google Data API key from the Google Cloud Console.
   - `YOUTUBE_API_KEY_PORT`/`PORT` (optional) — server port (default `3000`).
   - `YTDLP_BIN` (optional) — path to the `yt-dlp` executable (default `yt-dlp`, i.e. on `PATH`).
   - `DOWNLOAD_DIR` (optional) — directory used for downloaded media (default `./downloads`).
3. `yt-dlp` must be installed on the server (see https://github.com/yt-dlp/yt-dlp).
4. Start with `node app.js`.

## Base URL
The base URL is the server root, e.g.:
```
http://yourserver.com:3000
```
PlayTube's `yt_api_url` setting must point to this base (without `/api`).

---

## API Endpoints

### Get Channel Videos
Retrieve videos from a specific YouTube channel (raw Google Data API passthrough).

- **Endpoint**: `GET /api/channel/videos`
- **Query Parameters**:
  - `channelId` (string, required): The ID of the YouTube channel.
  - `sortBy` (string, optional): Sort by `date` or `relevance` (default: `date`).

#### Example Request:
```http
GET http://localhost:3000/api/channel/videos?channelId=UCeY0bbntWzzVIaj2z3QigXg&sortBy=date
```

---

### Search Videos
Search for videos on YouTube. Returns deduplicated metadata in a normalized shape. Supports paging via `pageToken`.

- **Endpoint**: `GET /api/videos/search`
- **Query Parameters**:
  - `query` (string, required unless `channelId` set): The search term.
  - `channelId` (string, optional): Use exclusive channel search (ignores `query`).
  - `limit` (integer, optional): Number of results (1-50, default `50`).
  - `pageToken` (string, optional): Next-page token from a previous response.

#### Example Request:
```http
GET http://localhost:3000/api/videos/search?query=cute+cats&limit=10
```

#### Example Response:
```json
{
  "success": true,
  "totalResults": 1000000,
  "token": "CAoQAA",
  "results": [
    {
      "id": "Nsi22POgg40",
      "title": "Cute Cat Compilation",
      "description": "A compilation of cute cats...",
      "thumbnail": "https://i.ytimg.com/vi/Nsi22POgg40/maxresdefault.jpg",
      "tags": ["cats", "cute"],
      "duration": "PT5M23S",
      "channelTitle": "Cat Lovers"
    }
  ]
}
```

---

### Get Video Info
Fetch metadata for a single video id.

- **Endpoint**: `GET /api/video/info`
- **Query Parameters**:
  - `id` (string, required): The YouTube video id.

#### Example Request:
```http
GET http://localhost:3000/api/video/info?id=Nsi22POgg40
```

#### Example Response:
```json
{
  "success": true,
  "id": "Nsi22POgg40",
  "title": "Cute Cat Compilation",
  "description": "A compilation of cute cats...",
  "thumbnail": "https://i.ytimg.com/vi/Nsi22POgg40/maxresdefault.jpg",
  "tags": ["cats", "cute"],
  "duration": "PT5M23S"
}
```

---

### Download Video
Downloads the best available quality (video+audio merged to mp4) using `yt-dlp`, caches the file, and returns a publicly fetchable URL.

- **Endpoint**: `GET /api/video/download`
- **Query Parameters**:
  - `id` (string, required): The YouTube video id.

#### Example Request:
```http
GET http://localhost:3000/api/video/download?id=Nsi22POgg40
```

#### Example Response:
```json
{
  "success": true,
  "id": "Nsi22POgg40",
  "filename": "Nsi22POgg40.mp4",
  "downloadUrl": "http://localhost:3000/files/Nsi22POgg40.mp4",
  "size": 15728640,
  "duration": 0
}
```

Cached files are served under `GET /files/{filename}` and are skipped on subsequent download calls.

---

## Error Handling

- **400 Bad Request**: Missing/invalid parameters.
- **404 Not Found**: Video metadata not found by the Google API.
- **500 Internal Server Error**: Search/info API failure or download failure.

### Example Error Response
```json
{
  "success": false,
  "message": "Download failed: yt-dlp: error: Requested format is not available"
}
```

---

## Rate Limits
The Google YouTube Data API has its own quota (default 10,000 units/day). Search costs 100 units per call; video metadata retrieval costs 1 unit per video id.