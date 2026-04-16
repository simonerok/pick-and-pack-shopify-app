<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BusinessCentralService;
use App\Services\ShopifyOrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessCentralTestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! BusinessCentralService::isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => 'Business Central is not configured',
                'hint' => 'Set BC_TENANT_ID, BC_CLIENT_ID, and BC_CLIENT_SECRET in .env',
            ], 200);
        }

        try {
            $token = BusinessCentralService::getAccessToken();
            $resolved = BusinessCentralService::resolveCompany($token);

            if (! $resolved) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No company found in Business Central',
                ], 500);
            }

            $salesOrders = BusinessCentralService::getSalesOrders($token, $resolved['companyId']);

            $out = [
                'ok' => true,
                'message' => 'Successfully connected to Business Central',
                'companyUsed' => [
                    'id' => $resolved['company']['id'],
                    'name' => $resolved['company']['name'],
                    'displayName' => $resolved['company']['displayName'] ?? null,
                ],
                'salesOrdersCount' => count($salesOrders),
            ];

            if ($request->query('withMatches') === 'true') {
                $shopifyData = ShopifyOrdersService::fetchOrders(false);
                $orders = $shopifyData['orders'] ?? [];
                $bcOrderIds = array_unique(array_filter(array_map(
                    fn ($o) => $o['business_central']['order_id'] ?? null,
                    $orders
                )));
                $bcOrderIds = array_slice(array_values($bcOrderIds), 0, 30);

                $matchedOrdersWithFullData = [];
                foreach ($bcOrderIds as $orderId) {
                    try {
                        $full = BusinessCentralService::getSalesOrderById(
                            $token,
                            $resolved['companyId'],
                            $orderId,
                            ['salesOrderLines']
                        );
                        $matchedOrdersWithFullData[] = $full;
                    } catch (\Throwable $e) {
                        $matchedOrdersWithFullData[] = ['id' => $orderId, '_fetchError' => $e->getMessage()];
                    }
                }

                $out['matchedCount'] = count(array_unique(array_filter(array_map(
                    fn ($o) => $o['business_central']['order_id'] ?? null,
                    $orders
                ))));
                $out['matchedOrdersWithFullData'] = $matchedOrdersWithFullData;
            }

            return response()->json($out);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Business Central connection failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
