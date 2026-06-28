<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once VC_HELPERS . '/header_menu_helper.php';

echo '<link rel="stylesheet" href="' . vc_asset('css/mobile.css') . '?v=2">';
echo '<link rel="stylesheet" href="' . vc_asset('css/vc-erp-nav.css') . '?v=2">';
echo '<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">';
vcRenderModalAssets();
if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('e')) {
    function e($value): string
    {
        return h($value);
    }
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'مستخدم';
$displayRole = 'مستخدم';
$headerIsAdmin = false;
$erp_modules = [];

try {
    if ($user_id > 0 && isset($conn)) {
        $stmtHeaderUser = $conn->prepare('SELECT id, username, role, is_admin, job_role FROM users WHERE id = ? LIMIT 1');
        if ($stmtHeaderUser) {
            $stmtHeaderUser->bind_param('i', $user_id);
            $stmtHeaderUser->execute();
            $headerUser = $stmtHeaderUser->get_result()->fetch_assoc();
            $stmtHeaderUser->close();
            if (!empty($headerUser)) {
                $username = (string) ($headerUser['username'] ?? $username);
                $job = (string) ($headerUser['job_role'] ?? 'user');
                $role = (string) ($headerUser['role'] ?? 'user');
                $headerIsAdmin = (int) ($headerUser['is_admin'] ?? 0) === 1
                    || $role === 'admin'
                    || $job === 'admin';
                if ($headerIsAdmin) {
                    $displayRole = 'أدمن';
                } elseif ($job === 'finance_manager') {
                    $displayRole = 'مدير مالي';
                } elseif ($job === 'commercial_manager') {
                    $displayRole = 'مدير تجاري';
                } elseif ($job === 'section_manager') {
                    $displayRole = 'مدير قسم';
                } elseif ($job === 'accountant') {
                    $displayRole = 'محاسب';
                }
            }
        }

        $erp_modules = vcBuildHeaderNavModules($conn, $user_id, $headerIsAdmin);
    }
} catch (Throwable $e) {
    error_log('header user role display error: ' . $e->getMessage());
}

$first_letter = mb_substr($username, 0, 1, 'UTF-8');
$currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
?>
<style>
.vc-header{background:rgba(255,255,255,.62);padding:14px 22px;display:grid;grid-template-columns:auto minmax(0,1fr) 260px;align-items:center;gap:18px;border-radius:22px;margin:15px auto 24px;width:min(1500px,calc(100% - 48px));min-height:108px;box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;border:1px solid rgba(226,232,240,.95)}
.vc-header-start{display:flex;align-items:center;gap:10px;min-width:0}
.vc-site-logo{
    display:flex;
    align-items:center;
    justify-content:center;
    width:52px;
    height:52px;
    border-radius:16px;
    background:#eef1f7;
    border:1px solid #e2e8f0;
    box-shadow:inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff;
    flex-shrink:0;
    overflow:hidden;
    text-decoration:none;
}
.vc-site-logo img{width:100%;height:100%;object-fit:cover;display:block}
.vc-menu-btn{display:flex;align-items:center;justify-content:center;width:52px;height:52px;border:none;border-radius:16px;background:#eef1f7;color:#4f46e5;font-size:22px;font-weight:900;cursor:pointer;box-shadow:inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff;flex-shrink:0}
.vc-menu-btn:hover,.vc-menu-btn.is-open{background:#4f46e5;color:#fff}
.vc-title{text-align:center;min-width:0}
.vc-title-main{font-size:22px;font-weight:900;color:#4f46e5;line-height:1.4}
.vc-title-sub{margin-top:3px;font-size:12px;font-weight:900;color:#8a94a6;letter-spacing:.4px}
.vc-right{display:flex;align-items:center;justify-content:flex-end;gap:14px;min-width:0}
.vc-user-box{
    display:flex;
    align-items:center;
    gap:10px;
    cursor:pointer;
    position:relative;
    background:#ffffff;
    border:1px solid #e2e8f0;
    min-width:0;
    max-width:220px;
    min-height:48px;
    border-radius:14px;
    padding:6px 10px 6px 12px;
    box-shadow:0 1px 3px rgba(15,23,42,.06);
    transition:border-color .18s ease,box-shadow .18s ease,background .18s ease;
}
.vc-user-box:hover{
    border-color:#93c5fd;
    background:#f8fafc;
    box-shadow:0 4px 14px rgba(68,114,196,.12);
}
.vc-avatar{
    width:38px;
    height:38px;
    border-radius:12px;
    background:linear-gradient(145deg,#4472c4,#3564b8);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
    box-shadow:0 4px 12px rgba(68,114,196,.25);
}
.vc-avatar i{font-size:20px;line-height:1}
.vc-user-info{min-width:0;flex:1;overflow:hidden;text-align:right}
.vc-name{font-size:13px;font-weight:900;color:#0f172a;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vc-user-chevron{
    color:#94a3b8;
    font-size:18px;
    flex-shrink:0;
    transition:transform .18s ease,color .18s ease;
}
.vc-user-box:hover .vc-user-chevron{color:#4472c4}
.vc-user-box.is-open .vc-user-chevron{transform:rotate(180deg);color:#4472c4}
.vc-dropdown{
    position:absolute;
    top:calc(100% + 8px);
    left:0;
    right:0;
    background:#ffffff;
    border-radius:14px;
    display:none;
    min-width:190px;
    box-shadow:0 16px 40px rgba(15,23,42,.14);
    border:1px solid #e2e8f0;
    overflow:hidden;
    z-index:99999;
    padding:6px;
}
.vc-dropdown a{
    display:flex;
    align-items:center;
    gap:10px;
    padding:11px 12px;
    text-decoration:none;
    color:#334155;
    border-radius:10px;
    font-size:13px;
    font-weight:800;
    transition:background .15s ease,color .15s ease;
}
.vc-dropdown a i{font-size:17px;color:#64748b;flex-shrink:0}
.vc-dropdown a:hover{background:#eff6ff;color:#1d4ed8}
.vc-dropdown a:hover i{color:#4472c4}
.vc-dropdown a.is-logout:hover{background:#fff1f2;color:#b42318}
.vc-dropdown a.is-logout:hover i{color:#b42318}
.vc-nav-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(2px);opacity:0;visibility:hidden;transition:opacity .25s ease;z-index:99990}
.vc-nav-overlay.is-open{opacity:1;visibility:visible}
.vc-nav-drawer{position:fixed;top:0;right:0;width:min(300px,92vw);height:100vh;background:#4472c4;color:#fff;box-shadow:-16px 0 48px rgba(68,114,196,.32);transform:translateX(100%);transition:transform .28s cubic-bezier(.4,0,.2,1);z-index:99995;display:flex;flex-direction:column;overflow:hidden}
.vc-nav-drawer.is-open{transform:translateX(0)}
.vc-nav-close{border:none;background:rgba(255,255,255,.14);color:#fff;width:36px;height:36px;border-radius:12px;font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:.2s}
.vc-nav-close:hover{background:rgba(255,255,255,.24)}
.vc-nav-user{display:flex;align-items:center;gap:10px;margin:12px 14px 10px;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.12);flex-shrink:0}
.vc-nav-user-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(145deg,#fff,#dbeafe);color:#4472c4;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px;flex-shrink:0}
.vc-nav-user-info{min-width:0;flex:1}
.vc-nav-user-name{font-size:13px;font-weight:900;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vc-nav-user-role{font-size:10px;font-weight:700;color:rgba(255,255,255,.72);margin-top:2px}
.vc-nav-footer{display:flex;gap:8px;padding:12px 14px 16px;border-top:1px solid rgba(255,255,255,.12);flex-shrink:0;background:rgba(0,0,0,.08)}
.vc-nav-footer a{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 8px;border-radius:12px;text-decoration:none;font-size:12px;font-weight:800;color:#fff;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.12);transition:.2s}
.vc-nav-footer a:hover{background:rgba(255,255,255,.18)}
.vc-nav-footer a i{font-size:16px}
@media(max-width:900px){
    .vc-header{grid-template-columns:auto minmax(0,1fr) auto;grid-template-rows:auto;align-items:center;padding:10px 12px;width:calc(100% - 24px);min-height:auto;gap:10px;margin:12px auto 18px}
    .vc-site-logo{width:44px;height:44px;border-radius:14px}
    .vc-menu-btn{width:44px;height:44px;font-size:18px;border-radius:14px}
    .vc-title{text-align:center;padding:0 4px;min-width:0}
    .vc-title-main{font-size:15px;line-height:1.35;color:#0f172a}
    .vc-title-sub{font-size:10px;margin-top:2px}
    .vc-right{justify-content:flex-end;flex-wrap:nowrap}
    .vc-user-box{max-width:min(168px,42vw);min-height:44px;padding:5px 8px 5px 10px;gap:8px;border-radius:12px}
    .vc-avatar{width:34px;height:34px;border-radius:10px}
    .vc-avatar i{font-size:18px}
    .vc-name{font-size:12px}
    .vc-user-chevron{font-size:16px}
    .vc-dropdown{top:calc(100% + 6px);min-width:170px}
}
@media(max-width:520px){
    .vc-user-info{display:none}
    .vc-user-box{max-width:none;width:44px;height:44px;padding:0;justify-content:center;border-radius:12px}
    .vc-user-chevron{display:none}
    .vc-dropdown{left:auto;right:0;min-width:180px}
}
</style>

<div class="vc-header">
    <div class="vc-header-start">
        <a href="dashboard.php" class="vc-site-logo" title="نظام إدارة العقود والإيجارات">
            <img src="<?= h(vcSiteLogoUrl()) ?>" alt="نظام إدارة العقود والإيجارات" width="52" height="52" decoding="async">
        </a>
        <button type="button" class="vc-menu-btn" id="vcMenuBtn" onclick="toggleAppMenu(event)" aria-label="فتح القائمة" title="القائمة">☰</button>
    </div>
    <div class="vc-title">
        <div class="vc-title-main">نظام إدارة العقود والإيجارات</div>
        <div class="vc-title-sub">VendorCore Basic</div>
    </div>
    <div class="vc-right">
        <div class="vc-user-box" id="vcUserBox" onclick="toggleUserMenu(event)" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false" aria-controls="userMenu" title="<?= h($username) ?>">
            <div class="vc-avatar" aria-hidden="true"><i class="ri-login-box-line"></i></div>
            <div class="vc-user-info">
                <div class="vc-name"><?= h($username) ?></div>
            </div>
            <i class="ri-arrow-down-s-line vc-user-chevron" aria-hidden="true"></i>
            <div class="vc-dropdown" id="userMenu">
                <a href="my_account.php"><i class="ri-user-settings-line"></i><span>حسابي</span></a>
                <a href="logout.php" class="is-logout"><i class="ri-logout-box-r-line"></i><span>تسجيل خروج</span></a>
            </div>
        </div>
    </div>
</div>

<div class="vc-nav-overlay" id="vcNavOverlay" onclick="closeAppMenu()"></div>
<aside class="vc-nav-drawer" id="vcNavDrawer" aria-label="قائمة التنقل">
    <div class="sidebar-brand vc-nav-drawer-brand">
        <?php include VC_VIEWS . '/partials/site_brand_mark.php'; ?>
        <div class="sidebar-brand-text">
            <strong>نظام إدارة العقود والإيجارات</strong>
        </div>
        <button type="button" class="vc-nav-close" onclick="closeAppMenu()" aria-label="إغلاق القائمة">
            <i class="ri-close-line"></i>
        </button>
    </div>

    <div class="vc-nav-user">
        <div class="vc-nav-user-avatar"><?= h($first_letter) ?></div>
        <div class="vc-nav-user-info">
            <div class="vc-nav-user-name"><?= h($username) ?></div>
            <div class="vc-nav-user-role"><?= h($displayRole) ?></div>
        </div>
    </div>

    <?php
    $erp_nav_drawer = true;
    $erp_nav_current_page = $currentPage;
    include VC_VIEWS . '/partials/dashboard_erp_sidebar.php';
    ?>

    <div class="vc-nav-footer">
        <a href="my_account.php" onclick="closeAppMenu()">
            <i class="ri-user-settings-line"></i>
            <span>حسابي</span>
        </a>
        <a href="logout.php">
            <i class="ri-logout-box-r-line"></i>
            <span>خروج</span>
        </a>
    </div>
</aside>

<script>
function toggleUserMenu(e) {
    e.stopPropagation();
    var menu = document.getElementById('userMenu');
    var box = document.getElementById('vcUserBox');
    if (!menu || !box) {
        return;
    }
    var open = menu.style.display !== 'block';
    menu.style.display = open ? 'block' : 'none';
    box.classList.toggle('is-open', open);
    box.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function closeUserMenu() {
    var menu = document.getElementById('userMenu');
    var box = document.getElementById('vcUserBox');
    if (menu) {
        menu.style.display = 'none';
    }
    if (box) {
        box.classList.remove('is-open');
        box.setAttribute('aria-expanded', 'false');
    }
}

function toggleAppMenu(e) {
    if (e) {
        e.stopPropagation();
    }
    var drawer = document.getElementById('vcNavDrawer');
    var overlay = document.getElementById('vcNavOverlay');
    var btn = document.getElementById('vcMenuBtn');
    if (!drawer || !overlay) {
        return;
    }
    var open = !drawer.classList.contains('is-open');
    drawer.classList.toggle('is-open', open);
    overlay.classList.toggle('is-open', open);
    if (btn) {
        btn.classList.toggle('is-open', open);
    }
    document.body.style.overflow = open ? 'hidden' : '';
}

function closeAppMenu() {
    var drawer = document.getElementById('vcNavDrawer');
    var overlay = document.getElementById('vcNavOverlay');
    var btn = document.getElementById('vcMenuBtn');
    if (drawer) {
        drawer.classList.remove('is-open');
    }
    if (overlay) {
        overlay.classList.remove('is-open');
    }
    if (btn) {
        btn.classList.remove('is-open');
    }
    document.body.style.overflow = '';
}

function toggleErpModule(moduleId) {
    var section = document.querySelector('.erp-module[data-module="' + moduleId + '"]');
    if (!section) {
        return;
    }
    section.classList.toggle('collapsed');
    var collapsed = section.classList.contains('collapsed');
    var head = section.querySelector('.erp-module-head');
    if (head) {
        head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }
}

document.addEventListener('click', function () {
    closeUserMenu();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeAppMenu();
        closeUserMenu();
    }
});
</script>
<?php include VC_VIEWS . '/partials/vc_modal.php'; ?>
