# Azure free-tier deployment (Storegrill_Azure)

Edge-to-edge blueprint for running the PlayTube stack on Azure's **free**
offerings. Everything is `$0` **for the first 12 months**; the two recurring
free items (Blob 5 GB, App Service F1 60 CPU-min/day) stay free forever.

| Component      | Azure service                       | SKU          | Cost       |
|----------------|-------------------------------------|--------------|------------|
| PlayTube site  | App Service (Linux) — container     | F1           | Always $0  |
| YouTube API    | App Service (Linux) — container     | F1 (shared)  | Always $0  |
| TikTok API     | App Service (Linux) — container     | F1 (shared)  | Always $0  |
| Database       | Azure Database for MySQL Flexible   | B1ms 750 h   | Free 12 mo |
| Media storage  | Blob storage (LRS)                  | 5 GB         | Always $0  |
| Import scheduler | Azure Functions (Node timer)      | Y1 Dynamic   | Always $0  |
| MediaMTX + cron| VM (Ubuntu 22.04) + public IP       | B1s 750 h    | Opt-in*     |

\* **VM is off by default.** During validation this subscription had no free-tier
VM capacity (`Standard_B1s` / `Standard_B2pts_v2` were capacity-restricted in
every region tried; `westeurope` is region-blocked entirely). The site stack
below is fully validated and deploys without it. To enable later, set
`deployVm=true` once compute capacity opens up, or point the workers at an
existing host. Public IP (Basic/dynamic) is a small separate charge (~$1–4/mo)
after year one.

## What gets created

One `Microsoft.Web/serverfarms` (F1, Linux) hosting three container apps
(`playtube-*`, `yt-api-*`, `tt-api-*`) sharing the 60 CPU-min/day quota, plus:

- MySQL Flexible Server `mysql-storegrill-free` (B1ms, port 3306, firewall
  opens Azure services + any `mysqlAllowedIps` you list)
- Storage account `ststoregrillfree` with a blob container `media`
  (**public read**, `Blob` ACL — media URLs point straight at the
  blob endpoint, so the container must be readable; keep `key1` secret)
- Consumption Function `func-cron-storegrill-free` (Y1, Node timer) that
  calls `<site>/cronjob.php` every hour — this is what actually processes
  imports and encodes queued videos on F1 (F1 sleeps, so nothing runs
  reliably on its own)
- Optional B1s VM running MediaMTX via Docker (RTSP 8554 / RTMP 1935 /
  HLS 8888 / WebRTC 8889 / API 8080), provisioned by cloud-init, plus PHP CLI
  for the cron worker entry points.

## Build the images, push to Docker Hub (public, no ACR cost)

```powershell
# repo root
docker build -t playtube/playtube:latest .
docker build -t playtube/yt-api:latest .\deploy\apis\youtube-media-downloader-api
docker build -t playtube/tt-api:latest .\deploy\apis\tiktok-downloader-api

docker login
docker push playtube/playtube:latest
docker push playtube/yt-api:latest
docker push playtube/tt-api:latest
```

Local test before pushing:
`.\deploy\start.ps1 -PlayTube -YouTubeDir ...\deploy\apis\youtube-media-downloader-api -TikTokDir ...\deploy\apis\tiktok-downloader-api`

## Provision

```powershell
az login
az group create --name rg-playtube-free --location eastus --subscription "Storegrill_Azure"

az deployment group create --resource-group rg-playtube-free `
  --template-file infra/main.bicep `
  --parameters main.parameters.json `
  --parameters sqlAdminPassword=$env:SQL_ADMIN_PASS `
  --parameters ytApiKey=$env:YOUTUBE_API_KEY ytApiToken=$env:YT_API_TOKEN ttApiToken=$env:TT_API_TOKEN `
  --parameters siteUrl="https://playtube-storegrill-free.azurewebsites.net" `
  --subscription "Storegrill_Azure"
```

Dry-run first: `az deployment group what-if ...` (same args).
To include the VM, add `--parameters deployVm=true vmSize=Standard_B1s vmAdminUser=playtube vmAdminSshPublicKey="$env:SSH_PUB"`.
The scheduler is on by default (`deployCron=true`); turn it off with `--parameters deployCron=false`.

