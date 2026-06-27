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

if (!function_exists('vcRenderModalAssets')) {
    function vcRenderModalAssets(): void
    {
        $version = '3';
        echo '<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">' . "\n";
        echo '<link rel="stylesheet" href="' . vc_asset('css/vc-modal.css') . '?v=' . $version . '">' . "\n";
        echo '<script src="' . vc_asset('js/vc-modal.js') . '?v=' . $version . '" defer></script>' . "\n";
    }
}

if (!function_exists('vcIsEmbedRequest')) {
    function vcIsEmbedRequest(): bool
    {
        return isset($_GET['embed']) && (string) $_GET['embed'] === '1';
    }
}

if (!function_exists('vcRedirectUrl')) {
    function vcRedirectUrl(string $url): string
    {
        if (!vcIsEmbedRequest()) {
            return $url;
        }
        if (str_contains($url, 'embed=1')) {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'embed=1';
    }
}

if (!function_exists('vcRenderEmbedShell')) {
    function vcRenderEmbedShell(): void
    {
        if (!vcIsEmbedRequest()) {
            return;
        }
        $version = '4';
        echo '<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">' . "\n";
        echo '<link rel="stylesheet" href="' . vc_asset('css/vc-modal-embed.css') . '?v=' . $version . '">' . "\n";
    }
}

if (!function_exists('vcSiteLogoUrl')) {
    function vcSiteLogoUrl(): string
    {
        return vc_asset('images/site-logo.svg') . '?v=1';
    }
}

if (!function_exists('vcRenderSiteFavicon')) {
    function vcRenderSiteFavicon(): void
    {
        $logo = htmlspecialchars(vcSiteLogoUrl(), ENT_QUOTES, 'UTF-8');
        echo '<link rel="icon" href="' . $logo . '" type="image/svg+xml">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . $logo . '">' . "\n";
    }
}
