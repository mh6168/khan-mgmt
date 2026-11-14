<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'FirebaseIdTokenVerifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'JewelerSession.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GoldPriceService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'JewelerProductService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SupabaseStorageClient.php';

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
