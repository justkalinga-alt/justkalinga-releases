FROM node:22-bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json worker.mjs ./

RUN sed -i "s|if (req.method === 'GET' && path === '/v1/health') {|if (req.method === 'GET' \&\& (path === '/' || path === '/health' || path === '/v1/health')) {|" worker.mjs

ENV NODE_ENV=production
EXPOSE 8080

CMD ["node", "worker.mjs"]