## Function code deploy (scheduler)

The Function App above is empty until you push its code:

```powershell
cd deploy/azure
Compress-Archive -Path functions\host.json,functions\cron\function.json,functions\cron\index.js -DestinationPath cron.zip -Force
az functionapp deployment source config-zip `
  --name func-cron-storegrill-free --resource-group rg-playtube-free --src cron.zip
```
(Adjust `--name` to `cronFuncName` printed by the deployment.) The Function
runs `node:20` on the v4 extension bundle and hits `<site>/cronjob.php` every
hour; keep the default 60-min interval so one hit encodes ≤`queue_count` videos.

Outputs: `playtubeUrl`, `ytApiUrl`, `ttApiUrl`, `sqlHost`, `mediaEndpoint`.

## Post-deploy

1. **Database bootstrap** (MySQL isn't created by ARM):
   ```bash
   # From the repo root, fill the env vars below (Azure Cloud Shell or bash):
   export MYSQL_HOST=$(...) MYSQL_ADMIN=playtube_admin MYSQL_ADMIN_PASSWORD=...
   export SITE_URL="https://$(az functionapp show -n playtube-storegrill-free -g rg-playtube-free --query defaultHostName -o tsv)"
   export YT_API_URL="https://yt-api-storegrill-free.azurewebsites.net"
   export TT_API_URL="https://tt-api-storegrill-free.azurewebsites.net"
   export YT_API_TOKEN=... TT_API_TOKEN=...     # must equal each API's API_TOKEN
   export AZURE_STORAGE_ACCOUNT=ststoregrillfree
   export AZURE_STORAGE_ACCOUNT_KEY="$(az storage account keys list -n $AZURE_STORAGE_ACCOUNT -g rg-playtube-free --query keys[0].value -o tsv)"
   ./deploy/azure/scripts/bootstrap-db.sh playtube.sql
   ```
   The script creates DB `playtube`, imports `playtube.sql`, and writes the
   Azure values into the `config` table: `site_url`, `yt_api_url`,
   `yt_api_token`, `tiktok_api_url`, `tiktok_api_token`,
   `azure_blob_storage=on`, `azure_blob_account`, `azure_blob_secret`
   (base64 account key), `azure_blob_bucket_name=media`,
   `azure_blob_endpoint`, `queue_count=2`, `import_worker_interval=60`.

2. **Container media is public-read**: the blob container is `Blob` (so
   site URLs resolve), keep `key1`/`azure_blob_secret` secret; if you prefer
   private blobs you must front them with a CDN and set `azure_blob_cdn`.

3. **App settings** are already wired: `SQL_DB_*`, `SITE_URL`, `PURCHASE_CODE`,
   `YOUTUBE_API_KEY`, `API_TOKEN` (both APIs), each app's `PORT`/`WEBSITES_PORT`.
   Secrets are visible in plaintext app settings — rotate via Key Vault later.
   On the PHP container `config.php` reads these env vars (see `config.php`).

4. **Workers**: the Function scheduler runs `<site>/cronjob.php` hourly; with
   `queue_count=2` each hit encodes ≤2 videos — keep the interval ≥60 min
   and the total under F1's 60 CPU-min/day. The CLI `import_worker.php` and
   `backup_worker.php` run on the VM only if you enable `deployVm`.

5. **SMTP**: PlayTube still needs an outgoing mail server (PHPMailer) —
   set the site's SMTP settings in the admin; it does not work out of the box
   on free-tier Azure.

## Free-tier caveats

- **F1**: 60 CPU-minutes/day per plan, 1 GB RAM, 1 GB storage, **no SLA**, apps
  sleep after ~20 min idle (cold start), no custom domain. Fine for demo/study,
  not for production traffic.
- **MySQL B1ms**: Free only for 12 months, then ~$15–20/mo. Budget for the
  upgrade or move to Postgres/self-hosted on the VM at that point.
- **Puppeteer (TikTok API)** in F1's 1 GB RAM is tight — Chromium plus Express
  usually fits, but a busy page can OOM. If it proves unstable, move the TikTok
  API container to the B1s VM.
- Remove the VM's public IP or VM after year one if you don't keep using it.