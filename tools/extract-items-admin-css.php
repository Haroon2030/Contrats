<?php
$diff = file_get_contents(getenv('TEMP') . '/ia.diff');
$lines = explode("\n", $diff);
$css = [];
$inStyle = false;
foreach ($lines as $line) {
    if (str_starts_with($line, '-<style>')) {
        $inStyle = true;
        continue;
    }
    if ($inStyle && str_starts_with($line, '-</style>')) {
        break;
    }
    if ($inStyle && str_starts_with($line, '-')) {
        $css[] = substr($line, 1);
    }
}
$full = implode("\n", $css);
if (!preg_match('/(\.summary-grid[\s\S]*)/s', $full, $m)) {
    fwrite(STDERR, "No summary-grid in diff\n");
    exit(1);
}
$extra = $m[1];
$path = dirname(__DIR__) . '/public/assets/css/vc-items-admin.css';
file_put_contents($path, "/* Items admin extensions */\n" . trim($extra) . "\n");
echo "Wrote {$path}, bytes=" . strlen($extra) . "\n";

$iaPath = dirname(__DIR__) . '/app/Controllers/items_admin.php';
$ia = file_get_contents($iaPath);
$ia = str_replace(
    "<?php vcRenderPageAssets(); ?>",
    "<?php vcRenderPageAssets(['extra' => ['vc-items-admin.css']]); ?>",
    $ia
);
file_put_contents($iaPath, $ia);
echo "Updated items_admin.php\n";
