<?php

if (!defined('VC_ROOT')) {
    define('VC_ROOT', dirname(__DIR__));
    define('VC_APP', VC_ROOT . '/app');
    define('VC_CONFIG', VC_ROOT . '/config');
    define('VC_PUBLIC', VC_ROOT . '/public');
    define('VC_VIEWS', VC_APP . '/Views');
    define('VC_HELPERS', VC_APP . '/Helpers');
    define('VC_CONTROLLERS', VC_APP . '/Controllers');
}

require_once VC_CONFIG . '/config.php';
require_once VC_APP . '/Core/Router.php';
require_once VC_HELPERS . '/pagination_helper.php';
require_once VC_HELPERS . '/contract_helper.php';
require_once VC_HELPERS . '/header_menu_helper.php';
require_once VC_HELPERS . '/table_actions_helper.php';
require_once VC_HELPERS . '/page_assets_helper.php';

if (!function_exists('vc_asset')) {
    function vc_asset(string $path): string
    {
        return 'public/assets/' . ltrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('vc_url')) {
    function vc_url(string $page, array $query = []): string
    {
        return Router::url($page, $query);
    }
}

