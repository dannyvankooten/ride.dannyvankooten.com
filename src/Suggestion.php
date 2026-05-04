<?php

/**
 * Return the single suggested workout for today, or [] if no suggestion is needed.
 *
 * The returned array (when non-empty) has keys:
 *   type        => 'hard' | 'long' | 'easy' | 'rest'
 *   duration    => int (minutes) | null for rest
 *   description => string
 *
 * Priority:
 *   1. Already rode enough today          → []
 *   2. Weekly volume target met           → rest/strength
 *   3. Fresh week (no rides yet)          → long ride
 *   4. Hard ride not done this week       → hard ride
 *   5. Long ride not done this week       → long ride
 *   6. Volume remaining                   → easy ride
 */
function suggest(array $dayRides, array $settings): array {
    $ridesDone   = count($dayRides);
    $timeDoneMin = array_sum(array_column($dayRides, 'moving_time')) / 60;
    $hasLongRide = detectLongRide($dayRides);
    $hasHardRide = detectHardRide($dayRides);

    $remainingMin = max(0.0, (float) $settings['target_minutes'] - $timeDoneMin);
    $base         = $settings['target_minutes'] / ($settings['target_rides'] - 1 + $settings['long_ride_factor']);

    if (todayRideMinutes($dayRides) >= $base) {
        return [];
    }

    if ($remainingMin <= 0) {
        if (daysSinceLastRide($dayRides) >= 3) {
            return [['type' => 'easy', 'duration' => (int) round($base), 'description' => rideDescription('easy')]];
        }
        return [['type' => 'rest', 'duration' => null, 'description' => rideDescription('rest')]];
    }

    if ($ridesDone === 0) {
        return [['type' => 'long', 'duration' => (int) round($base * $settings['long_ride_factor']), 'description' => rideDescription('long')]];
    }

    if (!$hasHardRide) {
        return [['type' => 'hard', 'duration' => (int) round($base), 'description' => rideDescription('hard')]];
    }

    if (!$hasLongRide) {
        return [['type' => 'long', 'duration' => (int) round($base * $settings['long_ride_factor']), 'description' => rideDescription('long')]];
    }

    return [['type' => 'easy', 'duration' => (int) round(min($base, $remainingMin)), 'description' => rideDescription('easy')]];
}

function daysSinceLastRide(array $dayRides): int {
    $dates = array_filter(array_column($dayRides, 'date'));
    if (empty($dates)) return 0;
    return (int) floor((strtotime(date('Y-m-d')) - strtotime(max($dates))) / 86400);
}

function todayRideMinutes(array $dayRides): float {
    $today = date('Y-m-d');
    foreach ($dayRides as $day) {
        if (($day['date'] ?? '') === $today) {
            return $day['moving_time'] / 60;
        }
    }
    return 0.0;
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
        'rest' => 'Take a rest day or do a strength and mobility session.',
        default => 'Zone 2 endurance — comfortable effort, able to hold a conversation.',
    };
}
