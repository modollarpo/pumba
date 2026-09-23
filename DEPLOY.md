# Deploy & Run Guide

This guide covers running the full PlayTube + self-hosted stack:

| Component | Role | Self-hosted |
|---|---|---|
| PlayTube (PHP) | Main site | Yes |
| `youtube-media-downloader-api` (Node/Express) | YouTube import: search, info, real mp4 download via `yt-dlp` | Yes |
| `tiktok-downloader-api` (Node/Express + Puppeteer) | TikTok import: video info, user/profile listing, keyword search | Yes |
| MediaMTX | Live streaming (WHIP ingest / HLS playback / recordings) | Yes |

No third-party paid API services are used.

---

## 1. Prerequisites

- **PHP 8.x** with extensions: `mysqli`, `curl`, `gd`, `mbstring`, `fileinfo`, `zip`, `exif`
- **MySQL/MariaDB**
- **Node.js 18+** and `npm`
- **ffmpeg** (on PATH) — used by PlayTube for video conversion and by MediaMTX recordings
- **yt-dlp** (on PATH, or configured via `YTDLP_BIN`) — used by the YouTube API for real downloads
- **MediaMTX binary** (Linux `.tar.gz`, Windows `.zip`) → download from <https://github.com/bluenviron/mediamtx/releases>
- A **Google Cloud API key** with the YouTube Data API v3 enabled (for search + metadata). Not used for downloading videos — that's `yt-dlp`.

---

## 2. Install PlayTube

1. Copy the project into your web root (e.g. `/var/www/pumba` on Linux or `C:\inetpub\wwwroot\pumba` on Windows).
2. Create MySQL database + user.
3. Open `config.php` and fill in DB credentials, `site_url`, paths.
4. Visit `http://your-domain/install` and follow the installer.
5. This imports `playtube.sql` which already contains the new settings rows:
   - `yt_api_url` (id 436)
   - `tiktok_api_url` (id 437)
   - `mediamtx_url` / `mediamtx_whip_url` / `mediamtx_rtmp_url` / `mediamtx_recordings_path` (ids 182-185)
   - `rapid_api` was **removed** — the codebase no longer references RapidAPI anywhere.

For an already-installed site, insert the new rows manually:

```sql
INSERT IGNORE INTO config (id, name, value) VALUES
  (436, 'yt_api_url', ''),
  (437, 'tiktok_api_url', '');
```

---

## 3. Deploy the YouTube API

Both APIs are vendored in the repo under `deploy/apis/`.

```bash
cd deploy/apis/youtube-media-downloader-api
npm install
```

Create `.env`:

```bash
YOUTUBE_API_KEY=AIza...........................   # required
PORT=3000                                       # default
YTDLP_BIN=yt-dlp                                # path to yt-dlp if not on PATH
DOWNLOAD_DIR=./downloads                        # where downloaded mp4s are written
```

Start:

```bash
node app.js
# or: npm start (configure script if needed)
```

Verification:

```bash
curl "http://127.0.0.1:3000/api/videos/search?query=test&limit=5"
curl "http://127.0.0.1:3000/api/video/info?id=VIDEO_ID"
curl "http://127.0.0.1:3000/api/video/download?id=VIDEO_ID"   # triggers first-time yt-dlp download
```

> **Windows note:** If `yt-dlp` isn't on PATH, set `YTDLP_BIN=C:\path\to\yt-dlp.exe`.

---

## 4. Deploy the TikTok API

```bash
cd deploy/apis/tiktok-downloader-api
npm install      # installs Puppeteer (downloads Chromium on first run)
```

No `.env` is required. Default `PORT=3000`; override with an environment variable if it collides.

Start:

```bash
node app.js
```

Verification:

