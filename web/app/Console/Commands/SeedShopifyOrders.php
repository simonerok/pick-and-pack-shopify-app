<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/* creates real order in the configured Shopify dev store for manuel QA of order tabs

typacal admin api scopes: read_products, read orders, write orders, read inventory, read locations, write 

dev store - where the test data lives

 * Creates real orders in the configured Shopify dev store for manual QA of order tabs.
 *
 * Typical Admin API scopes: read_products, read_orders, write_orders, read_inventory,
 * read_locations, write_fulfillments (names may differ in Partner UI).
 *
 * Uses SHOPIFY_STORE_DOMAIN, SHOPIFY_ACCESS_TOKEN, SHOPIFY_API_VERSION from web/.env.
 *
 * 30 orders: ready-to-pack, upcoming (unpaid + paid/custom line), on-hold, closed.
 * "Ready for pickup" is not seeded — it needs Shopify pickup + fulfillment state.
 * 
 * 
 * 
 * HOW TO USE
 * public function register(): void
{
    if ($this->app->runningInConsole()) {
        $this->commands([
            SeedShopifyTestOrders::class,
        ]);
    }
}


php artisan shopify:seed-test-orders --dry-run
php artisan shopify:seed-test-orders
# or
php artisan shopify:seed-test-orders --variant-id=1234567890

  */

class SeedShopifyTestOrders extends Command
{
    protected $signature = 'shopify:seed-test-orders
                            {--dry-run : Print payloads only, do not call Shopify}
                            {--variant-id= : Numeric variant id (otherwise first active product variant is used)}';

    protected $description = 'Create 30 real test orders in Shopify (dev store) for tab QA';

    private string $host = '';

    private string $token = '';

    private string $apiVersion = '';

    private ?int $locationId = null;

    public function handle(): int
    {
        $store = trim((string) env('SHOPIFY_STORE_DOMAIN', ''));
        $this->token = trim((string) env('SHOPIFY_ACCESS_TOKEN', ''));
        $this->apiVersion = trim((string) env('SHOPIFY_API_VERSION', '')) ?: '2025-10';

        if ($store === '' || $this->token === '') {
            $this->error('Set SHOPIFY_STORE_DOMAIN and SHOPIFY_ACCESS_TOKEN in web/.env');

            return self::FAILURE;
        }

        $this->host = preg_replace('#^https?://#', '', $store);
        $this->host = rtrim($this->host, '/');
        if ($this->host === '') {
            $this->error('Invalid SHOPIFY_STORE_DOMAIN');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $variantIdOpt = $this->option('variant-id');
        $variantId = is_string($variantIdOpt) && ctype_digit($variantIdOpt)
            ? (int) $variantIdOpt
            : null;

        if ($variantId === null && ! $dryRun) {
            $variantId = $this->resolveFirstVariantId();
            if ($variantId === null) {
                $this->error('No product variant found. Create a product or pass --variant-id=NUMERIC_ID');

                return self::FAILURE;
            }
            $this->info("Using variant_id={$variantId} (override with --variant-id=)");
        }

        if ($variantId === null && $dryRun) {
            $this->warn('Dry run: using placeholder variant_id=1 in payloads (pass --variant-id= for realism).');
            $variantId = 1;
        }

        $price = '19.99';
        $price = $this->fetchVariantPrice($variantId) ?? $price;

        $this->locationId = $dryRun ? 1 : $this->resolvePrimaryLocationId();
        if ($this->locationId === null && ! $dryRun) {
            $this->error('Could not resolve location_id (needs read_locations on the Admin token).');

            return self::FAILURE;
        }

        $plan = $this->buildPlan($variantId, $price);

        $this->warn('Creates REAL orders in Shopify — use a development store only.');
        if (! $dryRun && ! $this->confirm('Continue?', true)) {
            return self::SUCCESS;
        }

        foreach ($plan as $i => $row) {
            $label = $row['label'];
            $payload = $row['payload'];
            $after = $row['after'] ?? null;

            $this->line(sprintf('[%d/%d] %s', $i + 1, count($plan), $label));

            if ($dryRun) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT));

                continue;
            }

