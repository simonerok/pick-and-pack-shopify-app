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
                            {--start=1 : 1-based order plan number to start from}
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

    private const SEEDED_VARIANTS_QUERY = <<<'GQL'
query SeedSeededProductVariants {
  products(first: 100, query: "status:active") {
    nodes {
      title
      tags
      variants(first: 10) {
        nodes {
          id
          title
          sku
          price
        }
      }
    }
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
        $start = $this->positiveIntOption('start', 1);
        $delayMs = max(0, $this->positiveIntOption('delay-ms', 13000));
        $variantId = $this->variantIdOption();
        $currency = $dryRun ? 'DKK' : $this->resolveShopCurrency();
        $price = '599.00';
        $variants = [];

        if ($variantId !== null) {
            $variants = [$this->manualVariant($variantId, $price)];
            $this->info(sprintf(
                'Using manual variant_id=%d (price %s %s).',
                $variantId,
                $price,
                $currency
            ));
        } elseif ($dryRun) {
            $variants = $this->placeholderSeedVariants();
            $this->warn('Dry run: using placeholder seeded product variants. Pass --variant-id= for one fixed variant.');
        } else {
            $variants = $this->resolveSeededVariants();
            if ($variants === []) {
                $this->error(
                    'No active seeded product variants found. Run shopify:seed-test-products first, '
                    . 'or pass --variant-id=NUMERIC_ID.'
                );

                return self::FAILURE;
            }

            $this->info(sprintf('Using %d active seeded product variants.', count($variants)));
        }

        if (! $dryRun && $count > 5 && $delayMs < 12000) {
            $this->warn(
                'Shopify development/trial stores allow about 5 orderCreate calls per minute. '
                . 'Use --delay-ms=13000 unless this is not a development/trial store.'
            );
        }

        $plan = $this->buildPlan($variants, $currency, $count, $start);
        $this->warn('This creates REAL Shopify orders. Use a development store only.');
        $this->line(sprintf(
            'Plan: %d orders via Admin GraphQL %s.',
            count($plan),
            $dryRun ? '(dry run)' : 'against ' . $this->host
        ));
        $this->line(sprintf('Seed order plan range: %d-%d.', $start, $start + count($plan) - 1));

        if (! $dryRun && ! $this->confirm('Continue?', false)) {
            return self::SUCCESS;
        }

        $failures = 0;
        foreach ($plan as $index => $row) {
            $this->line(sprintf('[%d/%d] #%d %s', $index + 1, count($plan), $row['seed_number'], $row['label']));

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

    /**
     * @return list<array{id: string, price: string, label: string, sku: string, stock_bucket: string}>
     */
    private function resolveSeededVariants(): array
    {
        $json = $this->graphql(self::SEEDED_VARIANTS_QUERY);
        $products = $json['data']['products']['nodes'] ?? [];
        if (! is_array($products)) {
            return [];
        }

        $seeded = [];
        $seenVariantKeys = [];
        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $tags = $product['tags'] ?? [];
            $tags = is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
            $variants = $product['variants']['nodes'] ?? [];
            if (! is_array($variants)) {
                continue;
            }

            foreach ($variants as $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $id = (string) ($variant['id'] ?? '');
                $sku = trim((string) ($variant['sku'] ?? ''));
                if ($id === '' || ! $this->isSeededProduct($tags, $sku)) {
                    continue;
                }
                $variantKey = $sku !== '' ? $sku : $id;
                if (isset($seenVariantKeys[$variantKey])) {
                    continue;
                }
                $seenVariantKeys[$variantKey] = true;

                $productTitle = (string) ($product['title'] ?? 'Seed product');
                $variantTitle = (string) ($variant['title'] ?? '');
                $stockBucket = $this->stockBucketFromTags($tags);
                $label = $productTitle
                    . ($variantTitle !== '' && $variantTitle !== 'Default Title' ? ' / ' . $variantTitle : '')
                    . ($sku !== '' ? ' / ' . $sku : '')
                    . ' / stock:' . $stockBucket;

                $seeded[] = [
                    'id' => $id,
                    'price' => $this->moneyString($variant['price'] ?? '599.00'),
                    'label' => $label,
                    'sku' => $sku,
                    'stock_bucket' => $stockBucket,
                ];
            }
        }

        return $seeded;
    }

    /**
     * @return array{id: string, price: string, label: string, sku: string, stock_bucket: string}
     */
    private function manualVariant(int $variantId, string $price): array
    {
        return [
            'id' => 'gid://shopify/ProductVariant/' . $variantId,
            'price' => $this->moneyString($price),
            'label' => 'manual variant / ' . $variantId,
            'sku' => '',
            'stock_bucket' => 'manual',
        ];
    }

    /**
     * @return list<array{id: string, price: string, label: string, sku: string, stock_bucket: string}>
     */
    private function placeholderSeedVariants(): array
    {
        return [
            [
                'id' => 'gid://shopify/ProductVariant/1001',
                'price' => '1299.00',
                'label' => 'Emerald Halo Ring / SEED-JWL-001 / stock:out',
                'sku' => 'SEED-JWL-001',
                'stock_bucket' => 'out',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/1004',
                'price' => '1099.00',
                'label' => 'Ruby Tennis Bracelet / SEED-JWL-004 / stock:low',
                'sku' => 'SEED-JWL-004',
                'stock_bucket' => 'low',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/1003',
                'price' => '899.00',
                'label' => 'Diamond Pave Earrings / SEED-JWL-003 / stock:medium',
                'sku' => 'SEED-JWL-003',
                'stock_bucket' => 'medium',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/1002',
                'price' => '1599.00',
                'label' => 'Sapphire Solitaire Necklace / SEED-JWL-002 / stock:high',
                'sku' => 'SEED-JWL-002',
                'stock_bucket' => 'high',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/1006',
                'price' => '749.00',
                'label' => 'Amethyst Pendant Necklace / SEED-JWL-006 / stock:out',
                'sku' => 'SEED-JWL-006',
                'stock_bucket' => 'out',
            ],
            [
                'id' => 'gid://shopify/ProductVariant/1008',
                'price' => '699.00',
                'label' => 'Morganite Chain Bracelet / SEED-JWL-008 / stock:high',
                'sku' => 'SEED-JWL-008',
                'stock_bucket' => 'high',
            ],
        ];
    }

    /**
     * @param  list<string>  $tags
     */
    private function isSeededProduct(array $tags, string $sku): bool
    {
        if (in_array('seed:product', $tags, true)) {
            return true;
        }

        return str_starts_with($sku, 'SEED-JWL-');
    }

    /**
     * @param  list<string>  $tags
     */
    private function stockBucketFromTags(array $tags): string
    {
        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'stock:')) {
                return substr($tag, 6) ?: 'unknown';
            }
        }

        return 'unknown';
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
     *     seed_number: int,
     *     label: string,
     *     variables: array<string, mixed>,
     *     tags: list<string>,
     *     closeAfterCreate?: bool
     * }>
     */
    private function buildPlan(array $variants, string $currency, int $count, int $start = 1): array
    {
        $end = $start + $count - 1;
        $buckets = $this->bucketCounts($end);
        $plan = [];

        for ($i = 0; $i < $buckets['ready']; $i++) {
            $variant = $this->selectVariant($variants, ['high', 'medium'], $i);
            $plan[] = $this->planRow(
                'ready-to-pack (paid available product: ' . $variant['label'] . ')',
                $this->variantOrder($variant, $currency, 'PAID'),
                ['seed', 'seed:ready-to-pack', ...($i % 4 === 0 ? ['Gift'] : [])]
            );
        }

        for ($i = 0; $i < $buckets['unpaid']; $i++) {
            $variant = $this->selectVariant($variants, ['out', 'low'], $i);
            $plan[] = $this->planRow(
                'upcoming (payment pending, unavailable product: ' . $variant['label'] . ')',
                $this->variantOrder($variant, $currency, 'PENDING', false, $this->unavailableQuantityFor($variant)),
                ['seed', 'seed:upcoming-unpaid']
            );
        }

        for ($i = 0; $i < $buckets['custom']; $i++) {
            $plan[] = $this->planRow(
                'upcoming (paid custom line, no product variant)',
                $this->customLineOrder($this->customLinePrice($variants), $currency),
                ['seed', 'seed:upcoming-custom-line']
            );
        }

        for ($i = 0; $i < $buckets['hold']; $i++) {
            $variant = $this->selectVariant($variants, ['medium', 'high', 'low'], $i);
            $plan[] = $this->planRow(
                'on-hold (paid product order with On hold tag: ' . $variant['label'] . ')',
                $this->variantOrder($variant, $currency, 'PAID'),
                ['On hold', 'seed', 'seed:on-hold']
            );
        }

        for ($i = 0; $i < $buckets['closed']; $i++) {
            $variant = $this->selectVariant($variants, ['high', 'medium'], $i);
            $plan[] = $this->planRow(
                'archived (paid, fulfilled, then closed: ' . $variant['label'] . ')',
                $this->variantOrder($variant, $currency, 'PAID', true),
                ['seed', 'seed:archived'],
                true
            );
        }

        $plan = array_slice($plan, $start - 1, $count);
        foreach ($plan as $index => $row) {
            $plan[$index]['seed_number'] = $start + $index;
        }

        return $plan;
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
     * @param  list<array{id: string, price: string, label: string, sku: string, stock_bucket: string}>  $variants
     * @param  list<string>  $preferredBuckets
     * @return array{id: string, price: string, label: string, sku: string, stock_bucket: string}
     */
    private function selectVariant(array $variants, array $preferredBuckets, int $index): array
    {
        $pool = array_values(array_filter(
            $variants,
            static fn(array $variant): bool => in_array($variant['stock_bucket'], $preferredBuckets, true)
        ));

        if ($pool === []) {
            if (count($variants) === 1 && ($variants[0]['stock_bucket'] ?? '') === 'manual') {
                return $variants[0];
            }

            throw new \RuntimeException(
                'No seeded product variants found for stock bucket(s): '
                . implode(', ', $preferredBuckets)
                . '. Seed at least one available product first, for example: '
                . 'php artisan shopify:seed-test-products --count=5'
            );
        }

        return $pool[$index % count($pool)];
    }

    /**
     * @param  list<array{id: string, price: string, label: string, sku: string, stock_bucket: string}>  $variants
     * @param  list<string>  $preferredBuckets
     * @return array{id: string, price: string, label: string, sku: string, stock_bucket: string}
     */
    private function selectVariantOrFallback(array $variants, array $preferredBuckets, int $index): array
    {
        $pool = array_values(array_filter(
            $variants,
            static fn(array $variant): bool => in_array($variant['stock_bucket'], $preferredBuckets, true)
        ));

        if ($pool === []) {
            $pool = $variants;
        }

        return $pool[$index % count($pool)];
    }

    /**
     * @param  array{id: string, price: string, label: string, sku: string, stock_bucket: string}  $variant
     */
    private function unavailableQuantityFor(array $variant): int
    {
        if ($variant['stock_bucket'] === 'low') {
            return 4;
        }

        return random_int(1, 2);
    }

    /**
     * @param  array{id: string, price: string, label: string, sku: string, stock_bucket: string}  $variant
     * @return list<array{name: string, value: string}>
     */
    private function lineItemProperties(
        array $variant,
        int $quantity,
        string $financialStatus,
        bool $fulfilled
    ): array {
        $seed = $this->variantSeedNumber($variant);
        $properties = [[
            'name' => 'Gift note',
            'value' => $this->pickFakeValue([
                'Happy birthday - enjoy your special day',
                'A little sparkle for you',
                'Congratulations on the big moment',
                'With love from your family',
            ], $seed),
        ]];

        $productType = $this->productTypeFromVariantLabel($variant['label']);
        if ($productType === 'ring') {
            $properties[] = ['name' => 'Ring size', 'value' => $this->pickFakeValue(['50', '52', '54', '56', '58'], $seed)];
        }

        return $properties;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function customLineItemProperties(): array
    {
        return [
            ['name' => 'Gift note', 'value' => 'Please wrap this nicely as a surprise'],
        ];
    }

    private function variantSeedNumber(array $variant): int
    {
        $sku = (string) ($variant['sku'] ?? '');
        if (preg_match('/(\d+)$/', $sku, $matches)) {
            return max(1, (int) $matches[1]);
        }

        if (preg_match('#/(\d+)$#', (string) ($variant['id'] ?? ''), $matches)) {
            return max(1, (int) $matches[1]);
        }

        return 1;
    }

    private function productTypeFromVariantLabel(string $label): string
    {
        $prefix = strtolower(trim(explode('/', $label)[0] ?? $label));

        if (preg_match('/\bearrings?\b/', $prefix)) {
            return 'earrings';
        }

        if (preg_match('/\brings?\b/', $prefix)) {
            return 'ring';
        }

        if (preg_match('/\bnecklaces?\b/', $prefix)) {
            return 'necklace';
        }

        if (preg_match('/\bbracelets?\b/', $prefix)) {
            return 'bracelet';
        }

        return 'unknown';
    }

    /**
     * @param  list<string>  $values
     */
    private function pickFakeValue(array $values, int $seed): string
    {
        return $values[$seed % count($values)];
    }

    /**
     * @param  list<array{id: string, price: string, label: string, sku: string, stock_bucket: string}>  $variants
     */
    private function customLinePrice(array $variants): string
    {
        $variant = $this->selectVariantOrFallback($variants, ['medium', 'high'], 0);

        return $variant['price'];
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  list<string>  $tags
     * @return array{
     *     seed_number?: int,
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
     * @param  array{id: string, price: string, label: string, sku: string, stock_bucket: string}  $variant
     * @return array<string, mixed>
     */
    private function variantOrder(
        array $variant,
        string $currency,
        string $financialStatus,
        bool $fulfilled = false,
        ?int $quantity = null
    ): array {
        $price = $variant['price'];
        $quantity ??= random_int(1, 2);
        $total = $this->multiplyMoney($price, $quantity);

        $order = $this->baseOrder($financialStatus, $currency);
        $order['lineItems'] = [[
            'variantId' => $variant['id'],
            'quantity' => $quantity,
            'priceSet' => $this->moneyBag($price, $currency),
            'properties' => $this->lineItemProperties($variant, $quantity, $financialStatus, $fulfilled),
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
            'properties' => $this->customLineItemProperties(),
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
            ['Nora', 'Andersen', 'Osterbrogade 67', 'Copenhagen', '2100'],
            ['Ida', 'Christensen', 'Vesterbrogade 119', 'Copenhagen', '1620'],
            ['Freja', 'Pedersen', 'Norre Farimagsgade 28', 'Copenhagen', '1364'],
            ['Clara', 'Poulsen', 'Sonder Boulevard 73', 'Copenhagen', '1720'],
            ['Ella', 'Johansen', 'Godthaabsvej 41', 'Frederiksberg', '2000'],
            ['Anna', 'Mortensen', 'Amagerbrogade 156', 'Copenhagen', '2300'],
            ['Josefine', 'Rasmussen', 'Elmegade 19', 'Copenhagen', '2200'],
            ['Liv', 'Thomsen', 'Classensgade 22', 'Copenhagen', '2100'],
            ['Asta', 'Knudsen', 'Smallegade 14', 'Frederiksberg', '2000'],
            ['Mathilde', 'Lund', 'Hellerupvej 53', 'Hellerup', '2900'],
            ['Victoria', 'Berg', 'Kongevejen 88', 'Virum', '2830'],
            ['Karoline', 'Holm', 'Lyngby Hovedgade 37', 'Kongens Lyngby', '2800'],
            ['Julie', 'Dahl', 'Roskildevej 242', 'Valby', '2500'],
            ['Isabella', 'Moller', 'Nordre Frihavnsgade 31', 'Copenhagen', '2100'],
            ['Amalie', 'Sondergaard', 'Strandlodsvej 87', 'Copenhagen', '2300'],
            ['Karla', 'Eriksen', 'Jagtvej 101', 'Copenhagen', '2200'],
            ['Liva', 'Olsen', 'Borups Alle 132', 'Frederiksberg', '2000'],
            ['Ellen', 'Toft', 'Bernstorffsvej 66', 'Charlottenlund', '2920'],
            ['Frida', 'Bach', 'Islands Brygge 45', 'Copenhagen', '2300'],
            ['Marie', 'Skov', 'Tuborgvej 181', 'Hellerup', '2900'],
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
