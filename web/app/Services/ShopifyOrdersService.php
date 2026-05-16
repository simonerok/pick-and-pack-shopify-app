<?php

namespace App\Services;

use App\Helpers\GraphQLHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopifyOrdersService
{
    public static function twoMonthsAgoISO(): string
    {
        $d = new \DateTime();
        $d->modify('-2 months');

        return $d->format('Y-m-d');
    }

    public static function fetchOrders(
        bool $archived = false,
        ?array $financialStatusesForOpen = null,
        ?array $fulfillmentStatusesForOpen = null,
        bool $ignoreFinancialFilterForOpen = false
    ): array {
        $store = env('SHOPIFY_STORE_DOMAIN');
        $token = env('SHOPIFY_ACCESS_TOKEN');

        if (! $store || ! $token) {
            throw new \RuntimeException(
                'Missing Shopify config. Set SHOPIFY_STORE_DOMAIN '
                    . 'and SHOPIFY_ACCESS_TOKEN in .env'
            );
        }

        $orderQuery = $archived
            ? 'status:closed AND created_at:>=' . self::twoMonthsAgoISO()
            : 'status:not_closed AND created_at:>=' . self::twoMonthsAgoISO();

        $store = trim($store);
        $host = preg_replace('#^https?://#', '', $store);
        $host = rtrim($host, '/');
        if ($host === '') {
            throw new \RuntimeException('Invalid SHOPIFY_STORE_DOMAIN');
        }
        $apiVersion = trim(env('SHOPIFY_API_VERSION', '') ?: '') ?: '2025-10';
        $url = 'https://' . $host . '/admin/api/' . $apiVersion . '/graphql.json';

        $allOrders = [];
        $cursor = null;
        $first = 50;
        $allowedFinancialStatuses = $archived
            ? null
            : ($financialStatusesForOpen === null
                ? ['authorized', 'paid', 'partially_paid']
                : array_values(array_filter(array_map(
                    static fn($status) => self::normalizeStatusValue((string) $status),
                    $financialStatusesForOpen
                ))));
        $allowedFulfillmentStatuses = $archived
            ? null
            : ($fulfillmentStatusesForOpen === null
                ? null
                : array_values(array_filter(array_map(
                    static fn($status) => self::normalizeStatusValue((string) $status),
                    $fulfillmentStatusesForOpen
                ))));

        if (
            ! $archived
            && ! $ignoreFinancialFilterForOpen
            && is_array($allowedFinancialStatuses)
            && count($allowedFinancialStatuses) === 1
        ) {
            $searchFinancialStatus = self::shopifyFinancialStatusSearchValue($allowedFinancialStatuses[0]);
            if ($searchFinancialStatus !== null) {
                $orderQuery .= ' AND financial_status:' . $searchFinancialStatus;
            }
        }

        do {
            $variables = ['first' => $first, 'query' => $orderQuery];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $response = Http::timeout(90)->withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->post($url, [
                'query' => GraphQLHelper::ORDERS_QUERY,
                'variables' => $variables,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Shopify API error: ' . $response->body());
            }

            $json = $response->json();
            if (! empty($json['errors'])) {
                $messages = array_map(fn($e) => $e['message'] ?? '', $json['errors']);

                throw new \RuntimeException('GraphQL error: ' . implode('; ', $messages));
            }

            $ordersConnection = $json['data']['orders'] ?? null;
            if (! $ordersConnection) {
                throw new \RuntimeException('Unexpected response: No orders in response');
            }

            $edges = $ordersConnection['edges'] ?? [];
            foreach ($edges as $edge) {
                $node = $edge['node'] ?? null;
                if (! $node) {
                    continue;
                }
                $order = self::gqlOrderToOrder($node);
                $order['archived'] = $archived;

                Log::debug('Shopify order: ' . json_encode($order, JSON_THROW_ON_ERROR));

                if (
                    ($ignoreFinancialFilterForOpen
                        || $allowedFinancialStatuses === null
                        || in_array($order['financial_status'], $allowedFinancialStatuses, true))
                    && ($allowedFulfillmentStatuses === null
                        || in_array($order['fulfillment_status'] ?? '', $allowedFulfillmentStatuses, true))
                ) {
                    $allOrders[] = $order;
                }
            }

            $pageInfo = $ordersConnection['pageInfo'] ?? [];
            $hasNext = $pageInfo['hasNextPage'] ?? false;
            $cursor = $pageInfo['endCursor'] ?? null;
        } while ($hasNext && $cursor);

        $loadExternalOrderData = self::shouldLoadExternalOrderData();
        $integrationStatus = self::integrationStatus($loadExternalOrderData);

        if ($loadExternalOrderData && BusinessCentralService::isConfigured()) {
            try {
                self::enrichWithBusinessCentral($allOrders);
                $integrationStatus['sources']['business_central'] = self::integrationSourceStatus(
                    'loaded',
                    'Business Central data loaded.'
                );
            } catch (\Throwable $e) {
                report($e);
                Log::error('Business Central fetch failed: ' . $e->getMessage());
                $integrationStatus['sources']['business_central'] = self::integrationSourceStatus(
                    'failed',
                    'Business Central data could not be loaded. Shopify orders are still shown.'
                );
            }
        }

        if ($loadExternalOrderData && WebshipperService::isConfigured()) {
            try {
                self::enrichWithWebshipper($allOrders);
                $integrationStatus['sources']['webshipper'] = self::integrationSourceStatus(
                    'loaded',
                    'Webshipper data loaded.'
                );
            } catch (\Throwable $e) {
                report($e);
                Log::error('Webshipper fetch failed: ' . $e->getMessage());
                $integrationStatus['sources']['webshipper'] = self::integrationSourceStatus(
                    'failed',
                    'Webshipper data could not be loaded. Shopify orders are still shown.'
                );
            }
        }

        $shopDomain = $store;
        if (preg_match('#^https?://#', $shopDomain)) {
            $shopDomain = parse_url($shopDomain, PHP_URL_HOST) ?? $shopDomain;
        }
        $webshipperAccount = WebshipperService::isConfigured() ? trim(env('WEBSHIPPER_ACCOUNT_NAME', '') ?? '') : null;

        return [
            'orders' => $allOrders,
            'shop_domain' => $shopDomain,
            'webshipper_account' => $webshipperAccount,
            'VITE_APP_STATUS' => AppStatus::get(),
            'integration_status' => $integrationStatus,
        ];
    }

    public static function fetchOrdersReadyToPack(): array
    {
        return self::fetchOrders(false, ['paid']);
    }

    public static function fetchOrdersReadyForPickup(): array
    {
        $data = self::fetchOrders(false, null, null, true);
        $orders = $data['orders'] ?? [];
        $cutoff = new \DateTimeImmutable('-2 months');

        $data['orders'] = array_values(array_filter(
            $orders,
            static function (array $order) use ($cutoff): bool {
                $createdAtRaw = $order['created_at'] ?? null;
                if (! is_string($createdAtRaw) || trim($createdAtRaw) === '') {
                    return false;
                }

                try {
                    $createdAt = new \DateTimeImmutable($createdAtRaw);
                } catch (\Throwable $e) {
                    return false;
                }

                if ($createdAt < $cutoff) {
                    return false;
                }

                return self::orderIsReadyForPickup((int) ($order['id'] ?? 0));
            }
        ));

        return $data;
    }


    public static function fetchOrdersOnHold(): array
    {
        $openData = self::fetchOrders(false, null, null, true);
        $openOrders = $openData['orders'] ?? [];

        // Open, non-archived orders: every order with the On hold tag.
        $openOnHold = array_values(array_filter(
            $openOrders,
            static fn(array $order): bool => self::orderHasOnHoldTag($order)
        ));

        $byId = [];
        foreach ($openOnHold as $order) {
            $byId[(string) ($order['id'] ?? uniqid('onhold_', true))] = $order;
        }

        $openData['orders'] = array_values($byId);

        return $openData;
    }


    public static function addOnHoldTagToOrder(int $orderId): void
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('Invalid order id');
        }

        self::runOrderHoldTagMutation(GraphQLHelper::TAGS_ADD_MUTATION, $orderId, ['On hold']);
    }


    public static function removeOnHoldTagFromOrder(int $orderId): void
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('Invalid order id');
        }

        self::runOrderHoldTagMutation(GraphQLHelper::TAGS_REMOVE_MUTATION, $orderId, ['On hold']);
    }

    private static function runOrderHoldTagMutation(string $mutation, int $orderId, array $tags): void
    {
        $store = env('SHOPIFY_STORE_DOMAIN');
        $token = env('SHOPIFY_ACCESS_TOKEN');
        if (! $store || ! $token) {
            throw new \RuntimeException('Missing Shopify config');
        }

        $host = preg_replace('#^https?://#', '', trim($store));
        $host = rtrim((string) $host, '/');
        if ($host === '') {
            throw new \RuntimeException('Invalid SHOPIFY_STORE_DOMAIN');
        }

        $apiVersion = trim(env('SHOPIFY_API_VERSION', '') ?: '') ?: '2025-10';
        $url = 'https://' . $host . '/admin/api/' . $apiVersion . '/graphql.json';

        $response = Http::timeout(30)->withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'query' => $mutation,
            'variables' => [
                'id' => 'gid://shopify/Order/' . $orderId,
                'tags' => $tags,
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Shopify API error: ' . $response->body());
        }

        $json = $response->json();
        if (! empty($json['errors'])) {
            $messages = array_map(fn($e) => $e['message'] ?? '', $json['errors']);
            throw new \RuntimeException('GraphQL error: ' . implode('; ', $messages));
        }

        $block = $json['data']['tagsAdd'] ?? $json['data']['tagsRemove'] ?? null;
        $userErrors = $block['userErrors'] ?? [];
        if (! empty($userErrors)) {
            $messages = array_map(fn($e) => $e['message'] ?? '', $userErrors);
            throw new \RuntimeException(implode('; ', $messages));
        }
    }


    public static function readyOrderForPickup(string $fulfillmentOrderGid): void
    {
        $fulfillmentOrderGid = trim($fulfillmentOrderGid);
        if (
            $fulfillmentOrderGid === ''
            || ! preg_match('#^gid://shopify/FulfillmentOrder/\d+$#', $fulfillmentOrderGid)
        ) {
            throw new \InvalidArgumentException('Invalid fulfillment order GID');
        }

        self::runReadyOrderForPickupMutation($fulfillmentOrderGid);
    }

    public static function markOrderAsPickedUp(string $fulfillmentOrderGid): void
    {
        $fulfillmentOrderGid = trim($fulfillmentOrderGid);
        if (
            $fulfillmentOrderGid === ''
            || ! preg_match('#^gid://shopify/FulfillmentOrder/\d+$#', $fulfillmentOrderGid)
        ) {
            throw new \InvalidArgumentException('Invalid fulfillment order GID');
        }

        self::runMarkOrderAsPickedUpMutation($fulfillmentOrderGid);
    }

    private static function runMarkOrderAsPickedUpMutation(string $fulfillmentOrderGid): void
    {
        $store = env('SHOPIFY_STORE_DOMAIN');
        $token = env('SHOPIFY_ACCESS_TOKEN');
        if (! $store || ! $token) {
            throw new \RuntimeException('Missing Shopify config');
        }

        $host = preg_replace('#^https?://#', '', trim($store));
        $host = rtrim((string) $host, '/');
        if ($host === '') {
            throw new \RuntimeException('Invalid SHOPIFY_STORE_DOMAIN');
        }

        $apiVersion = trim(env('SHOPIFY_API_VERSION', '') ?: '') ?: '2025-10';
        $url = 'https://' . $host . '/admin/api/' . $apiVersion . '/graphql.json';

        $response = Http::timeout(30)->withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'query' => GraphQLHelper::MARK_ORDER_AS_PICKED_UP_MUTATION,
            'variables' => [
                'fulfillment' => [
                    'lineItemsByFulfillmentOrder' => [
                        ['fulfillmentOrderId' => $fulfillmentOrderGid],
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Shopify API error: ' . $response->body());
        }

        $json = $response->json();
        if (! empty($json['errors'])) {
            $messages = array_map(fn($e) => $e['message'] ?? '', $json['errors']);
            throw new \RuntimeException('GraphQL error: ' . implode('; ', $messages));
        }

        $block = $json['data']['fulfillmentOrderLineItemsPreparedForPickup'] ?? null;
        $userErrors = $block['userErrors'] ?? [];
        if (! empty($userErrors)) {
            $messages = array_map(fn($e) => $e['message'] ?? '', $userErrors);
            throw new \RuntimeException(implode('; ', $messages));
        }
    }
    private static function runReadyOrderForPickupMutation(string $fulfillmentOrderGid): void
    {
        $store = env('SHOPIFY_STORE_DOMAIN');
        $token = env('SHOPIFY_ACCESS_TOKEN');
        if (! $store || ! $token) {
            throw new \RuntimeException('Missing Shopify config');
        }

        $host = preg_replace('#^https?://#', '', trim($store));
        $host = rtrim((string) $host, '/');
        if ($host === '') {
            throw new \RuntimeException('Invalid SHOPIFY_STORE_DOMAIN');
        }

        $apiVersion = trim(env('SHOPIFY_API_VERSION', '') ?: '') ?: '2025-10';
        $url = 'https://' . $host . '/admin/api/' . $apiVersion . '/graphql.json';

        $response = Http::timeout(30)->withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'query' => GraphQLHelper::READY_ORDER_FOR_PICKUP_MUTATION,
            'variables' => [
                'input' => [
                    'lineItemsByFulfillmentOrder' => [
                        ['fulfillmentOrderId' => $fulfillmentOrderGid],
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Shopify API error: ' . $response->body());
        }

        $json = $response->json();
        if (! empty($json['errors'])) {
            $messages = array_map(fn($e) => $e['message'] ?? '', $json['errors']);
            throw new \RuntimeException('GraphQL error: ' . implode('; ', $messages));
        }

        $block = $json['data']['fulfillmentOrderLineItemsPreparedForPickup'] ?? null;
        $userErrors = $block['userErrors'] ?? [];
        if (! empty($userErrors)) {
            $messages = array_map(fn($e) => $e['message'] ?? '', $userErrors);
            throw new \RuntimeException(implode('; ', $messages));
        }
    }

    public static function fetchOrdersUpcoming(): array
    {
        $data = self::fetchOrders(false, null, null, true);
        $orders = $data['orders'] ?? [];
        $cutoff = new \DateTimeImmutable('-2 months');

        $data['orders'] = array_values(array_filter(
            $orders,
            static function (array $order) use ($cutoff): bool {
                $createdAtRaw = $order['created_at'] ?? null;
                if (! is_string($createdAtRaw) || trim($createdAtRaw) === '') {
                    return false;
                }

                try {
                    $createdAt = new \DateTimeImmutable($createdAtRaw);
                } catch (\Throwable $e) {
                    return false;
                }

                if ($createdAt < $cutoff) {
                    return false;
                }
                if (self::orderHasOnHoldTag($order)) {
                    return false;
                }

                $financial = (string) ($order['financial_status'] ?? '');
                $isPaidLike = in_array($financial, ['paid', 'authorized', 'partially_paid', 'pending'], true);
                $notFullyAvailable = ! self::orderIsFullyAvailable($order);


                return ! $isPaidLike || $notFullyAvailable;
            }
        ));

        return $data;
    }

    private static function parseOrderId(string $gid): int
    {
        if (preg_match('#/Order/(\d+)$#', $gid, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private static function mapAddress(?array $a): ?array
    {
        if (! $a) {
            return null;
        }

        return [
            'first_name' => $a['firstName'] ?? '',
            'last_name' => $a['lastName'] ?? '',
            'address1' => $a['address1'] ?? '',
            'address2' => $a['address2'] ?? null,
            'city' => $a['city'] ?? '',
            'province' => $a['provinceCode'] ?? '',
            'country' => $a['countryCodeV2'] ?? '',
            'zip' => $a['zip'] ?? '',
        ];
    }

    private static function gqlOrderToOrder(array $node): array
    {
        $totalSet = $node['totalPriceSet'] ?? [];
        $subtotalSet = $node['subtotalPriceSet'] ?? [];
        $taxSet = $node['totalTaxSet'] ?? [];
        $presentmentTotal = $totalSet['presentmentMoney'] ?? $totalSet['shopMoney'] ?? null;
        $presentmentSubtotal = $subtotalSet['presentmentMoney'] ?? $subtotalSet['shopMoney'] ?? null;
        $presentmentTax = $taxSet['presentmentMoney'] ?? $taxSet['shopMoney'] ?? null;
        $currency = $presentmentTotal['currencyCode'] ?? 'USD';

        $committedByLineId = [];
        foreach ($node['fulfillmentOrders']['edges'] ?? [] as $foEdge) {
            foreach ($foEdge['node']['lineItems']['edges'] ?? [] as $foLiEdge) {
                $foLi = $foLiEdge['node'] ?? [];
                $lineId = $foLi['lineItem']['id'] ?? null;
                if ($lineId) {
                    $committedByLineId[$lineId] = ($committedByLineId[$lineId] ?? 0)
                        + (int) ($foLi['totalQuantity'] ?? 0);
                }
            }
        }

        $lineItems = [];
        foreach ($node['lineItems']['edges'] ?? [] as $liEdge) {
            $li = $liEdge['node'] ?? [];
            $committedQuantity = $committedByLineId[$li['id'] ?? ''] ?? 0;
// Old: $inventoryQuantity = $li['variant']['sellableOnlineQuantity'] ?? null;
            $inventoryQuantity = $li['variant']['inventoryQuantity']
                ?? $li['variant']['sellableOnlineQuantity']
                ?? null;
            $customItem = empty($li['variant']);
            $properties = array_map(
                fn($attr) => ['name' => $attr['key'], 'value' => $attr['value']],
                $li['customAttributes'] ?? []
            );
            $variantOptions = [];
            foreach ($li['variant']['selectedOptions'] ?? [] as $opt) {
                if (($opt['name'] ?? '') === 'Title' && ($opt['value'] ?? '') === 'Default Title') {
                    continue;
                }
                $variantOptions[] = [
                    'name' => $opt['name'] ?? '',
                    'value' => $opt['value'] ?? '',
                ];
            }
            /*
            Old product lookup:
            $productGid = $li['variant']['product']['id'] ?? null;
            */
            $variant = $li['variant'] ?? [];
            $product = is_array($variant) ? ($variant['product'] ?? []) : [];
            $productGid = $product['id'] ?? null;
            $unitPriceMoney = $li['discountedUnitPriceSet']['presentmentMoney']
                ?? $li['originalUnitPriceSet']['presentmentMoney']
                ?? null;

            $lineItems[] = [
                'title' => $li['title'] ?? '',
                'quantity' => (int) ($li['quantity'] ?? 0),
                'unit_price' => $unitPriceMoney['amount'] ?? '0',
                'currency' => $unitPriceMoney['currencyCode'] ?? $currency,
                'product_id' => $productGid,
                /*
                Old line item variant fields:
                'sku' => $li['variant']['sku'] ?? null,
                */
                'variant_id' => $variant['id'] ?? null,
                'variant_title' => $variant['title'] ?? null,
                'sku' => $variant['sku'] ?? null,
                'product_type' => $product['productType'] ?? null,
                'gia_report' => $product['metafield']['value'] ?? null,
                'expected_receipt_date' => null,
                'inventory_quantity' => $inventoryQuantity,
                'committed_quantity' => $committedQuantity,
                'custom_item' => $customItem,
                'properties' => $properties,
                'variant_options' => $variantOptions,
            ];
        }

        $sourceName = strtolower($node['sourceName'] ?? '');
        $deliveryMethod = null;
        if ($sourceName === 'pos') {
            $deliveryMethod = 'In store purchase';
        } else {
            $foEdges = $node['fulfillmentOrders']['edges'] ?? [];
            $firstFo = $foEdges[0]['node'] ?? null;

            $methodType = $firstFo['deliveryMethod']['methodType'] ?? null;
            if (in_array($methodType, ['PICK_UP', 'PICKUP_POINT'], true)) {
                $deliveryMethod = 'Pickup';
            } elseif (in_array($methodType, ['SHIPPING', 'LOCAL', 'RETAIL', 'NONE'], true) || $methodType) {
                $deliveryMethod = 'Shipping';
            }
        }

        $shopifyOrderStatus = self::normalizeStatusValue($firstFo['status'] ?? null);

        return [
            'id' => self::parseOrderId($node['id'] ?? ''),
            'name' => $node['name'] ?? '',
            'order_number' => (int) ($node['number'] ?? 0),
            'email' => $node['email'] ?? null,
            'created_at' => $node['createdAt'] ?? '',
            'updated_at' => $node['updatedAt'] ?? '',
            'total_price' => $presentmentTotal['amount'] ?? '0',
            'subtotal_price' => $presentmentSubtotal['amount'] ?? '0',
            'total_tax' => $presentmentTax['amount'] ?? '0',
            'currency' => $currency,
            'shopify_order_status' => $shopifyOrderStatus,
            'financial_status' => self::normalizeStatusValue($node['displayFinancialStatus'] ?? null),
            'fulfillment_status_raw' => $node['displayFulfillmentStatus'] ?? null,
            'fulfillment_status' => self::normalizeStatusValue($node['displayFulfillmentStatus'] ?? null),
            'fulfillment_orders' => $node['fulfillmentOrders']['edges'] ?? [],
            'archived' => false,
            'cancelled_at' => $node['cancelledAt'] ?? null,
            'closed_at' => $node['closedAt'] ?? null,
            'tags' => $node['tags'] ?? [],
            'note' => $node['note'] ?? null,
            'billing_address' => self::mapAddress($node['billingAddress'] ?? null),
            'shipping_address' => self::mapAddress($node['shippingAddress'] ?? null),
            'delivery_method' => $deliveryMethod,
            'line_items' => $lineItems,
        ];
    }

    private static function normalizeStatusValue(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }
        $value = strtolower(trim($status));
        if ($value === '') {
            return null;
        }

        // Normalizes values like "READY_FOR_PICKUP", "Ready for pickup", "ready-for-pickup".
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        $value = trim($value, '_');

        return $value === '' ? null : $value;
    }

    private static function shopifyFinancialStatusSearchValue(?string $status): ?string
    {
        return match ($status) {
            'authorized',
            'paid',
            'partially_paid',
            'pending',
            'refunded',
            'voided' => $status,
            default => null,
        };
    }

    private static function orderHasOnHoldTag(array $order): bool
    {
        $tags = $order['tags'] ?? [];
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        if (! is_array($tags) || $tags === []) {
            return false;
        }

        foreach ($tags as $tag) {
            $normalized = self::normalizeStatusValue(is_string($tag) ? $tag : null);
            if ($normalized === 'on_hold') {
                return true;
            }
        }

        return false;
    }

    private static function orderIsFullyAvailable(array $order): bool
    {
        $lineItems = $order['line_items'] ?? [];
        if (! is_array($lineItems) || $lineItems === []) {
            return false;
        }

        foreach ($lineItems as $item) {
            if (! is_array($item)) {
                return false;
            }
            $quantity = (int) ($item['quantity'] ?? 0);
/*
            Old logic counted committed quantity as available:
            $available = ! empty($item['custom_item'])
                ? 0
                : (int) (($item['inventory_quantity'] ?? 0) + ($item['committed_quantity'] ?? 0));
            */
            $available = ! empty($item['custom_item'])
                ? 0
                : (int) ($item['inventory_quantity'] ?? 0);
            if ($quantity > $available) {
                return false;
            }
        }

        return true;
    }

    private static function orderIsReadyForPickup(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $store = env('SHOPIFY_STORE_DOMAIN');
        $token = env('SHOPIFY_ACCESS_TOKEN');
        if (! $store || ! $token) {
            return false;
        }

        $host = preg_replace('#^https?://#', '', trim($store));
        $host = rtrim((string) $host, '/');
        if ($host === '') {
            return false;
        }

        $apiVersion = trim(env('SHOPIFY_API_VERSION', '') ?: '') ?: '2025-10';
        $url = 'https://' . $host . '/admin/api/' . $apiVersion . '/orders/' . $orderId . '/fulfillment_orders.json';

        try {
            $res = Http::timeout(30)->withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->get($url);
        } catch (\Throwable $e) {
            Log::warning('Ready-for-pickup check failed for order ' . $orderId . ': ' . $e->getMessage());

            return false;
        }

        if (! $res->successful()) {
            Log::warning('Ready-for-pickup check HTTP ' . $res->status() . ' for order ' . $orderId);

            return false;
        }

        foreach ($res->json('fulfillment_orders', []) as $fo) {
            $methodType = self::normalizeStatusValue($fo['delivery_method']['method_type'] ?? null);
            $isPickupMethod = in_array($methodType, ['pick_up', 'pickup', 'pick_up_point'], true);
            if (! $isPickupMethod) {
                continue;
            }

            $displayStatus = self::normalizeStatusValue($fo['display_status'] ?? null);
            $status = self::normalizeStatusValue($fo['status'] ?? null);

            if ($displayStatus === 'ready_for_pickup' || $status === 'in_progress') {
                return true;
            }
        }

        return false;
    }

    private static function normalizeRef(string $s): string
    {
        $s = preg_replace('/^WEBORDER\s+/i', '', $s);
        $s = preg_replace('/^\s*#\s*/', '', $s);

        return trim($s);
    }

    private static function shouldLoadExternalOrderData(): bool
    {
        return filter_var(env('SHOPIFY_ORDERS_LOAD_EXTERNAL_DATA', false), FILTER_VALIDATE_BOOLEAN);
    }

    private static function integrationStatus(bool $loadExternalOrderData): array
    {
        $businessCentralStatus = self::integrationSourceStatus(
            'disabled',
            'Business Central data is not loaded in demo mode. Shopify orders are still shown.'
        );
        $webshipperStatus = self::integrationSourceStatus(
            'disabled',
            'Webshipper data is not loaded in demo mode. Shopify orders are still shown.'
        );

        if ($loadExternalOrderData) {
            $businessCentralStatus = BusinessCentralService::isConfigured()
                ? self::integrationSourceStatus('pending', 'Business Central data is being checked.')
                : self::integrationSourceStatus(
                    'not_configured',
                    'Business Central is not configured. Shopify orders are still shown.'
                );
            $webshipperStatus = WebshipperService::isConfigured()
                ? self::integrationSourceStatus('pending', 'Webshipper data is being checked.')
                : self::integrationSourceStatus(
                    'not_configured',
                    'Webshipper is not configured. Shopify orders are still shown.'
                );
        }

        return [
            'shopify' => self::integrationSourceStatus('loaded', 'Shopify order data loaded.'),
            'external_data_enabled' => $loadExternalOrderData,
            'sources' => [
                'business_central' => $businessCentralStatus,
                'webshipper' => $webshipperStatus,
            ],
        ];
    }

    private static function integrationSourceStatus(string $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message,
        ];
    }

    private static function enrichWithBusinessCentral(array &$allOrders): void
    {
        $token = BusinessCentralService::getAccessToken();
        $resolved = BusinessCentralService::resolveCompany($token);
        if (! $resolved) {
            throw new \RuntimeException('No company found');
        }
        $companyId = (string) $resolved['companyId'];
        $bcCachePrefix = 'orders:bc:' . md5($companyId);

        $bcOrders = Cache::remember(
            $bcCachePrefix . ':sales_orders',
            now()->addSeconds(90),
            static fn() => BusinessCentralService::getSalesOrders($token, $companyId)
        );
        $bcByRef = [];
        foreach ($bcOrders as $bc) {
            $ext = trim($bc['externalDocumentNumber'] ?? '');
            if ($ext !== '') {
                $bcByRef[$ext] = $bc;
                $norm = self::normalizeRef($ext);
                if ($norm !== '') {
                    $bcByRef[$norm] = $bc;
                }
            }
            $num = trim($bc['number'] ?? '');
            if ($num !== '') {
                $bcByRef[$num] = $bc;
            }
        }

        $expectedReceiptByItem = Cache::remember(
            $bcCachePrefix . ':expected_receipt_by_item',
            now()->addSeconds(90),
            static fn() => BusinessCentralService::getExpectedReceiptByItem($token, $companyId)
        );

        foreach ($allOrders as &$order) {
            $ref = (string) $order['order_number'];
            $refAlt = preg_replace('/^\s*#?\s*/', '', $order['name']);
            $bc = $bcByRef[$ref]
                ?? $bcByRef[$refAlt]
                ?? $bcByRef['#' . $ref]
                ?? $bcByRef['WEBORDER #' . $ref]
                ?? $bcByRef['WEBORDER #' . $refAlt]
                ?? null;

            if ($bc) {
                $order['business_central'] = [
                    'order_id' => $bc['id'],
                    'number' => $bc['number'],
                    'status' => $bc['status'],
                    'fully_shipped' => $bc['fullyShipped'],
                    'requested_delivery_date' => $bc['requestedDeliveryDate'] ?? null,
                    'shipment_date' => null,
                ];

                $shipmentDate = Cache::remember(
                    $bcCachePrefix . ':shipment_date:' . (string) $bc['id'],
                    now()->addSeconds(180),
                    static fn() => BusinessCentralService::getOrderShipmentDate($token, $companyId, (string) $bc['id'])
                );
                $order['business_central']['shipment_date'] = $shipmentDate;
            }

            foreach ($order['line_items'] ?? [] as &$item) {
                $available = $item['inventory_quantity'] ?? 0;
                if ($available < $item['quantity'] && ! empty($item['sku'])) {
                    $date = $expectedReceiptByItem[$item['sku']] ?? null;
                    if ($date) {
                        $item['expected_receipt_date'] = $date;
                    }
                }
            }
        }
    }

    private static function enrichWithWebshipper(array &$allOrders): void
    {
        $account = trim(env('WEBSHIPPER_ACCOUNT_NAME', '') ?? '');
        $wsCacheKey = 'orders:webshipper:' . md5($account) . ':orders_with_shipments';
        $wsOrders = Cache::remember(
            $wsCacheKey,
            now()->addSeconds(90),
            static fn() => WebshipperService::getOrders(15, true)
        );
        $wsByRef = [];
        foreach ($wsOrders as $ws) {
            $ref = trim($ws['reference'] ?? '');
            if ($ref === '') {
                continue;
            }
            if (preg_match('/^\d{12,}$/', $ref)) {
                continue;
            }
            $wsByRef[$ref] = $ws;
            $norm = self::normalizeRef($ref);
            if ($norm !== '') {
                $wsByRef[$norm] = $ws;
            }
        }

        $accountValid = $account !== '' && preg_match('/^[a-z0-9_.-]+$/i', $account);

        foreach ($allOrders as &$order) {
            $orderNumber = (string) $order['order_number'];
            $refAlt = preg_replace('/^\s*#+\s*/', '', $order['name']);
            $ws = $wsByRef[$orderNumber]
                ?? $wsByRef[$refAlt]
                ?? $wsByRef['#' . $orderNumber]
                ?? $wsByRef['##' . $orderNumber]
                ?? $wsByRef['WEBORDER #' . $orderNumber]
                ?? $wsByRef['WEBORDER #' . $refAlt]
                ?? null;

            if ($ws) {
                $orderUrl = $accountValid ? 'https://' . $account . '.webshipper.io/ship/orders/' . $ws['id'] : null;
                $shipmentUrl = null;
                if ($accountValid && isset($ws['shipment_id']) && $ws['shipment_id']) {
                    $shipmentUrl = 'https://' . $account . '.webshipper.io/ship/shipments/' . $ws['shipment_id'];
                }
                $order['webshipper'] = [
                    'order_id' => $ws['id'],
                    'status' => $ws['status'] ?? null,
                    'tracking_numbers' => $ws['tracking_numbers'] ?? [],
                    'carrier_names' => $ws['carrier_names'] ?? [],
                    'order_url' => $orderUrl,
                    'shipment_url' => $shipmentUrl,
                    'has_shipment' => $ws['has_shipment'] ?? false,
                ];
            }
        }
    }
}
