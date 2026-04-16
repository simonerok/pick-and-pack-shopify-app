<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class WebshipperService
{
    public static function isConfigured(): bool
    {
        $account = trim(env('WEBSHIPPER_ACCOUNT_NAME', '') ?? '');
        $token = trim(env('WEBSHIPPER_ACCESS_TOKEN', '') ?? '');

        return $account !== '' && $token !== '';
    }

    private static function getBaseUrl(): string
    {
        $account = trim(env('WEBSHIPPER_ACCOUNT_NAME', '') ?? '');
        if ($account === '') {
            throw new \RuntimeException('WEBSHIPPER_ACCOUNT_NAME is required');
        }

        return 'https://' . urlencode($account) . '.api.webshipper.io/v2';
    }

    private static function getToken(): string
    {
        $token = trim(env('WEBSHIPPER_ACCESS_TOKEN', '') ?? '');
        if ($token === '') {
            throw new \RuntimeException('WEBSHIPPER_ACCESS_TOKEN is required');
        }

        return $token;
    }

    public static function getOrders(int $maxPages = 20, bool $includeShipments = true): array
    {
        $token = self::getToken();
        $base = self::getBaseUrl();
        $orders = [];
        $pageSize = 30;

        $buildUrl = static function (int $page) use ($base, $pageSize, $includeShipments): string {
            $url = $base . '/orders?page[number]=' . $page . '&page[size]=' . $pageSize;
            if ($includeShipments) {
                $url .= '&include=shipments';
            }

            return $url;
        };

        $appendPage = static function (array &$orders, $response, int $page): int {
            if ($response === null) {
                throw new \RuntimeException('Webshipper orders failed: missing response for page ' . $page);
            }
            if (! $response->successful()) {
                throw new \RuntimeException(
                    'Webshipper orders failed: '
                    . $response->status()
                    . ' '
                    . $response->body()
                );
            }
            $json = $response->json();
            $data = $json['data'] ?? null;
            $list = is_array($data) ? $data : ($data ? [$data] : []);
            $included = $json['included'] ?? [];
            $includedMap = [];
            foreach ($included as $inc) {
                if (isset($inc['id'], $inc['type'])) {
                    $includedMap[$inc['id']] = $inc;
                }
            }

            foreach ($list as $item) {
                if (! is_array($item) || ! isset($item['id'], $item['attributes'])) {
                    continue;
                }
                try {
                    $orders[] = self::parseOrderFromResource($item, $includedMap);
                } catch (\Throwable $e) {
                    // skip malformed
                }
            }

            return count($list);
        };

        $page1 = Http::withToken($token)->get($buildUrl(1));
        $listCount = $appendPage($orders, $page1, 1);

        /** @var array<string, \Illuminate\Http\Client\Response> $responses */
        $responses = [];
        if ($listCount >= $pageSize && $maxPages >= 2) {
            $responses = Http::pool(function (Pool $pool) use ($token, $buildUrl, $maxPages) {
                for ($p = 2; $p <= $maxPages; $p++) {
                    $pool->as((string) $p)->withToken($token)->get($buildUrl($p));
                }
            });
        }

        for ($page = 2; $page <= $maxPages; $page++) {
            if ($listCount < $pageSize) {
                break;
            }
            $response = $responses[(string) $page] ?? null;
            $listCount = $appendPage($orders, $response, $page);
            if ($listCount < $pageSize) {
                break;
            }
        }

        return $orders;
    }

    private static function refStr($v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    private static function parseOrderFromResource(array $res, array $includedMap): array
    {
        $attrs = $res['attributes'] ?? [];
        $id = is_numeric($res['id']) ? (int) $res['id'] : (int) (string) $res['id'];

        $reference = self::refStr($attrs['reference'] ?? null)
            ?? self::refStr($attrs['reference_id'] ?? null)
            ?? self::refStr($attrs['referenceName'] ?? null)
            ?? self::refStr($attrs['referenceId'] ?? null)
            ?? self::refStr($attrs['visible_ref'] ?? null)
            ?? self::refStr($attrs['external_id'] ?? null)
            ?? self::refStr($attrs['order_number'] ?? null)
            ?? self::refStr($attrs['name'] ?? null)
            ?? self::refStr($attrs['shopify_order_id'] ?? null)
            ?? self::refStr($attrs['shopify_order_number'] ?? null);

        $orderLines = $attrs['order_lines'] ?? [];
        $orderLines = is_array($orderLines) ? $orderLines : [];
        $firstLine = isset($orderLines[0]) && is_array($orderLines[0]) ? $orderLines[0] : null;
        $firstLineExtRef = $firstLine ? self::refStr($firstLine['ext_ref'] ?? null) : null;
        $firstLineVisibleRef = $firstLine ? self::refStr($firstLine['visible_ref'] ?? null) : null;
        $orderVisibleRef = self::refStr($attrs['visible_ref'] ?? null);
        $orderReference = self::refStr($attrs['reference'] ?? null);
        $refFromLine = $firstLineExtRef ?? $firstLineVisibleRef;

        $status = isset($attrs['status']) && is_string($attrs['status']) ? $attrs['status'] : null;

        $trackingNumbers = [];
        $carrierNames = [];
        if (self::refStr($attrs['tracking_code'] ?? null)) {
            $trackingNumbers[] = trim($attrs['tracking_code']);
        }
        if (self::refStr($attrs['tracking_number'] ?? null)) {
            $trackingNumbers[] = trim($attrs['tracking_number']);
        }
        if (is_array($attrs['tracking_numbers'] ?? null)) {
            foreach ($attrs['tracking_numbers'] as $t) {
                if (is_string($t) && trim($t) !== '') {
                    $trackingNumbers[] = trim($t);
                }
            }
        }
        if (self::refStr($attrs['carrier_name'] ?? null)) {
            $carrierNames[] = trim($attrs['carrier_name']);
        }

        $shipRel = $res['relationships'] ?? [];
        $shipData = $shipRel['shipments']['data'] ?? null;
        $shipList = is_array($shipData) ? $shipData : ($shipData ? [$shipData] : []);
        $firstShipmentId = null;
        foreach ($shipList as $ref) {
            $sid = is_array($ref) && isset($ref['id']) ? (string) $ref['id'] : null;
            if ($sid && $firstShipmentId === null) {
                $num = (int) $sid;
                if ($num > 0) {
                    $firstShipmentId = $num;
                }
            }
            if ($sid && isset($includedMap[$sid]) && isset($includedMap[$sid]['attributes'])) {
                $a = $includedMap[$sid]['attributes'];
                if (self::refStr($a['tracking_code'] ?? null)) {
                    $trackingNumbers[] = trim($a['tracking_code']);
                }
                if (self::refStr($a['tracking_number'] ?? null)) {
                    $trackingNumbers[] = trim($a['tracking_number']);
                }
                if (self::refStr($a['carrier_name'] ?? null)) {
                    $carrierNames[] = trim($a['carrier_name']);
                }
            }
        }

        $referenceForMatch = $orderVisibleRef ?? $refFromLine ?? $reference;

        return [
            'id' => $id,
            'reference' => $referenceForMatch,
            'status' => $status,
            'tracking_numbers' => array_values(array_unique($trackingNumbers)),
            'carrier_names' => array_values(array_unique($carrierNames)),
            'has_shipment' => count($shipList) > 0,
            'shipment_id' => $firstShipmentId,
        ];
    }

    /**
     * @param  array<string, mixed>  $orderData
     */
    private static function firstShipmentIdFromOrderResource(array $orderData): ?int
    {
        $shipRel = $orderData['relationships'] ?? [];
        $shipData = $shipRel['shipments']['data'] ?? null;
        $shipList = is_array($shipData) ? $shipData : ($shipData ? [$shipData] : []);
        foreach ($shipList as $ref) {
            $sid = is_array($ref) && isset($ref['id']) ? (string) $ref['id'] : null;
            if ($sid === '') {
                continue;
            }
            $num = (int) $sid;
            if ($num > 0) {
                return $num;
            }
        }

        return null;
    }

    public static function getLabelPdfForOrder(int $wsOrderId): array
    {
        $token = self::getToken();
        $base = self::getBaseUrl();

        $shipmentId = null;
        $orderData = null;
        $orderRes = Http::withToken($token)->get(
            $base . '/orders/' . urlencode((string) $wsOrderId) . '?include=shipments'
        );
        if ($orderRes->successful()) {
            $raw = $orderRes->json('data');
            $orderData = is_array($raw) ? $raw : null;
            if ($orderData !== null) {
                $shipmentId = self::firstShipmentIdFromOrderResource($orderData);
            }
        }

        if (! $shipmentId) {
            // Match docs: "Creating a Shipment from an Order" — only `order` relationship, numeric id.
            // https://docs.webshipper.io/#shipments
            $createRes = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.api+json',
                    'Content-Type' => 'application/vnd.api+json',
                ])
                ->post($base . '/shipments', [
                    'data' => [
                        'type' => 'shipments',
                        'attributes' => new \stdClass(),
                        'relationships' => [
                            'order' => [
                                'data' => ['type' => 'orders', 'id' => $wsOrderId],
                            ],
                        ],
                    ],
                ]);
            if (! $createRes->successful()) {
                $status = $createRes->status();
                $body = $createRes->body();
                $msg = 'Create shipment failed: ' . $status . ' ' . $body;
                if ($status === 403 && trim($body) === '') {
                    $msg .= ' Webshipper refused creation (empty body). API tokens need the write_shipments scope; see https://docs.webshipper.io/#6-scopes and Settings → Access and tokens.';
                }

                return [
                    'ok' => false,
                    'error' => $msg,
                ];
            }
            $id = $createRes->json('data.id');
            if (! $id) {
                return ['ok' => false, 'error' => 'Create shipment response missing id'];
            }
            $shipmentId = (int) (string) $id;
        }

        $labelsRes = Http::withToken($token)->get($base . '/shipments/' . $shipmentId . '/labels');
        if (! $labelsRes->successful()) {
            return ['ok' => false, 'error' => 'Get labels failed: ' . $labelsRes->status() . ' ' . $labelsRes->body()];
        }
        $labelsList = $labelsRes->json('data');
        $labelsList = is_array($labelsList) ? $labelsList : [];
        $firstLabel = $labelsList[0] ?? null;
        if (! $firstLabel || ! isset($firstLabel['id'])) {
            return ['ok' => false, 'error' => 'No labels found for shipment'];
        }

        $labelId = (string) $firstLabel['id'];
        $pdfRes = Http::withToken($token)->get($base . '/labels/' . $labelId . '?download_as=PDF');
        if (! $pdfRes->successful()) {
            return ['ok' => false, 'error' => 'Get label PDF failed: ' . $pdfRes->status() . ' ' . $pdfRes->body()];
        }
        $base64 = $pdfRes->json('data.attributes.base64');
        if (! is_string($base64) || $base64 === '') {
            return ['ok' => false, 'error' => 'Label PDF not available (base64 missing)'];
        }

        return ['ok' => true, 'pdfBase64' => $base64];
    }

    public static function getReturnLabelPdfForOrder(int $wsOrderId): array
    {
        $token = self::getToken();
        $base = self::getBaseUrl();

        $orderLinesRes = Http::withToken($token)->get(
            $base . '/order_lines?filter[order_id]='
            . urlencode((string) $wsOrderId)
            . '&page[size]=50'
        );
        if (! $orderLinesRes->successful()) {
            return [
                'ok' => false,
                'error' => 'Order lines failed: '
                    . $orderLinesRes->status()
                    . ' '
                    . $orderLinesRes->body(),
            ];
        }
        $rawData = $orderLinesRes->json('data');
        $orderLinesList = is_array($rawData) ? $rawData : ($rawData !== null ? [$rawData] : []);
        if (empty($orderLinesList)) {
            return ['ok' => false, 'error' => 'No order lines found for this order'];
        }

        $orderLines = [];
        foreach ($orderLinesList as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }
            $id = is_numeric($item['id']) ? (int) $item['id'] : (int) (string) $item['id'];
            $attrs = $item['attributes'] ?? [];
            $qty = isset($attrs['quantity']) && is_numeric($attrs['quantity']) ? (int) $attrs['quantity'] : 1;
            $orderLines[] = ['id' => $id, 'quantity' => $qty];
        }
        if (empty($orderLines)) {
            return ['ok' => false, 'error' => 'Could not parse order lines'];
        }

        $causesRes = Http::withToken($token)->get($base . '/return_causes?page[size]=1');
        if (! $causesRes->successful()) {
            return ['ok' => false, 'error' => 'Return causes failed: ' . $causesRes->status()];
        }
        $causesList = $causesRes->json('data');
        $causesList = is_array($causesList) ? $causesList : [];
        $firstCause = $causesList[0] ?? null;
        $causeId = $firstCause && isset($firstCause['id']) ? (int) (string) $firstCause['id'] : null;
        if ($causeId === null || $causeId <= 0) {
            return ['ok' => false, 'error' => 'No return cause configured in Webshipper'];
        }

        $returnLines = array_map(function ($line) use ($causeId) {
            return [
                'order_line_id' => $line['id'],
                'quantity' => $line['quantity'],
                'cause_id' => $causeId,
                'cause_description' => 'Return requested by customer',
            ];
        }, $orderLines);

        $createRes = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/vnd.api+json'])
            ->post($base . '/returns', [
                'data' => [
                    'type' => 'returns',
                    'attributes' => [
                        'return_lines' => $returnLines,
                    ],
                    'relationships' => [
                        'order' => ['data' => ['type' => 'orders', 'id' => (string) $wsOrderId]],
                    ],
                ],
            ]);
        if (! $createRes->successful()) {
            return [
                'ok' => false,
                'error' => 'Create return failed: '
                    . $createRes->status()
                    . ' '
                    . $createRes->body(),
            ];
        }
        $createJson = $createRes->json();
        $returnId = $createJson['data']['id'] ?? null;
        if (! $returnId) {
            return ['ok' => false, 'error' => 'Create return response missing id'];
        }

        $base64 = $createJson['data']['attributes']['base64'] ?? null;
        if (! is_string($base64) || trim($base64) === '') {
            $getRes = Http::withToken($token)->get($base . '/returns/' . $returnId);
            if ($getRes->successful()) {
                $base64 = $getRes->json('data.attributes.base64') ?? '';
            }
        }
        if (! is_string($base64) || trim($base64) === '') {
            return [
                'ok' => false,
                'error' => 'Return label not yet available. Try again in a moment '
                    . 'or open the return in Webshipper.',
            ];
        }

        return ['ok' => true, 'pdfBase64' => $base64];
    }
}
