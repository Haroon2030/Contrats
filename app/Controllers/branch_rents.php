<?php
require_once VC_HELPERS . '/auth.php';



date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value): string {
    return number_format((float)$value, 2);
}

$months = [
    '01' => 'يناير',
    '02' => 'فبراير',
    '03' => 'مارس',
    '04' => 'أبريل',
    '05' => 'مايو',
    '06' => 'يونيو',
    '07' => 'يوليو',
    '08' => 'أغسطس',
    '09' => 'سبتمبر',
    '10' => 'أكتوبر',
    '11' => 'نوفمبر',
    '12' => 'ديسمبر'
];

$currentMonth = date("m");
$currentYear  = date("Y");

$month = $_GET['month'] ?? 'all';

if ($month !== 'all' && !array_key_exists($month, $months)) {
    $month = 'all';
}


$monthCounts = [];

$stmtCount = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM rents r
    LEFT JOIN contracts c ON c.id = r.contract_id
    WHERE c.status = 'approved'
    AND r.start_date <= ?
    AND r.end_date >= ?
");

foreach ($months as $num => $name) {
    $start = $currentYear . "-" . $num . "-01";
    $end   = date("Y-m-t", strtotime($start));

    $stmtCount->bind_param("ss", $end, $start);
    $stmtCount->execute();

    $row = $stmtCount->get_result()->fetch_assoc();
    $monthCounts[(int)$num] = (int)($row['total'] ?? 0);
}

$stmtCount->close();