```bash
curl "http://127.0.0.1:3000/api/tiktok/info?video_url=https%3A%2F%2Fwww.tiktok.com%2F%40user%2Fvideo%2F123"
curl "http://127.0.0.1:3000/api/tiktok/user?username=therock&limit=10"
curl "http://127.0.0.1:3000/api/tiktok/search?query=cats&limit=10"
```

> **Windows note:** Puppeteer requires Chrome/Chromium. If Windows can't find it, set
> `PUPPETEER_EXECUTABLE_PATH=C:\Program Files\Google\Chrome\Application\chrome.exe` (install Chrome or run `npx puppeteer browsers install chrome`).

---

## 5. Deploy MediaMTX

Copy `mediamtx.yml` from the repo root next to the MediaMTX binary, edit if needed:

```bash
./mediamtx      # Linux
mediamtx.exe    # Windows
```

Default ports (from the shipped `mediamtx.yml`):

| Purpose | Address | Setting in PlayTube |
|---|---|---|
| HLS for viewers | `:8888` | `mediamtx_url` = `http://server-ip:8888` |
| WHIP (WebRTC ingest) | `:8889` | `mediamtx_whip_url` = `http://server-ip:8889` |
| RTMP ingest (OBS) | `:1935` | `mediamtx_rtmp_url` = `rtmp://server-ip:1935` |
| Local recordings | `./recordings` | `mediamtx_recordings_path` = `./recordings` (relative to the MediaMTX working directory, must match the PHP side) |

Recordings are written by MediaMTX into `./recordings/stream_*/`, and PlayTube's admin
cron (`admin-panel/autoload.php`) moves them into `upload/videos/...` when a stream ends
(requires the "Live Streaming Strorage" option enabled).

### Optional: signed, revocable auth

By default streams are protected only by the unguessable `stream_*` name. To
require official tokens for every publish/read/record request:

1. Set a secret in **Admin → Live → MediaMTX Configuration → Auth Secret**
   (leave empty to keep the open behaviour).
2. Add the external auth block to `mediamtx.yml` next to the binary:

   ```yaml
   authMethod: http
   authHTTPAddress: http://server-ip/mediamtx_auth.php
   authHTTPExclude:
   - api
   - metrics
   - pprof
   ```

   `mediamtx_auth.php` ships at the webroot; only `stream_*` paths are checked,
   and MediaMTX is denied with a non-2xx status otherwise. When the secret is
   empty or the DB is unreachable the endpoint fails **open** so playback is
   never killed by a DB outage.
3. Restart MediaMTX. PlayTube already attaches a signed `Authorization:
   Bearer <token>` header to every WHIP and HLS request (via videojs VHS
   `beforeRequest`), so broadcasters and viewers keep working unmodified.

Ending a stream sets `live_ended = 1`, after which `read` tokens are rejected
server-side within ~30s, instantly killing all HLS sessions.

> RTMP caveat: the browser WHIP/HLS flows attach the signed token
> automatically. External RTMP encoders (OBS) cannot easily, so under hardened
> auth prefer the app's built-in WHIP broadcaster or keep auth off when RTMP
> relay is required.

### Recording retention

Set **Admin → Live → MediaMTX Configuration → Recordings Retention (days)** to
have the admin cron (`admin-panel/autoload.php`, runs on every admin page load)
prune `.ts`/`.mp4`/`.m4s` files older than N days from the Recordings Path (`0`
disables). External cron users can call the helper directly:

```php
PT_CleanLiveRecordings('./recordings', 7);
```

> Firewall: open 8888 (HLS, public), 8889 (WHIP), 1935 (RTMP, optional). Do **not** map the
> Web panel (`api` is disabled by default in the shipped config).

---

## 6. Wire it up in the PlayTube Admin

