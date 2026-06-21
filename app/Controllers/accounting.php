<?php
require_once VC_HELPERS . '/auth.php';



date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$allowedTabs = ['all', 'events', 'rents', 'discounts'];
$tab = $_GET['tab'] ?? 'all';

if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'all';
}

$search = trim($_GET['search'] ?? '');
$filter = trim($_GET['filter'] ?? '');

$months = [
    "01" => "يناير",
    "02" => "فبراير",
    "03" => "مارس",
    "04" => "أبريل",
    "05" => "مايو",
    "06" => "يونيو",
    "07" => "يوليو",
    "08" => "أغسطس",
    "09" => "سبتمبر",
    "10" => "أكتوبر",
    "11" => "نوفمبر",
    "12" => "ديسمبر"
];

function buildUrl(string $tab, string $search = '', string $filter = ''): string {
    $params = ['tab' => $tab];

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($filter !== '') {
        $params['filter'] = $filter;
    }

    return '?' . http_build_query($params);
}


$eventOptions = [];
$rentOptions = [];

if ($tab === 'events') {
    $resEvents = $conn->query("SELECT DISTINCT name FROM events WHERE name IS NOT NULL AND name != '' ORDER BY name ASC");
    while ($row = $resEvents->fetch_assoc()) {
        $eventOptions[] = $row['name'];
    }
}

if ($tab === 'rents') {
    $resRents = $conn->query("
        SELECT branch, COUNT(DISTINCT contract_id) AS c
        FROM rents
        WHERE branch IS NOT NULL AND branch != ''
        GROUP BY branch
        ORDER BY branch ASC
    ");

    while ($row = $resRents->fetch_assoc()) {
        $rentOptions[] = $row;
    }
}


$sql = "
    SELECT 
        c.*,
        (
            SELECT COUNT(*) 
            FROM events e 
            WHERE e.contract_id = c.id
        ) AS events_count,
        (
            SELECT GROUP_CONCAT(e.name SEPARATOR ', ') 
            FROM events e 
            WHERE e.contract_id = c.id
        ) AS event_names,
        (
            SELECT COUNT(*) 
            FROM rents r 
            WHERE r.contract_id = c.id
        ) AS rents_count,
        (
            SELECT MIN(start_date) 
            FROM rents r 
            WHERE r.contract_id = c.id
        ) AS rent_start
    FROM contracts c
    WHERE c.status = 'approved'
";

$params = [];
$types = "";

if ($tab === 'all') {
    if ($filter === 'annual') {
        $sql .= " AND (c.source IS NULL OR c.source != 'rent')";
    }

    if ($filter === 'rent') {
        $sql .= " AND c.source = 'rent'";
    }
}

if ($tab === 'events') {
    $sql .= " AND c.id IN (SELECT contract_id FROM events)";

    if ($filter !== '') {
        $sql .= " AND c.id IN (SELECT contract_id FROM events WHERE name = ?)";
        $params[] = $filter;
        $types .= "s";
    }
}

if ($tab === 'rents') {
    
    $sql .= " AND c.id IN (SELECT contract_id FROM rents)";

    if ($filter !== '') {
        $sql .= " AND c.id IN (SELECT contract_id FROM rents WHERE branch = ?)";
        $params[] = $filter;
        $types .= "s";
    }
}

if ($tab === 'discounts') {
    $sql .= " AND (
        c.discount_invoice > 0 
        OR c.discount_payment > 0 
        OR c.discount_quarter > 0
        OR c.id IN (SELECT contract_id FROM annual_discounts)
    )";

    if ($filter === 'invoice') {
        $sql .= " AND c.discount_invoice > 0";
    }

    if ($filter === 'payment') {
        $sql .= " AND c.discount_payment > 0";
    }

    if ($filter === 'quarter') {
        $sql .= " AND c.discount_quarter > 0";
    }

    if ($filter === 'annualdisc') {
        $sql .= " AND c.id IN (SELECT contract_id FROM annual_discounts)";
    }
}

if ($search !== '') {
    $cleanSearch = '%' . str_replace(' ', '', mb_strtolower($search, 'UTF-8')) . '%';
    $sql .= " AND LOWER(REPLACE(c.supplier_name, ' ', '')) LIKE ?";
    $params[] = $cleanSearch;
    $types .= "s";
}

