<?php

declare(strict_types=1);

namespace App\Lib;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Shopify\Auth\OAuth;
use Shopify\Context;
use Shopify\Utils;

class AuthRedirection
{
    public static function redirect(Request $request, bool $isOnline = false): RedirectResponse
    {
        $shopParam = $request->query('shop');
        $shop = is_string($shopParam) ? Utils::sanitizeShopDomain($shopParam) : '';

        if (!$shop) {
            abort(400, 'Missing required "shop" query parameter.');
        }

        if (Context::$IS_EMBEDDED_APP && $request->query("embedded", false) === "1") {
            $redirectUrl = self::clientSideRedirectUrl($shop, $request->query());
        } else {
            $redirectUrl = self::serverSideRedirectUrl($shop, $isOnline);
        }

        return redirect($redirectUrl);
    }

    private static function serverSideRedirectUrl(string $shop, bool $isOnline): string
    {
        return OAuth::begin(
            $shop,
            '/api/auth/callback',
            $isOnline,
            ['App\Lib\CookieHandler', 'saveShopifyCookie'],
        );
    }

    private static function clientSideRedirectUrl($shop, array $query): string
    {
        $appHost = Context::$HOST_NAME;
        $redirectParams = array_filter([
            'shop' => $shop,
            'host' => $query['host'] ?? null,
            'embedded' => $query['embedded'] ?? '1',
        ]);

        $redirectUri = "https://$appHost/api/auth?" . http_build_query($redirectParams);

        $queryString = http_build_query(array_merge($query, ["redirectUri" => $redirectUri]));
        return "/ExitIframe?$queryString";
    }
}
