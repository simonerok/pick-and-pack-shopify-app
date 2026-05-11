<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SeedShopifyProducts extends Command
{
    protected $signature = 'shopify:seed-test-products
                            {--dry-run : Print the GraphQL variables only, do not call Shopify}
                            {--count=30 : Number of products to create, up to the 30 curated seed products}
                            {--delay-ms=500 : Delay between productCreate calls}
                            {--location-id= : Shopify location ID to stock inventory at}
                            {--skip-inventory : Create products without setting Shopify inventory quantities}
                            {--continue-on-error : Keep creating products after an error}';

    protected $description = 'Create real Shopify jewelry test products with varied inventory for manual QA';

    private const PRODUCT_CREATE_MUTATION = <<<'GQL'
mutation SeedProductCreate($product: ProductCreateInput!) {
  productCreate(product: $product) {
    userErrors {
      field
      message
    }
    product {
      id
      title
      handle
      status
      productType
      tags
    }
  }
}
GQL;

    private const PRODUCT_VARIANTS_BULK_CREATE_MUTATION = <<<'GQL'
mutation SeedProductVariantCreate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkCreate(productId: $productId, variants: $variants, strategy: DEFAULT) {
    userErrors {
      field
      message
    }
    productVariants {
      id
      title
      price
      sku
      inventoryItem {
        id
        sku
        tracked
      }
    }
  }
}
GQL;

    private const LOCATIONS_QUERY = <<<'GQL'
