<?php

final class Router
{
    /** @var string[] */
    private static array $routes = [];

    public static function boot(): void
    {
        if (self::$routes !== []) {
            return;
        }

        $routesFile = VC_APP . '/routes.php';
        self::$routes = is_file($routesFile) ? (array) require $routesFile : [];
    }

    public static function resolvePage(): string
    {
        $page = $_GET['page'] ?? '';
        $page = self::sanitize((string) $page);
        if ($page !== '') {
            return $page;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (preg_match('#/([A-Za-z0-9_]+)\.php(?:\?|$)#', $uri, $matches)) {
            return self::sanitize($matches[1]);
        }

        return 'login';
    }

    public static function isAllowed(string $page): bool
    {
        self::boot();

        return $page !== '' && in_array($page, self::$routes, true);
    }

    public static function url(string $page, array $query = []): string
    {
        $page = self::sanitize($page);
        $url = $page . '.php';
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    public static function notFound(string $page = ''): never
    {
        http_response_code(404);
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>404</title></head><body>';
        echo '<h1>الصفحة غير موجودة</h1>';
        if ($page !== '') {
            echo '<p>' . htmlspecialchars($page, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '<p><a href="login.php">العودة لتسجيل الدخول</a></p>';
        echo '</body></html>';
        exit;
    }

    private static function sanitize(string $page): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $page) ?? '';
    }
}
