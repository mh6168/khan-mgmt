<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GoldPriceService.php';

final class MasterDealerPricingService
{
    public function __construct(
        private readonly ?GoldPriceService $goldService = null,
        private readonly ?GoldPriceService $silverService = null
    ) {
    }

    /**
     * @return array{goldUpdatedAt: ?string, silverUpdatedAt: ?string, source: string, gold24kPerGram: ?float, silver925PerGram: ?float, lira: array<string, ?float>, para: array<string, ?float>}
     */
    public function latest(): array
    {
        $gold = ($this->goldService ?? GoldPriceService::fromEnvironment('XAU'))->latest();
        $silver = ($this->silverService ?? GoldPriceService::fromEnvironment('XAG'))->latest();

        $goldPerGram = ($gold['available'] ?? false) === true && strtoupper((string) ($gold['currency'] ?? '')) === 'EGP'
            ? $this->positiveFloat($gold['price_gram_24k'] ?? null)
            : null;
        $silver925PerGram = ($silver['available'] ?? false) === true && strtoupper((string) ($silver['currency'] ?? '')) === 'EGP'
            ? $this->positiveFloat($silver['price_gram_925'] ?? null)
            : null;

        $liraSpot = $goldPerGram === null ? null : $goldPerGram * 0.01;
        $paraSpot = $silver925PerGram;

        return [
            'goldUpdatedAt' => ($gold['available'] ?? false) === true && is_string($gold['updatedAt'] ?? null)
                ? $gold['updatedAt']
                : null,
            'silverUpdatedAt' => ($silver['available'] ?? false) === true && is_string($silver['updatedAt'] ?? null)
                ? $silver['updatedAt']
                : null,
            'source' => $this->priceSource($gold, $silver),
            'gold24kPerGram' => $goldPerGram,
            'silver925PerGram' => $silver925PerGram,
            'lira' => [
                'spot' => $liraSpot === null ? null : round($liraSpot, 2),
                'selling' => $liraSpot === null ? null : round($liraSpot + 5, 2),
                'buyback' => $liraSpot === null ? null : round($liraSpot + 1, 2),
            ],
            'para' => [
                'spot' => $paraSpot === null ? null : round($paraSpot, 2),
                'selling' => $paraSpot === null ? null : round($paraSpot + 5, 2),
                'buyback' => $paraSpot === null ? null : round($paraSpot + 1, 2),
            ],
        ];
    }

    /**
     * @return array{spot: ?float, selling: ?float, buyback: ?float}
     */
    public static function calculateLira(?float $gold24kPerGram): array
    {
        $spot = $gold24kPerGram === null || !is_finite($gold24kPerGram) || $gold24kPerGram <= 0
            ? null
            : round($gold24kPerGram * 0.01, 2);

        return [
            'spot' => $spot,
            'selling' => $spot === null ? null : round($spot + 5, 2),
            'buyback' => $spot === null ? null : round($spot + 1, 2),
        ];
    }

    /**
     * @return array{spot: ?float, selling: ?float, buyback: ?float}
     */
    public static function calculatePara(?float $silver925PerGram): array
    {
        $spot = $silver925PerGram === null || !is_finite($silver925PerGram) || $silver925PerGram <= 0
            ? null
            : round($silver925PerGram, 2);

        return [
            'spot' => $spot,
            'selling' => $spot === null ? null : round($spot + 5, 2),
            'buyback' => $spot === null ? null : round($spot + 1, 2),
        ];
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        return is_finite($number) && $number > 0 ? $number : null;
    }

    /**
     * @param array<string, mixed> $gold
     * @param array<string, mixed> $silver
     */
    private function priceSource(array $gold, array $silver): string
    {
        $sources = [];
        foreach ([$gold, $silver] as $data) {
            if (($data['available'] ?? false) === true) {
                $sources[] = (string) ($data['source'] ?? 'unknown');
            }
        }

        if ($sources === []) {
            return 'unavailable';
        }

        return count(array_unique($sources)) === 1 ? $sources[0] : 'mixed';
    }
}