query SeedProductLocations {
  locations(first: 25) {
    nodes {
      id
      name
      isActive
      fulfillsOnlineOrders
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

        $skipInventory = (bool) $this->option('skip-inventory');

        try {
            $locationId = $this->locationIdOption();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $locationName = 'placeholder location';
        $currency = 'DKK';
        if (! $skipInventory && $locationId === null && ! $dryRun) {
            try {
                $location = $this->resolveInventoryLocation();
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'Access denied for locations field')) {
                    $this->error(
                        'Shopify denied the locations query. Pass --location-id=NUMERIC_ID '
                        . 'or set SHOPIFY_LOCATION_ID in web/.env, or add --skip-inventory.'
                    );
                    $this->line('The access token likely needs the read_locations or read_inventory scope.');

                    return self::FAILURE;
                }

                throw $e;
            }

            if ($location === null) {
                $this->error('No active Shopify inventory location found. Pass --location-id=NUMERIC_ID if needed.');

                return self::FAILURE;
            }

            $locationId = $location['id'];
            $locationName = $location['name'];
            $currency = $location['currency'];
        }

        if (! $skipInventory && $locationId === null) {
            $locationId = 'gid://shopify/Location/1';
            $this->warn('Dry run: using placeholder location gid://shopify/Location/1.');
        }

        if (! $skipInventory && $locationName === 'placeholder location' && ! $dryRun) {
            $locationName = $locationId;
        }

        $count = $this->positiveIntOption('count', 30);
        if ($count > count($this->catalog())) {
            $this->warn('Only 30 curated products are defined; creating 30 products.');
            $count = count($this->catalog());
        }

        $delayMs = max(0, $this->positiveIntOption('delay-ms', 500));
        $plan = $this->buildPlan($locationId, $count, $skipInventory);

        $this->warn(
            $skipInventory
                ? 'This creates REAL Shopify products without inventory quantities. Use a development store only.'
                : 'This creates REAL Shopify products and inventory. Use a development store only.'
        );
        $this->line(sprintf(
            'Plan: %d jewelry products via Admin GraphQL %s.',
            count($plan),
            $dryRun ? '(dry run)' : 'against ' . $this->host
        ));
        if ($skipInventory) {
            $this->line('Inventory quantities: skipped.');
        } else {
            $this->line(sprintf('Inventory location: %s (%s).', $locationName, $locationId));
        }
        $currencyLabel = ! $dryRun && ! $skipInventory ? ' (' . $currency . ')' : '';
        $this->line(sprintf('Prices use the shop default currency%s.', $currencyLabel));

        if (! $dryRun && ! $this->confirm('Continue?', false)) {
            return self::SUCCESS;
        }

        $failures = 0;
        foreach ($plan as $index => $row) {
            $this->line(sprintf(
                '[%d/%d] %s / qty %d / %s',
                $index + 1,
                count($plan),
                $row['label'],
                $row['quantity'],
                $row['sku']
            ));

            if ($dryRun) {
                $this->line(json_encode([
                    'productCreate' => [
                        'variables' => [
                            'product' => $row['product'],
                        ],
                    ],
                    'productVariantsBulkCreate' => [
                        'variables' => [
                            'productId' => 'gid://shopify/Product/PLACEHOLDER',
                            'variants' => [$row['variant']],
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                continue;
            }

            try {
                $product = $this->createProduct($row['product']);
                $variant = $this->createVariant((string) $product['id'], $row['variant']);

                $this->info(sprintf(
                    '  -> created %s (%s), variant %s',
                    $product['title'] ?? $product['id'],
                    $product['id'],
                    $variant['id'] ?? 'created'
                ));
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

        $this->info('Done. The seeded products may take a moment to appear in Shopify product search.');

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

    private function locationIdOption(): ?string
    {
        $value = $this->option('location-id');
        if (! is_string($value) || trim($value) === '') {
            $value = (string) env('SHOPIFY_LOCATION_ID', '');
            if (trim($value) === '') {
                return null;
            }

            return $this->normalizeLocationId($value, 'SHOPIFY_LOCATION_ID');
        }

        return $this->normalizeLocationId($value, '--location-id');
    }

    private function normalizeLocationId(string $value, string $source): string
    {
        $value = trim($value);
        if (ctype_digit($value)) {
            return 'gid://shopify/Location/' . $value;
        }

        if (preg_match('#^gid://shopify/Location/\d+$#', $value)) {
            return $value;
        }

        throw new \InvalidArgumentException(
            $source . ' must be numeric or formatted as gid://shopify/Location/123.'
        );
    }

    /**
     * @return array{id: string, name: string, currency: string}|null
     */
    private function resolveInventoryLocation(): ?array
    {
        $json = $this->graphql(self::LOCATIONS_QUERY);
        $nodes = $json['data']['locations']['nodes'] ?? [];
        if (! is_array($nodes)) {
            return null;
        }

        $currency = $json['data']['shop']['currencyCode'] ?? 'DKK';
        $currency = is_string($currency) && $currency !== '' ? $currency : 'DKK';

        $fallback = null;
        foreach ($nodes as $node) {
            if (! is_array($node) || empty($node['id']) || empty($node['name'])) {
                continue;
            }

            if (($node['isActive'] ?? false) !== true) {
                continue;
            }

            $location = [
                'id' => (string) $node['id'],
                'name' => (string) $node['name'],
                'currency' => $currency,
            ];

            if ($fallback === null) {
                $fallback = $location;
            }

            if (($node['fulfillsOnlineOrders'] ?? false) === true) {
                return $location;
            }
        }

        return $fallback;
    }

    /**
     * @return list<array{
     *     label: string,
     *     quantity: int,
     *     sku: string,
     *     product: array<string, mixed>,
     *     variant: array<string, mixed>
     * }>
     */
    private function buildPlan(?string $locationId, int $count, bool $skipInventory): array
    {
        $plan = [];
        foreach (array_slice($this->catalog(), 0, $count) as $item) {
            $plan[] = [
                'label' => $item['title'],
                'quantity' => $item['quantity'],
                'sku' => $item['sku'],
                'product' => $this->productPayload($item),
                'variant' => $this->variantPayload($item, $locationId, $skipInventory),
            ];
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function productPayload(array $item): array
    {
        return [
            'title' => $item['title'],
            'descriptionHtml' => $this->descriptionHtml($item),
            'vendor' => 'Seed Jewelry',
            'productType' => $item['type'],
            'status' => 'ACTIVE',
            'tags' => $this->tagsForItem($item),
            'metafields' => [
                $this->metafield('material', $item['material']),
                $this->metafield('stone', $item['stone']),
                $this->metafield('stone_carat', $item['stoneCarat'] . ' ct'),
                $this->metafield('gia_report', $item['gia']),
                $this->metafield('style', $item['style']),
                $this->metafield('seed_quantity', (string) $item['quantity']),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function variantPayload(array $item, ?string $locationId, bool $skipInventory): array
    {
        $variant = [
            'price' => $this->moneyString($item['price']),
            'barcode' => $item['barcode'],
            'inventoryPolicy' => 'DENY',
            'taxable' => true,
            'optionValues' => [
                [
                    'name' => 'Default Title',
                    'optionName' => 'Title',
                ],
            ],
        ];

        if (! $skipInventory) {
            $variant['inventoryItem'] = [
                'sku' => $item['sku'],
                'tracked' => true,
                'requiresShipping' => true,
                'cost' => (float) $this->costString($item['price']),
                'countryCodeOfOrigin' => 'DK',
                'harmonizedSystemCode' => '711319',
            ];
        }

        if (! $skipInventory && $locationId !== null) {
            $variant['inventoryQuantities'] = [
                [
                    'locationId' => $locationId,
                    'availableQuantity' => $item['quantity'],
                ],
            ];
        }

        if (isset($item['compareAtPrice']) && $item['compareAtPrice'] !== null) {
            $variant['compareAtPrice'] = $this->moneyString($item['compareAtPrice']);
        }

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function tagsForItem(array $item): array
    {
        $tags = [
            'seed',
            'seed:product',
            'seed:jewelry',
            'GIA',
            'type:' . $this->slug($item['type']),
            'material:' . $this->slug($item['material']),
            'stone:' . $this->slug($item['stone']),
            'carat:' . $item['stoneCarat'],
            'stock:' . $this->availabilityBucket($item['quantity']),
            ...$item['tags'],
        ];

        return array_values(array_unique($tags));
    }

    private function availabilityBucket(int $quantity): string
    {
        if ($quantity <= 0) {
            return 'out';
        }

        if ($quantity <= 3) {
            return 'low';
        }

        if ($quantity <= 20) {
            return 'medium';
        }

        return 'high';
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '');

        return trim($slug, '-');
    }

    private function metafield(string $key, mixed $value): array
    {
        return [
            'namespace' => 'seed_jewelry',
            'key' => $key,
            'type' => 'single_line_text_field',
            'value' => (string) $value,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function descriptionHtml(array $item): string
    {
        $rows = [
            'Type' => $item['type'],
            'Material' => $item['material'],
            'Center stone' => $item['stone'],
            'Stone carat' => $item['stoneCarat'] . ' ct',
            'GIA report' => $item['gia'],
            'Seed inventory quantity' => (string) $item['quantity'],
        ];

        $listItems = '';
        foreach ($rows as $label => $value) {
            $listItems .= sprintf('<li>%s: %s</li>', $this->h($label), $this->h($value));
        }

        return sprintf(
            '<p>%s</p><ul>%s</ul>',
            $this->h($item['description']),
            $listItems
        );
    }

    private function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    private function costString(mixed $price): string
    {
        return number_format(((float) $this->moneyString($price)) * 0.45, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function createProduct(array $product): array
    {
        $json = $this->graphql(self::PRODUCT_CREATE_MUTATION, [
            'product' => $product,
        ]);
        $block = $json['data']['productCreate'] ?? null;
        $this->throwUserErrors($block, 'productCreate');

        $created = is_array($block) ? ($block['product'] ?? null) : null;
        if (! is_array($created) || empty($created['id'])) {
            throw new \RuntimeException('productCreate returned no product.');
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private function createVariant(string $productGid, array $variant): array
    {
        $json = $this->graphql(self::PRODUCT_VARIANTS_BULK_CREATE_MUTATION, [
            'productId' => $productGid,
            'variants' => [$variant],
        ]);
        $block = $json['data']['productVariantsBulkCreate'] ?? null;
        $this->throwUserErrors($block, 'productVariantsBulkCreate');

        $variants = is_array($block) ? ($block['productVariants'] ?? null) : null;
        if (! is_array($variants) || ! is_array($variants[0] ?? null) || empty($variants[0]['id'])) {
            throw new \RuntimeException('productVariantsBulkCreate returned no variant.');
        }

        return $variants[0];
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

    /**
     * @return list<array{
     *     title: string,
     *     type: string,
     *     material: string,
     *     stone: string,
     *     stoneCarat: string,
     *     gia: string,
     *     style: string,
     *     price: string,
     *     compareAtPrice?: string|null,
     *     quantity: int,
     *     sku: string,
     *     barcode: string,
     *     tags: list<string>,
     *     description: string
     * }>
     */
    private function catalog(): array
    {
        return [
            [
                'title' => 'Emerald Halo Ring',
                'type' => 'Rings',
                'material' => '18k White Gold',
                'stone' => 'Emerald',
                'stoneCarat' => '0.72',
                'gia' => 'GIA-7421908301',
                'style' => 'Halo',
                'price' => '1299.00',
                'compareAtPrice' => '1499.00',
                'quantity' => 0,
                'sku' => 'SEED-JWL-001',
                'barcode' => '5700000000011',
                'tags' => ['ring', 'halo', 'bridal', 'out-of-stock'],
                'description' => 'Emerald halo ring with a GIA report and bright white gold setting.',
            ],
            [
                'title' => 'Sapphire Solitaire Necklace',
                'type' => 'Necklaces',
                'material' => '14k White Gold',
                'stone' => 'Sapphire',
                'stoneCarat' => '1.10',
                'gia' => 'GIA-7421908302',
                'style' => 'Solitaire',
                'price' => '1599.00',
                'compareAtPrice' => null,
                'quantity' => 50,
                'sku' => 'SEED-JWL-002',
                'barcode' => '5700000000028',
                'tags' => ['necklace', 'solitaire', 'gift', 'high-stock'],
                'description' => 'Sapphire solitaire necklace created for high-stock pick and pack testing.',
            ],
            [
                'title' => 'Diamond Pave Earrings',
                'type' => 'Earrings',
                'material' => '18k Yellow Gold',
                'stone' => 'Diamond',
                'stoneCarat' => '0.38',
                'gia' => 'GIA-7421908303',
                'style' => 'Pave',
                'price' => '899.00',
                'compareAtPrice' => '999.00',
                'quantity' => 8,
                'sku' => 'SEED-JWL-003',
                'barcode' => '5700000000035',
                'tags' => ['earrings', 'pave', 'classic', 'medium-stock'],
                'description' => 'Small diamond pave earrings with a warm yellow gold finish.',
            ],
            [
                'title' => 'Ruby Tennis Bracelet',
                'type' => 'Bracelets',
                'material' => 'Sterling Silver',
                'stone' => 'Ruby',
                'stoneCarat' => '1.45',
                'gia' => 'GIA-7421908304',
                'style' => 'Tennis',
                'price' => '1099.00',
                'compareAtPrice' => null,
                'quantity' => 1,
                'sku' => 'SEED-JWL-004',
                'barcode' => '5700000000042',
                'tags' => ['bracelet', 'tennis', 'low-stock', 'statement'],
                'description' => 'Ruby tennis bracelet in sterling silver for low-stock flow testing.',
            ],
            [
                'title' => 'Aquamarine Cathedral Ring',
                'type' => 'Rings',
                'material' => 'Platinum',
                'stone' => 'Aquamarine',
                'stoneCarat' => '0.91',
                'gia' => 'GIA-7421908305',
                'style' => 'Cathedral',
                'price' => '1899.00',
                'compareAtPrice' => '2099.00',
                'quantity' => 25,
                'sku' => 'SEED-JWL-005',
                'barcode' => '5700000000059',
                'tags' => ['ring', 'cathedral', 'platinum', 'high-stock'],
                'description' => 'Aquamarine cathedral ring with a cool platinum setting.',
            ],
            [
                'title' => 'Amethyst Pendant Necklace',
                'type' => 'Necklaces',
                'material' => '18k Rose Gold',
                'stone' => 'Amethyst',
                'stoneCarat' => '0.84',
                'gia' => 'GIA-7421908306',
                'style' => 'Pendant',
                'price' => '749.00',
                'compareAtPrice' => null,
                'quantity' => 0,
                'sku' => 'SEED-JWL-006',
                'barcode' => '5700000000066',
                'tags' => ['necklace', 'pendant', 'rose-gold', 'out-of-stock'],
                'description' => 'Amethyst pendant necklace for testing unavailable product handling.',
            ],
            [
                'title' => 'Topaz Huggie Earrings',
                'type' => 'Earrings',
                'material' => 'Gold Vermeil',
                'stone' => 'Blue Topaz',
                'stoneCarat' => '0.52',
                'gia' => 'GIA-7421908307',
                'style' => 'Huggie',
                'price' => '449.00',
                'compareAtPrice' => '529.00',
                'quantity' => 12,
                'sku' => 'SEED-JWL-007',
                'barcode' => '5700000000073',
                'tags' => ['earrings', 'huggie', 'gift', 'medium-stock'],
                'description' => 'Blue topaz huggie earrings with gold vermeil plating.',
            ],
            [
                'title' => 'Morganite Chain Bracelet',
                'type' => 'Bracelets',
                'material' => '14k Yellow Gold',
                'stone' => 'Morganite',
                'stoneCarat' => '0.66',
                'gia' => 'GIA-7421908308',
                'style' => 'Chain',
                'price' => '699.00',
                'compareAtPrice' => null,
                'quantity' => 35,
                'sku' => 'SEED-JWL-008',
                'barcode' => '5700000000080',
                'tags' => ['bracelet', 'chain', 'daily-wear', 'high-stock'],
                'description' => 'Morganite chain bracelet with plenty of stock for packing tests.',
            ],
            [
                'title' => 'Opal Signet Ring',
                'type' => 'Rings',
                'material' => 'Sterling Silver',
                'stone' => 'Opal',
                'stoneCarat' => '0.63',
                'gia' => 'GIA-7421908309',
                'style' => 'Signet',
                'price' => '549.00',
                'compareAtPrice' => null,
                'quantity' => 3,
                'sku' => 'SEED-JWL-009',
                'barcode' => '5700000000097',
                'tags' => ['ring', 'signet', 'low-stock', 'modern'],
                'description' => 'Opal signet ring in sterling silver for scarce inventory testing.',
            ],
            [
                'title' => 'Pear Diamond Necklace',
                'type' => 'Necklaces',
                'material' => '18k White Gold',
                'stone' => 'Diamond',
                'stoneCarat' => '0.95',
                'gia' => 'GIA-7421908310',
                'style' => 'Pear Cut',
                'price' => '2199.00',
                'compareAtPrice' => '2499.00',
                'quantity' => 50,
                'sku' => 'SEED-JWL-010',
                'barcode' => '5700000000103',
                'tags' => ['necklace', 'diamond', 'bridal', 'high-stock'],
                'description' => 'Pear diamond necklace with a GIA report and full-stock availability.',
            ],
            [
                'title' => 'Garnet Cluster Earrings',
                'type' => 'Earrings',
                'material' => '14k Rose Gold',
                'stone' => 'Garnet',
                'stoneCarat' => '0.74',
                'gia' => 'GIA-7421908311',
                'style' => 'Cluster',
                'price' => '649.00',
                'compareAtPrice' => null,
                'quantity' => 6,
                'sku' => 'SEED-JWL-011',
                'barcode' => '5700000000110',
                'tags' => ['earrings', 'cluster', 'birthstone', 'medium-stock'],
                'description' => 'Garnet cluster earrings in rose gold with a warm gemstone palette.',
            ],
            [
                'title' => 'Peridot Bangle Bracelet',
                'type' => 'Bracelets',
                'material' => '18k Yellow Gold',
                'stone' => 'Peridot',
                'stoneCarat' => '1.18',
                'gia' => 'GIA-7421908312',
                'style' => 'Bangle',
                'price' => '999.00',
                'compareAtPrice' => '1199.00',
                'quantity' => 0,
                'sku' => 'SEED-JWL-012',
                'barcode' => '5700000000127',
                'tags' => ['bracelet', 'bangle', 'out-of-stock', 'gift'],
                'description' => 'Peridot bangle bracelet for testing zero-quantity product lines.',
            ],
            [
                'title' => 'Citrine Oval Ring',
                'type' => 'Rings',
                'material' => 'Gold Vermeil',
                'stone' => 'Citrine',
                'stoneCarat' => '0.88',
                'gia' => 'GIA-7421908313',
                'style' => 'Oval',
                'price' => '399.00',
                'compareAtPrice' => null,
                'quantity' => 18,
                'sku' => 'SEED-JWL-013',
                'barcode' => '5700000000134',
                'tags' => ['ring', 'oval', 'stacking', 'medium-stock'],
                'description' => 'Citrine oval ring with a bright gold vermeil finish.',
            ],
            [
                'title' => 'Tourmaline Bar Necklace',
                'type' => 'Necklaces',
                'material' => 'Platinum',
                'stone' => 'Tourmaline',
                'stoneCarat' => '1.02',
                'gia' => 'GIA-7421908314',
                'style' => 'Bar',
                'price' => '1399.00',
                'compareAtPrice' => null,
                'quantity' => 2,
                'sku' => 'SEED-JWL-014',
                'barcode' => '5700000000141',
                'tags' => ['necklace', 'bar', 'low-stock', 'minimal'],
                'description' => 'Tourmaline bar necklace in platinum for nearly sold-out scenarios.',
            ],
            [
                'title' => 'Moonstone Drop Earrings',
                'type' => 'Earrings',
                'material' => 'Sterling Silver',
                'stone' => 'Moonstone',
                'stoneCarat' => '0.80',
                'gia' => 'GIA-7421908315',
                'style' => 'Drop',
                'price' => '499.00',
                'compareAtPrice' => '599.00',
                'quantity' => 40,
                'sku' => 'SEED-JWL-015',
                'barcode' => '5700000000158',
                'tags' => ['earrings', 'drop', 'evening', 'high-stock'],
                'description' => 'Moonstone drop earrings with high availability for QA orders.',
            ],
            [
                'title' => 'Emerald Tennis Bracelet',
                'type' => 'Bracelets',
                'material' => '18k White Gold',
                'stone' => 'Emerald',
                'stoneCarat' => '2.20',
                'gia' => 'GIA-7421908316',
                'style' => 'Tennis',
                'price' => '2499.00',
                'compareAtPrice' => '2799.00',
                'quantity' => 7,
                'sku' => 'SEED-JWL-016',
                'barcode' => '5700000000165',
                'tags' => ['bracelet', 'tennis', 'luxury', 'medium-stock'],
                'description' => 'Emerald tennis bracelet with a larger carat weight and GIA details.',
            ],
            [
                'title' => 'Sapphire Three Stone Ring',
                'type' => 'Rings',
                'material' => '14k White Gold',
                'stone' => 'Sapphire',
                'stoneCarat' => '1.34',
                'gia' => 'GIA-7421908317',
                'style' => 'Three Stone',
                'price' => '1799.00',
                'compareAtPrice' => null,
                'quantity' => 0,
                'sku' => 'SEED-JWL-017',
                'barcode' => '5700000000172',
                'tags' => ['ring', 'three-stone', 'bridal', 'out-of-stock'],
                'description' => 'Sapphire three stone ring kept at zero quantity for unavailable flow checks.',
            ],
            [
                'title' => 'Ruby Heart Necklace',
                'type' => 'Necklaces',
                'material' => '18k Rose Gold',
                'stone' => 'Ruby',
                'stoneCarat' => '0.57',
                'gia' => 'GIA-7421908318',
                'style' => 'Heart',
                'price' => '849.00',
                'compareAtPrice' => '949.00',
                'quantity' => 22,
                'sku' => 'SEED-JWL-018',
                'barcode' => '5700000000189',
                'tags' => ['necklace', 'heart', 'gift', 'high-stock'],
                'description' => 'Ruby heart necklace with rose gold styling and healthy inventory.',
            ],
            [
                'title' => 'Diamond Stud Earrings',
                'type' => 'Earrings',
                'material' => 'Platinum',
                'stone' => 'Diamond',
                'stoneCarat' => '0.50',
                'gia' => 'GIA-7421908319',
                'style' => 'Stud',
                'price' => '1199.00',
                'compareAtPrice' => null,
                'quantity' => 5,
                'sku' => 'SEED-JWL-019',
                'barcode' => '5700000000196',
                'tags' => ['earrings', 'stud', 'classic', 'medium-stock'],
                'description' => 'Diamond stud earrings in platinum with GIA report metadata.',
            ],
            [
                'title' => 'Sapphire Cuff Bracelet',
                'type' => 'Bracelets',
                'material' => 'Sterling Silver',
                'stone' => 'Sapphire',
                'stoneCarat' => '1.76',
                'gia' => 'GIA-7421908320',
                'style' => 'Cuff',
                'price' => '899.00',
                'compareAtPrice' => null,
                'quantity' => 30,
                'sku' => 'SEED-JWL-020',
                'barcode' => '5700000000202',
                'tags' => ['bracelet', 'cuff', 'statement', 'high-stock'],
                'description' => 'Sapphire cuff bracelet with enough stock for repeated order tests.',
            ],
            [
                'title' => 'Aquamarine Bezel Ring',
                'type' => 'Rings',
                'material' => '14k Yellow Gold',
                'stone' => 'Aquamarine',
                'stoneCarat' => '0.47',
                'gia' => 'GIA-7421908321',
                'style' => 'Bezel',
                'price' => '699.00',
                'compareAtPrice' => '799.00',
                'quantity' => 15,
                'sku' => 'SEED-JWL-021',
                'barcode' => '5700000000219',
                'tags' => ['ring', 'bezel', 'daily-wear', 'medium-stock'],
                'description' => 'Aquamarine bezel ring made for ordinary medium-stock scenarios.',
            ],
            [
                'title' => 'Amethyst Station Necklace',
                'type' => 'Necklaces',
                'material' => 'Sterling Silver',
                'stone' => 'Amethyst',
                'stoneCarat' => '1.25',
                'gia' => 'GIA-7421908322',
                'style' => 'Station',
                'price' => '599.00',
                'compareAtPrice' => null,
                'quantity' => 0,
                'sku' => 'SEED-JWL-022',
                'barcode' => '5700000000226',
                'tags' => ['necklace', 'station', 'out-of-stock', 'birthstone'],
                'description' => 'Amethyst station necklace reserved as another zero-stock case.',
            ],
            [
                'title' => 'Topaz Hoop Earrings',
                'type' => 'Earrings',
                'material' => '18k White Gold',
                'stone' => 'Blue Topaz',
                'stoneCarat' => '0.70',
                'gia' => 'GIA-7421908323',
                'style' => 'Hoop',
                'price' => '799.00',
                'compareAtPrice' => '899.00',
                'quantity' => 45,
                'sku' => 'SEED-JWL-023',
                'barcode' => '5700000000233',
                'tags' => ['earrings', 'hoop', 'gift', 'high-stock'],
                'description' => 'Blue topaz hoop earrings with high inventory for bulk order checks.',
            ],
            [
                'title' => 'Morganite Link Bracelet',
                'type' => 'Bracelets',
                'material' => '18k Rose Gold',
                'stone' => 'Morganite',
                'stoneCarat' => '0.93',
                'gia' => 'GIA-7421908324',
                'style' => 'Link',
                'price' => '1099.00',
                'compareAtPrice' => null,
                'quantity' => 4,
                'sku' => 'SEED-JWL-024',
                'barcode' => '5700000000240',
                'tags' => ['bracelet', 'link', 'rose-gold', 'medium-stock'],
                'description' => 'Morganite link bracelet that sits just above the low-stock bucket.',
            ],
            [
                'title' => 'Opal Crown Ring',
                'type' => 'Rings',
                'material' => 'Gold Vermeil',
                'stone' => 'Opal',
                'stoneCarat' => '0.58',
                'gia' => 'GIA-7421908325',
                'style' => 'Crown',
                'price' => '459.00',
                'compareAtPrice' => null,
                'quantity' => 10,
                'sku' => 'SEED-JWL-025',
                'barcode' => '5700000000257',
                'tags' => ['ring', 'crown', 'stacking', 'medium-stock'],
                'description' => 'Opal crown ring with a playful stacking profile.',
            ],
            [
                'title' => 'Ruby Marquise Ring',
                'type' => 'Rings',
                'material' => 'Platinum',
                'stone' => 'Ruby',
                'stoneCarat' => '1.06',
                'gia' => 'GIA-7421908326',
                'style' => 'Marquise',
                'price' => '1999.00',
                'compareAtPrice' => '2299.00',
                'quantity' => 28,
                'sku' => 'SEED-JWL-026',
                'barcode' => '5700000000264',
                'tags' => ['ring', 'marquise', 'luxury', 'high-stock'],
                'description' => 'Ruby marquise ring in platinum with GIA certification details.',
            ],
            [
                'title' => 'Diamond Baguette Bracelet',
                'type' => 'Bracelets',
                'material' => '18k White Gold',
                'stone' => 'Diamond',
                'stoneCarat' => '1.52',
                'gia' => 'GIA-7421908327',
                'style' => 'Baguette',
                'price' => '2699.00',
                'compareAtPrice' => null,
                'quantity' => 0,
                'sku' => 'SEED-JWL-027',
                'barcode' => '5700000000271',
                'tags' => ['bracelet', 'baguette', 'luxury', 'out-of-stock'],
                'description' => 'Diamond baguette bracelet held at zero quantity for exception testing.',
            ],
            [
                'title' => 'Citrine Stud Earrings',
                'type' => 'Earrings',
                'material' => '14k Yellow Gold',
                'stone' => 'Citrine',
                'stoneCarat' => '0.42',
                'gia' => 'GIA-7421908328',
                'style' => 'Stud',
                'price' => '349.00',
                'compareAtPrice' => '429.00',
                'quantity' => 33,
                'sku' => 'SEED-JWL-028',
                'barcode' => '5700000000288',
                'tags' => ['earrings', 'stud', 'birthstone', 'high-stock'],
                'description' => 'Citrine stud earrings for simple high-stock packing flows.',
            ],
            [
                'title' => 'Tourmaline Stack Ring',
                'type' => 'Rings',
                'material' => 'Sterling Silver',
                'stone' => 'Tourmaline',
                'stoneCarat' => '0.36',
                'gia' => 'GIA-7421908329',
                'style' => 'Stack',
                'price' => '329.00',
                'compareAtPrice' => null,
                'quantity' => 14,
                'sku' => 'SEED-JWL-029',
                'barcode' => '5700000000295',
                'tags' => ['ring', 'stacking', 'daily-wear', 'medium-stock'],
                'description' => 'Tourmaline stack ring in sterling silver with medium availability.',
            ],
            [
                'title' => 'Moonstone Charm Bracelet',
                'type' => 'Bracelets',
                'material' => 'Gold Vermeil',
                'stone' => 'Moonstone',
                'stoneCarat' => '0.64',
                'gia' => 'GIA-7421908330',
                'style' => 'Charm',
                'price' => '529.00',
                'compareAtPrice' => '629.00',
                'quantity' => 50,
                'sku' => 'SEED-JWL-030',
                'barcode' => '5700000000301',
                'tags' => ['bracelet', 'charm', 'gift', 'high-stock'],
                'description' => 'Moonstone charm bracelet with maximum seed quantity for availability testing.',
            ],
        ];
    }
}
