<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirebaseIdTokenVerifier.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'JewelerSession.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'GoldPriceService.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'JewelerProductService.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'SupabaseStorageClient.php';

$claims = JewelerSession::requireAuthenticated();
$uid = JewelerSession::uid($claims);
$token = (string) ($_SESSION['firebase_id_token'] ?? '');
$timeout = filter_var(getenv('FIREBASE_AUTH_TIMEOUT_SECONDS') ?: '10', FILTER_VALIDATE_INT);
$firestore = new FirestoreClient((string) (getenv('FIREBASE_PROJECT_ID') ?: ''), $timeout === false ? 10 : $timeout);
$application = $firestore->getApplication($uid, $token);

if ($application === null) {
    JewelerSession::redirect('/jeweler/application');
}

$applicationFields = is_array($application['fields'] ?? null) ? $application['fields'] : [];
$applicationValue = static function (string $field, string $fallback = '') use ($applicationFields): string {
    $value = $applicationFields[$field]['stringValue'] ?? null;
    return is_string($value) ? $value : $fallback;
};
$status = $applicationValue('status', 'pending');
$subscriptionStatus = $applicationValue('subscriptionStatus', 'unpaid');
$subscriptionExpiresAt = $applicationValue('subscriptionExpiresAt');
$subscriptionIsCurrent = false;
if ($subscriptionStatus === 'active' && $subscriptionExpiresAt !== '') {
    try {
        $subscriptionIsCurrent = new DateTimeImmutable($subscriptionExpiresAt) > new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Exception) {
        $subscriptionIsCurrent = false;
    }
}

if ($status !== 'approved' || !$subscriptionIsCurrent) {
    JewelerSession::redirect('/jeweler/approved.php');
}

$storage = null;
$storageError = null;
try {
    $storageTimeout = filter_var(getenv('SUPABASE_TIMEOUT_SECONDS') ?: '10', FILTER_VALIDATE_INT);
    $storage = new SupabaseStorageClient(
        trim((string) (getenv('SUPABASE_URL') ?: '')),
        trim((string) (getenv('SUPABASE_STORAGE_BUCKET') ?: '')),
        trim((string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '')),
        $storageTimeout === false ? 10 : $storageTimeout
    );
} catch (Throwable $exception) {
    $storageError = $exception->getMessage();
}

