<?php

namespace App\Http\Middleware;

use App\Lib\TopLevelRedirection;
use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAppUserAuthorizedForShop
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            $loginUrl = route('login', array_filter([
                'shop' => $request->query('shop'),
                'host' => $request->query('host'),
                'embedded' => $request->query('embedded'),
            ]));

            return TopLevelRedirection::redirect($request, $loginUrl);
        }

        $shopifySession = $request->get('shopifySession'); // set by shopify.auth middleware
        if (!$shopifySession) {
            abort(401, 'Missing Shopify session.');
        }

        $shopDomain = $shopifySession->getShop();
        $shop = Shop::where('domain', $shopDomain)->first();

        if (!$shop) {
            return redirect()->route('onboarding.link-shop-user', array_filter([
                'shop' => $request->query('shop'),
                'host' => $request->query('host'),
                'embedded' => $request->query('embedded'),
            ]));
        }

        $isAuthorized = auth()->user()
            ->shops()
            ->whereKey($shop->id)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$isAuthorized) {
            return redirect()->route('onboarding.link-shop-user', array_filter([
                'shop' => $request->query('shop'),
                'host' => $request->query('host'),
                'embedded' => $request->query('embedded'),
            ]));
        }

        $request->attributes->set('currentShop', $shop);

        return $next($request);
    }
}
