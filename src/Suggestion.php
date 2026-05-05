<?php

/**
 * Build the current calendar week's workout schedule (Monday-Sunday).
 *
 * Rules (in order):
 *   1) no hard ride in last 7 days -> hard
 *   2) else no long ride in last 5 days -> long
 *   3) else easy
 *
 * Scheduled ride minutes are distributed to match the remaining weekly target,
 * where long rides count as long_ride_factor units.
 */
function schedule(array $pastRides, array $settings, ?string $today = null): array {
    $today         = $today ?? date('Y-m-d');
    $factor        = (float) ($settings['long_ride_factor'] ?? 1.5);
    $availableDays = array_values(array_unique($settings['available_days'] ?? [0, 1, 2, 3, 4]));
    sort($availableDays);

    $weekStart = startOfCalendarWeek($today);
    $weekEnd   = endOfCalendarWeek($today);

    $rideByDate      = ridesByDate($pastRides);
    $availableDaySet = array_flip($availableDays);
    $plannedTypes    = planRideTypes($pastRides, $rideByDate, $availableDaySet, $today, $weekEnd);

    $effectiveTarget = effectiveTarget($pastRides, $settings, $today);
    $doneThisWeek    = minutesDoneInRange($pastRides, $weekStart, $today);
    $remaining       = max(0, $effectiveTarget - $doneThisWeek);
    $durations       = allocateRideDurations($plannedTypes, $remaining, $factor);

    return buildScheduleRows($rideByDate, $plannedTypes, $durations, $weekStart, $weekEnd, $today);
}

function ridesByDate(array $rides): array {
    $byDate = [];
    foreach ($rides as $ride) {
        if (!isset($ride['date'])) continue;
        $byDate[$ride['date']] = $ride;
    }
    return $byDate;
}

function latestRideDateWithFlag(array $rides, string $flag, string $beforeDate): ?string {
    $latest = null;
    foreach ($rides as $ride) {
        if (($ride[$flag] ?? false) === false) continue;
        $date = $ride['date'] ?? null;
        if ($date === null || $date >= $beforeDate) continue;
        if ($latest === null || $date > $latest) $latest = $date;
    }
    return $latest;
}

function planRideTypes(array $pastRides, array $rideByDate, array $availableDaySet, string $today, string $weekEnd): array {
    $plannedTypes = [];
    $lastHardDate = latestRideDateWithFlag($pastRides, 'has_hard', $today);
    $lastLongDate = latestRideDateWithFlag($pastRides, 'has_long', $today);

    for ($date = $today; $date <= $weekEnd; $date = date('Y-m-d', strtotime("$date +1 day"))) {
        if (isset($rideByDate[$date])) {
            $ride = $rideByDate[$date];
            if ($ride['has_hard'] ?? false) $lastHardDate = $date;
            if ($ride['has_long'] ?? false) $lastLongDate = $date;
            continue;
        }

        if (!isset($availableDaySet[dayOfWeek($date)])) continue;

        $type = pickRideType($lastHardDate, $lastLongDate, $date);
        $plannedTypes[$date] = $type;

        if ($type === 'hard') $lastHardDate = $date;
        if ($type === 'long') $lastLongDate = $date;
    }

    return $plannedTypes;
}

function pickRideType(?string $lastHardDate, ?string $lastLongDate, string $date): string {
    if ($lastHardDate === null || daysBetween($lastHardDate, $date) >= 7) return 'hard';
    if ($lastLongDate === null || daysBetween($lastLongDate, $date) >= 5) return 'long';
    return 'easy';
}

function minutesDoneInRange(array $rides, string $startDate, string $endDate): float {
    $minutes = 0.0;
    foreach ($rides as $ride) {
        $date = $ride['date'] ?? null;
        if ($date === null || $date < $startDate || $date > $endDate) continue;
        $minutes += $ride['moving_time'] / 60;
    }
    return $minutes;
}

function allocateRideDurations(array $plannedTypes, float $remainingMinutes, float $longFactor): array {
    $dates = array_keys($plannedTypes);
    if ($dates === []) return [];

    $targetMinutes = max(0, (int) round($remainingMinutes));
    if ($targetMinutes === 0) return array_fill_keys($dates, 0);

    $weights = [];
    $totalWeight = 0.0;
    foreach ($plannedTypes as $date => $type) {
        $weight = ($type === 'long') ? $longFactor : 1.0;
        $weights[$date] = $weight;
        $totalWeight += $weight;
    }

    if ($totalWeight <= 0) return array_fill_keys($dates, 0);

    $durations = [];
    $fractions = [];
    $assigned  = 0;
    foreach ($weights as $date => $weight) {
        $rawMinutes = ($targetMinutes * $weight) / $totalWeight;
        $whole = (int) floor($rawMinutes);
        $durations[$date] = $whole;
        $fractions[$date] = $rawMinutes - $whole;
        $assigned += $whole;
    }

    $left = $targetMinutes - $assigned;
    if ($left <= 0) return $durations;

    uksort($fractions, function (string $a, string $b) use ($fractions): int {
        if ($fractions[$a] === $fractions[$b]) return strcmp($a, $b);
        return $fractions[$a] > $fractions[$b] ? -1 : 1;
    });

    $fractionDates = array_keys($fractions);
    $count = count($fractionDates);
    for ($i = 0; $i < $left; $i++) {
        $durations[$fractionDates[$i % $count]]++;
    }

    return $durations;
}

function rideTypeFromRide(array $ride): string {
    if ($ride['has_hard'] ?? false) return 'hard';
    if ($ride['has_long'] ?? false) return 'long';
    return 'easy';
}

function buildScheduleRows(
    array $rideByDate,
    array $plannedTypes,
    array $durations,
    string $weekStart,
    string $weekEnd,
    string $today
): array {
    $rows = [];
    for ($date = $weekStart; $date <= $weekEnd; $date = date('Y-m-d', strtotime("$date +1 day"))) {
        $dow  = dayOfWeek($date);
        $ride = $rideByDate[$date] ?? null;

        if ($ride !== null && $date <= $today) {
            $type = rideTypeFromRide($ride);
            $rows[] = [
                'date'        => $date,
                'dow'         => $dow,
                'status'      => 'completed',
                'type'        => $type,
                'duration'    => (int) round($ride['moving_time'] / 60),
                'description' => rideDescription($type),
                'ids'         => $ride['ids'] ?? [],
            ];
            continue;
        }

        if (isset($plannedTypes[$date])) {
            $type = $plannedTypes[$date];
            $rows[] = [
                'date'        => $date,
                'dow'         => $dow,
                'status'      => 'scheduled',
                'type'        => $type,
                'duration'    => $durations[$date] ?? 0,
                'description' => rideDescription($type),
            ];
            continue;
        }

        $rows[] = [
            'date'        => $date,
            'dow'         => $dow,
            'status'      => 'rest',
            'type'        => 'rest',
            'duration'    => null,
            'description' => rideDescription('rest'),
        ];
    }

    return $rows;
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
