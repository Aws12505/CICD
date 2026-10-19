# Control-plane default image for a Next.js front end (decision 23).
#
# NEXT_PUBLIC_* values are frozen into the bundle at build time, so this image
# is built per stack: the control plane writes that stack's values into
# .env.production.local before the build, and the tag carries the stack id —
# never the client's name.

FROM node:22-bookworm-slim AS build
WORKDIR /app

COPY package.json package-lock.json* npm-shrinkwrap.json* ./
RUN if [ -f package-lock.json ] || [ -f npm-shrinkwrap.json ]; then \
      npm ci --no-audit --no-fund; \
    else \
      npm install --no-audit --no-fund; \
    fi

COPY . .
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build && npm prune --omit=dev

FROM node:22-bookworm-slim
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 PORT=3000 HOSTNAME=0.0.0.0

COPY --from=build --chown=node:node /app ./
USER node

EXPOSE 3000
CMD ["npm", "run", "start", "--", "-p", "3000", "-H", "0.0.0.0"]
