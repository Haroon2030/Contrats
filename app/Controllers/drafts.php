<?php
require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';



date_default_timezone_set('Asia/Riyadh');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reminderText(int $daysLeft): string {
    if ($daysLeft > 2) {
        return "متبقي {$daysLeft} أيام";
    }

    if ($daysLeft === 2) {
        return "باقى يومين";
    }

    if ($daysLeft === 1) {
        return "باقى يوم واحد";
    }

    if ($daysLeft === 0) {
        return "اليوم آخر مهلة";
    }

    return "متأخر " . abs($daysLeft) . " يوم";
}

function reminderClass(int $daysLeft): string {
    if ($daysLeft === 2) {
        return "warn-2";
    }

    if ($daysLeft === 1) {
        return "warn-1";
    }

    if ($daysLeft <= 0) {
        return "warn-now";
    }

    return "";
}

function defaultReminderDate(?string $createdAt, ?string $reminderDate): string {
    if (!empty($reminderDate) && $reminderDate !== '0000-00-00') {
        return date("Y-m-d", strtotime($reminderDate));
    }

    if (!empty($createdAt)) {
        return date("Y-m-d", strtotime($createdAt . " +4 days"));
    }

    return date("Y-m-d", strtotime("+4 days"));
}


function vcDraftColumnExists(VcDb $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->prepare("\n            SELECT COUNT(*) AS c\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n              AND COLUMN_NAME = ?\n        ");
        if (!$stmt) return false;
        $stmt->bind_param("ss", $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function vcDisabledDraftHookSetup(VcDb $conn): void {
    return;
}

function vcDraftGetUserJobRole(VcDb $conn, int $userId): string {
    if ($userId <= 0 || !vcDraftColumnExists($conn, 'users', 'job_role')) return 'user';
    $stmt = $conn->prepare("SELECT job_role FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return 'user';
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string)($row['job_role'] ?? 'user');
}

function vcDraftGetDirectSectionManagerId(VcDb $conn, int $ownerId): int {
    if ($ownerId <= 0 || !vcDraftColumnExists($conn, 'users', 'manager_id')) return 0;

    $stmt = $conn->prepare("SELECT manager_id FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $managerId = (int)($row['manager_id'] ?? 0);
    if ($managerId <= 0 || $managerId === $ownerId) return 0;

    $managerRole = vcDraftGetUserJobRole($conn, $managerId);
    if ($managerRole !== 'section_manager') {
        return 0;
    }

    return $managerId;
}

function vcDisabledDraftHookOnce(VcDb $conn, int $recipientId, int $contractId, string $title, string $message, string $link): void {
    return;
}

function vcDisabledDraftDeadlineHooks(VcDb $conn, array $row, int $daysLeft): void {
    return;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    header("Location: login.php");
    exit();
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


$stmt = $conn->prepare("SELECT is_admin, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$job_role = (string)($user['job_role'] ?? 'user');
$is_admin = !empty($user) && (
    (int)($user['is_admin'] ?? 0) === 1
    || ($user['role'] ?? '') === 'admin'
    || $job_role === 'admin'
    || $job_role === 'commercial_manager'
);
$can_delete_admin = vcUserCanDeleteAsAdmin($user);
$is_section_manager = ($job_role === 'section_manager');
$is_normal_user = ($job_role === 'user');
$show_deadline_alerts = ($is_normal_user || $is_section_manager);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_reminder') {

    header("Content-Type: application/json; charset=UTF-8");

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "طلب غير صالح"
        ]);
        exit();
    }

    $contract_id = (int)($_POST['id'] ?? 0);
    $date = trim($_POST['date'] ?? '');

    if ($contract_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "بيانات غير صحيحة"
        ]);
        exit();
    }

    if ($is_admin) {
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET reminder_date = ?
            WHERE id = ? AND status = 'draft'
            LIMIT 1
        ");
        $stmt->bind_param("si", $date, $contract_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET reminder_date = ?
            WHERE id = ? AND created_by = ? AND status = 'draft'
            LIMIT 1
        ");
        $stmt->bind_param("sii", $date, $contract_id, $user_id);
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        echo json_encode([
            "success" => false,
            "message" => "لم يتم حفظ التذكير"
        ]);
        exit();
    }

    echo json_encode([
        "success" => true,
        "message" => "تم حفظ التذكير"
    ]);
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && (
    (($_POST['action'] ?? '') === 'delete_draft') || isset($_POST['delete_id'])
)) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    if (!$can_delete_admin) {
        http_response_code(403);
        die("❌ ليس لديك صلاحية حذف المسودات");
    }

    $delete_id = (int)($_POST['delete_id'] ?? 0);

    if ($delete_id > 0) {
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET status = 'deleted' 
            WHERE id = ? AND status = 'draft'
            LIMIT 1
        ");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: drafts.php?deleted=1");
    exit();
}


