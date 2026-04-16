<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebshipperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WebshipperTestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! WebshipperService::isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => 'Webshipper is not configured',
                'hint' => 'Set WEBSHIPPER_ACCOUNT_NAME and WEBSHIPPER_ACCESS_TOKEN in .env',
            ], 200);
        }

        try {
            $account = trim(env('WEBSHIPPER_ACCOUNT_NAME', '') ?? '');
            $orders = WebshipperService::getOrders(3, true);

            $isLongNumericId = fn ($s) => $s && preg_match('/^\d{12,}$/', trim($s));
            $matchableOrders = array_filter(
                $orders,
                fn ($o) => ! empty($o['reference']) && ! $isLongNumericId($o['reference'])
            );
            $matchableCount = count($matchableOrders);

            $sample = array_slice(array_map(fn ($o) => [
                'id' => $o['id'],
                'status' => $o['status'],
                'reference_used_for_matching' => $o['reference'] ?? null,
                'matchable' => ! empty($o['reference']) && ! $isLongNumericId($o['reference']),
                'tracking_count' => count($o['tracking_numbers'] ?? []),
                'carrier_names' => $o['carrier_names'] ?? [],
            ], array_slice($orders, 0, 20)), 0, 20);

            $out = [
                'ok' => true,
                'message' => 'Successfully connected to Webshipper',
                'accountUsed' => $account ?: '(not set)',
                'ordersCount' => count($orders),
                'matchableByReferenceCount' => $matchableCount,
                'sampleOrders' => $sample,
            ];

            if ($request->query('raw') === 'true') {
                $token = env('WEBSHIPPER_ACCESS_TOKEN');
                $base = 'https://' . urlencode(trim(env('WEBSHIPPER_ACCOUNT_NAME', ''))) . '.api.webshipper.io/v2';
                $res = Http::withToken($token)->get($base . '/orders?page[number]=1&page[size]=5');
                if ($res->successful()) {
                    $data = $res->json('data');
                    $data = is_array($data) ? $data : ($data ? [$data] : []);
                    $first = $data[0] ?? null;
                    $out['raw'] = [
                        'firstOrderAttributes' => $first['attributes'] ?? null,
                        'firstOrderRelationships' => $first['relationships'] ?? null,
                        'attributeKeys' => isset($first['attributes']) ? array_keys($first['attributes']) : [],
                    ];
                } else {
                    $out['raw'] = ['error' => $res->status() . ' ' . $res->body()];
                }
            }

            return response()->json($out);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Webshipper connection failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
