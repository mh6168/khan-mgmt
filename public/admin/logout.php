<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'AdminAuth.php';

AdminAuth::logout();
header('Location: ' . AdminAuth::pageUrl('login.php'), true, 303);
exit;
