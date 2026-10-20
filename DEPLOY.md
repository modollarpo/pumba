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
  (437, 'tiktok_api_url', ''),
  (438, 'mediamtx_auth_secret', ''),
  (439, 'mediamtx_recordings_retention', '7'),
  (440, 'azure_blob_storage', 'off'),
  (441, 'azure_blob_account', ''),
  (442, 'azure_blob_secret', ''),
  (443, 'azure_blob_bucket_name', ''),
  (444, 'azure_blob_endpoint', ''),
  (445, 'azure_blob_cdn', ''),
(446, 'yt_api_token', ''),
  (447, 'tiktok_api_token', ''),
  (448, 'backup_retention_days', '30'),
  (449, 'import_worker_heartbeat', '0'),
  (450, 'import_worker_interval', '60');
```

Row 446/447 are the downloader API tokens — set them to the same value as the `API_TOKEN`
in each Node `.env` (see Step 3). Row 448 sets how many days of `backups/` blobs the
backup worker keeps (see `deploy/backup/README.md`). Rows 449-450 are the import/encoding
worker's heartbeat + resident-loop interval (see `deploy/import/README.md`).

### Ad network (KIKI pre-roll)

`playtube.sql` ships one `video_ads` row that points the watch pre-roll at the KIKI ad
network:

```sql
SELECT `vast_xml_link` FROM `video_ads` WHERE `active` = 1;
-- https://keekii.net/api/ads/vast?property=play&slot=preroll
```

On an already-installed site, apply the equivalent migration — it is idempotent and also
turns the ad system on:

```bash
mysql -u <user> -p <db> < deploy/sql/2026-10-19-keekii-ad-network.sql
```

The ad tag is served by the KIKI app (`/api/ads/vast`), which resolves `property=play` /
`slot=preroll`, picks a creative and returns VAST 4.2 plus signed impression, tracking and
click URLs. Rotate creatives on the KIKI side — add one row per creative here only if you
want PlayTube, not the network, to do the rotation, and leave `vast_xml_link` empty on a
row you want PlayTube to serve from its local `ad_media`/`ad_image` paths instead.

Check the end-to-end path from **Admin → Manage Video Ads → Check** (that button fetches
`vast_xml_link`), or directly:

```bash
curl -s -D- -o /dev/null \
  -H 'Origin: https://play.keekii.net' \
  'https://keekii.net/api/ads/vast?property=play&slot=preroll'
