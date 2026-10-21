# syntax=docker/dockerfile:1
# Control-plane default image for a Next.js front end.
#
# NEXT_PUBLIC_* values are frozen into the bundle at build time, so this image
# is built per stack: the control plane writes that stack's values into
# .env.production.local before the build, and the tag carries the stack id,
# never the client's name.
#
# The same three stages as the dashboard's current server Dockerfile.
# `npm ci` is tried first; the repository has carried two lockfiles
# (package-lock.json and pnpm-lock.yaml) that drift apart, so `npm install`
# stands in when ci refuses.

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

FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV HOSTNAME=0.0.0.0
ENV PORT=3000

# curl for the healthcheck; alpine has none.
RUN apk add --no-cache libc6-compat curl \
  && addgroup -S -g 1001 nodejs \
  && adduser -S -u 1001 -G nodejs nextjs

COPY package.json package-lock.json* ./
RUN (npm ci --omit=dev --no-audit --no-fund || npm install --omit=dev --no-audit --no-fund) \
  && npm cache clean --force

COPY --from=builder --chown=nextjs:nodejs /app/.next ./.next
COPY --from=builder --chown=nextjs:nodejs /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/next.config.* ./

USER nextjs

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD curl -fsS http://127.0.0.1:3000/ || exit 1

CMD ["npm", "run", "start"]
