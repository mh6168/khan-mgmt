<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerStore.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerPricingService.php';

final class MasterDealerBuybackService
{
    public function __construct(
        private readonly MasterDealerStore $store,
        private readonly MasterDealerPricingService $pricing
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function submit(
        string $customerUid,
        string $customerEmail,
        int $liraQuantity,
        int $paraQuantity,
        string $instaPayPhone,
        ?string $idempotencyKey = null
    ): array {
        if ($customerUid === '' || strlen($customerUid) > 128) {
            throw new InvalidArgumentException('Customer identity is invalid.');
        }
        if ($liraQuantity < 0 || $paraQuantity < 0 || $liraQuantity > 10000 || $paraQuantity > 10000 ||
            ($liraQuantity === 0 && $paraQuantity === 0)
        ) {
            throw new InvalidArgumentException('Enter a positive quantity of Liras or Paras.');
        }
        $instaPayPhone = trim($instaPayPhone);
        $phoneDigits = preg_replace('/\D/', '', $instaPayPhone) ?? '';
        if (preg_match('/^[0-9+ ()-]{7,24}$/', $instaPayPhone) !== 1 || strlen($phoneDigits) < 7 || strlen($phoneDigits) > 20) {
            throw new InvalidArgumentException('Enter a valid InstaPay phone number.');
        }
        if ($idempotencyKey !== null && preg_match('/^[A-Za-z0-9._-]{16,128}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Buyback idempotency key is invalid.');
        }

        $id = $idempotencyKey === null
            ? $this->store->newId()
            : substr(hash('sha256', $customerUid . ':' . $idempotencyKey), 0, 32);
        $path = 'masterDealerBuybacks/' . $id;
        $transaction = $this->store->beginTransaction();
        $existingRequest = $this->store->read($path, $transaction);
        if ($existingRequest !== null) {
            if (($existingRequest['customerUid'] ?? '') !== $customerUid ||
                (int) ($existingRequest['liraQuantity'] ?? -1) !== $liraQuantity ||
                (int) ($existingRequest['paraQuantity'] ?? -1) !== $paraQuantity ||
                ($existingRequest['instaPayPhone'] ?? '') !== $instaPayPhone
            ) {
                throw new InvalidArgumentException('Buyback idempotency key was already used for different request details.');
            }
            $existingRequest['id'] = $id;
            $existingRequest['totalAmountEgp'] = ((int) ($existingRequest['totalAmountMinorUnits'] ?? 0)) / 100;
            return $existingRequest;
        }

        $prices = $this->pricing->latest();
        $liraUnitPriceMinor = $liraQuantity > 0 ? $this->toMinorUnits($prices['lira']['buyback']) : 0;
        $paraUnitPriceMinor = $paraQuantity > 0 ? $this->toMinorUnits($prices['para']['buyback']) : 0;
        $totalAmountMinor = $liraQuantity * $liraUnitPriceMinor + $paraQuantity * $paraUnitPriceMinor;
        if ($totalAmountMinor <= 0) {
            throw new RuntimeException('Live buyback pricing is unavailable.');
        }

        $request = [
            'customerUid' => $customerUid,
            'customerEmail' => filter_var($customerEmail, FILTER_VALIDATE_EMAIL) ? strtolower($customerEmail) : '',
            'liraQuantity' => $liraQuantity,
            'paraQuantity' => $paraQuantity,
            'liraUnitPriceMinor' => $liraUnitPriceMinor,
            'paraUnitPriceMinor' => $paraUnitPriceMinor,
            'totalAmountMinorUnits' => $totalAmountMinor,
            'currency' => 'EGP',
            'instaPayPhone' => $instaPayPhone,
            'status' => 'pending',
            'submittedAt' => gmdate('c'),
        ];
        $this->store->commit($transaction, [
            $this->store->createWrite($path, $request),
        ]);
        $request['id'] = $id;
        $request['totalAmountEgp'] = $totalAmountMinor / 100;

        return $request;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        $requests = $this->store->collection('masterDealerBuybacks');
        usort($requests, static fn (array $left, array $right): int =>
            strcmp((string) ($right['submittedAt'] ?? ''), (string) ($left['submittedAt'] ?? ''))
        );

        return $requests;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function request(string $id): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            return null;
        }

        return $this->store->document('masterDealerBuybacks/' . $id);
    }