# 200 + Content-Type: application/xml + Access-Control-Allow-Origin: https://play.keekii.net
```

Without the CORS `Access-Control-Allow-Origin` echo the IMA SDK cannot fetch the tag
(`themes/playtag/layout/watch/ima.html:47`) and no ad is shown. The row itself is inert
when `user_ads` is off, or for Pro viewers and anyone who saw an ad within 600s.

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

> If you only need the auth/quota contract (not actual TikTok scraping), the
> auth middleware needs no browser — install without the Chromium download:
> `PUPPETEER_SKIP_DOWNLOAD=1 npm install puppeteer-core@23.5.0`.

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

> `GET /health` is public on both Node APIs (no token, no rate cap) and reports
> service/uptime/`tokenSet` — use it with uptime monitors or a load balancer health check.
> For YouTube, it also reports the `yt-dlp` binary version (or `unreachable`).

### Downloader API auth (HMAC) ###

The downloader APIs accept request signing instead of a static Bearer token:

- The site (`connect_to_url`) signs every request with
  `Authorization: HMAC <sig>` plus `X-Auth-Timestamp` (unix seconds) and
  `X-Auth-Nonce` (random hex). `sig` = HMAC-SHA256(API_TOKEN,
  `METHOD + SP + path?query + LF + timestamp + LF + nonce`).

- The API rejects requests whose timestamp is older/newer than 5 minutes and
  replays of the same nonce, so a captured request cannot be re-sent.

- Plain `Authorization: Bearer` (or `?token=` / `x-api-token`) still works as a
  fallback, so old clients keep functioning during rollout.

- Both sides must be within 5 minutes of each other (NTP). If requests start
  returning 401, check clock skew between the PHP host and the Node host.

> If PlayTube and the Node APIs run on separate machines, use the LAN/server IP instead of
> `127.0.0.1` and make sure CORS/visibility allows PHP's `connect_to_url()` to reach them.

#### Per-token quotas

Each API rate-limits by the authenticated token identity (not per-IP), so one
consumer can't starve another. All knobs are env vars:

| Var | Default | Meaning |
|---|---|---|
| `RATE_LIMIT_WINDOW_MS` | `60000` | quota window |
| `RATE_LIMIT_MAX` | `60` | default requests/window per token |
| `TOKEN_QUOTAS` | (none) | per-token overrides: `tokenA:120,tokenB:10` |
| `API_TOKENS` | (none) | extra accepted tokens (`tokenC,tokenD`) besides `API_TOKEN` |
| `AUTH_FAIL_WINDOW_MS` | `60000` | window for counting failed auth attempts |
| `AUTH_FAIL_LIMIT` | `20` | failed auths per window before 429 (brute-force guard) |

Example `.env`:

```
API_TOKEN=primary-secret
API_TOKENS=importer-bot
TOKEN_QUOTAS=primary-secret:120,importer-bot:30
```

With this, the primary consumer gets 120 requests/min, the importer bot 30/min,
and both are isolated from each other. HMAC signing always uses `API_TOKEN`
(both sides must match); extra `API_TOKENS` authenticate via Bearer fallback.

> **Order matters:** the API authenticates *first* so per-token buckets are keyed
> by the caller's identity, then rate-limits. The brute-force guard
> (`AUTH_FAIL_LIMIT`) throttles bad credentials independently.

#### Runtime auth/quota test

`deploy/tests/auth-quota-e2e.mjs` verifies the auth + quota contract against a
live API (no upstream needed). Start the API with a test token, then:

```bash
PORT=3100 TOKEN=test-secret QUOTED_TOKEN=bot npm start &
PORT=3100 TOKEN=test-secret QUOTED_TOKEN=bot node deploy/tests/auth-quota-e2e.mjs
```

It asserts: valid HMAC passes auth, wrong signature / stale+future timestamps /
empty nonce / missing headers are rejected, a replayed nonce is rejected, and
(when `QUOTED_TOKEN` is set) that token hits 429 after its quota while the
primary token stays unthrottled. Exit 0 = pass.

`deploy/tests/hmac-php-runtime-test.php` does the same check but from the other
side of the contract: it reproduces the exact signing that the PHP site's
`connect_to_url()` `hmac` branch produces (including a URL-encoded query) and
asserts the Node API accepts it, while tampered signature / empty nonce / stale
timestamp variants are rejected. Requires PHP's curl ext:

```bash
PORT=3100 TOKEN=test-secret php deploy/tests/hmac-php-runtime-test.php
```

Both test files work against either API (point `PORT` at the YouTube or TikTok
app). The full contract — auth, quota, replay, and PHP↔Node signing — has been
runtime-verified against both services.

`deploy/tests/api-error-paths-e2e.mjs` asserts the error path for the YouTube
API: every route returns `400 { success: false }` when its required param is
missing (`id`, `channelId`, `query`), and a traversal-shaped id is sanitized
(stripped to a safe token) instead of reaching the filesystem. Run it like the
auth test — it needs only an up API, no upstream key. (It also documents the
required-param validation rules the API enforces: `/api/channel/videos` 400s
without `channelId`, `/api/videos/search` 400s without `query`/`channelId` —
matching the `id` validation already on `/api/video/info` and
`/api/video/download`.)

```bash
PORT=3100 TOKEN=test-secret node deploy/tests/api-error-paths-e2e.mjs
```

---

## 7. Smoke Test (all-in-one)

Run the provided scripts after all services are up:

**Windows (PowerShell):**

```powershell
.\deploy\smoke-test.ps1 -YouTubeApi "http://127.0.0.1:3000" -TikTokApi "http://127.0.0.1:3001" -MediaMTX "http://127.0.0.1:8888" -ApiToken "your-token"
```

**Linux:**

```bash
bash deploy/smoke-test.sh --youtube http://127.0.0.1:3000 --tiktok http://127.0.0.1:3001 --mediamtx http://127.0.0.1:8888 --api-token "your-token"
```

Pass the same `API_TOKEN` you set in the Node `.env` (and in PlayTube admin) — without it
the downloader API checks are skipped (they require auth) but `/health`, MediaMTX and
PlayTube checks still run. With a token, the script verifies HMAC-signed success plus a
negative check that an unauthenticated request is rejected.

The script checks: YouTube search/info/download (HMAC-signed), TikTok info/user/search
(HMAC-signed), the public `/health` probes on both Node APIs, MediaMTX HLS health
(`/stream_health_check/index.m3u8` → returns 404/redirect but proves reachability),
PlayTube `/` returns 200, and (with a token) a negative check that an unauthenticated
request is rejected.

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
- **Background queues:** run the import/encoding worker for imports and conversions (`deploy/import/import_worker.php`, see `deploy/import/README.md`). Schedule it with `--once` every minute via cron/Task Scheduler, or run it as a resident loop. Encoding jobs are claimed atomically, so it is safe to run alongside `cronjob.php`. Its per-pass heartbeat lives in config key `import_worker_heartbeat`.
- **Backups:** run the backup worker daily (`deploy/backup/backup_worker.php`, see `deploy/backup/README.md`) to push an encrypted DB dump + recordings archive to the site's own Azure blob container.
- **Batch imports / long downloads:** PlayTube already runs with unlimited `max_execution_time`/memory (`app_start.php`), so PHP won't time out, but a reverse proxy (nginx `proxy_read_timeout`, Apache `ProxyTimeout`) still can when a large batch downloads several videos serially. Raise those timeouts (e.g. nginx `proxy_read_timeout 600s`) or import in smaller batches.
- **ffmpeg:** ensure `ffmpeg` is on the PHP process PATH for uploads/conversion.
- **Recordings cleanup:** use the built-in retention (see §5) — set *Recordings Retention* in Admin → Live, which prunes old files on admin loads. External cron: `PT_CleanLiveRecordings('./recordings', 7)`.
- **Security:** keep `YOUTUBE_API_KEY` and `.env` out of the web root and git (the Node `.gitignore` files already exclude `.env`).
- **CDN cache invalidation:** with Azure blob storage behind a CDN, set `azure_blob_cdn` (Admin → ffmpeg) and the site sends an HTTP `PURGE` for every media key on both write paths — deletes go through `PT_DeleteFromToS3` (so videos, thumbnails, avatars, covers, ads and photos all purge their CDN copy) and re-uploads through `PT_UploadToS3` purge the same key so replaced media refreshes immediately. `PT_AzureCDNPurge` is best-effort: unreachable or PURGE-less CDNs are ignored so the underlying delete/upload never blocks. If the CDN exposes a vendor purge API instead of `PURGE` (e.g. Azure Front Door management-plane purge), front the CDN origin with a layer that honors `PURGE`, or purge manually.
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
| `system-status.php` reports a problem | See `deploy/OPERATIONS.md` for what each check means and which ones to actually act on. |