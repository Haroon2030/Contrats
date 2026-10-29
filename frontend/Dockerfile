# واجهة Next.js — تُبنى من مجلد frontend/ كخدمة مستقلة.
# مهم: rewrites الخاصة بـ /api تُحسب وقت البناء، لذلك مرّر BACKEND_URL كـ build arg
# (عنوان خدمة NestJS الداخلي أو العام، مثل http://contracts-api:4000).
FROM node:22-alpine AS build
ARG BACKEND_URL=http://contracts-api:4000
ENV BACKEND_URL=${BACKEND_URL} \
    NEXT_TELEMETRY_DISABLED=1
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build && npm prune --omit=dev

FROM node:22-alpine
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000
COPY --from=build /app/.next ./.next
COPY --from=build /app/node_modules ./node_modules
COPY --from=build /app/package.json ./
COPY --from=build /app/next.config.mjs ./
EXPOSE 3000
CMD ["npx", "next", "start", "-p", "3000"]
