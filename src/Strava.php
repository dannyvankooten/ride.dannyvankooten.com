<?php

function loadTokens(): ?array {
    $file = tokensFile();
    if (!file_exists($file)) {
        return null;
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function saveTokens(array $tokens): void {
    $file = tokensFile();
    $dir  = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    file_put_contents($file, json_encode($tokens));
}

function exchangeCode(string $code): array {
    return stravaTokenRequest([
        'grant_type'    => 'authorization_code',
        'client_id'     => STRAVA_CLIENT_ID,
        'client_secret' => STRAVA_CLIENT_SECRET,
        'code'          => $code,
        'redirect_uri'  => STRAVA_REDIRECT_URI,
    ]);
}

function refreshTokens(array $tokens): array {
    $fresh = stravaTokenRequest([
        'grant_type'    => 'refresh_token',
        'client_id'     => STRAVA_CLIENT_ID,
        'client_secret' => STRAVA_CLIENT_SECRET,
        'refresh_token' => $tokens['refresh_token'],
    ]);
    $fresh['athlete'] = $tokens['athlete'] ?? null;
    saveTokens($fresh);
    return $fresh;
}

function stravaTokenRequest(array $params): array {
    $ch = curl_init('https://www.strava.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new RuntimeException("Strava token request failed (HTTP $status): $response");
    }
    return json_decode($response, true);
}

function getValidTokens(): ?array {
    $tokens = loadTokens();
    if ($tokens === null) {
        return null;
    }
    if (($tokens['expires_at'] ?? 0) < time()) {
        $tokens = refreshTokens($tokens);
    }
    return $tokens;
}

/**
 * Fetch rides from the last 7 days and group them by calendar day.
 * Returns one entry per day with combined time and averaged intensity metrics.
 */
function fetchRides(string $accessToken, int $days = 7): array {
    $after = strtotime("-{$days} days");
    $url   = 'https://www.strava.com/api/v3/athlete/activities?' . http_build_query([
        'after'    => $after,
        'per_page' => 50,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $accessToken"],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new RuntimeException("Strava activities request failed (HTTP $status): $response");
    }

    $activities = json_decode($response, true);

    // Filter to bike rides only
    $rides = array_filter($activities, fn($a) => in_array($a['type'] ?? '', ['Ride', 'VirtualRide'], true));

    // Group by calendar day (date portion of start_date_local)
    $byDay = [];
    foreach ($rides as $ride) {
        $date = substr($ride['start_date_local'], 0, 10);

        if (!isset($byDay[$date])) {
            $byDay[$date] = [
                'date'              => $date,
                'names'             => [],
                'moving_time'       => 0,
                'hr_readings'       => [],
                'watts_readings'    => [],
                'weighted_watts'    => [],
                'device_watts'      => false,
                'activity_ids'      => [],
                'zone_above_z2'     => 0,
                'zone_total'        => 0,
            ];
        }

        $byDay[$date]['names'][]             = $ride['name'];
        $byDay[$date]['activity_ids'][]      = (int) $ride['id'];
        $byDay[$date]['moving_time']  += (int) ($ride['moving_time'] ?? 0);

        if (!empty($ride['has_heartrate']) && isset($ride['average_heartrate'])) {
            $byDay[$date]['hr_readings'][] = (float) $ride['average_heartrate'];
        }

        if (isset($ride['average_watts'])) {
            $byDay[$date]['watts_readings'][] = (float) $ride['average_watts'];
        }
        if (isset($ride['weighted_average_watts'])) {
            $byDay[$date]['weighted_watts'][] = (float) $ride['weighted_average_watts'];
        }
        if (!empty($ride['device_watts'])) {
            $byDay[$date]['device_watts'] = true;
        }
    }

    // Fetch zone distributions for each activity and accumulate per-day totals
    foreach ($byDay as &$day) {
        foreach ($day['activity_ids'] as $id) {
            $zones = fetchActivityFracAboveZ2($accessToken, $id);
            if ($zones !== null) {
                $day['zone_above_z2'] += $zones['above_z2'];
                $day['zone_total']    += $zones['total'];
            }
        }
    }
    unset($day);

    // Collapse per-day raw readings into averages
    $result = [];
    foreach ($byDay as $day) {
        $result[] = [
            'date'              => $day['date'],
            'names'             => $day['names'],
            'moving_time'       => $day['moving_time'],
            'avg_heartrate'     => count($day['hr_readings'])     > 0 ? array_sum($day['hr_readings'])     / count($day['hr_readings'])     : null,
            'avg_watts'         => count($day['watts_readings'])  > 0 ? array_sum($day['watts_readings'])  / count($day['watts_readings'])  : null,
            'weighted_avg_watts'=> count($day['weighted_watts'])  > 0 ? array_sum($day['weighted_watts'])  / count($day['weighted_watts'])  : null,
            'device_watts'      => $day['device_watts'],
            'frac_above_z2'     => $day['zone_total'] > 0 ? $day['zone_above_z2'] / $day['zone_total'] : null,
        ];
    }

    // Sort ascending by date
    usort($result, fn($a, $b) => strcmp($a['date'], $b['date']));

    return $result;
}

/**
 * Count seconds above the Z2 threshold for a single activity using streams.
 * Prefers power (75% of FTP); falls back to heart rate (75% of MAX_HEARTRATE).
 * Returns ['above_z2' => int, 'total' => int] (seconds), or null if unavailable.
 */
function fetchActivityFracAboveZ2(string $accessToken, int $activityId): ?array {
    if (FTP !== null) {
        $data = fetchActivityStream($accessToken, $activityId, 'watts');
        if ($data !== null) {
            $threshold = 0.75 * FTP;
            $total     = count($data);
            $aboveZ2   = count(array_filter($data, fn($w) => $w > $threshold));
            return $total > 0 ? ['above_z2' => $aboveZ2, 'total' => $total] : null;
        }
    }

    if (MAX_HEARTRATE !== null) {
        $data = fetchActivityStream($accessToken, $activityId, 'heartrate');
        if ($data !== null) {
            $threshold = 0.75 * MAX_HEARTRATE;
            $total     = count($data);
            $aboveZ2   = count(array_filter($data, fn($hr) => $hr > $threshold));
            return $total > 0 ? ['above_z2' => $aboveZ2, 'total' => $total] : null;
        }
    }

    return null;
}

/**
 * Fetch a single stream for an activity. Returns the data array or null.
 */
function fetchActivityStream(string $accessToken, int $activityId, string $key): ?array {
    $url = "https://www.strava.com/api/v3/activities/{$activityId}/streams?"
         . http_build_query(['keys' => $key, 'key_by_type' => 'true']);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $accessToken"],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        return null;
    }

    $body = json_decode($response, true);
    return isset($body[$key]['data']) && is_array($body[$key]['data'])
        ? $body[$key]['data']
        : null;
}
