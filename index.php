<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$page = Router::resolvePage();

if (!Router::isAllowed($page)) {
    Router::notFound($page);
}

$controller = VC_CONTROLLERS . '/' . $page . '.php';
if (!is_file($controller)) {
    Router::notFound($page);
}

if (!defined('VC_PAGE')) {
    define('VC_PAGE', $page);
}

require $controller;
