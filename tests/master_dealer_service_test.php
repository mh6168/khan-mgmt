<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerStore.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'GoldPriceService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerPricingService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerInventoryService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerFinancialService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerBuybackService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'MasterDealerTransactionService.php';

function assertMasterDealer(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function writeMasterDealerPriceCache(string $path, string $metal, float $price): void
{
    $payload = [
        'available' => true,
        'metal' => $metal,
        'currency' => 'EGP',
        'updatedAt' => gmdate(DateTimeInterface::ATOM),
        'updatedAtUnix' => time(),
        'cacheExpiresAt' => time() + 600,
    ];
    $payload[$metal === 'XAU' ? 'price_gram_24k' : 'price_gram_925'] = $price;
    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
}

class InMemoryMasterDealerStore extends MasterDealerStore
{
    /** @var array<string, array<string, mixed>> */
    public array $documents = [];
    private int $idSequence = 0;

    public function __construct()
    {
    }

    public function beginTransaction(): string
    {
        return 'memory-transaction';
    }

    public function read(string $path, string $transaction): ?array
    {
        if (!isset($this->documents[$path])) {
            return null;
        }

        return ['id' => basename($path)] + $this->documents[$path];
    }

    public function document(string $path): ?array
    {
        return $this->read($path, '');
    }

    public function createWrite(string $path, array $data): array
    {
        return ['path' => $path, 'data' => $data, 'create' => true];
    }

    public function updateWrite(string $path, array $data): array
    {
        unset($data['id']);
        return ['path' => $path, 'data' => $data, 'create' => false];
    }

    public function commit(string $transaction, array $writes): void
    {
        foreach ($writes as $write) {
            $exists = isset($this->documents[$write['path']]);
            if (($write['create'] && $exists) || (!$write['create'] && !$exists)) {
                throw new RuntimeException('In-memory transaction precondition failed.');
            }
        }
        foreach ($writes as $write) {
            $this->documents[$write['path']] = $write['data'];
        }
    }

    public function collection(string $name): array
    {
        $result = [];
        foreach ($this->documents as $path => $data) {
            if (str_starts_with($path, $name . '/')) {
                $result[] = ['id' => basename($path)] + $data;
            }
        }
        return $result;
    }

    public function newId(): string
    {
        return str_pad(dechex(++$this->idSequence), 32, '0', STR_PAD_LEFT);
    }

    public function seed(string $path, array $data): void
    {
        $this->documents[$path] = $data;
    }
}

try {
    $liraPricing = MasterDealerPricingService::calculateLira(2500.0);
    assertMasterDealer($liraPricing['spot'] === 25.0, 'Lira spot formula mismatch.');
    assertMasterDealer($liraPricing['selling'] === 30.0, 'Lira selling price mismatch.');
    assertMasterDealer($liraPricing['buyback'] === 26.0, 'Lira buyback price mismatch.');
    $paraPricing = MasterDealerPricingService::calculatePara(100.0);
    assertMasterDealer($paraPricing['selling'] === 105.0, 'Para selling price mismatch.');
    assertMasterDealer($paraPricing['buyback'] === 101.0, 'Para buyback price mismatch.');
    assertMasterDealer(MasterDealerPricingService::calculateLira(2500.5)['spot'] === 25.01, 'Lira spot must round half-up to EGP piasters.');
    assertMasterDealer(MasterDealerPricingService::calculatePara(100.125)['spot'] === 100.13, 'Para spot must round half-up to EGP piasters.');
    assertMasterDealer(MasterDealerPricingService::calculateLira(null)['selling'] === null, 'Unavailable pricing must not fabricate a Lira quote.');

    $goldCache = tempnam(sys_get_temp_dir(), 'khan-md-gold-');
    $silverCache = tempnam(sys_get_temp_dir(), 'khan-md-silver-');
    if ($goldCache === false || $silverCache === false) {
        throw new RuntimeException('Unable to create temporary price caches.');
    }
    writeMasterDealerPriceCache($goldCache, 'XAU', 2500.0);
    writeMasterDealerPriceCache($silverCache, 'XAG', 100.0);
    $pricing = new MasterDealerPricingService(
        new GoldPriceService('', $goldCache, 1800, 2, null, 'XAU'),
        new GoldPriceService('', $silverCache, 1800, 2, null, 'XAG')
    );
    $livePricing = $pricing->latest();
    assertMasterDealer($livePricing['lira']['selling'] === 30.0 && $livePricing['lira']['buyback'] === 26.0, 'Live Lira quote formula mismatch.');
    assertMasterDealer($livePricing['para']['selling'] === 105.0 && $livePricing['para']['buyback'] === 101.0, 'Live Para quote formula mismatch.');

    $store = new InMemoryMasterDealerStore();
    $inventory = new MasterDealerInventoryService($store);
    $operationKey = str_repeat('a', 32);
    assertMasterDealer($inventory->adjust('lira', 100, 'Opening stock', $operationKey), 'Initial inventory adjustment failed.');
    assertMasterDealer(!$inventory->adjust('lira', 100, 'Opening stock', $operationKey), 'Repeated inventory operation should be idempotent.');
    assertMasterDealer($inventory->adjust('para', 50, 'Opening stock', str_repeat('e', 32)), 'Initial Para inventory adjustment failed.');
    assertMasterDealer($inventory->summary()['lira']['quantity'] === 100 && $inventory->summary()['para']['quantity'] === 50, 'Initial inventory does not match the scenario.');
    try {
        $inventory->adjust('lira', 99, 'Different details', $operationKey);
        throw new RuntimeException('An idempotency key must not be reused for different adjustment details.');
    } catch (InvalidArgumentException) {
    }

    try {
        $inventory->adjust('lira', -101, 'Invalid reduction', str_repeat('b', 32));
        throw new RuntimeException('Inventory must not become negative.');
    } catch (InvalidArgumentException) {
    }

    $transactions = new MasterDealerTransactionService($store, $pricing);
    $purchaseKey = 'purchase-test-key-001';
    $purchase = $transactions->createPurchaseOrder('customer-2', 20, 10, $purchaseKey);
    assertMasterDealer($purchase['amountMinorUnits'] === 165000, 'Purchase total did not use the locked Lira and Para selling prices.');
    $retriedPurchase = $transactions->createPurchaseOrder('customer-2', 20, 10, $purchaseKey);
    assertMasterDealer($retriedPurchase['id'] === $purchase['id'], 'Purchase retry did not return the existing order.');
    assertMasterDealer($inventory->summary()['lira']['available'] === 80 && $inventory->summary()['para']['available'] === 40, 'Purchase order did not reserve the requested quantities.');

    try {
        $transactions->settleVerifiedPurchase($purchase['id'], '123456788', $purchase['amountMinorUnits'] + 1);
        throw new RuntimeException('A payment with the wrong amount must not settle.');
    } catch (InvalidArgumentException) {
    }
    assertMasterDealer($inventory->summary()['lira']['quantity'] === 100 && $inventory->summary()['para']['quantity'] === 50, 'Failed payment changed on-hand inventory.');
    assertMasterDealer((new MasterDealerFinancialService($store))->summary()['balanceMinorUnits'] === 0, 'Failed payment changed the financial balance.');

    assertMasterDealer($transactions->settleVerifiedPurchase($purchase['id'], '123456789', $purchase['amountMinorUnits']), 'Verified purchase settlement failed.');
    assertMasterDealer(!$transactions->settleVerifiedPurchase($purchase['id'], '123456789', $purchase['amountMinorUnits']), 'Repeated payment notification should be idempotent.');
    assertMasterDealer($inventory->summary()['lira']['quantity'] === 80 && $inventory->summary()['para']['quantity'] === 40, 'Successful purchase did not deduct the expected inventory.');
    assertMasterDealer($inventory->summary()['lira']['reserved'] === 0 && $inventory->summary()['para']['reserved'] === 0, 'Purchase settlement did not release reservations.');
    assertMasterDealer((new MasterDealerFinancialService($store))->summary()['balanceMinorUnits'] === 165000, 'Verified purchase was not credited to the financial balance.');

    $expiredOrder = $transactions->createPurchaseOrder('customer-3', 1, 0, 'purchase-expiry-key-01');
    $store->documents['masterDealerPurchaseOrders/' . $expiredOrder['id']]['expiresAt'] = gmdate(DateTimeInterface::ATOM, time() - 1);
    assertMasterDealer($transactions->expirePendingOrders() === 1, 'Expired purchase order was not released.');
    assertMasterDealer($inventory->summary()['lira']['quantity'] === 80 && $inventory->summary()['lira']['reserved'] === 0, 'Expired order changed on-hand stock or left a reservation.');

    writeMasterDealerPriceCache($goldCache, 'XAU', 2600.0);
    writeMasterDealerPriceCache($silverCache, 'XAG', 110.0);
    $buybacks = new MasterDealerBuybackService($store, $pricing);
    $buyback = $buybacks->submit('customer-1', 'customer@example.com', 2, 3, '+20 (123) 456-7890', 'buyback-test-key-001');
    assertMasterDealer($buyback['instaPayPhone'] === '+20 (123) 456-7890', 'Buyback InstaPay phone was not saved.');
    assertMasterDealer($buyback['liraUnitPriceMinor'] === 2700 && $buyback['paraUnitPriceMinor'] === 11100, 'Buyback prices were not locked at submission.');
    assertMasterDealer($buyback['totalAmountMinorUnits'] === 38700, 'Buyback payout total is incorrect.');
    assertMasterDealer(($buybacks->request($buyback['id'])['id'] ?? null) === $buyback['id'], 'Buyback request details were not retrievable.');
    assertMasterDealer($buybacks->startProcessing($buyback['id']), 'Buyback processing transition failed.');
    assertMasterDealer(!$buybacks->startProcessing($buyback['id']), 'Repeated processing transition should not change the request.');

    writeMasterDealerPriceCache($goldCache, 'XAU', 3000.0);
    writeMasterDealerPriceCache($silverCache, 'XAG', 120.0);
    assertMasterDealer($buybacks->complete($buyback['id']), 'Buyback completion failed.');
    assertMasterDealer(!$buybacks->complete($buyback['id']), 'Buyback completion must be idempotent.');
    assertMasterDealer($inventory->summary()['lira']['quantity'] === 82 && $inventory->summary()['para']['quantity'] === 43, 'Buyback did not add the expected inventory exactly once.');

    $ledger = $transactions->history();
    $financialMovements = array_values(array_filter(
        $ledger,
        static fn (array $entry): bool => in_array($entry['type'] ?? '', ['customer_purchase', 'customer_buyback'], true)
    ));
    $ledgerBalance = array_sum(array_map(static fn (array $entry): int => (int) ($entry['amountMinorUnits'] ?? 0), $financialMovements));
    assertMasterDealer(count($financialMovements) === 2, 'Each purchase and buyback should create exactly one financial transaction.');
    assertMasterDealer($ledgerBalance === 126300, 'Financial transaction records do not net to the expected balance.');
    assertMasterDealer((new MasterDealerFinancialService($store))->summary()['balanceMinorUnits'] === $ledgerBalance, 'Financial balance does not reconcile to transaction records.');
    assertMasterDealer(count($inventory->movements()) === 4, 'Opening adjustments, purchase, and buyback movements must all be recorded.');

    echo json_encode([
        'status' => 'ok',
        'pricing' => 'live-cache Lira/Para formulas, piaster rounding, and unavailable-price behavior',
        'purchase' => 'order idempotency, reservations, failed amount protection, exact 100/50 to 80/40 settlement, and duplicate payment',
        'buyback' => 'saved phone, locked prices, request details, processing, exact 80/40 to 82/43 completion, and duplicate completion',
        'finance' => 'purchase credit and buyback payout reconcile to transaction ledger',
        'inventoryAdjustment' => 'manual movements are recorded, idempotent, and non-negative',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'TEST FAILED: ' . $exception->getMessage() . PHP_EOL);
    $testFailed = true;
} finally {
    if (isset($goldCache) && is_string($goldCache)) {
        @unlink($goldCache);
    }
    if (isset($silverCache) && is_string($silverCache)) {
        @unlink($silverCache);
    }
}

if (($testFailed ?? false) === true) {
    exit(1);
}
