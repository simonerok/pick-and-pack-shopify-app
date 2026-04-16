<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BusinessCentralService
{
    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const BC_SCOPE = 'https://api.businesscentral.dynamics.com/.default';

    public static function isConfigured(): bool
    {
        return ! empty(env('BC_TENANT_ID'))
            && ! empty(env('BC_CLIENT_ID'))
            && ! empty(env('BC_CLIENT_SECRET'));
    }

    public static function getAccessToken(): string
    {
        $tenantId = env('BC_TENANT_ID');
        $clientId = env('BC_CLIENT_ID');
        $clientSecret = env('BC_CLIENT_SECRET');

        if (! $tenantId || ! $clientId || ! $clientSecret) {
            throw new \RuntimeException(
                'BC_TENANT_ID, BC_CLIENT_ID, and BC_CLIENT_SECRET '
                . 'are required for Business Central'
            );
        }

        $url = sprintf(self::TOKEN_URL_TEMPLATE, $tenantId);
        $response = Http::asForm()->post($url, [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => self::BC_SCOPE,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('BC token failed: ' . $response->status() . ' ' . $response->body());
        }

        $data = $response->json();
        if (empty($data['access_token'])) {
            throw new \RuntimeException('BC token response missing access_token');
        }

        return $data['access_token'];
    }

    private static function getBaseUrl(): string
    {
        $tenantId = env('BC_TENANT_ID');
        $environment = env('BC_ENVIRONMENT', 'production');

        return sprintf(
            'https://api.businesscentral.dynamics.com/v2.0/%s/%s/api/v2.0',
            urlencode($tenantId),
            urlencode($environment)
        );
    }

    public static function getCompanies(string $accessToken): array
    {
        $base = self::getBaseUrl();
        $response = Http::withToken($accessToken)->get($base . '/companies?$top=20');

        if (! $response->successful()) {
            throw new \RuntimeException('BC companies failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json('value') ?? [];
    }

    public static function resolveCompany(string $accessToken): ?array
    {
        $companies = self::getCompanies($accessToken);
        if (empty($companies)) {
            return null;
        }

        $companyIdEnv = trim(env('BC_COMPANY_ID', '') ?? '');
        if ($companyIdEnv) {
            foreach ($companies as $c) {
                if (($c['id'] ?? '') === $companyIdEnv) {
                    return ['companyId' => $c['id'], 'company' => $c];
                }
            }
        }

        $companyName = trim(env('BC_COMPANY_NAME', '') ?? '');
        if ($companyName) {
            $lower = strtolower($companyName);
            foreach ($companies as $c) {
                $name = strtolower($c['name'] ?? '');
                $displayName = strtolower($c['displayName'] ?? '');
                if ($name === $lower || $displayName === $lower) {
                    return ['companyId' => $c['id'], 'company' => $c];
                }
            }
        }

        $first = $companies[0];

        return ['companyId' => $first['id'], 'company' => $first];
    }

    public static function getSalesOrders(string $accessToken, string $companyId): array
    {
        $base = self::getBaseUrl();
        $orders = [];
        $nextUrl = $base
            . '/companies(' . urlencode($companyId) . ')/salesOrders'
            . '?$top=250'
            . '&$select=id,number,externalDocumentNumber,orderDate,requestedDeliveryDate,'
            . 'status,customerName,totalAmountIncludingTax,fullyShipped,lastModifiedDateTime';

        while ($nextUrl) {
            $response = Http::withToken($accessToken)->get($nextUrl);
            if (! $response->successful()) {
                throw new \RuntimeException('BC sales orders failed: ' . $response->status() . ' ' . $response->body());
            }
            $json = $response->json();
            foreach ($json['value'] ?? [] as $o) {
                $orders[] = [
                    'id' => $o['id'] ?? '',
                    'number' => $o['number'] ?? '',
                    'externalDocumentNumber' => $o['externalDocumentNumber'] ?? null,
                    'orderDate' => $o['orderDate'] ?? null,
                    'requestedDeliveryDate' => $o['requestedDeliveryDate'] ?? null,
                    'status' => $o['status'] ?? '',
                    'customerName' => $o['customerName'] ?? null,
                    'totalAmountIncludingTax' => $o['totalAmountIncludingTax'] ?? null,
                    'fullyShipped' => $o['fullyShipped'] ?? false,
                    'lastModifiedDateTime' => $o['lastModifiedDateTime'] ?? null,
                ];
            }
            $nextUrl = $json['@odata.nextLink'] ?? null;
        }

        return $orders;
    }

    public static function getSalesOrderById(
        string $accessToken,
        string $companyId,
        string $orderId,
        array $expand = []
    ): array {
        $base = self::getBaseUrl();
        $url = $base
            . '/companies(' . urlencode($companyId) . ')'
            . '/salesOrders(' . urlencode($orderId) . ')';
        if (! empty($expand)) {
            $url .= '?$expand=' . implode(',', $expand);
        }
        $response = Http::withToken($accessToken)->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException(
                'BC sales order by id failed: '
                . $response->status()
                . ' '
                . $response->body()
            );
        }

        return $response->json();
    }

    public static function getOrderShipmentDate(string $accessToken, string $companyId, string $orderId): ?string
    {
        $order = self::getSalesOrderById($accessToken, $companyId, $orderId, ['salesOrderLines']);
        $lines = $order['salesOrderLines'] ?? null;
        if (is_array($lines)) {
            $raw = $lines;
        } elseif (is_array($lines) && isset($lines['value'])) {
            $raw = $lines['value'];
        } else {
            $raw = [];
        }
        $earliest = null;
        foreach ($raw as $line) {
            $d = $line['shipmentDate'] ?? null;
            if (is_string($d) && trim($d) !== '') {
                if ($earliest === null || $d < $earliest) {
                    $earliest = $d;
                }
            }
        }

        return $earliest;
    }

    public static function getExpectedReceiptByItem(string $accessToken, string $companyId): array
    {
        $base = self::getBaseUrl();
        $byItem = [];
        $nextUrl = $base . '/companies(' . urlencode($companyId) . ')/purchaseOrders?$top=100&$select=id';
        $allPoIds = [];

        while ($nextUrl) {
            $response = Http::withToken($accessToken)->get($nextUrl);
            if (! $response->successful()) {
                throw new \RuntimeException(
                    'BC purchase orders failed: '
                    . $response->status()
                    . ' '
                    . $response->body()
                );
            }
            $json = $response->json();
            foreach ($json['value'] ?? [] as $o) {
                if (! empty($o['id'])) {
                    $allPoIds[] = $o['id'];
                }
            }
            $nextUrl = $json['@odata.nextLink'] ?? null;
        }

        foreach (array_chunk($allPoIds, 12) as $chunk) {
            /** @var array<string, \Illuminate\Http\Client\Response> $lineResponses */
            $lineResponses = Http::pool(function (Pool $pool) use ($accessToken, $base, $companyId, $chunk) {
                foreach ($chunk as $poId) {
                    $linesUrl = $base
                        . '/companies(' . urlencode($companyId) . ')'
                        . '/purchaseOrders(' . urlencode((string) $poId) . ')/purchaseOrderLines'
                        . '?$top=500&$select=lineObjectNumber,expectedReceiptDate,receiveQuantity';
                    $pool->as((string) $poId)->withToken($accessToken)->get($linesUrl);
                }
            });

            foreach ($chunk as $poId) {
                $linesRes = $lineResponses[(string) $poId] ?? null;
                if ($linesRes === null) {
                    throw new \RuntimeException('BC purchase order lines failed: missing response');
                }
                if (! $linesRes->successful()) {
                    throw new \RuntimeException('BC purchase order lines failed: ' . $linesRes->status());
                }
                $linesJson = $linesRes->json();
                foreach ($linesJson['value'] ?? [] as $line) {
                    $qty = $line['receiveQuantity'] ?? 0;
                    $dateStr = $line['expectedReceiptDate'] ?? null;
                    $itemNo = isset($line['lineObjectNumber'])
                        ? trim($line['lineObjectNumber'])
                        : null;
                    if (
                        ! $itemNo
                        || $qty <= 0
                        || ! is_string($dateStr)
                        || trim($dateStr) === ''
                    ) {
                        continue;
                    }
                    $date = explode('T', $dateStr)[0] ?? '';
                    if (strlen($date) !== 10) {
                        continue;
                    }
                    if (! isset($byItem[$itemNo]) || $date < $byItem[$itemNo]) {
                        $byItem[$itemNo] = $date;
                    }
                }
            }
        }

        return $byItem;
    }

    public static function createSalesOrderLine(
        string $accessToken,
        string $companyId,
        string $salesOrderId,
        string $lineType,
        string $description
    ): array {
        $base = self::getBaseUrl();
        $url = $base
            . '/companies(' . urlencode($companyId) . ')'
            . '/salesOrders(' . urlencode($salesOrderId) . ')/salesOrderLines';
        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, [
                'lineType' => $lineType,
                'description' => $description,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'BC create sales order line failed: '
                . $response->status()
                . ' '
                . $response->body()
            );
        }

        return $response->json();
    }
}
