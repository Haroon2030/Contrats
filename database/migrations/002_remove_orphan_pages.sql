-- إزالة صفحات مسجّلة بدون ملف controller فعلي (PostgreSQL)
DELETE FROM user_permissions
WHERE page_id IN (
    SELECT id FROM pages WHERE name = 'payment_requests'
);

DELETE FROM pages
WHERE name = 'payment_requests';
