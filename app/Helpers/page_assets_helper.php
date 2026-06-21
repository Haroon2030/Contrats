<?php

declare(strict_types=1);

if (!function_exists('vcRenderPageAssets')) {
    /**
     * @param array{forms?: bool, extra?: string[]} $options
     */
    function vcRenderPageAssets(array $options = []): void
    {
        $version = '2';
        $forms = !empty($options['forms']);
        $extra = (array) ($options['extra'] ?? []);

        echo '<link rel="stylesheet" href="' . vc_asset('css/style.css') . '?v=' . $version . '">' . "\n";
        echo '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">' . "\n";
        echo '<link rel="stylesheet" href="' . vc_asset('css/vc-list-pages.css') . '?v=' . $version . '">' . "\n";

        if ($forms) {
            echo '<link rel="stylesheet" href="' . vc_asset('css/vc-forms.css') . '?v=' . $version . '">' . "\n";
        }

        foreach ($extra as $file) {
            $file = ltrim(str_replace('\\', '/', (string) $file), '/');
            if ($file === '' || str_contains($file, '..')) {
                continue;
            }
            echo '<link rel="stylesheet" href="' . vc_asset('css/' . $file) . '?v=' . $version . '">' . "\n";
        }
    }
}
