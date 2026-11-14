<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'SupabaseStorageClient.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'JewelerProductService.php';

function assertCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class TestSupabaseStorageClient
{
    public array $uploadedPaths = [];
    public array $deletedPaths = [];

    public function upload(string $path, string $contents, string $contentType): void
    {
        $this->uploadedPaths[] = [
            'path' => $path,
            'contentType' => $contentType,
            'size' => strlen($contents),
        ];
    }

    public function createSignedUrl(string $path, int $expiresInSeconds = 600): string
    {
        return 'https://cdn.example.com/' . $path . '?expires=' . $expiresInSeconds;
    }

    public function delete(string $path): void
    {
        $this->deletedPaths[] = $path;
    }
}

try {
    $service = new JewelerProductService();

    $watchFile = tempnam(sys_get_temp_dir(), 'watch_');
    if ($watchFile === false) {
        throw new RuntimeException('Unable to create a temp watch image.');
    }
    $pngContents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII=', true);
    if ($pngContents === false) {
        throw new RuntimeException('Unable to decode the image test fixture.');
    }
    file_put_contents($watchFile, $pngContents);

    $jewelryFile = tempnam(sys_get_temp_dir(), 'jewelry_');
    if ($jewelryFile === false) {
        throw new RuntimeException('Unable to create a temp jewelry image.');
    }
    file_put_contents($jewelryFile, $pngContents);

    $storage = new TestSupabaseStorageClient();
    $watchPhotos = $service->uploadProductPhotos($storage, 'jeweler_123', 'watch', [[
        'name' => 'watch.png',
        'type' => 'image/png',
        'tmp_name' => $watchFile,
        'size' => filesize($watchFile) ?: 0,
    ], [
        'name' => 'watch-detail.png',
        'type' => 'image/png',
        'tmp_name' => $watchFile,
        'size' => filesize($watchFile) ?: 0,
    ]]);
    assertCondition(count($watchPhotos) === 2, 'Multiple watch photo uploads should be accepted.');
    assertCondition(str_starts_with($watchPhotos[0], 'jewelers/jeweler_123/watch/photos/'), 'Expected durable object path for uploaded watch photo.');
    assertCondition(str_starts_with($service->signedPhotoUrls($storage, ['photoPaths' => $watchPhotos])[0], 'https://cdn.example.com/'), 'Private product photo should be signed for display.');

    $watchDocument = $service->createWatchDocument('jeweler_123', [
        'watchType' => 'luxury',
        'brand' => 'Rolex',
        'model' => 'Datejust',
        'caseMaterial' => 'Gold',
        'dialColor' => 'Black',
        'movementType' => 'Automatic',
        'price' => 18000,
        'countryOfOrigin' => 'Switzerland',
        'threeDModelUrl' => 'https://example.com/watch-3d',
    ], $watchPhotos);
    assertCondition($watchDocument['productType'] === 'watch', 'Watch document type mismatch.');
    assertCondition($watchDocument['brand'] === 'Rolex', 'Watch brand missing.');
    assertCondition($watchDocument['availability'] === 'available', 'Watch default availability should be available.');
    assertCondition(count($watchDocument['photoPaths']) === 2, 'Watch photo paths missing.');

    $jewelryPhotos = $service->uploadProductPhotos($storage, 'jeweler_123', 'jewelry', [[
        'name' => 'ring.png',
        'type' => 'image/png',
        'tmp_name' => $jewelryFile,
        'size' => filesize($jewelryFile) ?: 0,
    ]]);
    $jewelryDocument = $service->createJewelryDocument('jeweler_123', [
        'title' => 'Diamond Gold Ring',
        'category' => 'Ring',
        'totalWeight' => 12.5,
        'goldWeight' => 8.2,
        'goldKarat' => 18,
        'goldColor' => 'Yellow',
        'countryOfOrigin' => 'UAE',
        'currentPremium' => 210,
        'originalPremium' => 180,
        'threeDModelUrl' => 'https://example.com/ring-3d',
    ], $jewelryPhotos);
    assertCondition($jewelryDocument['productType'] === 'jewelry', 'Jewelry document type mismatch.');
    assertCondition($jewelryDocument['category'] === 'Ring', 'Jewelry category missing.');
    assertCondition($jewelryDocument['goldKarat'] === 18, 'Jewelry gold karat missing.');
    assertCondition($jewelryDocument['availability'] === 'available', 'Jewelry default availability should be available.');
    $pricing = $service->calculateJewelryPricing([
        'goldKarat' => 18,
        'goldWeight' => 10,
        'currentPremium' => 800,
        'originalPremium' => 1000,
    ], [18 => 3000]);
    assertCondition($pricing['currentPrice'] === 38000.0, 'Current jewelry price formula mismatch.');
    assertCondition($pricing['originalPrice'] === 40000.0, 'Original jewelry price formula mismatch.');
    assertCondition($pricing['discountPerGram'] === 200.0, 'Discount per gram mismatch.');
    assertCondition($pricing['discountAmount'] === 2000.0, 'Total discount mismatch.');
    assertCondition($pricing['discountPercent'] === 5.0, 'Discount percentage must use the complete original price.');
    assertCondition($service->calculateJewelryPricing([
        'goldKarat' => 18,
        'goldWeight' => 10,
        'currentPremium' => 800,
    ], [])['currentPrice'] === null, 'Missing gold price must not produce an estimate.');

    FirestoreClient::assertProductOwnership(['jewelerId' => 'jeweler_123'], 'jeweler_123');
    try {
        FirestoreClient::assertProductOwnership(['jewelerId' => 'another_jeweler'], 'jeweler_123');
        throw new RuntimeException('A jeweler must not access another jeweler product.');
    } catch (FirestoreException $exception) {
        assertCondition($exception->httpStatus() === 403, 'Cross-jeweler access should be forbidden.');
    }
    $service->deleteProductPhotos($storage, $watchPhotos);
    assertCondition(count($storage->deletedPaths) === count($watchPhotos), 'Deleting a product should clean up every private photo object.');
    assertCondition(count($storage->uploadedPaths) === 3, 'Expected two watch uploads and one jewelry upload.');

    assertCondition($service->normalizeAvailability('available') === 'available', 'Available status normalization failed.');
    $updatedAvailability = $service->normalizeAvailability('unavailable');
    assertCondition($updatedAvailability === 'unavailable', 'Availability normalization failed.');

    try {
        $service->normalizeAvailability('reserved');
        throw new RuntimeException('Unsupported availability values should be rejected.');
    } catch (InvalidArgumentException $exception) {
    }

    try {
        $service->normalizeWatchProduct([], $watchPhotos);
        throw new RuntimeException('Missing required watch fields should be rejected.');
    } catch (InvalidArgumentException $exception) {
        assertCondition(str_contains($exception->getMessage(), 'brand'), 'Missing watch brand should be reported.');
    }

    try {
        $service->normalizeJewelryProduct([], $jewelryPhotos);
        throw new RuntimeException('Missing required jewelry fields should be rejected.');
    } catch (InvalidArgumentException $exception) {
        assertCondition(str_contains($exception->getMessage(), 'title'), 'Missing jewelry title should be reported.');
    }

    try {
        $service->uploadProductPhotos($storage, 'jeweler_123', 'watch', [[
            'name' => 'not-supported.txt',
            'type' => 'text/plain',
            'tmp_name' => __FILE__,
            'size' => 100,
        ]]);
        throw new RuntimeException('Invalid file type should trigger an exception.');
    } catch (InvalidArgumentException $exception) {
        // expected
    }

    try {
        $service->uploadProductPhotos($storage, 'jeweler_123', 'watch', [[
            'name' => 'mislabelled.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $watchFile,
            'size' => filesize($watchFile) ?: 0,
        ]]);
        throw new RuntimeException('Mismatched uploaded image content should be rejected.');
    } catch (InvalidArgumentException $exception) {
    }

    $normalizedWatch = $service->normalizeWatchProduct([
        'watchType' => 'luxury',
        'brand' => 'Omega',
        'model' => 'Speedmaster',
        'caseMaterial' => 'Steel',
        'dialColor' => 'Silver',
        'movementType' => 'Automatic',
        'price' => 9500,
        'countryOfOrigin' => 'Switzerland',
        'threeDModelUrl' => 'https://example.com/omega',
    ], $watchPhotos);
    assertCondition($normalizedWatch['brand'] === 'Omega', 'Normalized watch brand mismatch.');
    assertCondition($normalizedWatch['threeDModelUrl'] === 'https://example.com/omega', 'HTTPS 3D URL should be retained.');

    $watchWithoutThreeD = $service->normalizeWatchProduct([
        'watchType' => 'dress',
        'brand' => 'Test',
        'model' => 'Optional URL',
        'caseMaterial' => 'Steel',
        'dialColor' => 'White',
        'movementType' => 'Quartz',
        'price' => 100,
        'countryOfOrigin' => 'Egypt',
    ], $watchPhotos);
    assertCondition($watchWithoutThreeD['threeDModelUrl'] === null, '3D URL should remain optional.');

    try {
        $service->normalizeWatchProduct([
            'watchType' => 'dress',
            'brand' => 'Test',
            'model' => 'Invalid URL',
            'caseMaterial' => 'Steel',
            'dialColor' => 'White',
            'movementType' => 'Quartz',
            'price' => 100,
            'countryOfOrigin' => 'Egypt',
            'threeDModelUrl' => 'http://example.com/model',
        ], $watchPhotos);
        throw new RuntimeException('Non-HTTPS 3D URLs should be rejected.');
    } catch (InvalidArgumentException $exception) {
    }

    unlink($watchFile);
    unlink($jewelryFile);

    echo json_encode([
        'status' => 'ok',
        'watch' => $watchDocument['productType'],
        'jewelry' => $jewelryDocument['productType'],
        'photoUploads' => count($storage->uploadedPaths),
        'availability' => $updatedAvailability,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    echo 'TEST FAILED: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
