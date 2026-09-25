# YouTube Media Downloader API (Azure App Service F1 / generic Linux host).
#   docker build -t playtube/yt-api:<tag> .
# Entrypoint is `node app.js` (listens on PORT, default 3000).
FROM node:20-bookworm-slim

ENV PORT=3000 \
    YTDLP_BIN=/usr/local/bin/yt-dlp \
    DOWNLOAD_DIR=/opt/downloads

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl ffmpeg unzip \
    && curl -L -o /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux \
    && chmod +x /usr/local/bin/yt-dlp \
    && curl -fsSL https://deno.land/install.sh | DENO_INSTALL=/usr/local sh -s -- v2.9.7 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/app
COPY package.json package-lock.json* ./
RUN npm install --omit=dev && npm cache clean --force

COPY . .
RUN mkdir -p "$DOWNLOAD_DIR"

EXPOSE 3000
CMD ["node", "app.js"]