$sql .= " ORDER BY c.id DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$contracts = [];
$totalContracts = 0;
$annualCount = 0;
$rentCount = 0;

while ($row = $res->fetch_assoc()) {
    $contracts[] = $row;
    $totalContracts++;

    $isRentContract = (($row['source'] ?? '') === 'rent');

    if ($isRentContract) {
        $rentCount++;
    } else {
        $annualCount++;
    }
}

$stmt->close();

function contractType(array $r): array {
    if (($r['source'] ?? '') === 'rent') {
        return ['rent', 'عقد إيجار', '🏢'];
    }

    return ['annual', 'عقد سنوي', '📄'];
}

function yesNoBadge(bool $condition): string {
    $class = $condition ? 'yes' : 'no';
    $text  = $condition ? 'يوجد' : 'لا يوجد';

    return "<span class='badge {$class}'>{$text}</span>";
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>لوحة المحاسبة</title>

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
    width:min(1320px, calc(100% - 32px));
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


.tabs{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:12px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:10px;
    margin-bottom:16px;
}

.tabs a{
    min-height:46px;
    border-radius:15px;
    text-decoration:none;
    color:#172033;
    background:#eef1f7;
    box-shadow:inset 2px 2px 6px #d1d9e6, inset -2px -2px 6px #ffffff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
    transition:.18s ease;
}

.tabs a:hover{
    transform:translateY(-1px);
}

.tabs a.active{
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    box-shadow:0 12px 22px rgba(109,74,255,.22);
}


.top-bar{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:14px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    display:grid;
    grid-template-columns:1fr 230px;
    gap:12px;
    margin-bottom:18px;
}

.top-bar input,
.top-bar select{
    width:100%;
    min-height:48px;
    padding:0 14px;
    border-radius:14px;
    border:1px solid #dfe6f0;
    background:#eef1f7;
    color:#172033;
    box-shadow:
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
    font-size:14px;
    outline:none;
    transition:.18s ease;
}

.top-bar input:focus,
.top-bar select:focus{
    border-color:#6d4aff;
    box-shadow:
        0 0 0 3px rgba(109,74,255,.12),
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
}


.cards{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(330px, 1fr));
    gap:18px;
    align-items:start;
}

.card{
    background:rgba(255,255,255,.66);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    min-height:310px;
}

.card-header{
    display:flex;
    min-height:68px;
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
}

.header-type{
    width:112px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:900;
    color:#fff;
    padding:10px;
    position:relative;
    flex-shrink:0;
}

.header-type::after{
    content:"";
    position:absolute;
    left:-14px;
    top:0;
    width:24px;
    height:100%;
    background:inherit;
    transform:skewX(-12deg);
}

.header-type.annual{
    background:#f59e0b;
    color:#fff;
}

.header-type.rent{
    background:#16a34a;
}

.header-name{
    flex:1;
    display:flex;
    align-items:center;
    padding:12px 20px 12px 10px;
    font-weight:900;
    font-size:15px;
    line-height:1.7;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.card-body{
    padding:15px;
    flex:1;
    display:flex;
    flex-direction:column;
    gap:10px;
}

.row{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    min-height:40px;
    border-radius:13px;
    padding:8px 11px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    font-size:13px;
    font-weight:800;
}

.row span:first-child{
    color:#667085;
}

.row span:last-child{
    color:#172033;
    text-align:left;
}


.badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:74px;
    height:28px;
    border-radius:999px;
    font-size:12px;
    color:#fff !important;
    font-weight:900;
}

.yes{
    background:#16a34a;
}

.no{
    background:#ef4444;
}