            try {
                $orderId = $this->postOrder($payload);
                $this->info("  → order id {$orderId}");
                if ($after !== null) {
                    $after($orderId);
                }
            } catch (\Throwable $e) {
                $this->error('  → ' . $e->getMessage());

                return self::FAILURE;
            }

            usleep(250000);
        }

        $this->info('Done. Reload the orders page in the app.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{label: string, payload: array<string, mixed>, after?: callable(int): void}>
     */
    private function buildPlan(int $variantId, string $price): array
    {
        $uniq = static fn(): string => bin2hex(random_bytes(4));

        $paidTx = static function (string $amount): array {
            return [
                [
                    'kind' => 'sale',
                    'status' => 'success',
                    'amount' => $amount,
                    'gateway' => 'bogus',
                ],
            ];
        };

        $variantLine = static function (int $vid, int $qty): array {
            return [
                [
                    'variant_id' => $vid,
                    'quantity' => $qty,
                ],
            ];
        };

        $customPaidLine = static function (string $amt): array {
            return [
                [
                    'title' => 'Seed custom line (no variant)',
                    'price' => $amt,
                    'quantity' => 1,
                    'requires_shipping' => true,
                ],
            ];
        };

        $baseOrder = static function (string $email, array $lineItems, ?array $transactions, string $tags) use ($uniq): array {
            $o = [
                'email' => $email,
                'line_items' => $lineItems,
                'send_receipt' => false,
                'send_fulfillment_receipt' => false,
                'tags' => $tags,
                'note' => 'seed:' . $uniq(),
            ];
            if ($transactions !== null) {
                $o['transactions'] = $transactions;
            }

            return ['order' => $o];
        };

        $plan = [];

        for ($i = 0; $i < 8; $i++) {
            $plan[] = [
                'label' => 'ready-to-pack (paid variant, no On hold)',
                'payload' => $baseOrder(
                    "seed-rtp-{$uniq()}@example.com",
                    $variantLine($variantId, 1),
                    $paidTx($price),
                    'seed,ready-to-pack-candidate'
                ),
            ];
        }

        for ($i = 0; $i < 5; $i++) {
            $plan[] = [
                'label' => 'upcoming (unpaid — no transactions)',
                'payload' => $baseOrder(
                    "seed-up-unpaid-{$uniq()}@example.com",
                    $variantLine($variantId, 1),
                    null,
                    'seed,upcoming-unpaid'
                ),
            ];
        }

        for ($i = 0; $i < 5; $i++) {
            $plan[] = [
                'label' => 'upcoming (paid + custom line — not fully available in app)',
                'payload' => $baseOrder(
                    "seed-up-custom-{$uniq()}@example.com",
                    $customPaidLine($price),
                    $paidTx($price),
                    'seed,upcoming-custom-paid'
                ),
            ];
        }

        for ($i = 0; $i < 6; $i++) {
            $plan[] = [
                'label' => 'on-hold (paid + On hold tag)',
                'payload' => $baseOrder(
                    "seed-hold-{$uniq()}@example.com",
                    $variantLine($variantId, 1),
                    $paidTx($price),
                    'On hold,seed,on-hold-candidate'
                ),
            ];
        }

        for ($i = 0; $i < 6; $i++) {
            $plan[] = [
                'label' => 'closed tab (paid → fulfill → close)',
                'payload' => $baseOrder(
                    "seed-closed-{$uniq()}@example.com",
                    $variantLine($variantId, 1),
                    $paidTx($price),
                    'seed,closed-candidate'
                ),
                'after' => function (int $orderId): void {
                    $this->fulfillAndCloseOrder($orderId);
                },
            ];
        }

        return $plan;
    }

    private function fulfillAndCloseOrder(int $orderId): void
    {
        $order = $this->getJson('GET', "/orders/{$orderId}.json");
        $orderData = $order['order'] ?? null;
        if (! is_array($orderData)) {
            throw new \RuntimeException('Unexpected GET order response');
        }

        $lineItems = $orderData['line_items'] ?? [];
        if (! is_array($lineItems) || $lineItems === []) {
            throw new \RuntimeException('Order has no line_items to fulfill');
        }

        $firstLineId = (int) ($lineItems[0]['id'] ?? 0);
        if ($firstLineId <= 0) {
            throw new \RuntimeException('Could not read line_item id');
        }

        $fulfillmentBody = [
            'fulfillment' => [
                'location_id' => $this->locationId,
                'notify_customer' => false,
                'line_items' => [
                    ['id' => $firstLineId, 'quantity' => 1],
                ],
            ],
        ];

        $this->postJson('POST', "/orders/{$orderId}/fulfillments.json", $fulfillmentBody);

        $closeRes = Http::timeout(60)->withHeaders($this->adminHeaders())
            ->post($this->restUrl("/orders/{$orderId}/close.json"), new \stdClass());
        if (! $closeRes->successful()) {
            throw new \RuntimeException('Close order failed: HTTP ' . $closeRes->status() . ' ' . $closeRes->body());
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(string $method, string $path, array $body): array
    {
        $res = Http::timeout(90)->withHeaders($this->adminHeaders())
            ->send($method, $this->restUrl($path), ['json' => $body]);
        if (! $res->successful()) {
            throw new \RuntimeException('HTTP ' . $res->status() . ' ' . $res->body());
        }

        return $res->json() ?? [];
    }

    private function getJson(string $method, string $path): array
    {
        $res = Http::timeout(90)->withHeaders($this->adminHeaders())
            ->send($method, $this->restUrl($path));
        if (! $res->successful()) {
            throw new \RuntimeException('HTTP ' . $res->status() . ' ' . $res->body());
        }

        return $res->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postOrder(array $payload): int
    {
        $json = $this->postJson('POST', '/orders.json', $payload);
        $order = $json['order'] ?? null;
        if (! is_array($order) || empty($order['id'])) {
            throw new \RuntimeException('Unexpected orders.json response: ' . json_encode($json));
        }

        return (int) $order['id'];
    }

    private function restUrl(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return 'https://' . $this->host . '/admin/api/' . $this->apiVersion . $path;
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'X-Shopify-Access-Token' => $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private function resolveFirstVariantId(): ?int
    {
        $res = Http::timeout(60)->withHeaders($this->adminHeaders())
            ->get($this->restUrl('/products.json?limit=10&status=active'));
        if (! $res->successful()) {
            $this->error($res->body());

            return null;
        }
        $products = $res->json('products');
        if (! is_array($products)) {
            return null;
        }
        foreach ($products as $product) {
            $variants = $product['variants'] ?? null;
            if (! is_array($variants)) {
                continue;
            }
            foreach ($variants as $v) {
                $id = isset($v['id']) ? (int) $v['id'] : 0;
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return null;
    }

    private function fetchVariantPrice(int $variantId): ?string
    {
        $res = Http::timeout(60)->withHeaders($this->adminHeaders())
            ->get($this->restUrl('/variants/' . $variantId . '.json'));
        if (! $res->successful()) {
            return null;
        }
        $v = $res->json('variant');

        return is_array($v) && isset($v['price']) ? (string) $v['price'] : null;
    }

    private function resolvePrimaryLocationId(): ?int
    {
        $res = Http::timeout(60)->withHeaders($this->adminHeaders())
            ->get($this->restUrl('/locations.json'));
        if (! $res->successful()) {
            return null;
        }
        $locations = $res->json('locations');
        if (! is_array($locations) || $locations === []) {
            return null;
        }
        $first = $locations[0];

        return isset($first['id']) ? (int) $first['id'] : null;
    }
}


