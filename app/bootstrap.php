<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');

require_once __DIR__ . '/lib/Core.php';
require_once __DIR__ . '/lib/Html.php';
require_once __DIR__ . '/lib/Ftp.php';
require_once __DIR__ . '/lib/Sync.php';
require_once __DIR__ . '/lib/Components.php';
require_once __DIR__ . '/lib/Languages.php';
require_once __DIR__ . '/lib/Validator.php';
require_once __DIR__ . '/lib/Publish.php';
require_once __DIR__ . '/lib/Responsive.php';
require_once __DIR__ . '/lib/Supabase.php';
require_once __DIR__ . '/lib/Media.php';
require_once __DIR__ . '/lib/Api.php';

Store::init();
Auth::startSession();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
