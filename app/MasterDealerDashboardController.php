<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerAuth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'FirestoreAdminClient.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerStore.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerPricingService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerInventoryService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerBuybackService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerFinancialService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerTransactionService.php';

final class MasterDealerDashboardController
{
    private readonly MasterDealerStore $store;
    private readonly MasterDealerPricingService $pricing;
    private readonly MasterDealerInventoryService $inventory;
    private readonly MasterDealerBuybackService $buybacks;
    private readonly MasterDealerFinancialService $financial;
    private readonly MasterDealerTransactionService $transactions;

    public function __construct()
    {
        MasterDealerAuth::requireAuthenticated();
        try {
            $this->store = MasterDealerStore::fromEnvironment();
        } catch (InvalidArgumentException $exception) {
            error_log('Master Dealer data service configuration failure: ' . $exception->getMessage());
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Master Dealer unavailable | KHAN</title></head><body><main><h1>Master Dealer data is unavailable</h1><p>Configure a valid Firebase project and server-only service-account file, then reload this page. No inventory or financial data was changed.</p><form method="post" action="' . self::escape(MasterDealerAuth::url('logout.php')) . '"><input type="hidden" name="csrf_token" value="' . self::escape(MasterDealerAuth::csrfToken()) . '"><button type="submit">Log out</button></form></main></body></html>';
            exit;
        }
        $this->pricing = new MasterDealerPricingService();
        $this->inventory = new MasterDealerInventoryService($this->store);
        $this->buybacks = new MasterDealerBuybackService($this->store, $this->pricing);
        $this->financial = new MasterDealerFinancialService($this->store);
        $this->transactions = new MasterDealerTransactionService($this->store, $this->pricing);
    }

    public function inventory(): MasterDealerInventoryService
    {
        return $this->inventory;
    }

    public function pricing(): MasterDealerPricingService
    {
        return $this->pricing;
    }

    public function buybacks(): MasterDealerBuybackService
    {
        return $this->buybacks;
    }

    public function financial(): MasterDealerFinancialService
    {
        return $this->financial;
    }

    public function transactions(): MasterDealerTransactionService
    {
        return $this->transactions;
    }

    public function store(): MasterDealerStore
    {
        return $this->store;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function money(int|float $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', ',') . ' EGP';
    }

    public static function quantity(mixed $value): string
    {
        return number_format((int) $value);
    }

    public static function date(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '—';
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Africa/Cairo'))->format('M j, Y · g:i A');
        } catch (Exception) {
            return '—';
        }
    }

    public static function operationKey(string $name): string
    {
        $sessionKey = 'master_dealer_operation_' . $name;
        if (!is_string($_SESSION[$sessionKey] ?? null) ||
            preg_match('/^[a-f0-9]{32}$/', $_SESSION[$sessionKey]) !== 1
        ) {
            $_SESSION[$sessionKey] = bin2hex(random_bytes(16));
        }

        return $_SESSION[$sessionKey];
    }

    public static function rotateOperationKey(string $name): void
    {
        $_SESSION['master_dealer_operation_' . $name] = bin2hex(random_bytes(16));
    }

    /**
     * @return array{csrfToken: string, operationKey: string}
     */
    public static function formTokens(string $operation): array
    {
        return [
            'csrfToken' => MasterDealerAuth::csrfToken(),
            'operationKey' => self::operationKey($operation),
        ];
    }
}
