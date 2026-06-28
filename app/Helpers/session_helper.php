<?php

declare(strict_types=1);

if (!function_exists('vcConfigureSessionCookie')) {
    function vcConfigureSessionCookie(): void
    {
        static $configured = false;

        if ($configured) {
            return;
        }

        $configured = true;

        $secure = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        );

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
        }
    }
}

if (!function_exists('vcEnsureSession')) {
    function vcEnsureSession(): void
    {
        vcConfigureSessionCookie();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
