# حاوية واحدة للنظام الجديد: NestJS على 4000 داخلياً، وNext.js على 3000 (الواجهة العامة).
# Next يمرّر /api إلى NestJS عبر localhost، لذلك BACKEND_URL ثابت هنا.
FROM node:22-alpine AS api-build
WORKDIR /app/backend
COPY backend/package.json backend/package-lock.json ./
RUN npm ci
COPY backend/tsconfig.json backend/nest-cli.json ./
COPY backend/src ./src
RUN npm run build && npm prune --omit=dev

FROM node:22-alpine AS web-build
ENV BACKEND_URL=http://localhost:4000 \
    NEXT_TELEMETRY_DISABLED=1
WORKDIR /app/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build && npm prune --omit=dev

FROM node:22-alpine
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1
COPY --from=api-build /app/backend/node_modules ./backend/node_modules
COPY --from=api-build /app/backend/dist ./backend/dist
COPY --from=api-build /app/backend/package.json ./backend/
COPY --from=web-build /app/frontend/node_modules ./frontend/node_modules
COPY --from=web-build /app/frontend/.next ./frontend/.next
COPY --from=web-build /app/frontend/package.json /app/frontend/next.config.mjs ./frontend/
COPY start.sh ./start.sh
RUN chmod +x start.sh
EXPOSE 3000
CMD ["./start.sh"]
