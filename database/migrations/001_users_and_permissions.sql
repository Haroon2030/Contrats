-- أعمدة users المطلوبة لإدارة المستخدمين والجلسات (آمن للتكرار)
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin SMALLINT DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS session_version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_password_change TIMESTAMP NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(30) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS whatsapp_enabled SMALLINT NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active SMALLINT NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS manager_id INTEGER NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_supervisor SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS job_role VARCHAR(50) NOT NULL DEFAULT 'user';

-- مزامنة job_role للحسابات القديمة
UPDATE users
SET job_role = CASE
    WHEN role = 'admin' OR COALESCE(is_admin, 0) = 1 THEN 'admin'
    WHEN COALESCE(is_supervisor, 0) = 1 THEN 'section_manager'
    ELSE COALESCE(NULLIF(job_role, ''), 'user')
END
WHERE job_role IS NULL OR job_role = '' OR job_role = 'user';

-- المدير التجاري = أدمن
UPDATE users SET role = 'admin', is_admin = 1 WHERE job_role = 'commercial_manager';

-- جدول صلاحيات الصفحات
CREATE TABLE IF NOT EXISTS user_permissions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER,
    page_id INTEGER,
    scope VARCHAR(10) NOT NULL DEFAULT 'own'
);

CREATE INDEX IF NOT EXISTS idx_user_permissions_user_id ON user_permissions (user_id);
CREATE INDEX IF NOT EXISTS idx_user_permissions_user_page ON user_permissions (user_id, page_id);
