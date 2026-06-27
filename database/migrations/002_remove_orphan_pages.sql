-- إزالة صفحات مسجّلة بدون ملف controller فعلي (PostgreSQL)
DELETE FROM user_permissions
WHERE page_id IN (
    SELECT id FROM pages WHERE name = 'payment_requests'
);

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = current_schema()
          AND table_name = 'user_page_order'
    ) THEN
        DELETE FROM user_page_order
        WHERE page_name = 'payment_requests';
    END IF;
END $$;

DELETE FROM pages
WHERE name = 'payment_requests';
