<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns an IP address into an approximate location (city / region / country)
 * and the name of the network it belongs to.
 *
 * How accurate is this, honestly?
 *   • Country is close to always right.
 *   • City is a decent guess on home broadband, and often WRONG on mobile data
 *     — a Jio or Airtel connection is usually placed at whichever city the
 *     carrier routes that block through, which can be hundreds of km away and
 *     identical for users nowhere near each other.
 * So treat it as "roughly where", never as proof of where somebody was. The
 * ISP name is the genuinely useful part: it explains a shared IP, because a
 * mobile carrier puts thousands of customers behind one address (CGNAT).
 *
 * Lookups are batched (one HTTP call for a whole page), cached, and the result
 * is written back onto the login_logs row, so any IP is only ever fetched once.
 * Every failure path is silent — the admin list must still render if the
 * lookup provider is down or the server has no outbound internet access.
 */
class IpLocationService
{
    /** Set for a few minutes after a failed call so we don't hammer a dead endpoint. */
    private const COOLDOWN_KEY = 'ip_location:cooldown';

    private const CACHE_DAYS = 30;

    /** ip-api.com accepts up to 100 addresses per batch call. */
    private const BATCH_LIMIT = 100;

    /**
     * @param  string[] $ips
     * @return array<string, array{city:?string, region:?string, country:?string, isp:?string}>
     */
    public function lookupMany(array $ips): array
    {
        $results = [];
        $toFetch = [];

        foreach (array_unique(array_filter($ips)) as $ip) {
            if (!$this->isPublicIp($ip)) {
                // Localhost / LAN — there is nothing to resolve, but mark it so
                // the row is never queued again.
                $results[$ip] = $this->blank('Local network');
                continue;
            }

            $cached = Cache::get($this->cacheKey($ip));
            if (is_array($cached)) {
                $results[$ip] = $cached;
                continue;
            }

            $toFetch[] = $ip;
        }

        if (empty($toFetch) || !config('services.ip_location.enabled', true)) {
            return $results;
        }

        if (Cache::has(self::COOLDOWN_KEY)) {
            return $results;
        }

        foreach (array_chunk($toFetch, self::BATCH_LIMIT) as $chunk) {
            $fetched = $this->fetchBatch($chunk);
            if ($fetched === null) {
                // Provider unreachable. Leave the rest unresolved; they'll be
                // picked up next time the admin opens the page.
                Cache::put(self::COOLDOWN_KEY, 1, now()->addMinutes(5));
                break;
            }

            foreach ($fetched as $ip => $row) {
                Cache::put($this->cacheKey($ip), $row, now()->addDays(self::CACHE_DAYS));
                $results[$ip] = $row;
            }
        }

        return $results;
    }

    /**
     * One HTTP call for up to 100 IPs.
     *
     * @param  string[] $ips
     * @return array<string, array>|null  null means the call itself failed
     */
    private function fetchBatch(array $ips): ?array
    {
        try {
            $endpoint = config(
                'services.ip_location.endpoint',
                'http://ip-api.com/batch'
            );

            $response = Http::timeout((int) config('services.ip_location.timeout', 5))
                ->acceptJson()
                ->post(
                    $endpoint . '?fields=status,country,regionName,city,isp,query',
                    array_values($ips)
                );

            if (!$response->successful()) {
                return null;
            }

            $body = $response->json();
            if (!is_array($body)) {
                return null;
            }

            $out = [];
            foreach ($body as $item) {
                if (!is_array($item) || empty($item['query'])) {
                    continue;
                }
                $ip = (string) $item['query'];

                if (($item['status'] ?? '') !== 'success') {
                    // A reserved or unroutable address the provider won't
                    // resolve. Cache the blank so it isn't retried forever.
                    $out[$ip] = $this->blank(null);
                    continue;
                }

                $out[$ip] = [
                    'city'    => $this->clean($item['city'] ?? null, 80),
                    'region'  => $this->clean($item['regionName'] ?? null, 80),
                    'country' => $this->clean($item['country'] ?? null, 80),
                    'isp'     => $this->clean($item['isp'] ?? null, 120),
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('IP location lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** Public, routable address? Private/LAN ranges can't be geolocated. */
    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function blank(?string $country): array
    {
        return ['city' => null, 'region' => null, 'country' => $country, 'isp' => null];
    }

    private function clean($value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : substr($value, 0, $max);
    }

    private function cacheKey(string $ip): string
    {
        return 'ip_location:' . md5($ip);
    }
}
