-- إزالة صفحات مسجّلة بدون ملف controller فعلي
DELETE up
FROM user_permissions up
INNER JOIN pages p ON p.id = up.page_id
WHERE p.name = 'payment_requests';

DELETE FROM user_page_order
WHERE page_name = 'payment_requests';

DELETE FROM pages
WHERE name = 'payment_requests';