.events-box{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.event-tag{
    background:#f0edff;
    color:#4f46e5;
    padding:7px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    border:1px solid rgba(109,74,255,.14);
}


.rent-line{
    display:grid;
    grid-template-columns:1fr auto;
    gap:10px;
    align-items:center;
}

.rent-branch{
    overflow-wrap:anywhere;
    word-break:break-word;
}

.rent-price{
    direction:ltr;
    font-weight:900;
    color:#166534 !important;
}


.card-footer{
    padding:0 15px 15px;
}

.view{
    display:flex;
    width:100%;
    min-height:44px;
    align-items:center;
    justify-content:center;
    text-align:center;
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    padding:10px;
    border-radius:14px;
    text-decoration:none;
    font-weight:900;
    box-shadow:0 12px 22px rgba(109,74,255,.20);
    transition:.18s ease;
}

.view:hover{
    transform:translateY(-1px);
    filter:brightness(.98);
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

@media(max-width:900px){
    .summary-grid{
        grid-template-columns:1fr;
    }

    .tabs{
        grid-template-columns:1fr 1fr;
    }

    .top-bar{
        grid-template-columns:1fr;
    }
}

@media(max-width:560px){
    .container{
        width:calc(100% - 18px);
        margin-top:18px;
    }

    .page-title{
        font-size:23px;
    }

    .tabs{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">💰 لوحة المحاسبة</h1>
        <p class="page-subtitle">
            متابعة العقود المعتمدة والفعاليات والإيجارات والخصومات من مكان واحد.
        </p>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">المعروض الآن</div>
            <div class="summary-value"><?= (int)$totalContracts ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">العقود السنوية</div>
            <div class="summary-value"><?= (int)$annualCount ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">عقود الإيجار</div>
            <div class="summary-value"><?= (int)$rentCount ?></div>
        </div>
    </div>

    <div class="tabs">
        <a href="<?= e(buildUrl('all', $search)) ?>" class="<?= $tab === 'all' ? 'active' : '' ?>">📊 الكل</a>
        <a href="<?= e(buildUrl('events', $search)) ?>" class="<?= $tab === 'events' ? 'active' : '' ?>">📅 الفعاليات</a>
        <a href="<?= e(buildUrl('rents', $search)) ?>" class="<?= $tab === 'rents' ? 'active' : '' ?>">🏢 الإيجارات</a>
        <a href="<?= e(buildUrl('discounts', $search)) ?>" class="<?= $tab === 'discounts' ? 'active' : '' ?>">💸 الخصومات</a>
    </div>

    <form class="top-bar" method="GET">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">

        <input type="text"
               name="search"
               id="searchInput"
               placeholder="🔍 بحث باسم المورد"
               value="<?= e($search) ?>"
               oninput="liveSearch()">

        <select name="filter" onchange="this.form.submit()">
            <option value="">كل الفلاتر</option>

            <?php if($tab === 'all'): ?>
                <option value="annual" <?= $filter === 'annual' ? 'selected' : '' ?>>عقد سنوي</option>
                <option value="rent" <?= $filter === 'rent' ? 'selected' : '' ?>>عقد إيجار</option>
            <?php endif; ?>

            <?php if($tab === 'events'): ?>
                <?php foreach($eventOptions as $eventName): ?>
                    <option value="<?= e($eventName) ?>" <?= $filter === $eventName ? 'selected' : '' ?>>
                        <?= e($eventName) ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if($tab === 'rents'): ?>
                <?php foreach($rentOptions as $rentOption): ?>
                    <option value="<?= e($rentOption['branch']) ?>" <?= $filter === $rentOption['branch'] ? 'selected' : '' ?>>
                        <?= e($rentOption['branch']) ?> (<?= (int)$rentOption['c'] ?>)
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if($tab === 'discounts'): ?>
                <option value="invoice" <?= $filter === 'invoice' ? 'selected' : '' ?>>خصم فاتورة</option>
                <option value="payment" <?= $filter === 'payment' ? 'selected' : '' ?>>خصم سداد</option>
                <option value="quarter" <?= $filter === 'quarter' ? 'selected' : '' ?>>خصم 3 شهور</option>
                <option value="annualdisc" <?= $filter === 'annualdisc' ? 'selected' : '' ?>>خصم سنوي</option>
            <?php endif; ?>

        </select>
    </form>

    <?php if(empty($contracts)): ?>

        <div class="empty">لا توجد بيانات مطابقة للفلاتر الحالية</div>

    <?php else: ?>

        <div class="cards">

            <?php foreach($contracts as $r): ?>
                <?php
                [$type, $type_text, $type_icon] = contractType($r);

                $events_count = (int)($r['events_count'] ?? 0);
                $rents_count  = (int)($r['rents_count'] ?? 0);

                $has_invoice = (float)($r['discount_invoice'] ?? 0) > 0;
                $has_payment = (float)($r['discount_payment'] ?? 0) > 0;
                $has_quarter = (float)($r['discount_quarter'] ?? 0) > 0;
                $has_any_discount = $has_invoice || $has_payment || $has_quarter;

                if($type === "annual"){
                    $dateText = "من " . ($r['start_date'] ?: '-') . " إلى " . ($r['end_date'] ?: '-');
                }else{
                    $m = !empty($r['rent_start']) ? date("m", strtotime($r['rent_start'])) : '';
                    $dateText = $months[$m] ?? "-";
                }
                ?>

                <div class="card">

                    <div class="card-header">
                        <div class="header-type <?= e($type) ?>">
                            <?= e($type_icon . ' ' . $type_text) ?>
                        </div>

                        <div class="header-name">
                            <?= e($r['supplier_name'] ?? '-') ?>
                        </div>
                    </div>

                    <div class="card-body">

                        <?php if($tab === 'events'): ?>

                            <div class="events-box">
                                <?php
                                $eventNames = array_filter(array_map('trim', explode(',', (string)($r['event_names'] ?? ''))));

                                if(empty($eventNames)){
                                    echo "<span class='event-tag'>لا توجد فعاليات</span>";
                                }else{
                                    foreach($eventNames as $ev){
                                        echo "<span class='event-tag'>" . e($ev) . "</span>";
                                    }
                                }
                                ?>
                            </div>

                        <?php elseif($tab === 'rents'): ?>

                            <?php
                            $stmtRents = $conn->prepare("SELECT branch, price FROM rents WHERE contract_id = ? ORDER BY branch ASC");
                            $cid = (int)$r['id'];
                            $stmtRents->bind_param("i", $cid);
                            $stmtRents->execute();
                            $rr = $stmtRents->get_result();
                            ?>

                            <?php if($rr->num_rows === 0): ?>
                                <div class="row"><span>الإيجارات</span><span>-</span></div>
                            <?php else: ?>
                                <?php while($x = $rr->fetch_assoc()): ?>
                                    <div class="row rent-line">
                                        <span class="rent-branch"><?= e($x['branch'] ?? '-') ?></span>
                                        <span class="rent-price"><?= e($x['price'] ?? '0') ?> ريال</span>
                                    </div>
                                <?php endwhile; ?>
                            <?php endif; ?>

                            <?php $stmtRents->close(); ?>

                        <?php elseif($tab === 'discounts'): ?>

                            <?php if($has_invoice): ?>
                                <div class="row"><span>خصم فاتورة</span><span><?= e($r['discount_invoice']) ?>%</span></div>
                            <?php endif; ?>

                            <?php if($has_payment): ?>
                                <div class="row"><span>خصم سداد</span><span><?= e($r['discount_payment']) ?>%</span></div>
                            <?php endif; ?>

                            <?php if($has_quarter): ?>
                                <div class="row"><span>خصم 3 شهور</span><span><?= e($r['discount_quarter']) ?>%</span></div>
                            <?php endif; ?>

                            <?php if(!$has_any_discount): ?>
                                <div class="row"><span>الخصومات</span><span>-</span></div>
                            <?php endif; ?>

                        <?php else: ?>

                            <div class="row">
                                <span>خصم تجاري</span>
                                <?= yesNoBadge($has_any_discount) ?>
                            </div>

                            <div class="row">
                                <span>الفعاليات</span>
                                <?= yesNoBadge($events_count > 0) ?>
                            </div>

                            <div class="row">
                                <span>الإيجار</span>
                                <?= yesNoBadge($rents_count > 0) ?>
                            </div>

                            <div class="row">
                                <span>تاريخ العقد</span>
                                <span><?= e($dateText) ?></span>
                            </div>

                            <?php if(!empty($r['payment_period']) && (int)$r['payment_period'] > 0): ?>
                                <div class="row">
                                    <span>فترة السداد</span>
                                    <span><?= (int)$r['payment_period'] ?> يوم</span>
                                </div>
                            <?php endif; ?>

                        <?php endif; ?>

                    </div>

                    <div class="card-footer">
                        <a class="view" href="view_contract.php?id=<?= (int)$r['id'] ?>">عرض</a>
                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<script>
let typingTimer;

function liveSearch(){
    clearTimeout(typingTimer);

    typingTimer = setTimeout(() => {
        document.querySelector(".top-bar").submit();
    }, 450);
}

document.addEventListener("keydown", function(e){
    if(e.key === "Escape"){
        let input = document.getElementById("searchInput");

        if(input){
            input.value = "";
            document.querySelector(".top-bar").submit();
        }
    }
});
</script>

</body>
</html>
