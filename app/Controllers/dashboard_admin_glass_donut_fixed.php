<?php
session_start();


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$uid = (int)$_SESSION['user_id'];


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json; charset=UTF-8");

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "CSRF"]);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (is_array($data)) {
        foreach ($data as $item) {
            if (!isset($item['name'], $item['position'])) {
                continue;
            }

            $page_name = trim((string)$item['name']);
            $sort_order = (int)$item['position'];

            if ($page_name === '' || $sort_order < 1) {
                continue;
            }

            $stmt = $conn->prepare("
                INSERT INTO user_page_order (user_id, page_name, sort_order)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
            ");
            $stmt->bind_param("isi", $uid, $page_name, $sort_order);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode(["success" => true]);
        exit();
    }

    echo json_encode(["success" => false]);
    exit();
}


$stmt = $conn->prepare("SELECT is_admin, username FROM users WHERE id = ?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$is_admin = ((int)$user['is_admin'] === 1);
$username = $user['username'] ?? 'User';

$first_letter = function_exists('mb_substr')
    ? mb_substr($username, 0, 1, 'UTF-8')
    : substr($username, 0, 1);


function countTable($conn, $table){
    $allowed = ['contracts','suppliers','users'];
    if (!in_array($table, $allowed, true)) return 0;

    $q = $conn->query("SELECT COUNT(*) c FROM `$table`");
    return $q ? (int)$q->fetch_assoc()['c'] : 0;
}

$contracts = countTable($conn,"contracts");
$suppliers = countTable($conn,"suppliers");
$users     = countTable($conn,"users");


$stmt = $conn->prepare("SELECT COUNT(*) c FROM contracts WHERE status='review'");
$stmt->execute();
$review_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();


$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(status='approved'), 0) AS approved,
        COALESCE(SUM(status='rejected'), 0) AS rejected,
        COALESCE(SUM(status='draft'), 0) AS draft,
        COALESCE(SUM(status='review'), 0) AS review
    FROM contracts
    WHERE status IS NULL OR status <> 'deleted'
");
$stmt->execute();
$allContractsStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$all_approved = (int)($allContractsStats['approved'] ?? 0);
$all_rejected = (int)($allContractsStats['rejected'] ?? 0);
$all_draft    = (int)($allContractsStats['draft'] ?? 0);
$all_review   = (int)($allContractsStats['review'] ?? 0);

$contracts_chart_total = $all_approved + $all_rejected + $all_draft + $all_review;

$calcPercent = function($value, $total){
    if($total <= 0) return 0;
    return round(($value / $total) * 100, 1);
};

$approved_pct = $calcPercent($all_approved, $contracts_chart_total);
$rejected_pct = $calcPercent($all_rejected, $contracts_chart_total);
$draft_pct    = $calcPercent($all_draft, $contracts_chart_total);
$review_pct   = $calcPercent($all_review, $contracts_chart_total);

$start1 = 0;
$end1   = $approved_pct;

$start2 = $end1;
$end2   = $start2 + $rejected_pct;

$start3 = $end2;
$end3   = $start3 + $draft_pct;

$start4 = $end3;
$end4   = 100;

if ($contracts_chart_total <= 0) {
    $contracts_chart_style = 'conic-gradient(#e5e7eb 0% 100%)';
} else {
    $contracts_chart_style = sprintf(
        'conic-gradient(#6d4aff %.1f%% %.1f%%, #ff5b7f %.1f%% %.1f%%, #22c7f0 %.1f%% %.1f%%, #f6a623 %.1f%% %.1f%%)',
        $start1, $end1,
        $start2, $end2,
        $start3, $end3,
        $start4, $end4
    );
}


$stmt = $conn->prepare("
SELECT 
SUM(status='approved') approved,
SUM(status='rejected') rejected
FROM contracts
WHERE created_by = ?
");
$stmt->bind_param("i", $uid);
$stmt->execute();
$myContracts = $stmt->get_result()->fetch_assoc();
$stmt->close();

$approved = (int)($myContracts['approved'] ?? 0);
$rejected = (int)($myContracts['rejected'] ?? 0);


if($is_admin){
    $stmt = $conn->prepare("
        SELECT 
            p.name, 
            p.title, 
            p.icon, 
            p.section,
            COALESCE(u.sort_order, p.sort_order) AS final_order
        FROM pages p
        LEFT JOIN user_page_order u 
            ON p.name = u.page_name AND u.user_id = ?
        WHERE p.status = 1
        ORDER BY p.section, final_order ASC
    ");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $result = $stmt->get_result();
}else{
    $stmt = $conn->prepare("
        SELECT 
            p.name, 
            p.title, 
            p.icon, 
            p.section,
            COALESCE(u.sort_order, p.sort_order) AS final_order
        FROM user_permissions up
        JOIN pages p ON up.page_id = p.id
        LEFT JOIN user_page_order u 
            ON p.name = u.page_name AND u.user_id = ?
        WHERE up.user_id = ? AND p.status = 1
        ORDER BY p.section, final_order ASC
    ");

    $stmt->bind_param("ii", $uid, $uid);
    $stmt->execute();
    $result = $stmt->get_result();
}

$contracts_group = [];
$rents_group = [];
$items_group = [];
$finance_group = [];
$admin_group = [];

while($row = $result->fetch_assoc()){
    if($row['section'] == 'contracts'){
        $contracts_group[] = $row;
    }
    elseif($row['section'] == 'rents'){
        $rents_group[] = $row;
    }
    elseif($row['section'] == 'items'){
        $items_group[] = $row;
    }
    elseif($row['section'] == 'admin'){
        $admin_group[] = $row;
    }
    else{
        $finance_group[] = $row;
    }
}

$stmt->close();

function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>

<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">

<style>
*{
    box-sizing:border-box;
    font-family:'Cairo',sans-serif;
}

body{
    margin:0;
    background:
        radial-gradient(circle at top right, rgba(109,74,255,.12), transparent 35%),
        #eef1f7;
    color:#172033;
}

.container{
    display:flex;
    min-height:100vh;
}


.sidebar{
    width:245px;
    background:linear-gradient(180deg,#6d4aff,#4f46e5);
    color:#fff;
    padding:18px 12px;
    transition:.3s;
    position:relative;
    overflow:hidden;
    flex-shrink:0;
    box-shadow:0 0 30px rgba(79,70,229,.25);
}

.sidebar.closed{
    width:74px;
}

.toggle-btn{
    position:absolute;
    left:-15px;
    top:50%;
    transform:translateY(-50%);
    background:#fff;
    color:#4f46e5;
    border-radius:50%;
    width:32px;
    height:32px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    box-shadow:0 5px 12px rgba(0,0,0,.2);
    z-index:10;
}

.sidebar.closed .toggle-btn i{
    transform:rotate(180deg);
}


.user-box{
    width:100%;
    text-align:center;
    margin-top:15px;
    margin-bottom:22px;
    display:flex;
    flex-direction:column;
    align-items:center;
}

.logo-box{
    width:46px;
    height:46px;
    border-radius:16px;
    background:rgba(255,255,255,.16);
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:14px;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.25);
}

.logo-box i{
    font-size:24px;
}

.avatar{
    width:76px;
    height:76px;
    border-radius:50%;
    background:
        radial-gradient(circle at 30% 18%, rgba(255,255,255,.55), transparent 22%),
        linear-gradient(145deg,#7c5cff,#4f46e5);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:29px;
    font-weight:900;
    color:#fff;
    box-shadow:
        0 12px 28px rgba(0,0,0,0.25),
        inset 0 2px 5px rgba(255,255,255,0.3),
        inset 0 -3px 6px rgba(0,0,0,0.2);
    border:3px solid rgba(255,255,255,.28);
    flex-shrink:0;
}

.username{
    margin-top:10px;
    font-weight:900;
    font-size:14px;
    color:#fff;
    text-align:center;
    width:100%;
    line-height:1.5;
    word-break:break-word;
}

.role{
    margin-top:4px;
    font-size:11px;
    color:rgba(255,255,255,.75);
    font-weight:700;
}

.logout-btn{
    margin-top:16px;
    width:100%;
    height:38px;
    border-radius:13px;
    background:rgba(255,255,255,.14);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    text-decoration:none;
    font-weight:800;
    font-size:13px;
    transition:.2s;
}

.logout-btn:hover{
    background:rgba(255,255,255,.22);
}

.sidebar.closed .logo-box,
.sidebar.closed .username,
.sidebar.closed .role,
.sidebar.closed .logout-btn span{
    display:none;
}

.sidebar.closed .avatar{
    width:47px;
    height:47px;
    font-size:20px;
    border-width:2px;
}

.sidebar.closed .logout-btn{
    width:47px;
    padding:0;
    margin:auto;
    margin-top:16px;
}


.content{
    flex:1;
    padding:26px;
    min-width:0;
}


.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:20px;
    flex-wrap:wrap;
}

.title-card{
    background:rgba(255,255,255,.85);
    border:1px solid #e5e7eb;
    padding:15px 18px;
    border-radius:22px;
    box-shadow:0 14px 35px rgba(23,32,51,.08);
}

.title-box{
    display:flex;
    align-items:center;
    gap:11px;
    font-weight:900;
    font-size:20px;
}

.title-box i{
    width:40px;
    height:40px;
    border-radius:14px;
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
}

.page-note{
    margin-top:7px;
    font-size:12px;
    color:#667085;
    font-weight:700;
}


.cards{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(190px,1fr));
    gap:16px;
    margin-bottom:24px;
}

.card{
    background:rgba(255,255,255,.78);
    border:1px solid #e5e7eb;
    border-radius:22px;
    padding:17px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    min-height:112px;
    position:relative;
    overflow:hidden;
}

.card::after{
    content:"";
    position:absolute;
    width:90px;
    height:90px;
    border-radius:50%;
    left:-30px;
    bottom:-30px;
    background:rgba(109,74,255,.08);
}

.card-icon{
    width:39px;
    height:39px;
    border-radius:14px;
    background:#f0edff;
    color:#6d4aff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:21px;
    margin-bottom:10px;
}

.card .number{
    font-size:27px;
    font-weight:900;
    line-height:1;
}

.card .label{
    margin-top:8px;
    font-size:13px;
    color:#667085;
    font-weight:800;
}



.admin-chart-area{
    margin-bottom:24px;
}

.admin-chart-card{
    position:relative;
    overflow:hidden;
    border-radius:30px;
    padding:24px;
    background:
        radial-gradient(circle at 12% 10%, rgba(255,255,255,.92), transparent 28%),
        radial-gradient(circle at 88% 8%, rgba(34,199,240,.12), transparent 30%),
        linear-gradient(135deg, rgba(255,255,255,.88), rgba(246,247,255,.72));
    border:1px solid rgba(109,74,255,.16);
    box-shadow:
        8px 8px 18px #d1d9e6,
        -8px -8px 18px #ffffff,
        inset 0 1px 0 rgba(255,255,255,.82);
}

.admin-chart-card::before{
    content:"";
    position:absolute;
    right:-105px;
    bottom:-120px;
    width:260px;
    height:260px;
    border-radius:50%;
    background:radial-gradient(circle, rgba(109,74,255,.16), transparent 68%);
}

.admin-chart-card::after{
    content:"";
    position:absolute;
    left:-120px;
    top:-130px;
    width:280px;
    height:280px;
    border-radius:50%;
    background:radial-gradient(circle, rgba(34,199,240,.13), transparent 70%);
}

.admin-chart-head{
    position:relative;
    z-index:1;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:22px;
}

.admin-chart-title{
    font-size:25px;
    font-weight:900;
    color:#3527a8;
    line-height:1.35;
}

.admin-chart-subtitle{
    margin-top:6px;
    color:#667085;
    font-size:13px;
    font-weight:800;
    line-height:1.8;
}

.admin-chart-pill{
    min-height:42px;
    padding:0 16px;
    border-radius:16px;
    background:rgba(255,255,255,.62);
    border:1px solid rgba(109,74,255,.15);
    box-shadow:
        0 8px 22px rgba(79,70,229,.08),
        inset 0 1px 0 rgba(255,255,255,.85);
    color:#4f46e5;
    font-size:13px;
    font-weight:900;
    display:flex;
    align-items:center;
    justify-content:center;
    white-space:nowrap;
}

.admin-chart-body{
    position:relative;
    z-index:1;
    display:grid;
    grid-template-columns:minmax(270px, 390px) 1fr;
    gap:28px;
    align-items:center;
}

.glass-donut-wrap{
    display:flex;
    align-items:center;
    justify-content:center;
}

.glass-donut{
    width:294px;
    height:294px;
    border-radius:50%;
    position:relative;
    background:var(--chart);
    box-shadow:
        0 18px 42px rgba(79,70,229,.18),
        0 0 0 9px rgba(255,255,255,.55),
        inset 0 4px 12px rgba(255,255,255,.42);
}

.glass-donut::before{
    content:"";
    position:absolute;
    inset:20px;
    border-radius:50%;
    background:rgba(255,255,255,.20);
    box-shadow:
        inset 0 5px 14px rgba(255,255,255,.42),
        inset 0 -8px 18px rgba(79,70,229,.08);
}

.glass-donut::after{
    content:"";
    position:absolute;
    inset:58px;
    border-radius:50%;
    background:
        radial-gradient(circle at 30% 18%, rgba(255,255,255,1), rgba(255,255,255,.86) 34%, rgba(243,245,255,.96) 100%);
    box-shadow:
        0 8px 22px rgba(79,70,229,.12),
        inset 0 1px 0 rgba(255,255,255,.95);
}

.glass-shine{
    position:absolute;
    inset:0;
    border-radius:50%;
    background:
        radial-gradient(circle at 32% 18%, rgba(255,255,255,.84), transparent 17%),
        linear-gradient(135deg, rgba(255,255,255,.42), transparent 36%);
    z-index:1;
    pointer-events:none;
    mix-blend-mode:screen;
}

.glass-donut-center{
    position:absolute;
    inset:0;
    z-index:2;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:0 78px;
}

.glass-total{
    font-size:52px;
    line-height:1;
    font-weight:900;
    color:#2e2a7c;
    letter-spacing:-1px;
}

.glass-label{
    margin-top:8px;
    font-size:16px;
    font-weight:900;
    color:#4f46e5;
}

.glass-note{
    margin-top:6px;
    font-size:11px;
    color:#667085;
    font-weight:800;
}

.glass-legend{
    display:grid;
    grid-template-columns:repeat(2,minmax(210px,1fr));
    gap:14px;
}

.glass-legend-card{
    background:rgba(255,255,255,.63);
    border:1px solid rgba(109,74,255,.13);
    border-radius:22px;
    padding:15px 16px;
    box-shadow:
        0 10px 22px rgba(17,24,39,.05),
        inset 0 1px 0 rgba(255,255,255,.78);
}

.legend-main{
    display:grid;
    grid-template-columns:auto 1fr auto;
    align-items:center;
    gap:10px;
}

.legend-dot{
    width:14px;
    height:14px;
    border-radius:50%;
    box-shadow:
        0 0 0 5px rgba(255,255,255,.5),
        0 3px 10px rgba(0,0,0,.14);
}

.glass-legend-card.approved .legend-dot{background:#6d4aff;}
.glass-legend-card.rejected .legend-dot{background:#ff5b7f;}
.glass-legend-card.draft .legend-dot{background:#22c7f0;}
.glass-legend-card.review .legend-dot{background:#f6a623;}

.legend-name{
    font-size:15px;
    font-weight:900;
    color:#172033;
    line-height:1.45;
}

.legend-number{
    font-size:25px;
    font-weight:900;
    color:#3527a8;
    line-height:1;
}

.legend-foot{
    margin-top:8px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    font-size:12px;
    color:#667085;
    font-weight:800;
}

.legend-foot strong{
    color:#4f46e5;
    font-size:14px;
    font-weight:900;
}



.section-title{
    margin:26px 0 13px;
    font-size:18px;
    font-weight:900;
    color:#4f46e5;
    display:flex;
    align-items:center;
    gap:8px;
}

.section-title::before{
    content:"";
    width:10px;
    height:24px;
    border-radius:20px;
    background:linear-gradient(180deg,#7c5cff,#4f46e5);
}


.nav-cards{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(185px,1fr));
    gap:16px;
    align-items:stretch;
}

.nav-card{
    min-height:166px;
    text-align:center;
    background:rgba(255,255,255,.78);
    border:1px solid #e5e7eb;
    border-radius:22px;
    padding:16px 12px 13px;
    text-decoration:none;
    color:#172033;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    transition:.2s;
    cursor:grab;
    user-select:none;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
}

.nav-card:active{
    cursor:grabbing;
}

.nav-card:hover{
    transform:translateY(-4px);
    border-color:rgba(109,74,255,.35);
}

.icon-wrap{
    width:94px;
    height:94px;
    border-radius:24px;
    background:#f7f8fc;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:10px;
    box-shadow:inset 4px 4px 9px #dce2ec, inset -4px -4px 9px #fff;
}

.icon-img{
    width:78px;
    height:78px;
    object-fit:contain;
    display:block;
}

.nav-title{
    font-size:14px;
    font-weight:900;
    line-height:1.55;
    word-break:break-word;
}

.drag-hint{
    margin-top:5px;
    font-size:10px;
    color:#98a2b3;
    font-weight:700;
}

.highlight{
    background:#ddd;
}

.sortable-ghost{
    opacity:.35;
}

.sortable-chosen{
    transform:scale(1.02);
}

@media(max-width:900px){
    .admin-chart-body{
        grid-template-columns:1fr;
    }

    .glass-donut{
        width:252px;
        height:252px;
    }

    .glass-donut::after{
        inset:48px;
    }

    .glass-legend{
        grid-template-columns:1fr 1fr;
    }


    .container{
        display:block;
    }

    .sidebar,
    .sidebar.closed{
        width:100%;
        border-radius:0 0 24px 24px;
    }

    .toggle-btn{
        display:none;
    }

    .sidebar.closed .username,
    .sidebar.closed .role,
    .sidebar.closed .logout-btn span{
        display:block;
    }

    .sidebar.closed .avatar{
        width:70px;
        height:70px;
        font-size:26px;
    }

    .sidebar.closed .logout-btn{
        width:100%;
    }

    .content{
        padding:18px;
    }
}

@media(max-width:520px){
    .admin-chart-card{
        padding:18px;
    }

    .admin-chart-title{
        font-size:21px;
    }

    .glass-legend{
        grid-template-columns:1fr;
    }

    .glass-donut{
        width:222px;
        height:222px;
    }

    .glass-donut::before{
        inset:18px;
    }

    .glass-donut::after{
        inset:42px;
    }

    .glass-total{
        font-size:40px;
    }


    .cards{
        grid-template-columns:1fr;
    }

    .nav-cards{
        grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
    }

    .nav-card{
        min-height:150px;
    }

    .icon-wrap{
        width:78px;
        height:78px;
    }

    .icon-img{
        width:64px;
        height:64px;
    }
}
</style>

</head>

<body>

<div class="container">


<div class="sidebar closed" id="sidebar">

    <div class="toggle-btn" onclick="toggleSidebar()">
        <i class="ri-arrow-right-s-line"></i>
    </div>

    <div class="user-box">

        <div class="logo-box">
            <i class="ri-dashboard-3-line"></i>
        </div>

        <div class="avatar">
            <?= e(strtoupper($first_letter)) ?>
        </div>

        <div class="username">
            <?= e($username) ?>
        </div>

        <div class="role">
            <?= $is_admin ? 'مدير النظام' : 'مستخدم' ?>
        </div>

        <a href="logout.php" class="logout-btn">
            <i class="ri-logout-box-r-line"></i>
            <span>تسجيل الخروج</span>
        </a>

    </div>

</div>

<div class="content">

    <div class="topbar">
        <div class="title-card">
            <div class="title-box">
                <i class="ri-building-4-line"></i>
                <span>نظام إدارة العقود و الإيجارات</span>
            </div>
            <div class="page-note">
                VendorCore
            </div>
        </div>
    </div>
    <?php if($is_admin): ?>

    <div class="admin-chart-area">
        <div class="admin-chart-card">

            <div class="admin-chart-head">
                <div>
                    <div class="admin-chart-title">تحليل حالة العقود</div>
                    <div class="admin-chart-subtitle">
                        إجمالي كل عقود المستخدمين داخل النظام — أي مستخدم جديد يدخل تلقائيًا في الإحصائية
                    </div>
                </div>

                <div class="admin-chart-pill">
                    <?= (int)$contracts_chart_total ?> عقد
                </div>
            </div>

            <div class="admin-chart-body">

                <div class="glass-donut-wrap">
                    <div class="glass-donut" style="--chart: <?= e($contracts_chart_style) ?>;">
                        <span class="glass-shine"></span>

                        <div class="glass-donut-center">
                            <div class="glass-total"><?= (int)$contracts_chart_total ?></div>
                            <div class="glass-label">إجمالي العقود</div>
                            <div class="glass-note">بدون العقود الملغية</div>
                        </div>
                    </div>
                </div>

                <div class="glass-legend">

                    <div class="glass-legend-card approved">
                        <div class="legend-main">
                            <span class="legend-dot"></span>
                            <span class="legend-name">العقود المقبولة</span>
                            <span class="legend-number"><?= (int)$all_approved ?></span>
                        </div>
                        <div class="legend-foot">
                            <span>تمت الموافقة</span>
                            <strong><?= e($approved_pct) ?>%</strong>
                        </div>
                    </div>

                    <div class="glass-legend-card rejected">
                        <div class="legend-main">
                            <span class="legend-dot"></span>
                            <span class="legend-name">العقود المرفوضة</span>
                            <span class="legend-number"><?= (int)$all_rejected ?></span>
                        </div>
                        <div class="legend-foot">
                            <span>تم الرفض</span>
                            <strong><?= e($rejected_pct) ?>%</strong>
                        </div>
                    </div>

                    <div class="glass-legend-card draft">
                        <div class="legend-main">
                            <span class="legend-dot"></span>
                            <span class="legend-name">عقود تحت التفاوض</span>
                            <span class="legend-number"><?= (int)$all_draft ?></span>
                        </div>
                        <div class="legend-foot">
                            <span>مسودات / تفاوض</span>
                            <strong><?= e($draft_pct) ?>%</strong>
                        </div>
                    </div>

                    <div class="glass-legend-card review">
                        <div class="legend-main">
                            <span class="legend-dot"></span>
                            <span class="legend-name">عقود تحت المراجعة</span>
                            <span class="legend-number"><?= (int)$all_review ?></span>
                        </div>
                        <div class="legend-foot">
                            <span>بانتظار الإدارة</span>
                            <strong><?= e($review_pct) ?>%</strong>
                        </div>
                    </div>

                </div>

            </div>

        </div>
    </div>

    <?php else: ?>


    <div class="cards">

        <div class="card">
            <div class="card-icon"><i class="ri-checkbox-circle-line"></i></div>
            <div class="number"><?= $approved ?></div>
            <div class="label">عقودي المقبولة</div>
        </div>

        <div class="card">
            <div class="card-icon"><i class="ri-close-circle-line"></i></div>
            <div class="number"><?= $rejected ?></div>
            <div class="label">عقودي المرفوضة</div>
        </div>

    </div>

    <?php endif; ?>

    <?php if(!empty($contracts_group)): ?>
    <h3 class="section-title">إدارة و متابعة العقود</h3>
    <div class="nav-cards">
        <?php foreach($contracts_group as $row): ?>
            <a href="<?= e($row['name']) ?>.php" class="nav-card" data-id="<?= e($row['name']) ?>">
                <div class="icon-wrap">
                    <img src="<?= e(vc_icon($row['icon'] ?? '')) ?>" class="icon-img" alt="">
                </div>
                <div class="nav-title"><?= e($row['title']) ?></div>
                <div class="drag-hint"></div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($rents_group)): ?>
    <h3 class="section-title">إدارة و متابعة الإيجارات</h3>
    <div class="nav-cards">
        <?php foreach($rents_group as $row): ?>
            <a href="<?= e($row['name']) ?>.php" class="nav-card" data-id="<?= e($row['name']) ?>">
                <div class="icon-wrap">
                    <img src="<?= e(vc_icon($row['icon'] ?? '')) ?>" class="icon-img" alt="">
                </div>
                <div class="nav-title"><?= e($row['title']) ?></div>
                <div class="drag-hint"></div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($items_group)): ?>
    <h3 class="section-title">إدخال و مراجعة الأصناف</h3>
    <div class="nav-cards">
        <?php foreach($items_group as $row): ?>
            <a href="<?= e($row['name']) ?>.php" class="nav-card" data-id="<?= e($row['name']) ?>">
                <div class="icon-wrap">
                    <img src="<?= e(vc_icon($row['icon'] ?? '')) ?>" class="icon-img" alt="">
                </div>
                <div class="nav-title"><?= e($row['title']) ?></div>
                <div class="drag-hint"></div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($finance_group)): ?>
    <h3 class="section-title">الإدارة المالية</h3>
    <div class="nav-cards">
        <?php foreach($finance_group as $row): ?>
            <a href="<?= e($row['name']) ?>.php" class="nav-card" data-id="<?= e($row['name']) ?>">
                <div class="icon-wrap">
                    <img src="<?= e(vc_icon($row['icon'] ?? '')) ?>" class="icon-img" alt="">
                </div>
                <div class="nav-title"><?= e($row['title']) ?></div>
                <div class="drag-hint"></div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($admin_group)): ?>
    <h3 class="section-title">الإدارة</h3>
    <div class="nav-cards">
        <?php foreach($admin_group as $row): ?>
            <a href="<?= e($row['name']) ?>.php" class="nav-card" data-id="<?= e($row['name']) ?>">
                <div class="icon-wrap">
                    <img src="<?= e(vc_icon($row['icon'] ?? '')) ?>" class="icon-img" alt="">
                </div>
                <div class="nav-title"><?= e($row['title']) ?></div>
                <div class="drag-hint"></div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

</div>

<script>
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('closed');
}
</script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<script>
document.querySelectorAll('.nav-cards').forEach(container => {

    new Sortable(container, {
        animation: 200,
        swap: true,
        swapClass: 'highlight',

        onEnd: function () {

            let order = [];

            container.querySelectorAll('.nav-card').forEach((el, index) => {
                order.push({
                    name: el.dataset.id,
                    position: index + 1
                });
            });

            fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= e($csrf_token) ?>'
                },
                body: JSON.stringify(order)
            });

        }
    });

});
</script>

</body>
</html>
