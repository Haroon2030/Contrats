#!/bin/sh
# يشغّل خادماً مؤقتاً على قاعدة جديدة (المنفذ 4100) ثم يجري اختبار المسار.
cd "$(dirname "$0")/.."
rm -rf ./data/test.sqlite3 ./data/media-test
DB_DRIVER=sqlite DB_SQLITE_PATH=./data/test.sqlite3 DEV_SEED=1 BOOTSTRAP_ADMIN_USERNAME=1 BOOTSTRAP_ADMIN_PASSWORD=526400 JWT_SECRET=testsecret MEDIA_ROOT=./data/media-test PORT=4100 JOBS_DISABLED=1 node dist/main.js > ./data/test.log 2>&1 &
PID=$!
sleep 9
node test/flow.mjs
CODE=$?
kill $PID 2>/dev/null
exit $CODE
