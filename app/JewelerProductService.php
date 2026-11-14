<?php

declare(strict_types=1);

final class JewelerProductService
{
    private const ALLOWED_FILE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_PHOTO_SIZE_BYTES = 10 * 1024 * 1024;
    private const MAX_PHOTO_COUNT = 8;

    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $photoUrls
     * @return array<string, mixed>
     */
    public function normalizeWatchProduct(array $data, array $photoUrls): array
    {
        $brand = trim((string) ($data['brand'] ?? ''));
        $model = trim((string) ($data['model'] ?? ''));
        $watchType = trim((string) ($data['watchType'] ?? ''));
        $caseMaterial = trim((string) ($data['caseMaterial'] ?? ''));
        $dialColor = trim((string) ($data['dialColor'] ?? ''));
        $movementType = trim((string) ($data['movementType'] ?? ''));
        $countryOfOrigin = trim((string) ($data['countryOfOrigin'] ?? ''));
        $price = $data['price'] ?? 0;

        if ($brand === '') {
            throw new InvalidArgumentException('Watch brand is required.');
        }

        if ($model === '') {
            throw new InvalidArgumentException('Watch model is required.');
        }

        if ($watchType === '') {
            throw new InvalidArgumentException('Watch type is required.');
        }

        if ($caseMaterial === '') {
            throw new InvalidArgumentException('Case material is required.');
        }

        if ($dialColor === '') {
            throw new InvalidArgumentException('Dial color is required.');
        }

        if ($movementType === '') {
            throw new InvalidArgumentException('Movement type is required.');
        }

        if ($countryOfOrigin === '') {
            throw new InvalidArgumentException('Country of origin is required.');
        }

        if (!is_numeric($price) || (float) $price <= 0) {
            throw new InvalidArgumentException('Watch price must be a positive number.');
        }

        if ($photoUrls === []) {
            throw new InvalidArgumentException('At least one watch photo is required.');
        }

        $normalized = [
            'watchType' => $watchType,
            'brand' => $brand,
            'model' => $model,
            'caseMaterial' => $caseMaterial,
            'dialColor' => $dialColor,
            'movementType' => $movementType,
            'price' => (float) $price,
            'countryOfOrigin' => $countryOfOrigin,
            'threeDModelUrl' => $this->normalizeOptionalUrl($data['threeDModelUrl'] ?? null),
            'photoPaths' => array_values(array_map('strval', $photoUrls)),
        ];

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $photoUrls
     * @return array<string, mixed>
     */
    public function normalizeJewelryProduct(array $data, array $photoUrls): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $category = trim((string) ($data['category'] ?? ''));
        $goldKarat = $data['goldKarat'] ?? null;
        $countryOfOrigin = trim((string) ($data['countryOfOrigin'] ?? ''));
        $goldColor = trim((string) ($data['goldColor'] ?? ''));
        $currentPremium = $data['currentPremium'] ?? null;
        $originalPremium = $data['originalPremium'] ?? null;
        $totalWeight = $data['totalWeight'] ?? null;
        $goldWeight = $data['goldWeight'] ?? null;

        if ($title === '') {
            throw new InvalidArgumentException('Jewelry title is required.');
        }

        if ($category === '') {
            throw new InvalidArgumentException('Jewelry category is required.');
        }

        if ($countryOfOrigin === '') {
            throw new InvalidArgumentException('Jewelry country of origin is required.');
        }

        if ($goldColor === '') {
            throw new InvalidArgumentException('Gold color is required.');
        }

        if (!is_numeric($totalWeight) || (float) $totalWeight <= 0) {
            throw new InvalidArgumentException('Total weight must be a positive number.');
        }

        if (!is_numeric($goldWeight) || (float) $goldWeight <= 0) {
            throw new InvalidArgumentException('Gold weight must be a positive number.');
        }

        if (!is_numeric($goldKarat) || !in_array((int) $goldKarat, [24, 22, 21, 18], true)) {
            throw new InvalidArgumentException('Gold karat must be 24K, 22K, 21K, or 18K.');
        }

        if (!is_numeric($currentPremium) || (float) $currentPremium < 0) {
            throw new InvalidArgumentException('Current premium must be zero or greater.');
        }

        if ($originalPremium !== null && $originalPremium !== '' && (!is_numeric($originalPremium) || (float) $originalPremium < 0)) {
            throw new InvalidArgumentException('Original premium must be zero or greater.');
        }

        if ($photoUrls === []) {
            throw new InvalidArgumentException('At least one jewelry photo is required.');
        }

        if ((float) $goldWeight > (float) $totalWeight) {
            throw new InvalidArgumentException('Gold weight cannot exceed total weight.');
        }

        return [
            'title' => $title,
            'category' => $category,
            'totalWeight' => (float) $totalWeight,
            'goldWeight' => (float) $goldWeight,
            'goldKarat' => (int) $goldKarat,
            'goldColor' => $goldColor,
            'countryOfOrigin' => $countryOfOrigin,
            'currentPremium' => (float) $currentPremium,
            'originalPremium' => $originalPremium === null || $originalPremium === '' ? null : (float) $originalPremium,
            'threeDModelUrl' => $this->normalizeOptionalUrl($data['threeDModelUrl'] ?? null),
            'photoPaths' => array_values(array_map('strval', $photoUrls)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createWatchDocument(string $jewelerId, array $data, array $photoUrls): array
    {
        $document = $this->normalizeWatchProduct($data, $photoUrls);

        return [
            'jewelerId' => $jewelerId,
            'productType' => 'watch',
            'availability' => 'available',
            'createdAt' => gmdate('c'),
            'updatedAt' => gmdate('c'),
            'watchType' => $document['watchType'],
            'brand' => $document['brand'],
            'model' => $document['model'],
            'caseMaterial' => $document['caseMaterial'],
            'dialColor' => $document['dialColor'],
            'movementType' => $document['movementType'],
            'price' => $document['price'],
            'countryOfOrigin' => $document['countryOfOrigin'],
            'threeDModelUrl' => $document['threeDModelUrl'],
            'photoPaths' => $document['photoPaths'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createJewelryDocument(string $jewelerId, array $data, array $photoUrls): array
    {
        $document = $this->normalizeJewelryProduct($data, $photoUrls);

        return [
            'jewelerId' => $jewelerId,
            'productType' => 'jewelry',
            'availability' => 'available',
            'createdAt' => gmdate('c'),
            'updatedAt' => gmdate('c'),
            'title' => $document['title'],
            'category' => $document['category'],
            'totalWeight' => $document['totalWeight'],
            'goldWeight' => $document['goldWeight'],
            'goldKarat' => $document['goldKarat'],
            'goldColor' => $document['goldColor'],
            'countryOfOrigin' => $document['countryOfOrigin'],
            'currentPremium' => $document['currentPremium'],
            'originalPremium' => $document['originalPremium'],
            'threeDModelUrl' => $document['threeDModelUrl'],
            'photoPaths' => $document['photoPaths'],
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, float|int> $goldPrices
     * @return array{currentPrice: ?float, originalPrice: ?float, discountPerGram: ?float, discountAmount: ?float, discountPercent: ?float}
     */
    public function calculateJewelryPricing(array $product, array $goldPrices): array
    {
        $karat = (int) ($product['goldKarat'] ?? 0);
        $goldWeight = $product['goldWeight'] ?? null;
        $currentPremium = $product['currentPremium'] ?? null;
        $originalPremium = $product['originalPremium'] ?? null;
        $goldPricePerGram = $goldPrices[$karat] ?? null;

        if (!is_numeric($goldPricePerGram) || (float) $goldPricePerGram <= 0 || !is_numeric($goldWeight) || (float) $goldWeight <= 0 || !is_numeric($currentPremium)) {
            return ['currentPrice' => null, 'originalPrice' => null, 'discountPerGram' => null, 'discountAmount' => null, 'discountPercent' => null];
        }

        $currentPrice = (float) $goldWeight * ((float) $goldPricePerGram + (float) $currentPremium);
        if (!is_numeric($originalPremium) || (float) $originalPremium <= (float) $currentPremium) {
            return ['currentPrice' => $currentPrice, 'originalPrice' => null, 'discountPerGram' => null, 'discountAmount' => null, 'discountPercent' => null];
        }

        $originalPrice = (float) $goldWeight * ((float) $goldPricePerGram + (float) $originalPremium);
        $discountAmount = $originalPrice - $currentPrice;

        return [
            'currentPrice' => $currentPrice,
            'originalPrice' => $originalPrice,
            'discountPerGram' => (float) $originalPremium - (float) $currentPremium,
            'discountAmount' => $discountAmount,
            'discountPercent' => $originalPrice > 0 ? ($discountAmount / $originalPrice) * 100 : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $uploadedFiles
     * @return list<string>
     */
    public function uploadProductPhotos(object $storage, string $jewelerId, string $productType, array $uploadedFiles): array
    {
        $normalizedType = strtolower(trim($productType));

        if (!method_exists($storage, 'upload')) {
            throw new InvalidArgumentException('Selected storage provider does not support product photo uploads.');
        }

        if (!in_array($normalizedType, ['watch', 'jewelry'], true)) {
            throw new InvalidArgumentException('Unsupported product type for uploads.');
        }

        if ($jewelerId === '') {
            throw new InvalidArgumentException('Jeweler ID is required for product upload.');
        }

        if (count($uploadedFiles) > self::MAX_PHOTO_COUNT) {
            throw new InvalidArgumentException('A product can have up to 8 photos.');
        }

        $uploadedPaths = [];

        foreach ($uploadedFiles as $fileData) {
            if (!is_array($fileData)) {
                throw new InvalidArgumentException('Uploaded product image is invalid.');
            }

            $name = (string) ($fileData['name'] ?? 'product-photo');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $tmpName = (string) ($fileData['tmp_name'] ?? '');
            $size = (int) ($fileData['size'] ?? 0);
            $uploadError = (int) ($fileData['error'] ?? UPLOAD_ERR_OK);

            if ($extension === '' || !in_array($extension, self::ALLOWED_FILE_EXTENSIONS, true)) {
                throw new InvalidArgumentException('Only JPG, JPEG, PNG, and WEBP product images are supported.');
            }

            if ($uploadError !== UPLOAD_ERR_OK || $tmpName === '' || !is_file($tmpName) || !is_readable($tmpName)) {
                throw new InvalidArgumentException('Uploaded product image file is missing or unreadable.');
            }

            $actualSize = filesize($tmpName);
            if ($actualSize === false || $actualSize < 1 || $actualSize > self::MAX_PHOTO_SIZE_BYTES || ($size > 0 && $size > self::MAX_PHOTO_SIZE_BYTES)) {
                throw new InvalidArgumentException('Each product photo must be 10MB or smaller.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $contentType = $finfo->file($tmpName);
            $expectedMimeTypes = [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
            ];
            if (!is_string($contentType) || !in_array($contentType, $expectedMimeTypes[$extension], true)) {
                throw new InvalidArgumentException('Product photo content does not match its file type.');
            }

            $contents = file_get_contents($tmpName);
            if ($contents === false) {
                throw new RuntimeException('Unable to read the uploaded product image.');
            }

            $path = 'jewelers/' . $jewelerId . '/' . $normalizedType . '/photos/' . bin2hex(random_bytes(8)) . '.' . $extension;
            $storage->upload($path, $contents, $contentType);
            $uploadedPaths[] = $path;
        }

        if ($uploadedPaths === []) {
            throw new InvalidArgumentException('At least one product photo is required.');
        }

        return $uploadedPaths;
    }

    /**
     * @param array<string, mixed> $product
     * @return list<string>
     */
    public function signedPhotoUrls(object $storage, array $product): array
    {
        $paths = $product['photoPaths'] ?? $product['photoUrls'] ?? [];
        if (!is_array($paths)) {
            return [];
        }

        $urls = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $urls[] = filter_var($path, FILTER_VALIDATE_URL)
                ? $path
                : $storage->createSignedUrl($path, 3600);
        }

        return $urls;
    }

    /**
     * @param list<string> $photoPaths
     */
    public function deleteProductPhotos(object $storage, array $photoPaths): void
    {
        if (!method_exists($storage, 'delete')) {
            throw new InvalidArgumentException('Selected storage provider does not support product photo deletion.');
        }

        foreach ($photoPaths as $path) {
            if (is_string($path) && $path !== '' && !filter_var($path, FILTER_VALIDATE_URL)) {
                $storage->delete($path);
            }
        }
    }

    public function normalizeAvailability(string $value): string
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, ['available', 'unavailable'], true)) {
            throw new InvalidArgumentException('Product availability must be either available or unavailable.');
        }

        return $normalized;
    }

    private function normalizeOptionalUrl(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) {
            throw new InvalidArgumentException('Optional 3D model URL must be a valid HTTPS URL.');
        }

        return $url;
    }

}
