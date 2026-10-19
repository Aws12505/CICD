FROM node:22-alpine

WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci --omit=dev; else npm install --omit=dev; fi
COPY lib.js server.js ./

USER node
ENV NODE_ENV=production PORT=8090
EXPOSE 8090
CMD ["node", "server.js"]
