<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerStore.php';

final class MasterDealerFinancialService
{
    public function __construct(
        private readonly MasterDealerStore $store
    ) {
    }

    /**
     * @return array{balanceMinorUnits: int, balanceEgp: float, updatedAt: ?string}
     */
    public function summary(): array
    {
        $account = $this->store->document('masterDealerFinance/account') ?? [];
        $balance = (int) ($account['balanceMinorUnits'] ?? 0);

        return [
            'balanceMinorUnits' => $balance,
            'balanceEgp' => $balance / 100,
            'updatedAt' => is_string($account['updatedAt'] ?? null) ? $account['updatedAt'] : null,
        ];
    }
}
