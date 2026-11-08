<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'GoldPriceService.php';

function assertGoldCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cacheFile = tempnam(sys_get_temp_dir(), 'khan-gold-test-');
if ($cacheFile === false) {
    throw new RuntimeException('Unable to create a temporary gold-price cache.');
}

try {
    $service = new GoldPriceService('', $cacheFile, 1800, 2, 'http://127.0.0.1:8765/');
    $normalizedResponse = new ReflectionMethod(GoldPriceService::class, 'normalizeResponse');
    $normalizedResponse->setAccessible(true);
    $normalized = $normalizedResponse->invoke($service, [
        'price_gram_24k' => 2400,
        'price_gram_22k' => 2200,
        'price_gram_21k' => 2100,
        'price_gram_18k' => 1800,
        'timestamp' => time(),
    ]);
    assertGoldCondition(is_array($normalized), 'Valid GoldAPI response should normalize.');
    assertGoldCondition($normalized['price_gram_24k'] === 2400.0, '24K price mismatch.');
    assertGoldCondition($normalized['price_gram_22k'] === 2200.0, '22K price mismatch.');
    assertGoldCondition($normalized['price_gram_21k'] === 2100.0, '21K price mismatch.');
    assertGoldCondition($normalized['price_gram_18k'] === 1800.0, '18K price mismatch.');
    assertGoldCondition($normalizedResponse->invoke($service, ['price_gram_24k' => -5]) === null, 'Invalid negative API prices must be rejected.');

    $cache = $normalized;
    $cache['cacheExpiresAt'] = time() + 600;
    file_put_contents($cacheFile, json_encode($cache, JSON_THROW_ON_ERROR));
    $cached = $service->latest();
    assertGoldCondition(($cached['source'] ?? null) === 'cache', 'Unexpired cache should be returned directly.');
    assertGoldCondition(($cached['available'] ?? false) === true, 'Valid cached prices should remain available.');
    assertGoldCondition($service->priceForKarat(18) === 1800.0, 'Cached karat lookup mismatch.');
    assertGoldCondition($service->priceForKarat(20) === null, 'Unsupported karat should not return a price.');

    $cache['price_gram_24k'] = -1;
    $cache['price_gram_22k'] = 0;
    $cache['price_gram_21k'] = -1;
    $cache['price_gram_18k'] = 0;
    $cache['cacheExpiresAt'] = time() + 600;
    file_put_contents($cacheFile, json_encode($cache, JSON_THROW_ON_ERROR));
    $invalidCacheResult = $service->latest();
    assertGoldCondition(($invalidCacheResult['available'] ?? true) === false, 'Invalid cached prices must not be presented as available.');

    $cache['price_gram_24k'] = 2400;
    $cache['cacheExpiresAt'] = time() - 1;
    file_put_contents($cacheFile, json_encode($cache, JSON_THROW_ON_ERROR));
    $expiredCacheResult = $service->latest();
    assertGoldCondition(($expiredCacheResult['available'] ?? true) === false, 'Expired cache must not be presented as current when API access fails.');

    file_put_contents($cacheFile, '{invalid json');
    $invalidJsonResult = $service->latest();
    assertGoldCondition(($invalidJsonResult['available'] ?? true) === false, 'Malformed cache must fail closed without a fabricated price.');

    $transportFailureService = new GoldPriceService('test-key', null, 1800, 1, 'http://127.0.0.1:1/');
    $transportFailure = $transportFailureService->latest();
    assertGoldCondition(($transportFailure['available'] ?? true) === false, 'GoldAPI transport failure must not fabricate a price.');

    echo json_encode(['status' => 'ok', 'cache' => 'valid/expired/invalid', 'karats' => [24, 22, 21, 18], 'apiTransportFailure' => 'unavailable'], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'TEST FAILED: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($cacheFile);
}