<?php

/**
 * Analyse completed ride days and return a list of suggested workouts.
 *
 * Each suggestion is an array with keys:
 *   type        => 'hard' | 'long' | 'easy'
 *   duration    => int (minutes)
 *   description => string
 */
function suggest(array $dayRides, array $settings): array {
    $ridesDone   = count($dayRides);
    $timeDoneMin = array_sum(array_column($dayRides, 'moving_time')) / 60;

    $hasLongRide = detectLongRide($dayRides);
    $hasHardRide = detectHardRide($dayRides);

    // Build the list of remaining slot types (priority: hard → long → easy)
    $remainingSlots = [];
    $remainingCount = max(0, $settings['target_rides'] - $ridesDone);

    if (!$hasHardRide && $remainingCount > 0) {
        $remainingSlots[] = 'hard';
        $remainingCount--;
    }
    if (!$hasLongRide && $remainingCount > 0) {
        $remainingSlots[] = 'long';
        $remainingCount--;
    }
    while ($remainingCount > 0) {
        $remainingSlots[] = 'easy';
        $remainingCount--;
    }

    // Exception: no rides at all in 7 days — skip structure, just get moving.
    if ($ridesDone === 0) {
        $gap = (int) ceil($settings['min_weekly_minutes'] - $timeDoneMin);
        return [[
            'type'        => 'easy',
            'duration'    => $gap,
            'description' => 'Steady zone 2 effort to meet the weekly minimum.',
        ]];
    }

    if (empty($remainingSlots)) {
        return [];
    }

    // Distribute remaining minutes across remaining slots
    $remainingMin = max(0.0, (float) $settings['target_minutes'] - $timeDoneMin);

    $durations = distributeDurations($remainingSlots, $remainingMin, $settings);

    $suggestions = [];
    foreach ($remainingSlots as $i => $type) {
        $suggestions[] = [
            'type'        => $type,
            'duration'    => $durations[$i],
            'description' => rideDescription($type),
        ];
    }

    return $suggestions;
}

function distributeDurations(array $slots, float $totalMinutes, array $settings): array {
    $units = array_map(fn($s) => $s === 'long' ? $settings['long_ride_factor'] : 1.0, $slots);
    $totalUnits = array_sum($units);

    if ($totalUnits <= 0) {
        return array_fill(0, count($slots), 0);
    }

    $minutesPerUnit = $totalMinutes / $totalUnits;
    return array_map(fn($u) => (int) round($u * $minutesPerUnit), $units);
}

function detectLongRide(array $dayRides): bool {
    foreach ($dayRides as $day) {
        if ($day['has_long'] ?? false) return true;
    }
    return false;
}

function detectHardRide(array $dayRides): bool {
    foreach ($dayRides as $day) {
        if ($day['has_hard'] ?? false) return true;
    }
    return false;
}

function rideDescription(string $type): string {
    return match ($type) {
        'hard' => 'High intensity — choose one: 2×20 min at threshold (FTP), or 5×4 min VO2max intervals at 110–120% FTP with equal rest.',
        'long' => 'Long zone 2 ride — steady conversational pace throughout.',
        default => 'Zone 2 endurance — comfortable effort, able to hold a conversation.',
    };
}
