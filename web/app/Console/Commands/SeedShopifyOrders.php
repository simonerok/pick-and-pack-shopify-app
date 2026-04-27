<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SeedShopifyOrders extends Command
{
    protected $signature = 'shopify:seed-test-orders
                            {--dry-run : Print the GraphQL variables only, do not call Shopify}
                            {--variant-id= : Numeric Shopify product variant ID}
                            {--count=30 : Number of orders to create}
                            {--delay-ms=13000 : Delay between orderCreate calls}
                            {--continue-on-error : Keep creating orders after an error}';

    protected $description = 'Create real Shopify test orders with Admin GraphQL for manual QA';

    private const ORDER_CREATE_MUTATION = <<<'GQL'
mutation SeedOrderCreate($order: OrderCreateOrderInput!, $options: OrderCreateOptionsInput) {
  orderCreate(order: $order, options: $options) {
    userErrors {
      field
      message
    }
    order {
      id
      name
      number
      displayFinancialStatus
      displayFulfillmentStatus
      closedAt
    }
  }
}
GQL;

    private const TAGS_ADD_MUTATION = <<<'GQL'
mutation SeedTagsAdd($id: ID!, $tags: [String!]!) {
  tagsAdd(id: $id, tags: $tags) {
    userErrors {
      field
      message
    }
  }
}
GQL;

    private const ORDER_CLOSE_MUTATION = <<<'GQL'
mutation SeedOrderClose($input: OrderCloseInput!) {
  orderClose(input: $input) {
    userErrors {
      field
      message
    }
    order {
      id
      closedAt
    }
  }
}
GQL;

    private const SHOP_QUERY = <<<'GQL'
query SeedShopInfo {
  shop {
    currencyCode
  }
}
GQL;

    private const FIRST_VARIANT_QUERY = <<<'GQL'
query SeedFirstVariant {
  products(first: 25, query: "status:active") {
    nodes {
      title
      variants(first: 25) {
        nodes {
          id
          title
          sku
          price
          product {
            title
          }
        }
      }
    }
  }
  shop {
    currencyCode
  }
}
GQL;

    private string $host = '';

    private string $token = '';

    private string $apiVersion = '';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->apiVersion = trim((string) env('SHOPIFY_API_VERSION', '')) ?: '2025-10';
        $this->token = trim((string) env('SHOPIFY_ACCESS_TOKEN', ''));
        $this->host = $this->normalizeShopHost((string) env('SHOPIFY_STORE_DOMAIN', ''));

        if (($this->host === '' || $this->token === '') && ! $dryRun) {
            $this->error('Set SHOPIFY_STORE_DOMAIN and SHOPIFY_ACCESS_TOKEN in web/.env.');

            return self::FAILURE;
        }

        if ($dryRun && ($this->host === '' || $this->token === '')) {
            $this->warn('Dry run: Shopify env values are missing, so placeholder values are used.');
            $this->host = $this->host !== '' ? $this->host : 'your-dev-store.myshopify.com';
        }

        $count = $this->positiveIntOption('count', 30);
        $delayMs = max(0, $this->positiveIntOption('delay-ms', 13000));
        $variantId = $this->variantIdOption();
        $currency = $dryRun ? 'DKK' : $this->resolveShopCurrency();
        $price = '599.00';

        if ($variantId === null && ! $dryRun) {
            $variant = $this->resolveFirstVariant();
            if ($variant === null) {
                $this->error('No active product variant found. Create a product or pass --variant-id=NUMERIC_ID.');

                return self::FAILURE;
            }
            $variantId = $variant['id'];
            $price = $variant['price'];
            $this->info(sprintf(
                'Using variant_id=%d (%s, price %s %s).',
                $variantId,
                $variant['label'],
                $price,
                $currency
            ));
        }

        if ($variantId === null) {
            $variantId = 1;
            $this->warn('Dry run: using placeholder variant_id=1. Pass --variant-id= for realistic payloads.');
        }

        if (! $dryRun && $count > 5 && $delayMs < 12000) {
            $this->warn(
                'Shopify development/trial stores allow about 5 orderCreate calls per minute. '
                . 'Use --delay-ms=13000 unless this is not a development/trial store.'
            );
        }

        $plan = $this->buildPlan($variantId, $price, $currency, $count);
        $this->warn('This creates REAL Shopify orders. Use a development store only.');
        $this->line(sprintf(
            'Plan: %d orders via Admin GraphQL %s.',
            count($plan),
            $dryRun ? '(dry run)' : 'against ' . $this->host
        ));

        if (! $dryRun && ! $this->confirm('Continue?', false)) {
            return self::SUCCESS;
        }

        $failures = 0;
        foreach ($plan as $index => $row) {
            $this->line(sprintf('[%d/%d] %s', $index + 1, count($plan), $row['label']));

            if ($dryRun) {
                $this->line(json_encode($row['variables'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                continue;
            }

            try {
                $created = $this->createOrder($row['variables']);
                $this->info(sprintf(
                    '  -> created %s (%s)',
                    $created['name'] ?? $created['id'],
                    $created['id']
                ));

                if ($row['tags'] !== []) {
                    $this->addTags((string) $created['id'], $row['tags']);
                    $this->line('  -> tagged: ' . implode(', ', $row['tags']));
                }

                if (($row['closeAfterCreate'] ?? false) === true) {
                    $this->closeOrder((string) $created['id']);
                    $this->line('  -> closed/archived');
                }
            } catch (\Throwable $e) {
                $failures++;
                $this->error('  -> ' . $e->getMessage());

                if (! (bool) $this->option('continue-on-error')) {
                    return self::FAILURE;
                }
            }

            if ($index < count($plan) - 1 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        if ($failures > 0) {
            $this->error(sprintf('Finished with %d failure(s).', $failures));

            return self::FAILURE;
        }

        $this->info('Done. Reload the app order tabs after Shopify finishes indexing the new orders.');

        return self::SUCCESS;
    }

    private function normalizeShopHost(string $store): string
    {
        $host = preg_replace('#^https?://#', '', trim($store)) ?? '';

        return rtrim($host, '/');
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);
        if (! is_string($value) || ! ctype_digit($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }

    private function variantIdOption(): ?int
    {
        $value = $this->option('variant-id');
        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function resolveShopCurrency(): string
    {
        $json = $this->graphql(self::SHOP_QUERY);
        $currency = $json['data']['shop']['currencyCode'] ?? null;

        return is_string($currency) && $currency !== '' ? $currency : 'DKK';
    }

    /**
     * @return array{id: int, price: string, label: string}|null
     */
    private function resolveFirstVariant(): ?array
    {
        $json = $this->graphql(self::FIRST_VARIANT_QUERY);
        $products = $json['data']['products']['nodes'] ?? [];
        if (! is_array($products)) {
            return null;
        }

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $variants = $product['variants']['nodes'] ?? [];
            if (! is_array($variants)) {
                continue;
            }

            foreach ($variants as $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $id = $this->parseLegacyId((string) ($variant['id'] ?? ''));
                if ($id <= 0) {
                    continue;
                }

                $productTitle = (string) ($variant['product']['title'] ?? $product['title'] ?? 'Product');
                $variantTitle = (string) ($variant['title'] ?? '');
                $sku = trim((string) ($variant['sku'] ?? ''));

                return [
                    'id' => $id,
                    'price' => $this->moneyString($variant['price'] ?? '599.00'),
                    'label' => trim($productTitle . ' ' . $variantTitle . ($sku !== '' ? ' / ' . $sku : '')),
                ];
            }
        }

        return null;
    }

    private function parseLegacyId(string $gid): int
    {
        if (preg_match('#/(\d+)$#', $gid, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function moneyString(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        if (is_string($value) && is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        return '599.00';
    }

    /**
     * @return list<array{
     *     label: string,
     *     variables: array<string, mixed>,
     *     tags: list<string>,
     *     closeAfterCreate?: bool
     * }>
     */
    private function buildPlan(int $variantId, string $price, string $currency, int $count): array
    {
        $buckets = $this->bucketCounts($count);
        $plan = [];

        for ($i = 0; $i < $buckets['ready']; $i++) {
            $plan[] = $this->planRow(
                'ready-to-pack (paid product order)',
                $this->variantOrder($variantId, $price, $currency, 'PAID'),
                ['seed', 'seed:ready-to-pack', ...($i % 4 === 0 ? ['Gift'] : [])]
            );
        }

        for ($i = 0; $i < $buckets['unpaid']; $i++) {
            $plan[] = $this->planRow(
                'upcoming (payment pending)',
                $this->variantOrder($variantId, $price, $currency, 'PENDING'),
                ['seed', 'seed:upcoming-unpaid']
            );
        }

        for ($i = 0; $i < $buckets['custom']; $i++) {
            $plan[] = $this->planRow(
                'upcoming (paid custom line, no product variant)',
                $this->customLineOrder($price, $currency),
                ['seed', 'seed:upcoming-custom-line']
            );
        }

        for ($i = 0; $i < $buckets['hold']; $i++) {
            $plan[] = $this->planRow(
                'on-hold (paid product order with On hold tag)',
                $this->variantOrder($variantId, $price, $currency, 'PAID'),
                ['On hold', 'seed', 'seed:on-hold']
            );
        }

        for ($i = 0; $i < $buckets['closed']; $i++) {
            $plan[] = $this->planRow(
                'archived (paid, fulfilled, then closed)',
                $this->variantOrder($variantId, $price, $currency, 'PAID', true),
                ['seed', 'seed:archived'],
                true
            );
        }

        return array_slice($plan, 0, $count);
    }

    /**
     * @return array{ready: int, unpaid: int, custom: int, hold: int, closed: int}
     */
    private function bucketCounts(int $count): array
    {
        $weights = [
            'ready' => 8,
            'unpaid' => 5,
            'custom' => 5,
            'hold' => 6,
            'closed' => 6,
        ];

        if ($count === 30) {
            return $weights;
        }

        $total = array_sum($weights);
        $counts = [];
        $allocated = 0;
        foreach ($weights as $name => $weight) {
            $counts[$name] = max(1, (int) floor($count * ($weight / $total)));
            $allocated += $counts[$name];
        }

        while ($allocated < $count) {
            foreach (array_keys($weights) as $name) {
                $counts[$name]++;
                $allocated++;
                if ($allocated >= $count) {
                    break;
                }
            }
        }

        while ($allocated > $count) {
            foreach (array_reverse(array_keys($weights)) as $name) {
                if ($counts[$name] <= 0) {
                    continue;
                }
                $counts[$name]--;
                $allocated--;
                if ($allocated <= $count) {
                    break;
                }
            }
        }

        return [
            'ready' => $counts['ready'],
            'unpaid' => $counts['unpaid'],
            'custom' => $counts['custom'],
            'hold' => $counts['hold'],
            'closed' => $counts['closed'],
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  list<string>  $tags
     * @return array{
     *     label: string,
     *     variables: array<string, mixed>,
     *     tags: list<string>,
     *     closeAfterCreate?: bool
     * }
     */
    private function planRow(string $label, array $order, array $tags, bool $closeAfterCreate = false): array
    {
        return [
            'label' => $label,
            'variables' => [
                'order' => $order,
                'options' => [
                    'sendReceipt' => false,
                    'sendFulfillmentReceipt' => false,
                ],
            ],
            'tags' => $tags,
            'closeAfterCreate' => $closeAfterCreate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variantOrder(
        int $variantId,
        string $price,
        string $currency,
        string $financialStatus,
        bool $fulfilled = false
    ): array {
        $quantity = random_int(1, 2);
        $total = $this->multiplyMoney($price, $quantity);

        $order = $this->baseOrder($financialStatus, $currency);
        $order['lineItems'] = [[
            'variantId' => 'gid://shopify/ProductVariant/' . $variantId,
            'quantity' => $quantity,
            'priceSet' => $this->moneyBag($price, $currency),
        ]];

        if ($financialStatus === 'PAID') {
            $order['transactions'] = [$this->saleTransaction($total, $currency)];
        }

        if ($fulfilled) {
            $order['fulfillmentStatus'] = 'FULFILLED';
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function customLineOrder(string $price, string $currency): array
    {
        $order = $this->baseOrder('PAID', $currency);
        $order['lineItems'] = [[
            'title' => 'Seed custom jewellery service',
            'quantity' => 1,
            'priceSet' => $this->moneyBag($price, $currency),
        ]];
        $order['transactions'] = [$this->saleTransaction($price, $currency)];

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseOrder(string $financialStatus, string $currency): array
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $address = $this->seedAddress();

        return [
            'email' => 'seed-' . $suffix . '@example.com',
            'currency' => $currency,
            'financialStatus' => $financialStatus,
            'billingAddress' => $address,
            'shippingAddress' => $address,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function seedAddress(): array
    {
        $people = [
            ['Alma', 'Nielsen', 'Frederiksborggade 12', 'Copenhagen', '1360'],
            ['Maja', 'Hansen', 'Aarhusgade 88', 'Copenhagen', '2100'],
            ['Sofia', 'Larsen', 'Gammel Kongevej 45', 'Frederiksberg', '1850'],
            ['Laura', 'Jensen', 'Jaegersborggade 31', 'Copenhagen', '2200'],
            ['Emma', 'Madsen', 'Strandvejen 104', 'Hellerup', '2900'],
        ];
        $person = $people[array_rand($people)];

        return [
            'firstName' => $person[0],
            'lastName' => $person[1],
            'address1' => $person[2],
            'city' => $person[3],
            'countryCode' => 'DK',
            'zip' => $person[4],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleTransaction(string $amount, string $currency): array
    {
        return [
            'kind' => 'SALE',
            'status' => 'SUCCESS',
            'amountSet' => $this->moneyBag($amount, $currency),
        ];
    }

    /**
     * @return array<string, array<string, float|string>>
     */
    private function moneyBag(string $amount, string $currency): array
    {
        $amount = $this->moneyString($amount);

        return [
            'shopMoney' => [
                'amount' => (float) $amount,
                'currencyCode' => $currency,
            ],
        ];
    }

    private function multiplyMoney(string $amount, int $quantity): string
    {
        return number_format(((float) $amount) * $quantity, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function createOrder(array $variables): array
    {
        $json = $this->graphql(self::ORDER_CREATE_MUTATION, $variables);
        $block = $json['data']['orderCreate'] ?? null;
        $this->throwUserErrors($block, 'orderCreate');

        $order = is_array($block) ? ($block['order'] ?? null) : null;
        if (! is_array($order) || empty($order['id'])) {
            throw new \RuntimeException('orderCreate returned no order.');
        }

        return $order;
    }

    /**
     * @param  list<string>  $tags
     */
    private function addTags(string $orderGid, array $tags): void
    {
        $json = $this->graphql(self::TAGS_ADD_MUTATION, [
            'id' => $orderGid,
            'tags' => $tags,
        ]);
        $this->throwUserErrors($json['data']['tagsAdd'] ?? null, 'tagsAdd');
    }

    private function closeOrder(string $orderGid): void
    {
        $json = $this->graphql(self::ORDER_CLOSE_MUTATION, [
            'input' => ['id' => $orderGid],
        ]);
        $this->throwUserErrors($json['data']['orderClose'] ?? null, 'orderClose');
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables = []): array
    {
        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $response = Http::timeout(90)->withHeaders([
            'X-Shopify-Access-Token' => $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->graphqlUrl(), $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Shopify HTTP ' . $response->status() . ': ' . $response->body());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException('Shopify returned an invalid JSON response.');
        }

        if (! empty($json['errors']) && is_array($json['errors'])) {
            $messages = array_map(
                static fn(array $error): string => (string) ($error['message'] ?? 'Unknown GraphQL error'),
                $json['errors']
            );

            throw new \RuntimeException('Shopify GraphQL error: ' . implode('; ', $messages));
        }

        return $json;
    }

    private function graphqlUrl(): string
    {
        return 'https://' . $this->host . '/admin/api/' . $this->apiVersion . '/graphql.json';
    }

    private function throwUserErrors(mixed $block, string $operation): void
    {
        if (! is_array($block)) {
            throw new \RuntimeException($operation . ' returned an unexpected response.');
        }

        $errors = $block['userErrors'] ?? [];
        if (! is_array($errors) || $errors === []) {
            return;
        }

        $messages = [];
        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }
            $field = $error['field'] ?? null;
            $prefix = is_array($field) && $field !== [] ? implode('.', $field) . ': ' : '';
            $messages[] = $prefix . (string) ($error['message'] ?? 'Unknown user error');
        }

        throw new \RuntimeException($operation . ' user error: ' . implode('; ', $messages));
    }
}
