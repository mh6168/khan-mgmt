<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerAuth.php';

MasterDealerAuth::logout();
header('Location: ' . MasterDealerAuth::url('login.php'), true, 303);
exit;
