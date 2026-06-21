<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo '<link rel="stylesheet" href="' . vc_asset('css/mobile.css') . '?v=basic">';
if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
$user_id = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'مستخدم';
$displayRole = 'مستخدم';
try {
    if ($user_id > 0) {
        $stmtHeaderUser = $conn->prepare("SELECT id, username, role, is_admin, job_role FROM users WHERE id = ? LIMIT 1");
        if ($stmtHeaderUser) {
            $stmtHeaderUser->bind_param("i", $user_id);
            $stmtHeaderUser->execute();
            $headerUser = $stmtHeaderUser->get_result()->fetch_assoc();
            $stmtHeaderUser->close();
            if (!empty($headerUser)) {
                $username = (string)($headerUser['username'] ?? $username);
                $job = (string)($headerUser['job_role'] ?? 'user');
                $role = (string)($headerUser['role'] ?? 'user');
                $isAdmin = (int)($headerUser['is_admin'] ?? 0) === 1;
                if ($isAdmin || $role === 'admin' || $job === 'admin') $displayRole = 'أدمن';
                elseif ($job === 'finance_manager') $displayRole = 'مدير مالي';
                elseif ($job === 'commercial_manager') $displayRole = 'مدير تجاري';
                elseif ($job === 'section_manager') $displayRole = 'مدير قسم';
                elseif ($job === 'accountant') $displayRole = 'محاسب';
            }
        }
    }
} catch (Throwable $e) {
    error_log("header user role display error: " . $e->getMessage());
}
$first_letter = mb_substr($username, 0, 1, 'UTF-8');
?>
<style>
.vc-header{background:rgba(255,255,255,.62);padding:14px 22px;display:grid;grid-template-columns:190px minmax(0,1fr) 260px;align-items:center;gap:18px;border-radius:22px;margin:15px auto 24px;width:min(1500px,calc(100% - 48px));min-height:108px;box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;border:1px solid rgba(226,232,240,.95)}.vc-logo{display:flex;align-items:center;justify-content:flex-start}.vc-logo a{display:flex;align-items:center;justify-content:center;width:170px;height:72px;border-radius:18px;background:#eef1f7;box-shadow:inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff;padding:7px;text-decoration:none;color:#4f46e5;font-weight:900}.vc-title{text-align:center;min-width:0}.vc-title-main{font-size:22px;font-weight:900;color:#4f46e5;line-height:1.4}.vc-title-sub{margin-top:3px;font-size:12px;font-weight:900;color:#8a94a6;letter-spacing:.4px}.vc-right{display:flex;align-items:center;justify-content:flex-end;gap:14px;min-width:0}.vc-user-box{display:flex;align-items:center;gap:10px;cursor:pointer;position:relative;background:#eef1f7;width:176px;min-width:176px;max-width:176px;min-height:52px;border-radius:16px;padding:5px 7px 5px 13px;box-shadow:inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff}.vc-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(145deg,#7c5cff,#4f46e5);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px}.vc-user-info{min-width:0;flex:1;overflow:hidden}.vc-name{font-size:13px;font-weight:900;color:#172033;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.vc-role{font-size:10px;color:#8a94a6;font-weight:800;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.vc-dropdown{position:absolute;top:54px;right:0;background:#eef1f7;border-radius:16px;display:none;min-width:175px;box-shadow:0 18px 40px rgba(23,32,51,.16);border:1px solid rgba(226,232,240,.95);overflow:hidden;z-index:99999}.vc-dropdown a{display:block;padding:12px;text-decoration:none;color:#172033;border-bottom:1px solid #e0e5ec;font-size:13px;font-weight:900}.vc-dropdown a:hover{background:#f0edff;color:#4f46e5}@media(max-width:900px){.vc-header{grid-template-columns:1fr;text-align:center;padding:16px;width:calc(100% - 24px);min-height:auto}.vc-logo{justify-content:center}.vc-right{justify-content:center;flex-wrap:wrap}.vc-user-box{width:176px;min-width:176px;max-width:176px}}
</style>
<div class="vc-header"><div class="vc-logo"><a href="dashboard.php">VendorCore</a></div><div class="vc-title"><div class="vc-title-main">نظام إدارة العقود والإيجارات</div><div class="vc-title-sub">VendorCore Basic</div></div><div class="vc-right"><div class="vc-user-box" onclick="toggleMenu(event)"><div class="vc-avatar"><?= h($first_letter) ?></div><div class="vc-user-info"><div class="vc-name"><?= h($username) ?></div><div class="vc-role"><?= h($displayRole) ?></div></div><div class="vc-dropdown" id="menu"><a href="my_account.php">👤 حسابي</a><a href="logout.php">🚪 تسجيل خروج</a></div></div></div></div>
<script>
function toggleMenu(e){e.stopPropagation();var menu=document.getElementById('menu');if(menu){menu.style.display=(menu.style.display==='block')?'none':'block';}}
document.addEventListener('click',function(){var menu=document.getElementById('menu');if(menu){menu.style.display='none';}});
</script>
