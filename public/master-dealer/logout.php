<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerAuth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

if (!MasterDealerAuth::verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    exit('Your session could not be verified.');
}

MasterDealerAuth::logout();
header('Location: ' . MasterDealerAuth::url('login.php'), true, 303);
exit;
