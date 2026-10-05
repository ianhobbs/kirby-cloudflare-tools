<?php

namespace IanHobbs\CloudflareTools\Api;

/**
 * Daily traffic for the zone from the GraphQL Analytics API.
 *
 * `httpRequests1dGroups` is available on every plan. Results are cached
 * (option `cacheTtl`) so a dashboard doesn't hit the API on every load.
 *
 * The output shape matches klub/cloudflare's Analytics::traffic(), so a site
 * using that plugin's members panel can read from this one instead.
 *
 * Token permission: Zone › Analytics › Read.
 */
final class Analytics extends Endpoint
{
    public const MAX_DAYS = 30;

    /**
     * @return array{zone: string, range: array, totals: array, series: array, status: array, cacheHitRatio: float, errorRate: float, topCountries: array}
     */
    public function traffic(int $days = 7): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $zone = $this->cloudflare->zoneId();

        return $this->cloudflare->cached('traffic.' . $days, function () use ($zone, $days) {
            $until = date('Y-m-d');
            $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

            $data   = $this->client()->graphql(self::trafficQuery($zone, $since, $until));
            $groups = $data['viewer']['zones'][0]['httpRequests1dGroups'] ?? [];

            return [
                'domain' => $this->cloudflare->domain(),
                'zone'   => $zone,
                'range'  => ['since' => $since, 'until' => $until, 'days' => $days],
                ...self::normaliseGroups($groups),
            ];
        });
    }

    /**
     * Folds httpRequests1dGroups rows into flat totals and a daily series.
     * Pure — no network, no Kirby — so it is tested against a fixture.
     *
     * @param array $groups the raw `httpRequests1dGroups` array
     */
    public static function normaliseGroups(array $groups): array
    {
        $series = [];
        $totals = [
            'requests' => 0, 'pageViews' => 0, 'bytes' => 0, 'threats' => 0, 'uniques' => 0,
            'cachedRequests' => 0, 'cachedBytes' => 0,
        ];
        $status    = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        $countries = [];

        foreach ($groups as $group) {
            $sum = $group['sum'] ?? [];

            $row = [
                'date'      => $group['dimensions']['date'] ?? null,
                'requests'  => (int)($sum['requests'] ?? 0),
                'pageViews' => (int)($sum['pageViews'] ?? 0),
                'bytes'     => (int)($sum['bytes'] ?? 0),
                'threats'   => (int)($sum['threats'] ?? 0),
                'uniques'   => (int)($group['uniq']['uniques'] ?? 0),
            ];

            foreach ($row as $key => $value) {
                if (isset($totals[$key])) {
                    $totals[$key] += $value;
                }
            }

            $totals['cachedRequests'] += (int)($sum['cachedRequests'] ?? 0);
            $totals['cachedBytes']    += (int)($sum['cachedBytes'] ?? 0);
            $series[] = $row;

            foreach ($sum['responseStatusMap'] ?? [] as $entry) {
                $code   = (int)($entry['edgeResponseStatus'] ?? 0);
                $bucket = match (true) {
                    $code >= 200 && $code < 300 => '2xx',
                    $code >= 300 && $code < 400 => '3xx',
                    $code >= 400 && $code < 500 => '4xx',
                    $code >= 500 && $code < 600 => '5xx',
                    default                     => 'other',
                };
                $status[$bucket] += (int)($entry['requests'] ?? 0);
            }

            foreach ($sum['countryMap'] ?? [] as $entry) {
                $name = (string)($entry['clientCountryName'] ?? '');

                if ($name === '') {
                    continue;
                }

                $countries[$name] ??= ['requests' => 0, 'bytes' => 0, 'threats' => 0];
                $countries[$name]['requests'] += (int)($entry['requests'] ?? 0);
                $countries[$name]['bytes']    += (int)($entry['bytes'] ?? 0);
                $countries[$name]['threats']  += (int)($entry['threats'] ?? 0);
            }
        }

        uasort($countries, fn ($a, $b) => $b['requests'] <=> $a['requests']);

        $topCountries = [];

        foreach (array_slice($countries, 0, 8, true) as $name => $country) {
            $topCountries[] = ['country' => $name] + $country;
        }

        $requests = $totals['requests'];

        return [
            'totals'        => $totals,
            'series'        => $series,
            'status'        => $status,
            'cacheHitRatio' => $requests > 0 ? round($totals['cachedRequests'] / $requests * 100, 1) : 0.0,
            'errorRate'     => $requests > 0 ? round(($status['4xx'] + $status['5xx']) / $requests * 100, 1) : 0.0,
            'topCountries'  => $topCountries,
        ];
    }

    /**
     * The GraphQL document, with the zone and dates inlined as literals:
     * Cloudflare's lowercase scalar names break typed variables. Both inputs
     * are validated here, so nothing else can reach the query.
     */
    public static function trafficQuery(string $zone, string $since, string $until): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $zone) !== 1) {
            throw new \InvalidArgumentException('Invalid zone ID');
        }

        foreach ([$since, $until] as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new \InvalidArgumentException('Invalid date "' . $date . '"');
            }
        }

        return sprintf(
            'query {
  viewer {
    zones(filter: { zoneTag: "%s" }) {
      httpRequests1dGroups(limit: 31, filter: { date_geq: "%s", date_leq: "%s" }, orderBy: [date_ASC]) {
        dimensions { date }
        sum {
          requests pageViews bytes threats cachedRequests cachedBytes
          responseStatusMap { edgeResponseStatus requests }
          countryMap { clientCountryName requests bytes threats }
        }
        uniq { uniques }
      }
    }
  }
}',
            $zone,
            $since,
            $until
        );
    }

    /** Human-readable byte count, e.g. 1536 → "1.5 KB" */
    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i     = 0;
        $n     = (float)$bytes;

        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return ($i === 0 ? (string)$bytes : number_format($n, 1)) . ' ' . $units[$i];
    }
}