if ($month !== 'all') {

    $start = $currentYear . "-" . $month . "-01";
    $end   = date("Y-m-t", strtotime($start));

    $stmt = $conn->prepare("
        SELECT r.*, c.supplier_name
        FROM rents r
        LEFT JOIN contracts c ON c.id = r.contract_id
        WHERE c.status = 'approved'
        AND r.start_date <= ?
        AND r.end_date >= ?
        ORDER BY r.branch ASC, r.start_date ASC
    ");
    $stmt->bind_param("ss", $end, $start);

} else {

    $stmt = $conn->prepare("
        SELECT r.*, c.supplier_name
        FROM rents r
        LEFT JOIN contracts c ON c.id = r.contract_id
        WHERE c.status = 'approved'
        ORDER BY r.branch ASC, r.start_date ASC
    ");
}

$stmt->execute();
$res = $stmt->get_result();

$branch_data = [];
$totalRows = 0;
$totalAmount = 0;

while ($r = $res->fetch_assoc()) {
    $branch = trim((string)($r['branch'] ?? 'غير محدد'));
    if ($branch === '') {
        $branch = 'غير محدد';
    }

    $branch_data[$branch][] = $r;
    $totalRows++;
}

$stmt->close();

function monthClass(string $num, string $currentMonth): string {
    if ($num < $currentMonth) return 'past';
    if ($num === $currentMonth) return 'current';
    return 'future';
}

$selectedMonthName = ($month === 'all') ? 'كل الشهور' : ($months[$month] ?? 'كل الشهور');
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>إيجارات الفروع</title>

<link rel="stylesheet" href="public/assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
*{
    box-sizing:border-box;
    font-family:'Cairo', Tahoma, Arial, sans-serif;
}

html, body{
    direction:rtl;
    text-align:right;
}

body{
    margin:0;
    background:
        radial-gradient(circle at top right, rgba(109,74,255,.11), transparent 34%),
        #eef1f7;
    color:#172033;
}

.container{
    width:min(1300px, calc(100% - 32px));
    margin:28px auto 45px;
}


.page-head{
    text-align:center;
    margin-bottom:22px;
}

.page-title{
    margin:0 0 7px;
    font-size:28px;
    font-weight:900;
    color:#172033;
    letter-spacing:-.3px;
}

.page-subtitle{
    margin:0;
    color:#667085;
    font-size:15px;
    line-height:1.9;
    font-weight:700;
}


.summary-grid{
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:14px;
    margin-bottom:18px;
}

.summary-card{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:20px;
    padding:16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

.summary-label{
    font-size:13px;
    color:#667085;
    font-weight:800;
    margin-bottom:7px;
}

.summary-value{
    font-size:24px;
    font-weight:900;
    color:#4f46e5;
}


.months-wrap{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    margin-bottom:20px;
}

.months-title{
    display:flex;
    align-items:center;
    gap:10px;
    color:#4f46e5;
    font-weight:900;
    margin-bottom:14px;
}

.months-title::before{
    content:"";
    width:9px;
    height:24px;
    border-radius:999px;
    background:linear-gradient(180deg,#7c5cff,#4f46e5);
}

.months{
    display:grid;
    grid-template-columns:repeat(13, 1fr);
    gap:9px;
}

.months a{
    min-height:58px;
    padding:9px 7px;
    border-radius:15px;
    text-align:center;
    text-decoration:none;
    color:#172033;
    background:#eef1f7;
    position:relative;
    box-shadow:6px 6px 12px #d1d9e6,-6px -6px 12px #fff;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    font-size:12px;
    font-weight:900;
    transition:.18s ease;
}

.months a:hover{
    transform:translateY(-2px);
}

.months a.current,
.months a.all.active{
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
}

.months a.past{
    background:#fff1f2;
    color:#b42318;
}

.months a.future{
    background:#ecfdf3;
    color:#166534;
}

.months a.active{
    background:linear-gradient(145deg,#7c5cff,#4f46e5) !important;
    color:#fff !important;
}

.month-count{
    position:absolute;
    top:-7px;
    left:-7px;
    min-width:25px;
    height:25px;
    border-radius:999px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:11px;
    font-weight:900;
    background:#172033;
    color:#fff;
    padding:0 7px;
    box-shadow:0 8px 16px rgba(23,32,51,.18);
}


.cards{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(370px, 1fr));
    gap:18px;
}

.branch-card{
    background:rgba(255,255,255,.64);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    overflow:hidden;
}

.branch-header{
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    padding:14px 15px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    cursor:pointer;
    user-select:none;
}

.branch-title{
    display:flex;
    align-items:center;
    gap:9px;
    font-weight:900;
    font-size:15px;
    min-width:0;
}

.branch-title span{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.branch-meta{
    display:flex;
    align-items:center;
    gap:8px;
    flex-shrink:0;
}

.branch-count{
    background:rgba(255,255,255,.18);
    padding:4px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
}

.slide-btn{
    width:30px;
    height:30px;
    border-radius:50%;
    border:0;
    background:rgba(255,255,255,.18);
    color:#fff;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
    font-size:18px;
    transition:.18s ease;
}

.branch-card.closed .slide-btn{
    transform:rotate(180deg);
}

.branch-body{
    padding:15px;
    display:grid;
    gap:11px;
    max-height:900px;
    opacity:1;
    overflow:hidden;
    transition:max-height .32s ease, opacity .22s ease, padding .25s ease;
}

.branch-card.closed .branch-body{
    max-height:0;
    opacity:0;
    padding-top:0;
    padding-bottom:0;
}


.rent-box{
    background:rgba(255,255,255,.72);
    border:1px solid rgba(226,232,240,.92);
    padding:13px;
    border-radius:16px;
    position:relative;
    box-shadow:inset 2px 2px 6px #d1d9e6, inset -2px -2px 6px #ffffff;
}

.rent-box.carry{
    border-right:5px solid #facc15;
    background:#fffbeb;
}

.carry-tag{
    position:absolute;
    top:10px;
    left:10px;
    background:#facc15;
    color:#172033;
    padding:3px 9px;
    font-size:10px;
    font-weight:900;
    border-radius:999px;
}

.row{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:13px;
    line-height:1.8;
    color:#172033;
}

.icon{
    width:22px;
    height:22px;
    border-radius:8px;
    background:#f0edff;
    color:#4f46e5;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}

.supplier{
    font-weight:900;
    padding-left:70px;
}

.type-row{
    margin-top:8px;
}

.type-badge{
    background:#f0edff;
    color:#4f46e5;
    padding:5px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
}

.date{
    color:#667085;
    font-size:12px;
    margin-top:8px;
    font-weight:800;
    direction:ltr;
    justify-content:flex-end;
}

.amount{
    margin-top:8px;
    color:#166534;
    font-weight:900;
}

.empty{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:20px;
    padding:24px;
    text-align:center;
    color:#667085;
    font-weight:900;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}


.view-tools{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-bottom:14px;
}

.tool-btn{
    border:0;
    border-radius:12px;
    background:#6d4aff;
    color:#fff;
    height:38px;
    padding:0 13px;
    cursor:pointer;
    font-weight:900;
    box-shadow:0 10px 20px rgba(109,74,255,.18);
}

.tool-btn.secondary{
    background:#64748b;
}


@media(max-width:1000px){
    .summary-grid{
        grid-template-columns:1fr;
    }

    .months{
        grid-template-columns:repeat(4, 1fr);
    }

    .cards{
        grid-template-columns:1fr;
    }
}

@media(max-width:560px){
    .container{
        width:calc(100% - 18px);
        margin-top:18px;
    }

    .months{
        grid-template-columns:repeat(2, 1fr);
    }

    .page-title{
        font-size:23px;
    }
}



html{
    scroll-behavior:auto !important;
}

.cards{
    display:grid !important;
    grid-template-columns:repeat(3, minmax(0, 1fr)) !important;
    gap:18px !important;
    align-items:start !important;
    column-count:unset !important;
    column-gap:unset !important;
}

.masonry-col{
    display:flex;
    flex-direction:column;
    gap:18px;
    min-width:0;
}

.branch-card{
    display:block !important;
    width:100% !important;
    margin:0 !important;
    align-self:start !important;
    height:auto !important;
    break-inside:auto !important;
    page-break-inside:auto !important;
}

.branch-body{
    max-height:none !important;
    overflow:visible !important;
}

.branch-card.closed .branch-body{
    display:none !important;
    max-height:0 !important;
    opacity:0 !important;
    padding:0 !important;
    margin:0 !important;
}

.amount{
    display:none !important;
}

.date{
    direction:rtl !important;
    justify-content:flex-start !important;
    text-align:right !important;
    color:#667085 !important;
    font-size:12px !important;
    font-weight:800 !important;
}

.date span:not(.icon){
    direction:rtl !important;
}

.supplier{
    padding-left:0 !important;
    overflow-wrap:anywhere;
    word-break:break-word;
}

@media(max-width:1150px){
    .cards{
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
    }
}

@media(max-width:700px){
    .cards{
        grid-template-columns:1fr !important;
    }
}

</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">🏢 إيجارات الفروع</h1>
        <p class="page-subtitle">
            متابعة إيجارات الفروع حسب الشهر، مع إمكانية طي كل فرع لوحده.
        </p>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">الشهر المعروض</div>
            <div class="summary-value"><?= e($selectedMonthName) ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">عدد الفروع</div>
            <div class="summary-value"><?= (int)count($branch_data) ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">عدد الإيجارات</div>
            <div class="summary-value"><?= (int)$totalRows ?></div>
        </div>
    </div>

    <div class="months-wrap">
        <div class="months-title">فلترة الشهور</div>

        <div class="months">
            <a href="?month=all" class="all <?= $month === 'all' ? 'active' : '' ?>">
                الكل
                <span class="month-count"><?= (int)array_sum($monthCounts) ?></span>
            </a>

            <?php foreach($months as $num => $name): ?>
                <?php
                $class = monthClass($num, $currentMonth);
                $active = ($month === $num) ? 'active' : '';
                $count = $monthCounts[(int)$num] ?? 0;
                ?>

                <a href="?month=<?= e($num) ?>" class="<?= e($class . ' ' . $active) ?>">
                    <?= e($name) ?>
                    <span class="month-count"><?= (int)$count ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="view-tools">
        <button type="button" class="tool-btn" onclick="openAllBranches()">فتح كل الفروع</button>
        <button type="button" class="tool-btn secondary" onclick="closeAllBranches()">طي كل الفروع</button>
    </div>

    <?php if(empty($branch_data)): ?>
        <div class="empty">لا توجد إيجارات في الفترة المحددة</div>
    <?php else: ?>

        <div class="cards">

            <?php foreach($branch_data as $branch => $rows): ?>
                <?php
                $count = count($rows);

                $branchId = 'branch_' . md5($branch);
                ?>

                <div class="branch-card" id="<?= e($branchId) ?>">

                    <div class="branch-header" onclick="toggleBranch('<?= e($branchId) ?>')">
                        <div class="branch-title">
                            <strong>🏢</strong>
                            <span><?= e($branch) ?></span>
                        </div>

                        <div class="branch-meta">
                            <span class="branch-count"><?= (int)$count ?> بند</span>
                            <button type="button" class="slide-btn" onclick="event.stopPropagation(); toggleBranch('<?= e($branchId) ?>')">⌃</button>
                        </div>
                    </div>

                    <div class="branch-body">

                        <?php foreach($rows as $r): ?>
                            <?php
                            $isCarry = false;

                            if($month !== 'all'){
                                if(($r['start_date'] ?? '') < $start){
                                    $isCarry = true;
                                }
                            }
                            ?>

                            <div class="rent-box <?= $isCarry ? 'carry' : '' ?>">

                                <?php if($isCarry): ?>
                                    <div class="carry-tag">🔁 مرحّل</div>
                                <?php endif; ?>

                                <div class="row supplier">
                                    <span class="icon">👤</span>
                                    <?= e($r['supplier_name'] ?? '-') ?>
                                </div>

                                <div class="row type-row">
                                    <span class="type-badge"><?= e($r['type'] ?? '-') ?></span>
                                    <?php if(!empty($r['qty'])): ?>
                                        <span class="type-badge">عدد: <?= e($r['qty']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="row date">
                                    <span class="icon">📅</span>
                                    <span>من <?= e($r['start_date'] ?? '-') ?> إلى <?= e($r['end_date'] ?? '-') ?></span>
                                </div>

                            </div>
                        <?php endforeach; ?>

                    </div>
                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<script>
let masonryBuilt = false;
let originalCards = [];

function getColumnCount(){
    if(window.innerWidth <= 700){
        return 1;
    }

    if(window.innerWidth <= 1150){
        return 2;
    }

    return 3;
}

function buildMasonry(){
    const container = document.querySelector(".cards");

    if(!container){
        return;
    }

    if(!masonryBuilt){
        originalCards = Array.from(container.querySelectorAll(".branch-card"));
        masonryBuilt = true;
    }else{
        originalCards = Array.from(document.querySelectorAll(".branch-card"));
    }

    container.innerHTML = "";

    const count = getColumnCount();
    const cols = [];

    for(let i = 0; i < count; i++){
        const col = document.createElement("div");
        col.className = "masonry-col";
        container.appendChild(col);
        cols.push(col);
    }

    originalCards.forEach(card => {
        let shortest = cols[0];

        cols.forEach(col => {
            if(col.offsetHeight < shortest.offsetHeight){
                shortest = col;
            }
        });

        shortest.appendChild(card);
    });
}

function toggleBranch(id){
    const card = document.getElementById(id);

    if(!card){
        return;
    }

    
    const oldScroll = window.scrollY;

    card.classList.toggle("closed");

    
    requestAnimationFrame(function(){
        window.scrollTo({
            top: oldScroll,
            left: 0,
            behavior: "instant"
        });
    });
}

function openAllBranches(){
    const oldScroll = window.scrollY;

    document.querySelectorAll(".branch-card").forEach(card => {
        card.classList.remove("closed");
    });

    requestAnimationFrame(function(){
        window.scrollTo({
            top: oldScroll,
            left: 0,
            behavior: "instant"
        });
    });
}

function closeAllBranches(){
    const oldScroll = window.scrollY;

    document.querySelectorAll(".branch-card").forEach(card => {
        card.classList.add("closed");
    });

    requestAnimationFrame(function(){
        window.scrollTo({
            top: oldScroll,
            left: 0,
            behavior: "instant"
        });
    });
}

window.addEventListener("load", buildMasonry);

let resizeTimer;
window.addEventListener("resize", function(){
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(buildMasonry, 150);
});
</script>
</body>
</html>
