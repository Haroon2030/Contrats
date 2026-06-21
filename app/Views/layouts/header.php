<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once VC_HELPERS . '/header_menu_helper.php';

echo '<link rel="stylesheet" href="' . vc_asset('css/mobile.css') . '?v=2">';
echo '<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">';
if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? 'مستخدم';
$displayRole = 'مستخدم';
$headerIsAdmin = false;
$navModules = [];

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

        $navModules = vcBuildHeaderNavModules($conn, $user_id, $headerIsAdmin);
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
.vc-menu-btn{display:flex;align-items:center;justify-content:center;width:52px;height:52px;border:none;border-radius:16px;background:#eef1f7;color:#4f46e5;font-size:22px;font-weight:900;cursor:pointer;box-shadow:inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff;flex-shrink:0}
.vc-menu-btn:hover,.vc-menu-btn.is-open{background:#4f46e5;color:#fff}
.vc-title{text-align:center;min-width:0}
.vc-title-main{font-size:22px;font-weight:900;color:#4f46e5;line-height:1.4}
.vc-title-sub{margin-top:3px;font-size:12px;font-weight:900;color:#8a94a6;letter-spacing:.4px}
.vc-right{display:flex;align-items:center;justify-content:flex-end;gap:14px;min-width:0}
.vc-user-box{display:flex;align-items:center;gap:10px;cursor:pointer;position:relative;background:#eef1f7;width:176px;min-width:176px;max-width:176px;min-height:52px;border-radius:16px;padding:5px 7px 5px 13px;box-shadow:inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff}
.vc-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(145deg,#7c5cff,#4f46e5);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px}
.vc-user-info{min-width:0;flex:1;overflow:hidden}
.vc-name{font-size:13px;font-weight:900;color:#172033;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vc-role{font-size:10px;color:#8a94a6;font-weight:800;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vc-dropdown{position:absolute;top:54px;right:0;background:#eef1f7;border-radius:16px;display:none;min-width:175px;box-shadow:0 18px 40px rgba(23,32,51,.16);border:1px solid rgba(226,232,240,.95);overflow:hidden;z-index:99999}
.vc-dropdown a{display:block;padding:12px;text-decoration:none;color:#172033;border-bottom:1px solid #e0e5ec;font-size:13px;font-weight:900}
.vc-dropdown a:hover{background:#f0edff;color:#4f46e5}
.vc-nav-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(2px);opacity:0;visibility:hidden;transition:opacity .25s ease;z-index:99990}
.vc-nav-overlay.is-open{opacity:1;visibility:visible}
.vc-nav-drawer{position:fixed;top:0;right:0;width:min(300px,92vw);height:100vh;background:linear-gradient(180deg,#5b21b6 0%,#4f46e5 42%,#4338ca 100%);color:#fff;box-shadow:-16px 0 48px rgba(79,70,229,.35);transform:translateX(100%);transition:transform .28s cubic-bezier(.4,0,.2,1);z-index:99995;display:flex;flex-direction:column;overflow:hidden}
.vc-nav-drawer.is-open{transform:translateX(0)}
.vc-nav-brand{display:flex;align-items:center;gap:12px;padding:18px 16px 14px;border-bottom:1px solid rgba(255,255,255,.12);flex-shrink:0}
.vc-nav-brand-mark{width:42px;height:42px;border-radius:14px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;letter-spacing:.5px;flex-shrink:0;box-shadow:inset 0 1px 0 rgba(255,255,255,.15)}
.vc-nav-brand-text{flex:1;min-width:0}
.vc-nav-brand-text strong{display:block;font-size:15px;font-weight:900;line-height:1.3}
.vc-nav-brand-text small{display:block;font-size:10px;font-weight:700;color:rgba(255,255,255,.72);margin-top:2px;letter-spacing:.4px}
.vc-nav-close{border:none;background:rgba(255,255,255,.14);color:#fff;width:36px;height:36px;border-radius:12px;font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:.2s}
.vc-nav-close:hover{background:rgba(255,255,255,.24)}
.vc-nav-user{display:flex;align-items:center;gap:10px;margin:12px 14px 10px;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.12);flex-shrink:0}
.vc-nav-user-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(145deg,#fff,#e0e7ff);color:#4f46e5;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px;flex-shrink:0}
.vc-nav-user-info{min-width:0;flex:1}
.vc-nav-user-name{font-size:13px;font-weight:900;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vc-nav-user-role{font-size:10px;font-weight:700;color:rgba(255,255,255,.72);margin-top:2px}
.vc-nav-scroll{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:4px 12px 12px;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.35) transparent}
.vc-nav-scroll::-webkit-scrollbar{width:5px}
.vc-nav-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.28);border-radius:999px}
.vc-nav-home{display:flex;align-items:center;gap:10px;padding:11px 12px;margin-bottom:10px;border-radius:14px;text-decoration:none;color:#fff;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.14);box-shadow:inset 0 1px 0 rgba(255,255,255,.12);transition:.2s}
.vc-nav-home:hover,.vc-nav-home.is-active{background:rgba(255,255,255,.2);border-color:rgba(255,255,255,.22)}
.vc-nav-home-icon{width:34px;height:34px;border-radius:11px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.vc-nav-home-text{min-width:0}
.vc-nav-home-text strong{display:block;font-size:13px;font-weight:900}
.vc-nav-home-text small{display:block;font-size:10px;font-weight:700;color:rgba(255,255,255,.72);margin-top:2px}
.vc-nav-module{margin-bottom:8px}
.vc-nav-module-head{width:100%;border:0;background:rgba(255,255,255,.08);color:#fff;border-radius:14px;padding:10px 11px;display:flex;align-items:center;gap:10px;cursor:pointer;text-align:right;transition:.2s;border:1px solid rgba(255,255,255,.08)}
.vc-nav-module-head:hover{background:rgba(255,255,255,.13)}
.vc-nav-module-icon{width:32px;height:32px;border-radius:10px;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.vc-nav-module-label{flex:1;min-width:0;text-align:right}
.vc-nav-module-label strong{display:block;font-size:12px;font-weight:900;line-height:1.35}
.vc-nav-module-label small{display:block;font-size:9px;font-weight:700;color:rgba(255,255,255,.68);margin-top:2px;letter-spacing:.3px}
.vc-nav-module-chevron{font-size:18px;opacity:.85;transition:transform .2s;flex-shrink:0}
.vc-nav-module.collapsed .vc-nav-module-chevron{transform:rotate(-90deg)}
.vc-nav-module-body{overflow:hidden;max-height:600px;transition:max-height .25s ease,opacity .2s ease;opacity:1}
.vc-nav-module.collapsed .vc-nav-module-body{max-height:0;opacity:0}
.vc-nav-module-list{display:flex;flex-direction:column;gap:4px;padding:6px 4px 2px}
.vc-nav-link{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:12px;text-decoration:none;color:rgba(255,255,255,.95);background:rgba(255,255,255,.06);border:1px solid transparent;transition:.18s}
.vc-nav-link:hover{background:rgba(255,255,255,.14);border-color:rgba(255,255,255,.12);transform:translateX(-2px)}
.vc-nav-link.is-active{background:rgba(255,255,255,.22);border-color:rgba(255,255,255,.2);box-shadow:0 4px 14px rgba(0,0,0,.12)}
.vc-nav-link-icon{width:28px;height:28px;border-radius:9px;background:rgba(255,255,255,.92);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
.vc-nav-link-icon img{width:20px;height:20px;object-fit:contain}
.vc-nav-link-text{flex:1;min-width:0;font-size:12px;font-weight:800;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vc-nav-footer{display:flex;gap:8px;padding:12px 14px 16px;border-top:1px solid rgba(255,255,255,.12);flex-shrink:0;background:rgba(0,0,0,.08)}
.vc-nav-footer a{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 8px;border-radius:12px;text-decoration:none;font-size:12px;font-weight:800;color:#fff;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.12);transition:.2s}
.vc-nav-footer a:hover{background:rgba(255,255,255,.18)}
.vc-nav-footer a i{font-size:16px}
@media(max-width:900px){.vc-header{grid-template-columns:auto minmax(0,1fr) auto;grid-template-rows:auto;align-items:center;padding:10px 12px;width:calc(100% - 24px);min-height:auto;gap:10px;margin:12px auto 18px}.vc-menu-btn{width:44px;height:44px;font-size:18px;border-radius:14px}.vc-title{text-align:center;padding:0 4px;min-width:0}.vc-title-main{font-size:15px;line-height:1.35}.vc-title-sub{font-size:10px;margin-top:2px}.vc-right{justify-content:flex-end;flex-wrap:nowrap}.vc-user-box{width:auto;min-width:0;max-width:130px;min-height:44px;padding:4px 6px 4px 8px;gap:8px}.vc-avatar{width:32px;height:32px;font-size:13px}.vc-name{font-size:12px}.vc-role{font-size:9px}.vc-dropdown{top:48px;right:0;transform:none}}
</style>

<div class="vc-header">
    <div class="vc-header-start">
        <button type="button" class="vc-menu-btn" id="vcMenuBtn" onclick="toggleAppMenu(event)" aria-label="فتح القائمة" title="القائمة">☰</button>
    </div>
    <div class="vc-title">
        <div class="vc-title-main">نظام إدارة العقود والإيجارات</div>
        <div class="vc-title-sub">VendorCore Basic</div>
    </div>
    <div class="vc-right">
        <div class="vc-user-box" onclick="toggleUserMenu(event)">
            <div class="vc-avatar"><?= h($first_letter) ?></div>
            <div class="vc-user-info">
                <div class="vc-name"><?= h($username) ?></div>
                <div class="vc-role"><?= h($displayRole) ?></div>
            </div>
            <div class="vc-dropdown" id="userMenu">
                <a href="my_account.php">👤 حسابي</a>
                <a href="logout.php">🚪 تسجيل خروج</a>
            </div>
        </div>
    </div>
</div>

<div class="vc-nav-overlay" id="vcNavOverlay" onclick="closeAppMenu()"></div>
<aside class="vc-nav-drawer" id="vcNavDrawer" aria-label="قائمة التنقل">
    <div class="vc-nav-brand">
        <div class="vc-nav-brand-mark">VC</div>
        <div class="vc-nav-brand-text">
            <strong>VendorCore</strong>
            <small>Contract & Lease ERP</small>
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

    <nav class="vc-nav-scroll" aria-label="صفحات النظام">
        <a href="dashboard.php" class="vc-nav-home<?= $currentPage === 'dashboard.php' ? ' is-active' : '' ?>" onclick="closeAppMenu()">
            <span class="vc-nav-home-icon"><i class="ri-dashboard-3-line"></i></span>
            <span class="vc-nav-home-text">
                <strong>لوحة التحكم</strong>
                <small>Dashboard</small>
            </span>
        </a>

        <?php foreach ($navModules as $navModule): ?>
            <?php
            $moduleId = (string) ($navModule['id'] ?? '');
            $items = (array) ($navModule['items'] ?? []);
            if ($moduleId === '' || $items === []) {
                continue;
            }
            ?>
            <section class="vc-nav-module" data-module="<?= h($moduleId) ?>">
                <button type="button" class="vc-nav-module-head" onclick="toggleNavModule('<?= h($moduleId) ?>')" aria-expanded="true">
                    <span class="vc-nav-module-icon"><i class="<?= h((string) ($navModule['icon'] ?? 'ri-folder-line')) ?>"></i></span>
                    <span class="vc-nav-module-label">
                        <strong><?= h((string) ($navModule['title'] ?? '')) ?></strong>
                        <small><?= h((string) ($navModule['subtitle'] ?? '')) ?></small>
                    </span>
                    <i class="ri-arrow-down-s-line vc-nav-module-chevron" aria-hidden="true"></i>
                </button>
                <div class="vc-nav-module-body" id="vc-nav-module-<?= h($moduleId) ?>">
                    <div class="vc-nav-module-list">
                        <?php foreach ($items as $navPage): ?>
                            <?php
                            $pageName = (string) ($navPage['name'] ?? '');
                            if ($pageName === '') {
                                continue;
                            }
                            $pageHref = $pageName . '.php';
                            $isActive = $currentPage === $pageHref;
                            $iconSrc = function_exists('vc_icon') ? vc_icon($navPage['icon'] ?? '') : '';
                            ?>
                            <a href="<?= h($pageHref) ?>" class="vc-nav-link<?= $isActive ? ' is-active' : '' ?>" onclick="closeAppMenu()" title="<?= h((string) ($navPage['title'] ?? $pageName)) ?>">
                                <span class="vc-nav-link-icon">
                                    <img src="<?= h($iconSrc) ?>" alt="" loading="lazy">
                                </span>
                                <span class="vc-nav-link-text"><?= h((string) ($navPage['title'] ?? $pageName)) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
        <?php unset($navModule, $navPage, $items); ?>
    </nav>

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
    if (menu) {
        menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
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

function toggleNavModule(moduleId) {
    var module = document.querySelector('.vc-nav-module[data-module="' + moduleId + '"]');
    if (!module) {
        return;
    }
    module.classList.toggle('collapsed');
    var head = module.querySelector('.vc-nav-module-head');
    if (head) {
        head.setAttribute('aria-expanded', module.classList.contains('collapsed') ? 'false' : 'true');
    }
}

document.addEventListener('click', function () {
    var menu = document.getElementById('userMenu');
    if (menu) {
        menu.style.display = 'none';
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeAppMenu();
    }
});
</script>
