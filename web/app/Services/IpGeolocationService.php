<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IpGeolocationService
{
    private const CACHE_TTL_SECONDS = 600; // 10 minutes

    /**
     * Get location (country, city, etc.) for an IP. Returns null on failure or for local IPs.
     */
    public function getLocation(string $ip): ?array
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }

        if ($this->isLocalOrPrivateIp($ip)) {
            return null;
        }

        $cacheKey = 'geo:' . $ip;
        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($ip) {
            return $this->fetchFromApi($ip);
        });
    }

    /**
     * Add country, city, region to an existing properties array when geo is available.
     * Use for activity log properties to avoid repetition.
     */
    public static function addGeoToProperties(array $properties, ?string $ip = null): array
    {
        $ip = $ip ?? request()->ip();
        $geo = app(self::class)->getLocation($ip);
        if ($geo !== null) {
            $properties['country'] = $geo['country'];
            $properties['city'] = $geo['city'];
            $properties['region'] = $geo['region'];
        }
        return $properties;
    }

    /**
     * Call ip-api.com and return location array or null.
     */
    private function fetchFromApi(string $ip): ?array
    {
        try {
            $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,regionName,city';
            $response = Http::timeout(2)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            if (($data['status'] ?? '') !== 'success' || empty($data['country'] ?? null)) {
                return null;
            }

            return [
                'country' => $data['country'],
                'country_code' => $data['countryCode'] ?? '',
                'region' => $data['regionName'] ?? '',
                'city' => $data['city'] ?? '',
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isLocalOrPrivateIp(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
