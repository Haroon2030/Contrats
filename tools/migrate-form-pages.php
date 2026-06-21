<?php
require dirname(__DIR__) . '/app/bootstrap.php';
require VC_HELPERS . '/page_assets_helper.php';

$replacement = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">' . "\n"
    . '<?php vcRenderPageAssets([\'forms\' => true]); ?>';

foreach (['add_contract.php', 'rents.php'] as $file) {
    $path = VC_CONTROLLERS . '/' . $file;
    $content = file_get_contents($path);
    $start = strpos($content, '<link href="https://fonts.googleapis.com/css2?family=Cairo');
    $styleEnd = strpos($content, '</style>', $start !== false ? $start : 0);
    if ($start === false || $styleEnd === false) {
        echo "FAIL {$file}\n";
        continue;
    }
    $new = substr($content, 0, $start) . $replacement . substr($content, $styleEnd + strlen('</style>'));
    file_put_contents($path, $new);
    echo "Updated {$file}\n";
}
