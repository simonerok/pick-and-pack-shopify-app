<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;

class ShopOnboardingController extends Controller
{
    public function __invoke(Request $request)
    {
        $shopifySession = $request->get('shopifySession');
        if (!$shopifySession) {
            abort(401, 'Missing Shopify session.');
        }

        $shopDomain = $shopifySession->getShop();

        // 1) Create shop if missing
        $shop = Shop::firstOrCreate([
            'domain' => $shopDomain,
        ]);

        // 2) Attach logged-in user as owner (if not already attached)
        $shop->users()->syncWithoutDetaching([
            auth()->id() => [
                'role' => 'owner',
                'status' => 'active',
            ],
        ]);

        return redirect()->route('home', array_filter([
            'shop' => $request->query('shop'),
            'host' => $request->query('host'),
            'embedded' => $request->query('embedded'),
        ], fn ($value) => is_string($value) && $value !== ''));
    }
}
