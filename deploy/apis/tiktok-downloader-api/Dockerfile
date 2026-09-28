# TikTok Downloader API (Azure App Service F1 / generic Linux host).
#   docker build -t playtube/tt-api:<tag> .
# yt-dlp resolves TikTok content and ffmpeg muxes the separated
# video/audio streams into a single playable MP4. Entrypoint: `node app.js`.
FROM node:20-bookworm-slim

ENV PORT=3000 \
    YTDLP_BIN=/usr/local/bin/yt-dlp \
    MEDIA_DIR=/tmp/tt-media

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl ffmpeg \
    && curl -L -o /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux \
    && chmod +x /usr/local/bin/yt-dlp \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/app
COPY package.json package-lock.json* ./
RUN npm install --omit=dev && npm cache clean --force

COPY . .

EXPOSE 3000
CMD ["node", "app.js"]