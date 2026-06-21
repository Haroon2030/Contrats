<?php

declare(strict_types=1);

/**
 * One-time migration: extract inline CSS to shared files and update controllers.
 * Run: C:\xampp\htdocs\php\php.exe tools/migrate-vc-styles.php
 */

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
require_once VC_HELPERS . '/page_assets_helper.php';

$listCssPath = VC_PUBLIC . '/assets/css/vc-list-pages.css';
$formsCssPath = VC_PUBLIC . '/assets/css/vc-forms.css';

function extractStyleBlock(string $content): ?string
{
    if (!preg_match('/<style>(.*?)<\/style>/s', $content, $m)) {
        return null;
    }

    return trim($m[1]);
}

function replaceStyleWithAssets(string $content, string $replacement): ?string
{
    $patterns = [
        '#<link rel="stylesheet" href="public/assets/css/style\.css">\s*<link href="https://fonts\.googleapis\.com/css2\?family=Cairo[^"]+" rel="stylesheet">\s*<style>.*?</style>#s',
        '#<link href="https://fonts\.googleapis\.com/css2\?family=Cairo[^"]+" rel="stylesheet">\s*<link rel="stylesheet" href="https://cdn\.jsdelivr\.net/npm/flatpickr[^"]+" rel="stylesheet">\s*<style>.*?</style>#s',
    ];

    foreach ($patterns as $pattern) {
        $new = preg_replace($pattern, $replacement, $content, 1, $count);
        if ($count > 0) {
            return $new;
        }
    }

    return null;
}

// Base list CSS from contracts.php
$contracts = file_get_contents(VC_CONTROLLERS . '/contracts.php');
$baseCss = extractStyleBlock($contracts);
if ($baseCss === null) {
    fwrite(STDERR, "Could not extract contracts CSS\n");
    exit(1);
}

$extensions = <<<'CSS'

