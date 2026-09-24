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
  (kebab-case name; set it in the PlayTube admin **azure_blob_storage ✓**)
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

Outputs: `playtubeUrl`, `ytApiUrl`, `ttApiUrl`, `sqlHost`, `mediaEndpoint`.

## Post-deploy

1. **App settings** are already wired: `SQL_DB_*`, `SITE_URL`, `PURCHASE_CODE`,
   `YOUTUBE_API_KEY`, `API_TOKEN` (both APIs), each app's `PORT`/`WEBSITES_PORT`.
   Secrets are visible in plaintext app settings — rotate via Key Vault later.
2. **Database bootstrap**: MySQL databases are not created by ARM. Connect once
   (`mysql --host=<sqlHost> -u playtube_admin -p`) and run the installer /
   `install` flow against SQL_DB_NAME `playtube`. Set `queue_count` low — F1
   shares 60 CPU-min/day across all three apps, and encoding is CPU-heavy.
3. **Media**: turn on `azure_blob_storage` + `S3` in the PlayTube admin, point
   it at the `media` container endpoint, and set the account `key1`/connection
   string. The app container's own disk is ephemeral — never rely on it for
   video files.
4. **VM workers**: the VM only installs Docker/MediamTX/PHP. Clone the repo on
   the VM, point `config.php` (env vars) at the same MySQL + blob storage, and
   schedule `php deploy/import/import_worker.php --once` and the backup worker
   with cron every N minutes. MediamTX publishes on the NSG-opened ports.

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