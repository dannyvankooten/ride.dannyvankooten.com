<?php

/**
 * Analyse completed ride days and return a list of suggested workouts.
 *
 * Each suggestion is an array with keys:
 *   type        => 'hard' | 'long' | 'easy'
 *   duration    => int (minutes)
 *   description => string
 */
function suggest(array $dayRides): array {
    $ridesDone   = count($dayRides);
    $timeDoneMin = array_sum(array_column($dayRides, 'moving_time')) / 60;

    $hasLongRide = detectLongRide($dayRides);
    $hasHardRide = detectHardRide($dayRides);

    // Build the list of remaining slot types (priority: hard → long → easy)
    $remainingSlots = [];
    $remainingCount = max(0, TARGET_RIDES - $ridesDone);

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

    if (empty($remainingSlots)) {
        // All 4 days done — check the 150 min floor
        if ($timeDoneMin < MIN_WEEKLY_MINUTES) {
            $gap = (int) ceil(MIN_WEEKLY_MINUTES - $timeDoneMin);
            return [
                '_warning' => "You've completed all 4 ride days but only logged " . round($timeDoneMin) . " min. Add at least {$gap} more min to hit the weekly minimum.",
                'suggestions' => [[
                    'type'        => 'easy',
                    'duration'    => $gap,
                    'description' => 'Steady zone 2 effort to meet the weekly minimum.',
                ]],
            ];
        }
        return ['_complete' => true, 'suggestions' => []];
    }

    // Distribute remaining minutes across remaining slots
    $remainingMin = max(0.0, (float) TARGET_MINUTES - $timeDoneMin);

    // Ensure total for the week won't fall below MIN_WEEKLY_MINUTES
    $projectedTotal = $timeDoneMin + $remainingMin;
    if ($projectedTotal < MIN_WEEKLY_MINUTES) {
        $remainingMin = (float) MIN_WEEKLY_MINUTES - $timeDoneMin;
    }

    $durations = distributeDurations($remainingSlots, $remainingMin);

    $suggestions = [];
    foreach ($remainingSlots as $i => $type) {
        $suggestions[] = [
            'type'        => $type,
            'duration'    => $durations[$i],
            'description' => rideDescription($type),
        ];
    }

    return ['_complete' => false, 'suggestions' => $suggestions];
}

/**
 * Distribute $totalMinutes across $slots respecting the long/easy ratio.
 * Long ride is LONG_RIDE_FACTOR × an easy slot. Hard ride is the same as easy.
 * Returns array of int minutes, one per slot.
 */
function distributeDurations(array $slots, float $totalMinutes): array {
    // Each easy/hard slot = 1 unit; long slot = LONG_RIDE_FACTOR units
    $units = array_map(fn($s) => $s === 'long' ? LONG_RIDE_FACTOR : 1.0, $slots);
    $totalUnits = array_sum($units);

    if ($totalUnits <= 0) {
        return array_fill(0, count($slots), 0);
    }

    $minutesPerUnit = $totalMinutes / $totalUnits;
    return array_map(fn($u) => (int) round($u * $minutesPerUnit), $units);
}

function detectLongRide(array $dayRides): bool {
    foreach ($dayRides as $day) {
        if ($day['moving_time'] >= 85 * 60) {
            return true;
        }
    }
    return false;
}

function detectHardRide(array $dayRides): bool {
    if (count($dayRides) === 0) {
        return false;
    }

    // --- Zone-based detection (preferred): >50% of ride time above zone 2 ---
    $zoneDays = array_filter($dayRides, fn($d) => ($d['frac_above_z2'] ?? null) !== null);
    if (count($zoneDays) > 0) {
        foreach ($zoneDays as $day) {
            if ($day['frac_above_z2'] > 0.50) {
               
                return true;
            }
        }
        return false;
    }

    // --- Heart-rate fallback ---
    $hrDays = array_filter($dayRides, fn($d) => $d['avg_heartrate'] !== null);
    if (count($hrDays) < 1) {
        // No data available; cannot detect — assume not done
        return false;
    }

    $hrs  = array_column(array_values($hrDays), 'avg_heartrate');
    $mean = array_sum($hrs) / count($hrs);
    $maxHr = max($hrs);

    // Flag as hard if the highest-HR day is >= 10% above the mean
    return $maxHr >= $mean * 1.10;
}

function rideDescription(string $type): string {
    return match ($type) {
        'hard' => 'High intensity — choose one: 2×20 min at threshold (FTP), or 5×4 min VO2max intervals at 110–120% FTP with equal rest.',
        'long' => 'Long zone 2 ride — steady conversational pace throughout.',
        default => 'Zone 2 endurance — comfortable effort, able to hold a conversation.',
    };
}