$productService = new JewelerProductService();
if (!isset($_SESSION['jeweler_dashboard_csrf']) || !is_string($_SESSION['jeweler_dashboard_csrf'])) {
    $_SESSION['jeweler_dashboard_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['jeweler_dashboard_csrf'];
$errorMessage = null;
$view = (string) ($_GET['view'] ?? 'overview');
$views = ['overview', 'products', 'reservations', 'analytics', 'account'];
if (!in_array($view, $views, true)) {
    $view = 'overview';
}

/** @return list<array<string, mixed>> */
$uploadedProductFiles = static function (array $files): array {
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        if ((string) $name === '') {
            continue;
        }
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }
    return $normalized;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedToken = $_POST['_csrf'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(400);
        $errorMessage = 'Your session could not be verified. Refresh the page and try again.';
    } elseif ($storage === null && in_array((string) ($_POST['action'] ?? ''), ['create', 'update'], true)) {
        $errorMessage = 'Photo storage is unavailable. Check the Supabase Storage configuration and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $productId = trim((string) ($_POST['productId'] ?? ''));
        $uploadedPaths = [];
        try {
            if ($action === 'create' || $action === 'update') {
                $productType = (string) ($_POST['productType'] ?? '');
                $existingProduct = null;
                if ($action === 'update') {
                    $existingProduct = $firestore->getProduct($productId, $token);
                    if ($existingProduct === null || ($existingProduct['jewelerId'] ?? null) !== $uid) {
                        throw new FirestoreException('Product access was denied.', 403);
                    }
                    if (($existingProduct['productType'] ?? '') !== $productType) {
                        throw new InvalidArgumentException('Product type cannot be changed while editing.');
                    }
                }

                $photoFiles = $uploadedProductFiles($_FILES['photos'] ?? []);
                $currentPhotoPaths = is_array($existingProduct['photoPaths'] ?? null)
                    ? $existingProduct['photoPaths']
                    : (is_array($existingProduct['photoUrls'] ?? null) ? $existingProduct['photoUrls'] : []);
                $photoPaths = $currentPhotoPaths;
                if ($photoFiles !== []) {
                    $uploadedPaths = $productService->uploadProductPhotos($storage, $uid, $productType, $photoFiles);
                    $photoPaths = $uploadedPaths;
                }

                $formData = $_POST;
                if ($productType === 'watch') {
                    $product = $productService->createWatchDocument($uid, $formData, $photoPaths);
                } elseif ($productType === 'jewelry') {
                    $product = $productService->createJewelryDocument($uid, $formData, $photoPaths);
                } else {
                    throw new InvalidArgumentException('Choose a supported product type.');
                }

                if ($existingProduct !== null) {
                    $product['availability'] = (string) ($existingProduct['availability'] ?? 'available');
                    $product['createdAt'] = (string) ($existingProduct['createdAt'] ?? gmdate('c'));
                    $firestore->updateProduct($productId, $uid, $token, $product);
                    if ($photoFiles !== []) {
                        try {
                            $productService->deleteProductPhotos($storage, $currentPhotoPaths);
                        } catch (Throwable) {
                            error_log('Unable to remove replaced product photos.');
                        }
                    }
                } else {
                    $firestore->createProduct($uid, $token, $product);
                }

                header('Location: ' . JewelerSession::url('/jeweler/dashboard.php?view=products&saved=1'), true, 303);
                exit;
            }

            if ($action === 'availability') {
                $availability = $productService->normalizeAvailability((string) ($_POST['availability'] ?? ''));
                $firestore->updateProductAvailability($productId, $uid, $token, $availability === 'available');
                header('Location: ' . JewelerSession::url('/jeweler/dashboard.php?view=products&updated=1'), true, 303);
                exit;
            }

            if ($action === 'delete') {
                $product = $firestore->getProduct($productId, $token);
                if ($product === null || ($product['jewelerId'] ?? null) !== $uid) {
                    throw new FirestoreException('Product access was denied.', 403);
                }
                $firestore->deleteProduct($productId, $uid, $token);
                $photoPaths = is_array($product['photoPaths'] ?? null)
                    ? $product['photoPaths']
                    : (is_array($product['photoUrls'] ?? null) ? $product['photoUrls'] : []);
                if ($storage !== null) {
                    try {
                        $productService->deleteProductPhotos($storage, $photoPaths);
                    } catch (Throwable) {
                        error_log('Unable to remove photos for a deleted product.');
                    }
                }
                header('Location: ' . JewelerSession::url('/jeweler/dashboard.php?view=products&deleted=1'), true, 303);
                exit;
            }

            throw new InvalidArgumentException('Unsupported product action.');
        } catch (Throwable $exception) {
            if ($uploadedPaths !== [] && $storage !== null) {
                try {
                    $productService->deleteProductPhotos($storage, $uploadedPaths);
                } catch (Throwable) {
                    error_log('Unable to clean up product photos after a failed product operation.');
                }
            }
            if ($exception instanceof FirestoreException || $exception instanceof SupabaseStorageException) {
                error_log('Jeweler product action failed: ' . get_class($exception) . '.');
                $errorMessage = $exception->httpStatus() === 403
                    ? 'This product could not be accessed with your account.'
                    : 'The product could not be saved right now. Please try again.';
            } elseif ($exception instanceof InvalidArgumentException) {
                $errorMessage = $exception->getMessage();
            } else {
                error_log('Jeweler product action failed: ' . get_class($exception) . '.');
                $errorMessage = 'The product could not be saved right now. Please try again.';
            }
        }
    }
}

$products = [];
try {
    $products = $firestore->listProductsByJeweler($uid, $token);
} catch (Throwable $exception) {
    error_log('Jeweler product listing failed: ' . get_class($exception) . '.');
    $errorMessage ??= 'Product listings are temporarily unavailable.';
}

foreach ($products as &$product) {
    $product['photoDisplayUrls'] = [];
    if ($storage !== null) {
        try {
            $product['photoDisplayUrls'] = $productService->signedPhotoUrls($storage, $product);
        } catch (Throwable) {
            $product['photoDisplayUrls'] = [];
        }
    }
}
unset($product);

$goldData = GoldPriceService::fromEnvironment()->latest();
$goldPrices = [];
foreach ([24, 22, 21, 18] as $karat) {
    $field = 'price_gram_' . $karat . 'k';
    if (($goldData['available'] ?? false) === true && is_numeric($goldData[$field] ?? null) && (float) $goldData[$field] > 0) {
        $goldPrices[$karat] = (float) $goldData[$field];
    }
}
$totalProducts = count($products);
$availableProducts = count(array_filter($products, static fn (array $product): bool => ($product['availability'] ?? '') === 'available'));
$unavailableProducts = $totalProducts - $availableProducts;
$viewTitle = [
    'overview' => 'Overview',
    'products' => 'My Products',
    'reservations' => 'Reservations',
    'analytics' => 'Sales & Analytics',
    'account' => 'Account',
][$view];

$productTitle = static function (array $product): string {
    if (($product['productType'] ?? '') === 'watch') {
        return trim((string) ($product['brand'] ?? '') . ' ' . (string) ($product['model'] ?? '')) ?: 'Watch';
    }
    return (string) ($product['title'] ?? 'Jewelry piece');
};
$productPrice = static function (array $product) use ($goldPrices, $productService): ?float {
    if (($product['productType'] ?? '') === 'watch') {
        return is_numeric($product['price'] ?? null) ? (float) $product['price'] : null;
    }
    return $productService->calculateJewelryPricing($product, $goldPrices)['currentPrice'];
};
$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Date unavailable';
    }
    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Exception) {
        return 'Date unavailable';
    }
};
$editProduct = null;
$editId = trim((string) ($_GET['edit'] ?? ''));
if ($editId !== '') {
    foreach ($products as $candidate) {
        if (($candidate['id'] ?? '') === $editId) {
            $editProduct = $candidate;
            break;
        }
    }
}
$subscriptionStatusLabel = $subscriptionIsCurrent ? 'Active' : 'Expired';
$subscriptionExpiresLabel = $subscriptionExpiresAt !== '' ? $formatDate($subscriptionExpiresAt) : 'Not set';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= JewelerSession::escape($viewTitle) ?> | KHAN Jeweler</title>
    <style>
        <?= JewelerSession::styles() ?>
        :root{color-scheme:dark;--bg:#111618;--gold:#E5C158;--gold-pale:#FBF2B5;--panel:#2B3539;--line:rgba(251,242,181,.14);--muted:#a9b5b7;--good:#a8d6b1;--bad:#ffb4a9}[hidden]{display:none!important}
        body{display:block;min-height:100vh;padding:0;background:var(--bg);color:#f5f5ee;font-family:Inter,"Segoe UI",sans-serif}
        .app-shell{min-height:100vh;display:grid;grid-template-columns:248px minmax(0,1fr)}
        .sidebar{position:sticky;top:0;height:100vh;padding:26px 18px;background:#171e20;border-right:1px solid var(--line);display:flex;flex-direction:column;gap:28px}
        .brand{padding:8px 12px 20px;border-bottom:1px solid var(--line)}.brand-mark{color:var(--gold);font:500 1.65rem Georgia,serif;letter-spacing:.08em}.brand small{display:block;margin-top:8px;color:var(--muted);font-size:.72rem;letter-spacing:.13em;text-transform:uppercase}
        .nav-list{display:grid;gap:6px}.nav-list a{display:flex;align-items:center;gap:12px;padding:12px;border-radius:6px;color:#c5cece;text-decoration:none;font-size:.92rem}.nav-list a:hover,.nav-list a[aria-current=page]{background:rgba(229,193,88,.12);color:var(--gold-pale)}.nav-icon{width:20px;text-align:center;color:var(--gold)}
        .sidebar-bottom{margin-top:auto;border-top:1px solid var(--line);padding:18px 10px 0}.sidebar-bottom p{margin:0;color:var(--muted);font-size:.78rem;line-height:1.55}.sidebar-bottom a{display:inline-block;margin-top:12px;color:var(--gold-pale);font-size:.86rem;text-decoration:none}
        .main-area{min-width:0;padding:32px clamp(18px,4vw,56px) 48px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;padding-bottom:24px;border-bottom:1px solid var(--line)}.topbar h1{margin:0;color:#fff;font:500 2rem Georgia,serif;letter-spacing:0}.topbar p{margin:7px 0 0;color:var(--muted);font-size:.9rem}.top-actions{display:flex;align-items:center;gap:12px}.eyebrow{margin:0 0 8px;color:var(--gold);font-size:.72rem;font-weight:700;letter-spacing:.16em}.content{max-width:1380px;margin:26px auto 0}.button{display:inline-flex;align-items:center;justify-content:center;gap:8px;margin:0;padding:10px 15px;border:1px solid var(--gold);border-radius:5px;background:var(--gold);color:#161916;text-decoration:none;font:600 .88rem Inter,"Segoe UI",sans-serif;cursor:pointer}.button:hover{background:#f0d47a}.button.secondary{background:transparent;color:var(--gold-pale);border-color:var(--line)}.button.danger{background:transparent;color:var(--bad);border-color:rgba(255,180,169,.4)}.button.small{padding:8px 10px;font-size:.8rem}
        .notice,.error-banner,.warning-banner{padding:13px 15px;border:1px solid var(--line);border-radius:5px;margin-bottom:20px}.notice{color:var(--good);background:rgba(168,214,177,.07)}.error-banner{color:var(--bad);background:rgba(255,180,169,.06)}.warning-banner{color:var(--gold-pale);background:rgba(229,193,88,.08)}
        .metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.metric{padding:17px;border:1px solid var(--line);border-radius:5px;background:rgba(43,53,57,.58);min-height:110px}.metric-label{color:var(--muted);font-size:.8rem}.metric-value{display:block;margin-top:14px;color:#fff;font:500 1.8rem Georgia,serif}.metric-accent{color:var(--gold-pale)}
        .section-heading{display:flex;align-items:end;justify-content:space-between;gap:16px;margin:30px 0 15px}.section-heading h2{margin:0;color:#fff;font:500 1.35rem Georgia,serif;letter-spacing:0}.section-heading p{margin:6px 0 0;color:var(--muted);font-size:.84rem}
        .gold-strip{display:grid;grid-template-columns:minmax(200px,1.3fr) repeat(4,minmax(100px,1fr));border:1px solid var(--line);border-radius:5px;overflow:hidden}.gold-intro,.gold-item{padding:16px;background:rgba(43,53,57,.42)}.gold-intro{border-right:1px solid var(--line)}.gold-intro strong{display:block;color:var(--gold-pale);font:500 1.15rem Georgia,serif}.gold-intro span,.gold-item span{display:block;margin-top:7px;color:var(--muted);font-size:.76rem}.gold-item{border-right:1px solid var(--line)}.gold-item:last-child{border:0}.gold-item strong{display:block;color:#fff;font-size:1.05rem}.gold-item small{color:var(--muted);font-size:.75rem}
        .toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:20px 0}.toolbar input,.toolbar select,.field input,.field select,.field textarea{width:100%;padding:11px 12px;border:1px solid rgba(251,242,181,.2);border-radius:4px;background:#151c1e;color:#f5f5ee;font:inherit}.toolbar .search{flex:1 1 240px}.toolbar select{width:auto;min-width:160px}.toolbar label{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:.82rem}.products-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.product-card{min-width:0;overflow:hidden;border:1px solid var(--line);border-radius:5px;background:#1a2224}.product-image{height:205px;background:#263033;display:grid;place-items:center;overflow:hidden}.product-image img{width:100%;height:100%;object-fit:cover}.product-image-placeholder{color:var(--muted);font-size:.8rem}.product-body{padding:15px}.product-title-row{display:flex;align-items:start;justify-content:space-between;gap:12px}.product-body h3{margin:0;color:#fff;font:500 1.1rem Georgia,serif}.product-type{margin:6px 0 0;color:var(--muted);font-size:.78rem}.availability{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;color:var(--good);font-size:.72rem}.availability.off{color:var(--bad)}.price-line{margin:14px 0;color:var(--gold-pale);font-size:1rem;font-weight:600}.price-line small{color:var(--muted);font-size:.75rem;font-weight:400}.product-meta{padding-top:12px;border-top:1px solid var(--line);color:var(--muted);font-size:.76rem}.product-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:14px}.inline-form{display:inline-flex;margin:0}.empty-state{padding:36px 22px;border:1px dashed rgba(251,242,181,.22);border-radius:5px;text-align:center;color:var(--muted)}.empty-state h2{margin:0 0 8px;color:var(--gold-pale);font:500 1.35rem Georgia,serif}.empty-state p{margin:0;line-height:1.65}
        .account-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.info-panel{padding:19px;border:1px solid var(--line);border-radius:5px;background:rgba(43,53,57,.45)}.info-panel h2{margin:0 0 14px;color:var(--gold-pale);font:500 1.15rem Georgia,serif}.info-row{display:flex;justify-content:space-between;gap:15px;padding:10px 0;border-bottom:1px solid var(--line);font-size:.86rem}.info-row:last-child{border-bottom:0}.info-row span:first-child{color:var(--muted)}.info-row span:last-child{text-align:right;overflow-wrap:anywhere}
        dialog{width:min(720px,calc(100% - 24px));max-height:90vh;padding:0;border:1px solid rgba(229,193,88,.32);border-radius:6px;background:#1b2325;color:#f5f5ee;box-shadow:0 24px 80px #000b}dialog::backdrop{background:rgba(4,8,9,.78)}.dialog-head{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;padding:18px 22px;background:#1b2325;border-bottom:1px solid var(--line)}.dialog-head h2{margin:0;color:var(--gold-pale);font:500 1.35rem Georgia,serif}.icon-button{width:34px;height:34px;border:1px solid var(--line);border-radius:4px;background:transparent;color:#fff;font-size:1.2rem;cursor:pointer}.product-form{padding:20px 22px 24px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{min-width:0;display:grid;gap:7px}.field.full{grid-column:1/-1}.field label,.field>span{color:#d8dfdf;font-size:.82rem}.field input,.field select{min-width:0}.field input[type=file]{padding:9px}.field-help{color:var(--muted);font-size:.73rem;line-height:1.45}.form-type{display:flex;gap:8px;margin-bottom:18px}.form-type button{flex:1;padding:10px;border:1px solid var(--line);border-radius:4px;background:transparent;color:var(--muted);cursor:pointer}.form-type button[aria-pressed=true]{border-color:var(--gold);background:rgba(229,193,88,.12);color:var(--gold-pale)}.price-preview{grid-column:1/-1;padding:14px;border:1px solid var(--line);border-radius:4px;background:#141a1c}.price-preview strong{display:block;color:var(--gold-pale);font-size:1.25rem}.price-preview p{margin:6px 0 0;color:var(--muted);font-size:.8rem}.preview-warning{color:var(--bad)!important}.discount{color:var(--good)!important}.existing-photos{display:flex;gap:8px;flex-wrap:wrap}.existing-photos img{width:62px;height:62px;object-fit:cover;border-radius:3px}.form-footer{display:flex;justify-content:flex-end;gap:9px;margin-top:20px}
        .mobile-brand{display:none}
        @media(max-width:1100px){.metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.products-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.gold-strip{grid-template-columns:repeat(4,minmax(100px,1fr))}.gold-intro{grid-column:1/-1;border-right:0;border-bottom:1px solid var(--line)}}
        @media(max-width:720px){.app-shell{display:block}.sidebar{position:relative;width:100%;height:auto;padding:12px 14px 8px;border-right:0;border-bottom:1px solid var(--line);gap:10px}.brand{display:none}.mobile-brand{display:block;padding:4px 8px 10px;color:var(--gold);font:500 1.3rem Georgia,serif;letter-spacing:.08em}.nav-list{display:flex;gap:3px;overflow-x:auto;padding-bottom:3px}.nav-list a{flex:0 0 auto;gap:7px;padding:10px;font-size:.8rem}.nav-icon{width:auto}.sidebar-bottom{display:none}.main-area{padding:20px 15px 34px}.topbar{align-items:flex-start;padding-bottom:18px}.topbar h1{font-size:1.65rem}.topbar p{font-size:.8rem}.top-actions .button{padding:9px 10px;font-size:.78rem}.metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.metric{min-height:90px;padding:13px}.metric-value{font-size:1.45rem;margin-top:10px}.gold-strip{grid-template-columns:repeat(2,minmax(0,1fr))}.gold-item:nth-child(odd){border-right:0}.gold-item{border-bottom:1px solid var(--line)}.products-grid{grid-template-columns:1fr}.product-image{height:230px}.account-grid{grid-template-columns:1fr}.section-heading{align-items:flex-start}.toolbar{align-items:stretch}.toolbar select{flex:1;min-width:0}.toolbar label{flex:1}.form-grid{grid-template-columns:1fr}.field.full,.price-preview{grid-column:auto}.form-footer{flex-direction:column-reverse}.form-footer .button{width:100%}}
        @media(max-width:380px){.nav-list a{font-size:.74rem;padding:9px 8px}.nav-icon{display:none}.top-actions .button{font-size:0}.top-actions .button::before{content:'+';font-size:1.1rem}}
    </style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand"><div class="brand-mark">KHAN</div><small>Jeweler workspace</small></div>
        <div class="mobile-brand">KHAN</div>
        <nav class="nav-list" aria-label="Jeweler dashboard">
            <?php foreach (['overview' => ['Overview', '⌂'], 'products' => ['My Products', '◇'], 'reservations' => ['Reservations', '▤'], 'analytics' => ['Sales & Analytics', '▥'], 'account' => ['Account', '○']] as $navView => [$label, $icon]): ?>
                <a href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/dashboard.php?view=' . $navView)) ?>" <?= $view === $navView ? 'aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true"><?= $icon ?></span><?= JewelerSession::escape($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <p><?= JewelerSession::escape($applicationValue('storeName', $applicationValue('fullName', 'Jeweler account'))) ?><br>Subscription <?= JewelerSession::escape(strtolower($subscriptionStatusLabel)) ?></p>
            <a href="<?= JewelerSession::escape(JewelerSession::url('/auth/logout')) ?>">Log out</a>
        </div>
    </aside>
    <main class="main-area">
        <header class="topbar">
            <div><p class="eyebrow">KHAN JEWELER WORKSPACE</p><h1><?= JewelerSession::escape($viewTitle) ?></h1><p><?= JewelerSession::escape($applicationValue('storeName', 'Manage your store')) ?></p></div>
            <div class="top-actions">
                <?php if ($view === 'products'): ?><button class="button" type="button" data-open-product>＋ <span>Add product</span></button><?php endif; ?>
            </div>
        </header>
        <div class="content">
            <?php if ($errorMessage !== null): ?><div class="error-banner" role="alert"><?= JewelerSession::escape($errorMessage) ?></div><?php endif; ?>
            <?php if (isset($_GET['saved'])): ?><div class="notice" role="status">Product saved successfully.</div><?php endif; ?>
            <?php if (isset($_GET['updated'])): ?><div class="notice" role="status">Product availability updated.</div><?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice" role="status">Product deleted.</div><?php endif; ?>
            <?php if ($storageError !== null): ?><div class="warning-banner" role="status">Photo storage is unavailable. Product photo operations are disabled until storage configuration is restored.</div><?php endif; ?>

            <?php if ($view === 'overview'): ?>
                <section class="metrics" aria-label="Store overview">
                    <div class="metric"><span class="metric-label">Total product listings</span><strong class="metric-value"><?= $totalProducts ?></strong></div>
                    <div class="metric"><span class="metric-label">Available listings</span><strong class="metric-value metric-accent"><?= $availableProducts ?></strong></div>
                    <div class="metric"><span class="metric-label">Unavailable listings</span><strong class="metric-value"><?= $unavailableProducts ?></strong></div>
                    <div class="metric"><span class="metric-label">Total reservations</span><strong class="metric-value">0</strong></div>
                    <div class="metric"><span class="metric-label">Completed reservations</span><strong class="metric-value">0</strong></div>
                </section>
                <section>
                    <div class="section-heading"><div><h2>Gold market</h2><p><?= JewelerSession::escape((string) ($goldData['message'] ?? 'Gold price data unavailable.')) ?></p></div><span class="availability <?= ($goldData['cached'] ?? false) ? 'off' : '' ?>"><?= ($goldData['cached'] ?? false) ? 'Cached' : (($goldData['available'] ?? false) ? 'Live' : 'Unavailable') ?></span></div>
                    <?php if ($goldPrices === []): ?>
                        <div class="warning-banner">A valid gold price is unavailable. Jewelry prices cannot be calculated until a current or cached price is available.</div>
                    <?php else: ?>
                        <div class="gold-strip">
                            <div class="gold-intro"><strong>Gold price per gram</strong><span>Updated <?= JewelerSession::escape((string) ($goldData['updatedAt'] ?? 'time unavailable')) ?> · EGP</span></div>
                            <?php foreach ([24, 22, 21, 18] as $karat): ?><div class="gold-item"><strong><?= isset($goldPrices[$karat]) ? number_format($goldPrices[$karat], 2) : 'Unavailable' ?></strong><span><?= $karat ?>K <small>EGP / gram</small></span></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
                <section>
                    <div class="section-heading"><div><h2>Recent products</h2><p>Your latest product listings</p></div><a class="button secondary small" href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/dashboard.php?view=products')) ?>">View products</a></div>
                    <?php if ($products === []): ?><div class="empty-state"><h2>Your catalog is ready for its first listing</h2><p>Add a watch or jewelry piece to begin building your KHAN storefront.</p><a class="button" style="margin-top:16px" href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/dashboard.php?view=products')) ?>">Open products</a></div>
                    <?php else: ?><div class="products-grid"><?php foreach (array_slice($products, 0, 3) as $product): ?>
                        <article class="product-card"><div class="product-image"><?php if (($product['photoDisplayUrls'][0] ?? '') !== ''): ?><img src="<?= JewelerSession::escape((string) $product['photoDisplayUrls'][0]) ?>" alt="<?= JewelerSession::escape($productTitle($product)) ?>" loading="lazy"><?php else: ?><span class="product-image-placeholder">Photo unavailable</span><?php endif; ?></div><div class="product-body"><div class="product-title-row"><div><h3><?= JewelerSession::escape($productTitle($product)) ?></h3><p class="product-type"><?= ($product['productType'] ?? '') === 'watch' ? 'Watch' : 'Jewelry piece' ?></p></div><span class="availability <?= ($product['availability'] ?? '') === 'available' ? '' : 'off' ?>"><?= ($product['availability'] ?? '') === 'available' ? 'Available' : 'Unavailable' ?></span></div><p class="price-line"><?php $price = $productPrice($product); ?><?= $price === null ? 'Price unavailable' : number_format($price, 2) . ' EGP' ?></p><div class="product-meta">Added <?= JewelerSession::escape($formatDate($product['createdAt'] ?? null)) ?></div></div></article>
                    <?php endforeach; ?></div><?php endif; ?>
                </section>
            <?php elseif ($view === 'products'): ?>
                <div class="toolbar" role="search">
                    <input class="search" id="product-search" type="search" placeholder="Search your products" aria-label="Search products">
                    <label>Type <select id="type-filter" aria-label="Filter by product type"><option value="all">All types</option><option value="watch">Watches</option><option value="jewelry">Jewelry pieces</option></select></label>
                    <label>Availability <select id="availability-filter" aria-label="Filter by availability"><option value="all">All listings</option><option value="available">Available</option><option value="unavailable">Unavailable</option></select></label>
                </div>
                <?php if ($products === []): ?><div class="empty-state"><h2>No product listings yet</h2><p>When you add watches or jewelry pieces, they will appear here.</p><button class="button" style="margin-top:16px" type="button" data-open-product>Add your first product</button></div>
                <?php else: ?><div class="products-grid" id="products-grid">
                    <?php foreach ($products as $product): $type = (string) ($product['productType'] ?? 'jewelry'); $availability = (string) ($product['availability'] ?? 'unavailable'); $title = $productTitle($product); ?>
                        <article class="product-card" data-product-card data-type="<?= JewelerSession::escape($type) ?>" data-availability="<?= JewelerSession::escape($availability) ?>" data-search="<?= JewelerSession::escape(strtolower($title . ' ' . $type . ' ' . (string) ($product['category'] ?? '') . ' ' . (string) ($product['brand'] ?? ''))) ?>">
                            <div class="product-image"><?php if (($product['photoDisplayUrls'][0] ?? '') !== ''): ?><img src="<?= JewelerSession::escape((string) $product['photoDisplayUrls'][0]) ?>" alt="<?= JewelerSession::escape($title) ?>" loading="lazy"><?php else: ?><span class="product-image-placeholder">Photo unavailable</span><?php endif; ?></div>
                            <div class="product-body">
                                <div class="product-title-row"><div><h3><?= JewelerSession::escape($title) ?></h3><p class="product-type"><?= $type === 'watch' ? 'Watch' : 'Jewelry piece' ?><?php if ($type === 'jewelry' && ($product['category'] ?? '') !== ''): ?> · <?= JewelerSession::escape((string) $product['category']) ?><?php endif; ?></p></div><span class="availability <?= $availability === 'available' ? '' : 'off' ?>"><?= $availability === 'available' ? 'Available' : 'Unavailable' ?></span></div>
                                <?php $price = $productPrice($product); ?><p class="price-line"><?= $price === null ? 'Price unavailable' : number_format($price, 2) . ' EGP' ?><?php if ($type === 'jewelry'): ?><small> · current gold pricing</small><?php endif; ?></p>
                                <div class="product-meta">Created <?= JewelerSession::escape($formatDate($product['createdAt'] ?? null)) ?></div>
                                <div class="product-actions">
                                    <a class="button secondary small" href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/dashboard.php?view=products&edit=' . rawurlencode((string) $product['id']))) ?>">Edit</a>
                                    <form class="inline-form" method="post"><input type="hidden" name="_csrf" value="<?= JewelerSession::escape($csrfToken) ?>"><input type="hidden" name="action" value="availability"><input type="hidden" name="productId" value="<?= JewelerSession::escape((string) $product['id']) ?>"><input type="hidden" name="availability" value="<?= $availability === 'available' ? 'unavailable' : 'available' ?>"><button class="button secondary small" type="submit">Mark <?= $availability === 'available' ? 'unavailable' : 'available' ?></button></form>
                                    <form class="inline-form" method="post" data-delete-form><input type="hidden" name="_csrf" value="<?= JewelerSession::escape($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="productId" value="<?= JewelerSession::escape((string) $product['id']) ?>"><button class="button danger small" type="submit">Delete</button></form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div><div class="empty-state" id="filter-empty" hidden><p>No products match those filters.</p></div><?php endif; ?>
            <?php elseif ($view === 'reservations'): ?>
                <div class="section-heading"><div><h2>Reservations</h2><p>Reservation activity for your product listings</p></div></div>
                <div class="empty-state"><h2>No reservation records yet</h2><p>Reservation totals will appear here once reservation records are added to the KHAN data model.</p></div>
            <?php elseif ($view === 'analytics'): ?>
                <div class="section-heading"><div><h2>Sales & Analytics</h2><p>Store performance and completed sales</p></div></div>
                <div class="metrics"><div class="metric"><span class="metric-label">Product listings</span><strong class="metric-value"><?= $totalProducts ?></strong></div><div class="metric"><span class="metric-label">Available listings</span><strong class="metric-value metric-accent"><?= $availableProducts ?></strong></div><div class="metric"><span class="metric-label">Unavailable listings</span><strong class="metric-value"><?= $unavailableProducts ?></strong></div></div>
                <div class="empty-state" style="margin-top:18px"><h2>Sales data is not connected yet</h2><p>Sales totals and trends will appear here when KHAN adds sales records. No sales figures are being estimated.</p></div>
            <?php else: ?>
                <div class="account-grid">
                    <section class="info-panel"><h2>Store information</h2><div class="info-row"><span>Store name</span><span><?= JewelerSession::escape($applicationValue('storeName', 'Not provided')) ?></span></div><div class="info-row"><span>Account name</span><span><?= JewelerSession::escape($applicationValue('fullName', 'Not provided')) ?></span></div><div class="info-row"><span>Phone</span><span><?= JewelerSession::escape($applicationValue('phone', 'Not provided')) ?></span></div><div class="info-row"><span>Store address</span><span><?= JewelerSession::escape($applicationValue('storeAddress', 'Not provided')) ?></span></div></section>
                    <section class="info-panel"><h2>Subscription</h2><div class="info-row"><span>Status</span><span><?= JewelerSession::escape($subscriptionStatusLabel) ?></span></div><div class="info-row"><span>Valid through</span><span><?= JewelerSession::escape($subscriptionExpiresLabel) ?></span></div><div class="info-row"><span>Application</span><span><?= JewelerSession::escape(ucfirst($status)) ?></span></div><?php if (!$subscriptionIsCurrent): ?><a class="button" style="margin-top:16px" href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/subscription-payment.php')) ?>">Manage subscription</a><?php endif; ?></section>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<dialog id="product-dialog" aria-labelledby="product-dialog-title">
    <div class="dialog-head"><h2 id="product-dialog-title"><?= $editProduct === null ? 'Add product' : 'Edit product' ?></h2><button class="icon-button" type="button" aria-label="Close product form" data-close-product>×</button></div>
    <form class="product-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= JewelerSession::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="<?= $editProduct === null ? 'create' : 'update' ?>">
        <input type="hidden" name="productId" value="<?= JewelerSession::escape((string) ($editProduct['id'] ?? '')) ?>">
        <input type="hidden" name="productType" id="product-type-input" value="<?= JewelerSession::escape((string) ($editProduct['productType'] ?? 'watch')) ?>">
        <div class="form-type" <?= $editProduct === null ? '' : 'hidden' ?>><button type="button" data-product-type="watch" aria-pressed="<?= ($editProduct['productType'] ?? 'watch') === 'watch' ? 'true' : 'false' ?>">Watch</button><button type="button" data-product-type="jewelry" aria-pressed="<?= ($editProduct['productType'] ?? '') === 'jewelry' ? 'true' : 'false' ?>">Jewelry piece</button></div>
        <?php if ($editProduct !== null && ($editProduct['photoDisplayUrls'] ?? []) !== []): ?><div class="field full" style="margin-bottom:16px"><span>Current photos</span><div class="existing-photos"><?php foreach ($editProduct['photoDisplayUrls'] as $photoUrl): ?><img src="<?= JewelerSession::escape((string) $photoUrl) ?>" alt="Current product photo"><?php endforeach; ?></div></div><?php endif; ?>
        <fieldset data-product-fields="watch" <?= ($editProduct['productType'] ?? 'watch') === 'watch' ? '' : 'hidden' ?> style="padding:0;border:0;margin:0">
            <div class="form-grid">
                <div class="field"><label for="watch-brand">Brand</label><input id="watch-brand" name="brand" required value="<?= JewelerSession::escape((string) ($editProduct['brand'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-model">Model</label><input id="watch-model" name="model" required value="<?= JewelerSession::escape((string) ($editProduct['model'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-type">Watch type</label><input id="watch-type" name="watchType" required value="<?= JewelerSession::escape((string) ($editProduct['watchType'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-material">Case material</label><input id="watch-material" name="caseMaterial" required value="<?= JewelerSession::escape((string) ($editProduct['caseMaterial'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-dial">Dial color</label><input id="watch-dial" name="dialColor" required value="<?= JewelerSession::escape((string) ($editProduct['dialColor'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-movement">Movement type</label><input id="watch-movement" name="movementType" required value="<?= JewelerSession::escape((string) ($editProduct['movementType'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-price">Price (EGP)</label><input id="watch-price" name="price" type="number" min="0.01" step="0.01" required value="<?= JewelerSession::escape((string) ($editProduct['price'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-origin">Country of origin</label><input id="watch-origin" name="countryOfOrigin" required value="<?= JewelerSession::escape((string) ($editProduct['countryOfOrigin'] ?? '')) ?>"></div>
                <div class="field full"><label for="watch-3d">Optional 3D URL</label><input id="watch-3d" name="threeDModelUrl" type="url" placeholder="https://" value="<?= JewelerSession::escape((string) ($editProduct['threeDModelUrl'] ?? '')) ?>"></div>
            </div>
        </fieldset>
        <fieldset data-product-fields="jewelry" <?= ($editProduct['productType'] ?? '') === 'jewelry' ? '' : 'hidden' ?> style="padding:0;border:0;margin:0">
            <div class="form-grid">
                <div class="field"><label for="jewelry-title">Title</label><input id="jewelry-title" name="title" required value="<?= JewelerSession::escape((string) ($editProduct['title'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-category">Category</label><input id="jewelry-category" name="category" required value="<?= JewelerSession::escape((string) ($editProduct['category'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-total-weight">Total weight (g)</label><input id="jewelry-total-weight" name="totalWeight" type="number" min="0.01" step="0.01" required value="<?= JewelerSession::escape((string) ($editProduct['totalWeight'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-gold-weight">Gold weight (g)</label><input id="jewelry-gold-weight" name="goldWeight" type="number" min="0.01" step="0.01" required value="<?= JewelerSession::escape((string) ($editProduct['goldWeight'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-karat">Gold karat</label><select id="jewelry-karat" name="goldKarat" required><?php foreach ([24, 22, 21, 18] as $karat): ?><option value="<?= $karat ?>" <?= (int) ($editProduct['goldKarat'] ?? 18) === $karat ? 'selected' : '' ?>><?= $karat ?>K</option><?php endforeach; ?></select></div>
                <div class="field"><label for="jewelry-color">Gold color</label><input id="jewelry-color" name="goldColor" required value="<?= JewelerSession::escape((string) ($editProduct['goldColor'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-origin">Country of origin</label><input id="jewelry-origin" name="countryOfOrigin" required value="<?= JewelerSession::escape((string) ($editProduct['countryOfOrigin'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-current-premium">Current premium / g (EGP)</label><input id="jewelry-current-premium" name="currentPremium" type="number" min="0" step="0.01" required value="<?= JewelerSession::escape((string) ($editProduct['currentPremium'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-original-premium">Original premium / g (optional)</label><input id="jewelry-original-premium" name="originalPremium" type="number" min="0" step="0.01" value="<?= JewelerSession::escape((string) ($editProduct['originalPremium'] ?? '')) ?>"><small class="field-help">Used to show an original price and a calculated discount when higher than the current premium.</small></div>
                <div class="field"><label for="jewelry-3d">Optional 3D URL</label><input id="jewelry-3d" name="threeDModelUrl" type="url" placeholder="https://" value="<?= JewelerSession::escape((string) ($editProduct['threeDModelUrl'] ?? '')) ?>"></div>
                <div class="price-preview" id="jewelry-price-preview" aria-live="polite"><strong id="jewelry-current-price">Price unavailable</strong><p id="jewelry-original-price"></p><p id="jewelry-discount" class="discount" hidden></p><p id="jewelry-price-warning" class="preview-warning" <?= $goldPrices === [] ? '' : 'hidden' ?>>A valid gold price is unavailable. No estimate will be shown.</p></div>
            </div>
        </fieldset>
        <div class="field full" style="margin-top:15px"><label for="product-photos"><?= $editProduct === null ? 'Product photos' : 'Replace photos (optional)' ?></label><input id="product-photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple <?= $editProduct === null ? 'required' : '' ?>><small class="field-help">JPG, PNG, or WEBP. Up to 10 MB per photo. Selecting photos replaces the current gallery.</small></div>
        <div class="form-footer"><button class="button secondary" type="button" data-close-product>Cancel</button><button class="button" type="submit"><?= $editProduct === null ? 'Create listing' : 'Save changes' ?></button></div>
    </form>
</dialog>
<script>
    const goldPrices = <?= json_encode($goldPrices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
    const productDialog = document.getElementById('product-dialog');
    const typeInput = document.getElementById('product-type-input');
    const typeButtons = [...document.querySelectorAll('[data-product-type]')];
    const productFieldsets = [...document.querySelectorAll('[data-product-fields]')];

    function setProductType(type) {
        typeInput.value = type;
        typeButtons.forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.productType === type));
        });
        productFieldsets.forEach((fieldset) => {
            const active = fieldset.dataset.productFields === type;
            fieldset.hidden = !active;
            fieldset.querySelectorAll('input, select, textarea').forEach((input) => {
                input.disabled = !active;
            });
        });
        updateJewelryPrice();
    }

    function updateJewelryPrice() {
        const karat = Number(document.getElementById('jewelry-karat')?.value || 0);
        const weight = Number(document.getElementById('jewelry-gold-weight')?.value || 0);
        const currentPremium = Number(document.getElementById('jewelry-current-premium')?.value || 0);
        const originalPremiumField = document.getElementById('jewelry-original-premium');
        const originalPremium = originalPremiumField?.value === '' ? null : Number(originalPremiumField?.value);
        const currentOutput = document.getElementById('jewelry-current-price');
        const originalOutput = document.getElementById('jewelry-original-price');
        const discountOutput = document.getElementById('jewelry-discount');
        const warning = document.getElementById('jewelry-price-warning');
        const goldPerGram = goldPrices[karat];

        discountOutput.hidden = true;
        originalOutput.textContent = '';
        if (!Number.isFinite(goldPerGram) || goldPerGram <= 0) {
            currentOutput.textContent = 'Price unavailable';
            warning.hidden = false;
            return;
        }
        warning.hidden = true;
        if (weight <= 0) {
            currentOutput.textContent = 'Enter gold weight';
            return;
        }

        const currentPrice = weight * (goldPerGram + currentPremium);
        currentOutput.textContent = `${currentPrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} EGP`;
        if (originalPremium !== null && Number.isFinite(originalPremium)) {
            const originalPrice = weight * (goldPerGram + originalPremium);
            originalOutput.textContent = `Original price: ${originalPrice.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} EGP`;
            if (originalPremium > currentPremium && originalPrice > 0) {
                const discountPerGram = originalPremium - currentPremium;
                const discountAmount = originalPrice - currentPrice;
                const discountPercent = (discountAmount / originalPrice) * 100;
                discountOutput.textContent = `Save ${discountPerGram.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} EGP / g · ${discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})} EGP total (${discountPercent.toFixed(2)}%)`;
                discountOutput.hidden = false;
            }
        }
    }

    document.querySelectorAll('[data-open-product]').forEach((button) => button.addEventListener('click', () => productDialog.showModal()));
    document.querySelectorAll('[data-close-product]').forEach((button) => button.addEventListener('click', () => productDialog.close()));
    typeButtons.forEach((button) => button.addEventListener('click', () => setProductType(button.dataset.productType)));
    productDialog?.addEventListener('click', (event) => {
        if (event.target === productDialog) productDialog.close();
    });
    document.querySelectorAll('#jewelry-karat, #jewelry-gold-weight, #jewelry-current-premium, #jewelry-original-premium').forEach((input) => input.addEventListener('input', updateJewelryPrice));
    document.querySelectorAll('[data-delete-form]').forEach((form) => form.addEventListener('submit', (event) => {
        if (!window.confirm('Delete this product and its uploaded photos? This cannot be undone.')) event.preventDefault();
    }));

    const searchInput = document.getElementById('product-search');
    const typeFilter = document.getElementById('type-filter');
    const availabilityFilter = document.getElementById('availability-filter');
    const cards = [...document.querySelectorAll('[data-product-card]')];
    function filterProducts() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        let visibleCount = 0;
        cards.forEach((card) => {
            const visible = (typeFilter.value === 'all' || card.dataset.type === typeFilter.value)
                && (availabilityFilter.value === 'all' || card.dataset.availability === availabilityFilter.value)
                && card.dataset.search.includes(query);
            card.hidden = !visible;
            if (visible) visibleCount++;
        });
        const empty = document.getElementById('filter-empty');
        if (empty) empty.hidden = visibleCount > 0;
    }
    [searchInput, typeFilter, availabilityFilter].forEach((input) => input?.addEventListener('input', filterProducts));
    setProductType(typeInput.value);
    updateJewelryPrice();
    <?php if (($editProduct !== null || $errorMessage !== null) && $view === 'products'): ?>productDialog.showModal();<?php endif; ?>
</script>
</body>
</html>
