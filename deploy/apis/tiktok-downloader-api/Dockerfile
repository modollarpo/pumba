# TikTok Downloader API (Azure App Service F1 / generic Linux host).
#   docker build -t playtube/tt-api:<tag> .
# Runs Puppeteer against the Chromium downloaded during `npm install`
# (PUPPETEER_CACHE_DIR keeps it in the image). Entrypoint: `node app.js`.
FROM node:20-bookworm-slim

ENV PORT=3000 \
    PUPPETEER_CACHE_DIR=/opt/puppeteer

# Chromium headless runtime deps for Puppeteer 23+ on Debian bookworm.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates fonts-liberation xdg-utils \
        libasound2 libatk-bridge2.0-0 libatk1.0-0 libatspi2.0-0 \
        libcups2 libdbus-1-3 libdrm2 libgbm1 libgtk-3-0 \
        libnspr4 libnss3 libx11-6 libxcb1 libxcomposite1 libxdamage1 \
        libxext6 libxfixes3 libxkbcommon0 libxrandr2 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/app
COPY package.json package-lock.json* ./
RUN npm install --omit=dev && npm cache clean --force

COPY . .

EXPOSE 3000
CMD ["node", "app.js"]