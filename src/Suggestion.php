<?php

/**
 * Build the current calendar week's workout schedule (Monday–Sunday).
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
 *   1. Walk each available day from today through Sunday. Spacing rules
 *      against the most recent hard/long ride (past or planned earlier in
 *      the walk):
 *        - hard if last hard ≥ 7 days ago
 *        - else long if last long ≥ 5 days ago
 *        - else easy
 *   2. Carry over any *surplus* from last calendar week (Mon–Sun): if last
 *      week's total exceeded target_minutes, this week's effective target is
 *      reduced by the overshoot. Under-riding last week does not roll forward.
 *   3. Distribute (effective_target − minutes done so far this calendar week)
 *      across the remaining planned days. Long rides count as
 *      long_ride_factor× the base duration. Missed riding days fall out of
 *      the divisor automatically, so the remaining days scale up to meet
 *      the weekly target.
 */
function schedule(array $pastRides, array $settings, ?string $today = null): array {
    $today         = $today ?? date('Y-m-d');
    $factor        = $settings['long_ride_factor'];
    $availableDays = $settings['available_days'] ?? [0, 1, 2, 3, 4];

    $rideByDate = [];
    foreach ($pastRides as $r) {
        $rideByDate[$r['date']] = $r;
    }

    $weekStart = startOfCalendarWeek($today);
    $weekEnd   = endOfCalendarWeek($today);

    // Seed spacing tracker from past rides only (anything before today)
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

    // Pass 1: walk today → Sunday, assign type per riding day, updating the
    // spacing tracker so planned rides influence later decisions.
    $plannedTypes = [];
    for ($d = $today; $d <= $weekEnd; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        if (isset($rideByDate[$d])) {
            $r = $rideByDate[$d];
            if ($r['has_hard'] ?? false) $lastHardDate = $d;
            if ($r['has_long'] ?? false) $lastLongDate = $d;
            continue;
        }
        if (!in_array(dayOfWeek($d), $availableDays, true)) continue;

        $canHard = $lastHardDate === null || daysBetween($lastHardDate, $d) >= 7;
        $canLong = $lastLongDate === null || daysBetween($lastLongDate, $d) >= 5;
        if ($canHard) {
            $plannedTypes[$d] = 'hard';
            $lastHardDate = $d;
        } elseif ($canLong) {
            $plannedTypes[$d] = 'long';
            $lastLongDate = $d;
        } else {
            $plannedTypes[$d] = 'easy';
        }
    }

    // Pass 2: distribute (effective_target − done_this_week) across this
    // week's planned days. Missed past riding days have already fallen out
    // of the planning walk, so the remaining days absorb their share
    // automatically.
    $effectiveTarget = effectiveTarget($pastRides, $settings, $today);

    $done = 0.0;
    foreach ($pastRides as $r) {
        if ($r['date'] >= $weekStart && $r['date'] <= $today) {
            $done += $r['moving_time'] / 60;
        }
    }
    $remaining = max(0, $effectiveTarget - $done);
    $units     = 0;
    foreach ($plannedTypes as $t) {
        $units += ($t === 'long') ? $factor : 1;
    }
    $base = $units > 0 ? $remaining / $units : 0;

    $durations = [];
    foreach ($plannedTypes as $d => $t) {
        $durations[$d] = (int) round(($t === 'long') ? $base * $factor : $base);
    }

    // Build the display window: Monday through Sunday of the current week.
    $result = [];
    for ($d = $weekStart; $d <= $weekEnd; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $dow  = dayOfWeek($d);
        $ride = $rideByDate[$d] ?? null;
        $past = ($d < $today);

        if ($ride !== null && ($past || $d === $today)) {
            $type = ($ride['has_hard'] ?? false) ? 'hard'
                  : (($ride['has_long'] ?? false) ? 'long' : 'easy');
            $result[] = [
                'date'        => $d,
                'dow'         => $dow,
                'status'      => 'completed',
                'type'        => $type,
                'duration'    => (int) round($ride['moving_time'] / 60),
                'description' => rideDescription($type),
                'ids'         => $ride['ids'] ?? [],
            ];
        } elseif (isset($plannedTypes[$d])) {
            $t = $plannedTypes[$d];
            $result[] = [
                'date'        => $d,
                'dow'         => $dow,
                'status'      => 'scheduled',
                'type'        => $t,
                'duration'    => $durations[$d],
                'description' => rideDescription($t),
            ];
        } else {
            $result[] = [
                'date'        => $d,
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

/**
 * This week's target after subtracting last calendar week's surplus.
 *
 * If last week's total exceeded target_minutes, half of the overshoot rolls
 * into this week as a target reduction. The carry is capped at 50% of target,
 * so even a very heavy week still leaves a meaningful plan. Under-riding does
 * not roll forward.
 */
function effectiveTarget(array $pastRides, array $settings, ?string $today = null): int {
    $today  = $today ?? date('Y-m-d');
    $target = $settings['target_minutes'];

    $weekStart     = startOfCalendarWeek($today);
    $lastWeekStart = date('Y-m-d', strtotime("$weekStart -7 days"));
    $lastWeekEnd   = date('Y-m-d', strtotime("$weekStart -1 day"));

    $lastWeekDone = 0.0;
    foreach ($pastRides as $r) {
        if ($r['date'] >= $lastWeekStart && $r['date'] <= $lastWeekEnd) {
            $lastWeekDone += $r['moving_time'] / 60;
        }
    }
    $surplus = min(max(0, 0.50 * ($lastWeekDone - $target)), $target * 0.5);
    return (int) round($target - $surplus);
}

function dayOfWeek(string $date): int {
    return (int) date('N', strtotime($date)) - 1; // 0=Mon … 6=Sun
}

function startOfCalendarWeek(string $date): string {
    $dow = dayOfWeek($date);
    return date('Y-m-d', strtotime("$date -$dow days"));
}

function endOfCalendarWeek(string $date): string {
    return date('Y-m-d', strtotime(startOfCalendarWeek($date) . ' +6 days'));
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
