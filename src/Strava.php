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
 * Fetch rides from the last 7 days, classify each one, and group by calendar day.
 * Returns one entry per day: date, names, moving_time, has_hard, has_long.
 */
function fetchRides(string $accessToken, int $days, array $settings): array {
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

    // Classify each ride and group by calendar day
    $byDay = [];
    foreach ($rides as $ride) {
        $date = substr($ride['start_date_local'], 0, 10);

        if (!isset($byDay[$date])) {
            $byDay[$date] = [
                'date'         => $date,
                'ids'          => [],
                'names'        => [],
                'moving_time'  => 0,
                'has_hard'     => false,
                'has_long'     => false,
                'hr_readings'  => [],
                'avg_watts'    => [],
                'device_watts' => false,
            ];
        }

        $byDay[$date]['ids'][]        = (int) $ride['id'];
        $byDay[$date]['names'][]      = $ride['name'];
        $byDay[$date]['moving_time'] += (int) ($ride['moving_time'] ?? 0);

        if (!empty($ride['has_heartrate']) && isset($ride['average_heartrate'])) {
            $byDay[$date]['hr_readings'][] = (float) $ride['average_heartrate'];
        }
        if (isset($ride['average_watts'])) {
            $byDay[$date]['avg_watts'][] = (float) $ride['average_watts'];
        }
        if (!empty($ride['device_watts'])) {
            $byDay[$date]['device_watts'] = true;
        }

        $type = classifyRide($ride, $accessToken, $settings);
        if ($type['hard']) $byDay[$date]['has_hard'] = true;
        if ($type['long']) $byDay[$date]['has_long'] = true;
    }

    // Collapse per-day readings into averages
    $result = array_values(array_map(function ($day) {
        $day['avg_heartrate'] = count($day['hr_readings']) > 0
            ? array_sum($day['hr_readings']) / count($day['hr_readings']) : null;
        $day['avg_watts'] = count($day['avg_watts']) > 0
            ? array_sum($day['avg_watts']) / count($day['avg_watts']) : null;
        unset($day['hr_readings']);
        return $day;
    }, $byDay));
    usort($result, fn($a, $b) => strcmp($a['date'], $b['date']));

    return $result;
}

/**
 * Classify a single ride as hard and/or long.
 *
 * Hard detection tiers (first match wins):
 *   1. Per-second power stream: >50% of seconds above 75% FTP
 *   2. Weighted average power: weighted_average_watts / FTP > 0.75
 *   3. Per-second HR stream: >50% of seconds above 75% max HR
 *   4. Average HR: average_heartrate / MAX_HEARTRATE > 0.75
 *   5. Workout type tag: race (11) or workout (12)
 *
 * Long: moving_time >= 85 minutes.
 */
function classifyRide(array $ride, string $accessToken, array $settings): array {
    $ftp    = $settings['ftp'];
    $maxHr  = $settings['max_heartrate'];
    $numSlots = max(1, count($settings['available_days'] ?? [0, 1, 2, 3, 4]));
    $long     = ($ride['moving_time'] ?? 0) >= $settings['long_ride_factor']
        * ($settings['target_minutes'] / ($numSlots - 1 + $settings['long_ride_factor'])) * 60;
    $id     = (int) $ride['id'];

    // Tier 1: per-second power stream
    if ($ftp !== null) {
        $watts = fetchActivityStream($accessToken, $id, 'watts');
        if ($watts !== null && count($watts) > 0) {
            $hard = count(array_filter($watts, fn($w) => $w > 0.80 * $ftp)) > 1800
                 || count(array_filter($watts, fn($w) => $w > 0.90 * $ftp)) > 1200
                 || count(array_filter($watts, fn($w) => $w > 1.00 * $ftp)) >  600;
            return ['hard' => $hard, 'long' => $long];
        }
    }

    // Tier 2: weighted average power / FTP
    if ($ftp !== null && isset($ride['weighted_average_watts'])) {
        return ['hard' => ($ride['weighted_average_watts'] / $ftp) > 0.75, 'long' => $long];
    }

    // Tier 3: per-second HR stream
    if ($maxHr !== null) {
        $hr = fetchActivityStream($accessToken, $id, 'heartrate');
        if ($hr !== null && count($hr) > 0) {
            $hard = count(array_filter($hr, fn($h) => $h > 0.80 * $maxHr)) > 1200
                 || count(array_filter($hr, fn($h) => $h > 0.85 * $maxHr)) >  600
                 || count(array_filter($hr, fn($h) => $h > 0.90 * $maxHr)) >  300;
            return ['hard' => $hard, 'long' => $long];
        }
    }

    // Tier 4: average heartrate / max HR
    if ($maxHr !== null && !empty($ride['has_heartrate']) && isset($ride['average_heartrate'])) {
        return ['hard' => ($ride['average_heartrate'] / $maxHr) > 0.75, 'long' => $long];
    }

    // Tier 5: workout type tag (race=11, workout=12)
    $wt = $ride['workout_type'] ?? null;
    return ['hard' => ($wt === 11 || $wt === 12), 'long' => $long];
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
