<?php

namespace App\Http\Middleware;

use App\Lib\AuthRedirection;
use App\Models\Session;
use Closure;
use Illuminate\Http\Request;
use Shopify\Utils;

class EnsureShopifyInstalled
{
    /**
     * Checks if the shop in the query arguments is currently installed.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $shopParam = $request->query('shop');
        $shop = is_string($shopParam) ? Utils::sanitizeShopDomain($shopParam) : '';

        $isExitingIframe = preg_match("/^ExitIframe/i", $request->path()) === 1;

        // No valid shop param: allow request to continue (don't force OAuth).
        if ($shop === '' && !$isExitingIframe) {
            return $next($request);
        }

        $appInstalled = $shop !== ''
            && Session::where('shop', $shop)->where('access_token', '<>', null)->exists();

        if ($appInstalled || $isExitingIframe) {
            return $next($request);
        }

        // Start OAuth only when we have a valid sanitized shop domain.
        return AuthRedirection::redirect($request);
    }
}