| Setting | Admin page | Value |
|---|---|---|
| `yt_api_url` | Settings → ffmpeg → "YouTube Downloader API URL" | `http://127.0.0.1:3000` |
| `tiktok_api_url` | Settings → ffmpeg → "TikTok Downloader API URL" | `http://127.0.0.1:3001` |
| `mediamtx_url` | Settings → Live | `http://server-ip:8888` |
| `mediamtx_whip_url` | Settings → Live | `http://server-ip:8889` |
| `mediamtx_rtmp_url` | Settings → Live | `rtmp://server-ip:1935` |
| `mediamtx_recordings_path` | Settings → Live | `./recordings` |

**Why two different Node ports:** both APIs default to port 3000. Run the YouTube API on
3000 and the TikTok API on 3001, or set distinct `PORT` env values on each. Then point
`yt_api_url` / `tiktok_api_url` at the correct ports.

> If PlayTube and the Node APIs run on separate machines, use the LAN/server IP instead of
> `127.0.0.1` and make sure CORS/visibility allows PHP's `connect_to_url()` to reach them.

---

## 7. Smoke Test (all-in-one)

Run the provided scripts after all services are up:

**Windows (PowerShell):**

```powershell
.\deploy\smoke-test.ps1 -YouTubeApi "http://127.0.0.1:3000" -TikTokApi "http://127.0.0.1:3000" -MediaMTX "http://127.0.0.1:8888"
```

**Linux:**

```bash
bash deploy/smoke-test.sh --youtube http://127.0.0.1:3000 --tiktok http://127.0.0.1:3000 --mediamtx http://127.0.0.1:8888
```

The script checks: YouTube search/info/download headers, TikTok info/user/search, MediaMTX
HLS health (`/stream_health_check/index.m3u8` → returns 404/redirect but proves reachability),
and PlayTube `/` returns 200.

---

## 8. Quick start (all services at once)

The start scripts default the API directories to the vendored
`deploy/apis/youtube-media-downloader-api` and `deploy/apis/tiktok-downloader-api`
(override with `-YouTubeDir` / `--youtube-dir`), so they run from the repo as-is.

**Windows (PowerShell):**

```powershell
.\deploy\start.ps1 -MediaMTX "C:\path\to\mediamtx.exe" -MediaMTXConfig "C:\path\to\mediamtx.yml"
```

**Linux:**

```bash
bash deploy/start.sh --mediamtx /usr/local/bin/mediamtx --mediamtx-config /etc/mediamtx.yml
```

Logs go to `deploy/logs/`. Ctrl+C stops the foreground processes.

---

## 9. Production notes

- **Process managers:** use `systemd` units (Linux) or NSSM/Task Scheduler (Windows) instead of the foreground scripts.
- **ffmpeg:** ensure `ffmpeg` is on the PHP process PATH for uploads/conversion.
- **Recordings cleanup:** use the built-in retention (see §5) — set *Recordings Retention* in Admin → Live, which prunes old files on admin loads. External cron: `PT_CleanLiveRecordings('./recordings', 7)`.
- **Security:** keep `YOUTUBE_API_KEY` and `.env` out of the web root and git (the Node `.gitignore` files already exclude `.env`).
- **TikTok rate limits:** TikTok aggressively rate-limits/awards captcha on web scraping. Set conservative `limit` values in the admin import page and expect occasional failures — the import handler falls back cleanly.

---

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| YouTube search returns `YouTube API URL is not configured` | Set `yt_api_url` in Admin → Settings → ffmpeg. |
| YouTube download returns empty | `yt-dlp` missing / not on PATH — install it or set `YTDLP_BIN`. |
| TikTok returns `Could not extract video info` | TikTok changed page markup or is captcha-gated; retry; reduce `limit`. |
| Live streams don't play | Check `mediamtx_url` reachability from the viewer's browser; open port 8888. |
| Live broadcast fails | Check `mediamtx_whip_url`; MediaMTX must allow WHIP (`whip: yes` in `paths.stream_`). |
| `rapid_api` referenced | The reference was removed in this codebase — update any cached copy. |
| Config rows missing on an existing install | Run the `INSERT IGNORE` statements from Step 2. |