/* ——— Shared extensions ——— */
.title{margin:0;font-size:25px;font-weight:900;color:#172033;}
.subtitle{margin-top:5px;font-size:13px;color:#667085;font-weight:700;}
.alert{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:800;box-shadow:0 10px 24px rgba(23,32,51,.06);}
.alert-success{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0;}
.alert-error{background:#fff1f2;color:#b42318;border:1px solid #fecdd3;}
.card{background:rgba(255,255,255,.68);border:1px solid rgba(226,232,240,.95);border-radius:20px;padding:16px;box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;text-align:center;}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:18px;}
.search-box{background:rgba(255,255,255,.68);border:1px solid rgba(226,232,240,.95);border-radius:22px;padding:14px;box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;display:grid;grid-template-columns:1fr 180px 180px 140px;gap:12px;margin-bottom:18px;align-items:center;}
.table-wrap{background:rgba(255,255,255,.62);border:1px solid rgba(226,232,240,.95);border-radius:22px;padding:14px;box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;overflow-x:auto;}
.col-actions{width:120px;}
.btn-view,.vc-act-view{background:#6d4aff !important;}
.btn.primary,.btn-primary{background:#6d4aff;color:#fff;}
.btn.muted,.btn-muted{background:#eef1f7;color:#475569;border:1px solid #dfe6f0;}
.btn.print{background:#0ea5e9;color:#fff;}
.btn.danger,.btn.reject{background:#ef4444;color:#fff;}
.btn.approve{background:#16a34a;color:#fff;}
.table tr.row-rejected td{background:#fff7f7;}
.table tr.row-approved td{background:#f7fff9;}
.badge.pending{background:#fffbeb;color:#92400e;}
.badge.paid{background:#eff6ff;color:#1d4ed8;}
.badge.cancelled{background:#f1f5f9;color:#475569;}
.due-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:7px 11px;font-weight:900;font-size:12px;line-height:1.4;}
.due-passed{background:#ecfdf3!important;color:#166534!important;border:1px solid #86efac!important;}
.due-future{background:#fff1f2!important;color:#b42318!important;border:1px solid #fca5a5!important;}
.due-empty{background:#f1f5f9!important;color:#475569!important;border:1px solid #e2e8f0!important;}
.view-card{background:rgba(255,255,255,.78);border-radius:22px;padding:18px;margin-bottom:16px;border:1px solid rgba(226,232,240,.95);box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;}
.view-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;border-bottom:1px solid #e2e8f0;padding-bottom:14px;margin-bottom:14px;}
.view-title{font-size:22px;font-weight:900;}
.meta{font-size:12px;color:#64748b;font-weight:900;margin-top:5px;}
.grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;}
.two-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:10px 0;}
.info{background:#f8fafc;border:1px solid #e2e8f0;border-radius:15px;padding:11px;}
.info span{display:block;font-size:11px;color:#64748b;font-weight:900;margin-bottom:6px;}
.info b{display:block;font-size:14px;color:#172033;line-height:1.6;}
.page-head--split{display:flex;align-items:center;justify-content:space-between;gap:14px;text-align:right;}
.container--wide{width:min(100%,calc(100% - 20px));max-width:none;margin:16px auto 32px;}

CSS;

// Drafts-specific overrides
$drafts = file_get_contents(VC_CONTROLLERS . '/drafts.php');
$draftsCss = extractStyleBlock($drafts);
$draftsOnlyPath = VC_PUBLIC . '/assets/css/vc-drafts.css';
if ($draftsCss !== null && preg_match('/(\.draft-rows-notice[\s\S]*)$/', $draftsCss, $dm)) {
    file_put_contents($draftsOnlyPath, "/* Drafts table extensions */\n" . trim($dm[1]));
    echo "Wrote {$draftsOnlyPath}\n";
}

file_put_contents($listCssPath, "/* VendorCore list pages — generated from contracts.php reference */\n" . $baseCss . $extensions);

// Forms CSS from add_contract.php
$addContract = file_get_contents(VC_CONTROLLERS . '/add_contract.php');
$formsCss = extractStyleBlock($addContract);
if ($formsCss === null) {
    fwrite(STDERR, "Could not extract add_contract CSS\n");
    exit(1);
}
file_put_contents($formsCssPath, "/* VendorCore forms — generated from add_contract.php reference */\n" . $formsCss);

echo "Wrote {$listCssPath}\n";
echo "Wrote {$formsCssPath}\n";

$listPages = [
    'contracts.php',
    'my_contracts.php',
    'drafts.php',
    'under_review.php',
    'admin_review.php',
    'my_items.php',
    'under_review_items.php',
    'data_entry_items.php',
    'finance_items.php',
    'items_admin.php',
    'users.php',
    'view_items.php',
];

$listReplacementDefault = "<?php vcRenderPageAssets(); ?>";
$listReplacementDrafts = "<?php vcRenderPageAssets(['extra' => ['vc-drafts.css']]); ?>";
$formReplacement = "<link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css\">\n<?php vcRenderPageAssets(['forms' => true]); ?>";

foreach ($listPages as $file) {
    $path = VC_CONTROLLERS . '/' . $file;
    $content = file_get_contents($path);
    $replacement = $file === 'drafts.php' ? $listReplacementDrafts : $listReplacementDefault;
    $new = replaceStyleWithAssets($content, $replacement);
    if ($new !== null) {
        file_put_contents($path, $new);
        echo "Updated list page: {$file}\n";
    } else {
        echo "SKIP list page: {$file}\n";
    }
}

foreach (['add_contract.php', 'rents.php', 'add_items.php'] as $file) {
    $path = VC_CONTROLLERS . '/' . $file;
    $content = file_get_contents($path);
    $new = replaceStyleWithAssets($content, $formReplacement);
    if ($new !== null) {
        file_put_contents($path, $new);
        echo "Updated form page: {$file}\n";
    } else {
        echo "SKIP form page: {$file}\n";
    }
}

// payment_approvals: replace minified style block
$paymentCss = <<<'CSS'
/* Payment approvals view/detail */
.amount-highlight{margin:12px 0;background:#f5f3ff;border:2px solid #8b5cf6;border-radius:16px;padding:14px;text-align:center;}
.amount-highlight span{display:block;font-size:12px;color:#4c1d95;font-weight:900;margin-bottom:6px;}
.amount-highlight b{display:block;font-size:24px;color:#4f46e5;font-weight:900;}
.balances-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:10px 0;}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px;}
.step{background:#f8fafc;border:1px solid #e2e8f0;border-radius:15px;padding:11px;}
.step h4{margin:0 0 8px;font-size:14px;font-weight:900;color:#4f46e5;}
.step p{margin:4px 0;color:#475569;font-weight:800;font-size:12px;line-height:1.7;}
.note-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:10px;margin-top:10px;color:#9a3412;font-weight:900;line-height:1.8;}
.action-box{margin-top:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:12px;}
.action-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;align-items:end;}
.action-buttons{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
.rejected-status-banner{margin-top:14px;background:#fff1f2;border:2px solid #ef4444;color:#991b1b;border-radius:16px;padding:18px 20px;text-align:center;font-weight:900;font-size:18px;}
.discount-preview{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:10px;}
@media(max-width:900px){.grid{grid-template-columns:1fr 1fr}.two-grid,.balances-grid,.steps,.action-row,.discount-preview{grid-template-columns:1fr}}
@media(max-width:560px){.grid{grid-template-columns:1fr}.view-head{flex-direction:column}}
CSS;
file_put_contents(VC_PUBLIC . '/assets/css/vc-payment.css', $paymentCss);

$paPath = VC_CONTROLLERS . '/payment_approvals.php';
$pa = file_get_contents($paPath);
$paNew = preg_replace(
    '#<style>.*?</style>#s',
    "<?php vcRenderPageAssets(['extra' => ['vc-payment.css']]); ?>",
    $pa,
    1,
    $paCount
);
if ($paCount > 0) {
    $paNew = str_replace('class="table-wrap"', 'class="table-box"', $paNew);
    file_put_contents($paPath, $paNew);
    echo "Updated payment_approvals.php\n";
}

echo "Done.\n";