    public function startProcessing(string $id): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new InvalidArgumentException('Buyback request ID is invalid.');
        }

        $path = 'masterDealerBuybacks/' . $id;
        $transaction = $this->store->beginTransaction();
        $request = $this->store->read($path, $transaction);
        if ($request === null) {
            throw new InvalidArgumentException('Buyback request was not found.');
        }
        if (($request['status'] ?? '') === 'completed') {
            return false;
        }
        if (($request['status'] ?? '') === 'processing') {
            return false;
        }
        if (($request['status'] ?? '') !== 'pending') {
            throw new InvalidArgumentException('Buyback request cannot be processed.');
        }

        $request['status'] = 'processing';
        $request['processingStartedAt'] = gmdate('c');
        $this->store->commit($transaction, [$this->store->updateWrite($path, $request)]);
        return true;
    }

    public function complete(string $id): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new InvalidArgumentException('Buyback request ID is invalid.');
        }

        $requestPath = 'masterDealerBuybacks/' . $id;
        $ledgerPath = 'masterDealerTransactions/buyback-' . $id;
        $liraPath = 'masterDealerInventory/lira';
        $paraPath = 'masterDealerInventory/para';
        $financePath = 'masterDealerFinance/account';
        $transaction = $this->store->beginTransaction();
        $request = $this->store->read($requestPath, $transaction);
        $existingEntry = $this->store->read($ledgerPath, $transaction);
        if ($existingEntry !== null || ($request['status'] ?? '') === 'completed') {
            return false;
        }
        if ($request === null || !in_array($request['status'] ?? '', ['pending', 'processing'], true)) {
            throw new InvalidArgumentException('Buyback request is not ready for completion.');
        }

        $lira = $this->store->read($liraPath, $transaction);
        $para = $this->store->read($paraPath, $transaction);
        $finance = $this->store->read($financePath, $transaction);
        $liraQuantity = (int) ($request['liraQuantity'] ?? 0);
        $paraQuantity = (int) ($request['paraQuantity'] ?? 0);
        $amount = (int) ($request['totalAmountMinorUnits'] ?? 0);
        if ($amount <= 0) {
            throw new RuntimeException('Buyback payout amount is invalid.');
        }

        $now = gmdate('c');
        $request['status'] = 'completed';
        $request['completedAt'] = $now;
        $request['payoutRecorded'] = true;
        $financeBalance = (int) ($finance['balanceMinorUnits'] ?? 0) - $amount;
        $ledgerEntry = [
            'type' => 'customer_buyback',
            'status' => 'completed',
            'source' => 'instapay_manual',
            'buybackRequestId' => $id,
            'customerUid' => (string) ($request['customerUid'] ?? ''),
            'liraQuantity' => $liraQuantity,
            'paraQuantity' => $paraQuantity,
            'liraUnitPriceMinor' => (int) ($request['liraUnitPriceMinor'] ?? 0),
            'paraUnitPriceMinor' => (int) ($request['paraUnitPriceMinor'] ?? 0),
            'amountMinorUnits' => -$amount,
            'payoutAmountMinorUnits' => $amount,
            'createdAt' => $now,
        ];

        $writes = [
            $this->inventoryWrite($liraPath, $lira, (int) ($lira['quantity'] ?? 0) + $liraQuantity, (int) ($lira['reserved'] ?? 0)),
            $this->inventoryWrite($paraPath, $para, (int) ($para['quantity'] ?? 0) + $paraQuantity, (int) ($para['reserved'] ?? 0)),
            $finance === null
                ? $this->store->createWrite($financePath, ['balanceMinorUnits' => $financeBalance, 'updatedAt' => $now])
                : $this->store->updateWrite($financePath, ['balanceMinorUnits' => $financeBalance, 'updatedAt' => $now]),
            $this->store->updateWrite($requestPath, $request),
            $this->store->createWrite($ledgerPath, $ledgerEntry),
        ];
        $this->store->commit($transaction, $writes);

        return true;
    }

    private function toMinorUnits(?float $price): int
    {
        if ($price === null || !is_finite($price) || $price <= 0) {
            throw new RuntimeException('Live buyback pricing is unavailable.');
        }

        return (int) round($price * 100, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function inventoryWrite(string $path, ?array $existing, int $quantity, int $reserved): array
    {
        $data = [
            'asset' => str_ends_with($path, '/lira') ? 'lira' : 'para',
            'quantity' => $quantity,
            'reserved' => $reserved,
            'updatedAt' => gmdate('c'),
        ];

        return $existing === null
            ? $this->store->createWrite($path, $data)
            : $this->store->updateWrite($path, $data);
    }
}
