#!/bin/sh
# يشغّل NestJS في الخلفية على 4000، ثم Next.js في المقدمة على 3000.
set -e
cd /app/backend
PORT=4000 node dist/main.js &
cd /app/frontend
exec npx next start -p 3000