$search = trim($_GET['search'] ?? '');
$user_filter = trim($_GET['user'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$page_name = 'drafts';


$scope = 'own';

$stmt = $conn->prepare("
    SELECT up.scope 
    FROM user_permissions up
    JOIN pages pg ON pg.id = up.page_id
    WHERE up.user_id = ? AND pg.name = ?
    LIMIT 1
");
$stmt->bind_param("is", $user_id, $page_name);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows > 0) {
    $perm = $res->fetch_assoc();
    $scope = $perm['scope'] ?? 'own';
}
$stmt->close();

$scope = in_array($scope, ['own','team','all'], true) ? $scope : 'own';
$scopedUserIds = vcGetScopedUserIds($conn, $user_id, $scope, $is_admin);


$show_user_column = ($is_admin || in_array($scope, ['team','all'], true));


$can_view_all_drafts = empty($scopedUserIds);
if (!$show_user_column || ($user_filter !== '' && !vcIsUserInScope((int)$user_filter, $scopedUserIds))) {
    $user_filter = '';
}


$fromWhere = "
    FROM contracts
    LEFT JOIN users ON users.id = contracts.created_by
    WHERE contracts.status IN ('draft', 'review')
";

$params = [];
$types = "";

$fromWhere .= vcBuildInCondition('contracts.created_by', $scopedUserIds, $params, $types);
if ($show_user_column && $user_filter !== '') {
    $fromWhere .= " AND contracts.created_by = ?";
    $params[] = (int)$user_filter;
    $types .= "i";
}

if ($status_filter === 'new') {
    $fromWhere .= " AND contracts.last_edited_by IS NULL";
}

if ($status_filter === 'edited') {
    $fromWhere .= " AND contracts.last_edited_by IS NOT NULL";
}

if ($search !== '') {
    $fromWhere .= " AND contracts.supplier_name LIKE ?";
    $params[] = "%{$search}%";
    $types .= "s";
}

$pg = vcPaginationState();
$totalRows = vcPaginationCount($conn, $fromWhere, $params, $types);
$totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
$paginationPage = min($pg['page'], $totalPages);

$sql = "
    SELECT contracts.*, users.username
    {$fromWhere}
    ORDER BY contracts.id DESC
    LIMIT ? OFFSET ?
";

[$dataParams, $dataTypes] = vcPaginationBindLimit($params, $types, $pg['limit'], ($paginationPage - 1) * $pg['per_page']);

$stmt = $conn->prepare($sql);

if (!empty($dataParams)) {
    $stmt->bind_param($dataTypes, ...$dataParams);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
$draftRowsNotice = [];
$todayObj = new DateTime(date("Y-m-d"));

while ($row = $result->fetch_assoc()) {

    $reminder = defaultReminderDate($row['created_at'] ?? '', $row['reminder_date'] ?? '');
    $reminderObj = new DateTime($reminder);
    $daysLeft = (int)$todayObj->diff($reminderObj)->format("%r%a");

    $row['_reminder'] = $reminder;
    $row['_days_left'] = $daysLeft;
    $row['_warning_class'] = reminderClass($daysLeft);
    $row['_reminder_text'] = reminderText($daysLeft);

    
    $rowOwnerId = (int)($row['created_by'] ?? 0);
    $rowInsideCurrentScope = empty($scopedUserIds) || in_array($rowOwnerId, $scopedUserIds, true);

    if ($show_deadline_alerts && $daysLeft <= 2) {
        if (($is_normal_user && $rowOwnerId === $user_id) || ($is_section_manager && $rowInsideCurrentScope)) {
            $draftRowsNotice[] = $row;
        }
    }

    
    vcDisabledDraftDeadlineHooks($conn, $row, $daysLeft);

    $rows[] = $row;
}
$stmt->close();


$users_result = $show_user_column ? vcGetVisibleUsersForFilter($conn, $scopedUserIds) : [];

function statusBadge($isEdited, string $contractStatus = 'draft'): string {
    if ($contractStatus === 'review') {
        return '<span class="badge badge-review">تحت المراجعة</span>';
    }

    if ($isEdited) {
        return '<span class="badge badge-edited">معدل من الإدارة</span>';
    }

    return '<span class="badge badge-new">تفاوض جديد</span>';
}

$colspan = 5 + ($is_admin ? 1 : 0) + ($show_user_column ? 1 : 0);
$colsContract = 2 + ($is_admin ? 1 : 0) + ($show_user_column ? 1 : 0);
$colsDates = 3;
$colsTrack = 2;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>مسودات العقود</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<?php vcRenderPageAssets(['extra' => ['vc-drafts.css']]); ?>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container container--wide">

    <div class="page-head">
        <div>
            <h1 class="title">📄 مسودات العقود</h1>
            <div class="subtitle">متابعة عقود التفاوض والعقود تحت المراجعة حسب الصلاحية</div>
        </div>
    </div>

    <?php if(isset($_GET['deleted'])): ?>
        <div class="alert alert-success">تم حذف المسودة بنجاح ✅</div>
    <?php endif; ?>

    <?php if(!empty($draftRowsNotice)): ?>
        <div class="draft-rows-notice">
            <?php foreach($draftRowsNotice as $n): ?>
                <div class="notify-card <?= e($n['_warning_class']) ?>">
                    <div>
                        <div class="notify-title">
                            العقد رقم #<?= (int)$n['id'] ?> - <?= e($n['supplier_name'] ?? '-') ?>
                        </div>
                        <div class="notify-sub">
                            مهلة الرد: <?= e($n['_reminder']) ?>
                        </div>
                    </div>

                    <div class="notify-tag">
                        <?= e($n['_reminder_text']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="search-box">

        <input type="text"
               id="searchInput"
               placeholder="🔍 بحث باسم المورد..."
               value="<?= e($search) ?>">

        <select id="statusFilter" onchange="applyFilters()">
            <option value="">الحالة</option>
            <option value="new" <?= ($status_filter === 'new') ? 'selected' : '' ?>>تفاوض جديد</option>
            <option value="edited" <?= ($status_filter === 'edited') ? 'selected' : '' ?>>معدل من الإدارة</option>
        </select>

        <?php if($show_user_column): ?>
            <select id="userFilter" onchange="applyFilters()">
                <option value="">بواسطة: كل الفريق</option>
                <?php foreach($users_result as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= ((string)$user_filter === (string)$u['id']) ? 'selected' : '' ?>>
                        <?= e($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

    </div>

    <div class="table-box">

        <div class="drafts-table-meta">
            <div class="drafts-table-meta-title">قائمة المسودات — <?= (int)$totalRows ?> سجل</div>
            <div class="drafts-table-meta-hint">
                ترتيب حسب الأحدث
                <span><i class="urgency-dot warn-now"></i> عاجل</span>
                <span><i class="urgency-dot warn-1"></i> قريب</span>
                <span><i class="urgency-dot warn-2"></i> تنبيه</span>
            </div>
        </div>

        <div class="drafts-table-wrap">

        <table class="table drafts-table">

            <thead>
                <tr class="thead-groups">
                    <th colspan="<?= (int)$colsContract ?>" class="th-group th-group--contract">بيانات العقد</th>
                    <th colspan="<?= (int)$colsDates ?>" class="th-group th-group--dates">المواعيد والمتابعة</th>
                    <th colspan="<?= (int)$colsTrack ?>" class="th-group">الحالة والإجراءات</th>
                </tr>
                <tr class="thead-cols">
                    <th class="col-id cell-contract">رقم</th>
                    <th class="col-supplier cell-contract">المورد</th>

                    <?php if($is_admin): ?>
                        <th class="col-manager cell-contract">المسؤول</th>
                    <?php endif; ?>

                    <?php if($show_user_column): ?>
                        <th class="col-user cell-contract">بواسطة</th>
                    <?php endif; ?>

                    <th class="col-created cell-dates">تاريخ التفاوض</th>
                    <th class="col-reminder cell-dates">تاريخ التذكير</th>
                    <th class="col-deadline cell-dates">مهلة الرد</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-actions">إجراءات</th>
                </tr>
            </thead>

            <tbody>

            <?php if(!empty($rows)): ?>

                <?php foreach($rows as $row): ?>

                    <?php
                    $created = $row['created_at'] ?? '';
                    $isEdited = !empty($row['last_edited_by']);

                    $edit_link = (($row['source'] ?? '') === 'rent')
                        ? "rents.php?id=" . (int)$row['id']
                        : "add_contract.php?id=" . (int)$row['id'];

                    $view_link = "view_contract.php?id=" . (int)$row['id'];

                    $rowClass = $row['_warning_class'] ?? '';

                    
                    // أي مسودة تظهر في الجدول فالمستخدم لديه صلاحية ضمن نطاقه (own/team/all)
                    $canTakeDraftAction = true;
                    ?>

                    <tr class="draft-row <?= e($rowClass) ?>" data-id="<?= (int)$row['id'] ?>">

                        <td class="cell-contract">
                            <span class="draft-id">#<?= (int)$row['id'] ?></span>
                        </td>

                        <td class="cell-contract supplier-cell">
                            <span class="supplier-name"><?= e($row['supplier_name'] ?? '-') ?></span>
                        </td>

                        <?php if($is_admin): ?>
                            <td class="cell-contract">
                                <span class="meta-chip manager-name"><?= e($row['company_name'] ?? '-') ?></span>
                            </td>
                        <?php endif; ?>

                        <?php if($show_user_column): ?>
                            <td class="cell-contract">
                                <span class="meta-chip user-name"><?= e($row['username'] ?? '-') ?></span>
                            </td>
                        <?php endif; ?>

                        <td class="cell-dates">
                            <span class="date-chip"><?= $created ? e(date("Y-m-d", strtotime($created))) : '-' ?></span>
                        </td>

                        <td class="cell-dates">
                            <?php if($canTakeDraftAction): ?>
                                <input type="date"
                                       class="reminder-input"
                                       value="<?= e($row['_reminder']) ?>"
                                       onchange="updateReminder(<?= (int)$row['id'] ?>, this.value)">
                            <?php else: ?>
                                <span class="date-chip"><?= e($row['_reminder']) ?></span>
                            <?php endif; ?>
                        </td>

                        <td class="cell-dates">
                            <span class="deadline-pill <?= e($rowClass) ?>">
                                <?= e($row['_reminder_text']) ?>
                            </span>
                        </td>

                        <td class="cell-status">
                            <?= statusBadge($isEdited, (string)($row['status'] ?? 'draft')) ?>
                        </td>

                        <td class="cell-actions actions-cell">
                            <?php
                            $draftActions = [
                                'view' => [
                                    'href' => $view_link,
                                ],
                            ];

                            $draftActions['edit'] = ['href' => $edit_link];

                            if ($can_delete_admin) {
                                $draftActions['delete'] = [
                                    'action' => 'delete_draft',
                                    'fields' => ['delete_id' => (string)(int)$row['id']],
                                    'confirm' => 'هل أنت متأكد من حذف هذه المسودة؟',
                                ];
                            }

                            vcRenderRowActions($draftActions, $csrf_token, $can_delete_admin);
                            ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td class="empty" colspan="<?= (int)$colspan + 1 ?>">لا توجد مسودات</td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

        </div>

    </div>

    <?php vcRenderPagination($paginationPage, $totalPages); ?>

</div>

<div id="toast"></div>

<script>
const csrfToken = "<?= e($csrf_token) ?>";

let timer;
const searchInput = document.getElementById("searchInput");

if(searchInput){
    searchInput.addEventListener("keyup", function(){
        clearTimeout(timer);

        let value = this.value;

        timer = setTimeout(function(){
            let url = new URL(window.location.href);

            if(value){
                url.searchParams.set("search", value);
            }else{
                url.searchParams.delete("search");
            }

            url.searchParams.delete("pg");

            window.location.href = url;
        }, 400);
    });
}

function updateReminder(id, date){

    const body = new URLSearchParams();
    body.append("action", "update_reminder");
    body.append("id", id);
    body.append("date", date);
    body.append("csrf_token", csrfToken);

    fetch("drafts.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Requested-With": "XMLHttpRequest"
        },
        body: body.toString()
    })
    .then(res => res.json())
    .then(data => {

        if(data.success){

            showToast("تم حفظ التذكير ✅");

            let row = document.querySelector("tr[data-id='" + id + "']");

            if(row){
                row.style.transition = ".2s";
                row.style.background = "#dcfce7";

                setTimeout(() => {
                    location.reload();
                }, 700);
            }

        }else{
            showToast(data.message || "لم يتم حفظ التذكير", "error");
        }

    })
    .catch(() => {
        showToast("تعذر حفظ التذكير", "error");
    });

}

function showToast(msg, type){
    let t = document.getElementById("toast");
    t.innerText = msg;

    if(type === "error"){
        t.classList.add("error");
    }else{
        t.classList.remove("error");
    }

    t.style.display = "block";

    setTimeout(() => {
        t.style.display = "none";
    }, 2000);
}

function applyFilters(){

    let url = new URL(window.location.href);

    let search = document.getElementById("searchInput")
        ? document.getElementById("searchInput").value
        : "";

    let status = document.getElementById("statusFilter")
        ? document.getElementById("statusFilter").value
        : "";

    let userSelect = document.getElementById("userFilter");
    let user = userSelect ? userSelect.value : "";

    if(search){
        url.searchParams.set("search", search);
    }else{
        url.searchParams.delete("search");
    }

    if(status){
        url.searchParams.set("status", status);
    }else{
        url.searchParams.delete("status");
    }

    if(user){
        url.searchParams.set("user", user);
    }else{
        url.searchParams.delete("user");
    }

    url.searchParams.delete("pg");

    window.location.href = url;
}
</script>

</body>
</html>
