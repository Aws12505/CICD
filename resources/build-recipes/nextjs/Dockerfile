# syntax=docker/dockerfile:1
# Control-plane default image for a Next.js front end.
#
# NEXT_PUBLIC_* values are frozen into the bundle at build time, so this image
# is built per stack: the control plane writes that stack's values into
# .env.production.local before the build, and the tag carries the stack id,
# never the client's name.
#
# `npm ci` is tried first; the repository has carried two lockfiles
# (package-lock.json and pnpm-lock.yaml) that drift apart, so `npm install`
# stands in when ci refuses.
#
# Lean when the repository builds standalone output (`output: "standalone"`
# in next.config): the runner then gets only the traced server files, the
# static assets and public/, a fraction of a full node_modules. Without it
# the runner falls back to the production node_modules and .next, as before.
# The build cache (.next/cache) never reaches the image either way.

FROM node:22-alpine AS deps
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
RUN apk add --no-cache libc6-compat
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund

FROM node:22-alpine AS builder
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
ENV NODE_ENV=production
RUN apk add --no-cache libc6-compat
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build

# Everything the runner needs, in /out, whichever way the app was built.
RUN mkdir -p /out \
 && if [ -f .next/standalone/server.js ]; then \
      cp -r .next/standalone/. /out/ \
      && mkdir -p /out/.next \
      && cp -r .next/static /out/.next/static \
      && cp -r public /out/public; \
    else \
      cp package.json /out/ \
      && { cp package-lock.json /out/ 2>/dev/null || true; } \
      && cp -r .next /out/.next && rm -rf /out/.next/cache \
      && cp -r public /out/public \
      && cp next.config.* /out/ \
      && cd /out \
      && (npm ci --omit=dev --no-audit --no-fund || npm install --omit=dev --no-audit --no-fund) \
      && npm cache clean --force; \
    fi

FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV HOSTNAME=0.0.0.0
ENV PORT=3000

RUN apk add --no-cache libc6-compat \
 && addgroup -S -g 1001 nodejs \
 && adduser -S -u 1001 -G nodejs nextjs

COPY --from=builder --chown=nextjs:nodejs /out ./

USER nextjs
EXPOSE 3000

# node, not curl: nothing extra installed for the check.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:3000/',{redirect:'manual'}).then(r=>process.exit(r.status<500?0:1)).catch(()=>process.exit(1))"

# Node is PID 1's child (compose runs it with init), never npm.
CMD ["sh", "-c", "if [ -f server.js ]; then exec node server.js; else exec node node_modules/next/dist/bin/next start; fi"]
