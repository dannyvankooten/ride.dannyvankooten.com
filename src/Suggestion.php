<?php

/**
 * Build a 7-day workout schedule starting from today.
 *
 * Each returned element covers one calendar day and has keys:
 *   date        => 'Y-m-d'
 *   dow         => int  0=Monday … 6=Sunday
 *   status      => 'completed' | 'scheduled' | 'rest'
 *   type        => 'hard' | 'long' | 'easy' | 'rest'
 *   duration    => int (minutes) | null for rest
 *   description => string
 *
 * Algorithm:
 *   1. Compute clamped surplus/deficit from the past 7 days.
 *      effective_target = target_minutes + deficit, with deficit clamped at ±20%.
 *   2. Walk each available day in order. Spacing rules against the most recent
 *      hard/long ride (past or earlier in the window):
 *        - hard if last hard ≥ 7 days ago
 *        - else long if last long ≥ 4 days ago
 *        - else easy
 *   3. Distribute remaining minutes across scheduled days.
 *      Long rides count as long_ride_factor× the base duration.
 */
function schedule(array $pastRides, array $settings): array {
    $today         = date('Y-m-d');
    $factor        = $settings['long_ride_factor'];
    $availableDays = $settings['available_days'] ?? [0, 1, 2, 3, 4];

    $window = [];
    for ($i = 0; $i < 7; $i++) {
        $window[] = date('Y-m-d', strtotime("+$i days"));
    }

    $rideByDate = [];
    foreach ($pastRides as $r) {
        $rideByDate[$r['date']] = $r;
    }

    // Clamped surplus/deficit from the past 7 days
    $last7Start   = date('Y-m-d', strtotime('-7 days'));
    $minutesLast7 = 0.0;
    foreach ($pastRides as $r) {
        if ($r['date'] >= $last7Start && $r['date'] < $today) {
            $minutesLast7 += $r['moving_time'] / 60;
        }
    }
    $creditCap       = (int) round($settings['target_minutes'] * 0.20);
    $deficit         = max(-$creditCap, min($settings['target_minutes'], $settings['target_minutes'] - $minutesLast7));
    $effectiveTarget = $settings['target_minutes'] + $deficit;

    // Most recent hard/long from past rides drives spacing checks
    $lastHardDate = null;
    $lastLongDate = null;
    foreach ($pastRides as $r) {
        if ($r['date'] >= $today) continue;
        if (($r['has_hard'] ?? false) && ($lastHardDate === null || $r['date'] > $lastHardDate)) {
            $lastHardDate = $r['date'];
        }
        if (($r['has_long'] ?? false) && ($lastLongDate === null || $r['date'] > $lastLongDate)) {
            $lastLongDate = $r['date'];
        }
    }

    // First pass: pick a type for each available day in the window
    $types = [];
    foreach ($window as $date) {
        if (isset($rideByDate[$date])) {
            $ride = $rideByDate[$date];
            if ($ride['has_hard'] ?? false) $lastHardDate = $date;
            if ($ride['has_long'] ?? false) $lastLongDate = $date;
            continue;
        }
        if (!in_array(dayOfWeek($date), $availableDays, true)) {
            continue;
        }

        $canHard = $lastHardDate === null || daysBetween($lastHardDate, $date) >= 7;
        $canLong = $lastLongDate === null || daysBetween($lastLongDate, $date) >= 4;

        if ($canHard) {
            $types[$date] = 'hard';
            $lastHardDate = $date;
        } elseif ($canLong) {
            $types[$date] = 'long';
            $lastLongDate = $date;
        } else {
            $types[$date] = 'easy';
        }
    }

    // Second pass: distribute remaining minutes; long counts as factor× base
    $remaining = $effectiveTarget;
    foreach ($window as $date) {
        if (isset($rideByDate[$date])) {
            $remaining -= $rideByDate[$date]['moving_time'] / 60;
        }
    }
    $remaining = max(0, $remaining);

    $longs = $others = 0;
    foreach ($types as $type) {
        if ($type === 'long') $longs++;
        else $others++;
    }
    $totalUnits = $longs * $factor + $others;
    $base       = $totalUnits > 0 ? $remaining / $totalUnits : 0;

    $slotAssignments = [];
    foreach ($types as $date => $type) {
        $duration = ($type === 'long')
            ? (int) round($base * $factor)
            : (int) round($base);
        $slotAssignments[$date] = ['type' => $type, 'duration' => $duration];
    }

    // Build the 7-element result
    $result = [];
    foreach ($window as $date) {
        $dow          = dayOfWeek($date);
        $existingRide = $rideByDate[$date] ?? null;
        $isPast       = ($date < $today);

        if ($existingRide !== null && ($isPast || $date === $today)) {
            $type = ($existingRide['has_hard'] ?? false) ? 'hard'
                  : (($existingRide['has_long'] ?? false) ? 'long' : 'easy');
            $result[] = [
                'date'        => $date,
                'dow'         => $dow,
                'status'      => 'completed',
                'type'        => $type,
                'duration'    => (int) round($existingRide['moving_time'] / 60),
                'description' => rideDescription($type),
            ];
        } elseif ($isPast) {
            $result[] = [
                'date'        => $date,
                'dow'         => $dow,
                'status'      => 'rest',
                'type'        => 'rest',
                'duration'    => null,
                'description' => rideDescription('rest'),
            ];
        } elseif (isset($slotAssignments[$date])) {
            $a = $slotAssignments[$date];
            $result[] = [
                'date'        => $date,
                'dow'         => $dow,
                'status'      => 'scheduled',
                'type'        => $a['type'],
                'duration'    => $a['duration'],
                'description' => rideDescription($a['type']),
            ];
        } else {
            $result[] = [
                'date'        => $date,
                'dow'         => $dow,
                'status'      => 'rest',
                'type'        => 'rest',
                'duration'    => null,
                'description' => rideDescription('rest'),
            ];
        }
    }

    return $result;
}

function dayOfWeek(string $date): int {
    return (int) date('N', strtotime($date)) - 1; // 0=Mon … 6=Sun
}

function daysBetween(string $d1, string $d2): int {
    // Parse as date-only so any time component and DST shifts cannot affect
    // the result: a ride at 21:00 yesterday vs the dashboard at 06:00 today
    // must yield 1, not 0.
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', substr($d1, 0, 10));
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', substr($d2, 0, 10));
    $diff = $a->diff($b);
    return ($diff->invert ? -1 : 1) * (int) $diff->days;
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
