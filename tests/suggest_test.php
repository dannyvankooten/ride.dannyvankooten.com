#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/Suggestion.php';

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------

$pass = $fail = 0;

function check(bool $ok, string $label): void {
    global $pass, $fail;
    if ($ok) { echo "  pass  $label\n"; $pass++; }
    else     { echo "  FAIL  $label\n"; $fail++; }
}

/** Build a minimal day-entry (moving_time in seconds). */
function day(int $sec, bool $hasHard = false, bool $hasLong = false, ?string $date = null): array {
    $d = ['moving_time' => $sec, 'has_hard' => $hasHard, 'has_long' => $hasLong];
    if ($date !== null) $d['date'] = $date;
    return $d;
}

// All 7 days available, target = 300, factor = 1.5
$s = [
    'target_minutes'   => 300,
    'long_ride_factor' => 1.5,
    'available_days'   => [0, 1, 2, 3, 4, 5, 6],
    'ftp'              => null,
    'max_heartrate'    => null,
];

// Pin "today" to a known Monday so tests are independent of the day they run.
$MON = '2026-01-05'; // Monday
$SUN = '2026-01-11'; // following Sunday (today + 6)

// ------------------------------------------------------------
// 1. No past rides → spacing produces hard/long/easy×4/long
//    Units = 1 + 4 + 2*1.5 = 8; base = 300/8 = 37.5; hard/easy = 38; long = 56
// ------------------------------------------------------------
echo "no rides, target distributed across week\n";
$r = schedule([], $s, $MON);
check(count($r) === 7,                  '7 days returned');
check($r[0]['date']     === $MON,       'first day is today');
check($r[6]['date']     === $SUN,       'last day is today+6');
check($r[0]['status']   === 'scheduled','today is scheduled');
check($r[0]['type']     === 'hard',     'today gets hard');
check($r[1]['type']     === 'long',     'tue gets long');
check($r[2]['type']     === 'easy',     'wed easy');
check($r[5]['type']     === 'easy',     'sat easy (only 4d since long)');
check($r[6]['type']     === 'long',     'sun long (5d since tue long)');
check($r[0]['duration'] === 38,         'hard 38 min (300/8 round)');
check($r[1]['duration'] === 56,         'long 56 min (38*1.5 round)');

// ------------------------------------------------------------
// 2. Hard done today → no further hards (min 7d); longs still scheduled
// ------------------------------------------------------------
echo "\nhard done today\n";
$r = schedule([day(4800, true, false, $MON)], $s, $MON);
check($r[0]['status'] === 'completed', 'today is completed');
$sched = array_column(array_filter($r, fn($d) => $d['status'] === 'scheduled'), 'type');
check(!in_array('hard', $sched), 'no hard scheduled (last hard < 7 days ago)');
check(in_array('long', $sched),  'long still scheduled');

// ------------------------------------------------------------
// 3. Long done today → hard tomorrow; long again at day 5 (Sat)
// ------------------------------------------------------------
echo "\nlong done today\n";
$r = schedule([day(6000, false, true, $MON)], $s, $MON);
check($r[0]['status'] === 'completed', 'today is completed');
check($r[1]['type']   === 'hard',      'tue gets hard (no past hard)');
check($r[5]['type']   === 'long',      'sat (day 5) gets long again');

// ------------------------------------------------------------
// 4. Hard+long done today → no hard for 7 days; long again at day 5
// ------------------------------------------------------------
echo "\nhard+long done today\n";
$r = schedule([day(6000, true, true, $MON)], $s, $MON);
check($r[0]['status'] === 'completed', 'today is completed');
$sched = array_column(array_filter($r, fn($d) => $d['status'] === 'scheduled'), 'type');
check(!in_array('hard', $sched), 'no hard scheduled (< 7 days)');
check($r[5]['type']   === 'long', 'long on day 5');

// ------------------------------------------------------------
// 5. Hard+long yesterday → no hard until day 6 (7d after); long at day 4 (5d after)
// ------------------------------------------------------------
echo "\nhard+long yesterday\n";
$r = schedule([day(4800, true, true, '2026-01-04')], $s, $MON);
check($r[0]['type'] === 'easy',  'today is easy (hard+long 1 day ago)');
check($r[4]['type'] === 'long',  'long on day 4 (5 days after sun)');
check($r[6]['type'] === 'hard',  'hard on day 6 (7 days after sun)');

// ------------------------------------------------------------
// 6. Hard+long 7 days ago → outside spacing windows
// ------------------------------------------------------------
echo "\nhard+long 7 days ago\n";
$r = schedule([day(4800, true, true, '2025-12-29')], $s, $MON);
check($r[0]['type'] === 'hard', 'hard available today (7d since last hard)');

// ------------------------------------------------------------
// 7. Rode today (easy) → today completed; hard tomorrow, long day 2
// ------------------------------------------------------------
echo "\nrode today (easy)\n";
$r = schedule([day(3600, false, false, $MON)], $s, $MON);
check($r[0]['status']   === 'completed', 'today is completed');
check($r[0]['duration'] === 60,          'today duration 60 min');
check($r[1]['type']     === 'hard',      'hard on tomorrow');
check($r[2]['type']     === 'long',      'long on day 2');

// ------------------------------------------------------------
// 8. Single available day → only 1 scheduled slot, gets hard
// ------------------------------------------------------------
echo "\nonly 1 available day (Wednesday)\n";
$sOne = array_merge($s, ['available_days' => [2]]);
$r    = schedule([], $sOne, $MON);
$scheduled = array_filter($r, fn($d) => $d['status'] === 'scheduled');
check(count($scheduled) === 1,                        'exactly 1 scheduled slot');
check(array_values($scheduled)[0]['type'] === 'hard', 'single slot gets hard');

// ------------------------------------------------------------
// 9. No available days → all rest
// ------------------------------------------------------------
echo "\nno available days\n";
$sNone = array_merge($s, ['available_days' => []]);
$r     = schedule([], $sNone, $MON);
$types = array_unique(array_column($r, 'type'));
check($types === ['rest'], 'all days are rest');

// ------------------------------------------------------------
// 10. Display always Mon–Sun: mid-week today still shows the full week
//     with past days as rest/completed and future days as scheduled.
// ------------------------------------------------------------
echo "\ndisplay window is Mon–Sun regardless of today\n";
$WED  = '2026-01-07'; // Wednesday
$rWed = schedule([], $s, $WED);
check(count($rWed) === 7,                       '7 days returned');
check($rWed[0]['date']   === $MON,              'first day is Monday of current week');
check($rWed[6]['date']   === $SUN,              'last day is Sunday of current week');
check($rWed[0]['status'] === 'rest',            'past Mon (no ride) shows as rest');
check($rWed[1]['status'] === 'rest',            'past Tue (no ride) shows as rest');
check($rWed[2]['status'] === 'scheduled',       'today (Wed) is scheduled');

// ------------------------------------------------------------
// 11. All entries have required keys
// ------------------------------------------------------------
echo "\nall entries have required keys\n";
$keys    = ['date', 'dow', 'status', 'type', 'duration', 'description'];
$r       = schedule([], $s, $MON);
$allKeys = array_reduce($r, fn($ok, $d) => $ok && !array_diff($keys, array_keys($d)), true);
check($allKeys, 'all entries have required keys');

// ------------------------------------------------------------
// 12. Today's hard ride shows as completed with correct duration
// ------------------------------------------------------------
echo "\ntoday's hard ride shows as completed\n";
$r = schedule([day(4800, true, false, $MON)], $s, $MON);
check($r[0]['status']   === 'completed', 'today status is completed');
check($r[0]['type']     === 'hard',      'today type is hard');
check($r[0]['duration'] === 80,          'today duration 80 min');

// ------------------------------------------------------------
// 13. Stability invariant: rest day → next day produces the same plan
//     for every shared future date when no new rides occurred.
// ------------------------------------------------------------
echo "\nregression: rest day → next day plan is stable for shared dates\n";
$sUser = [
    'target_minutes'   => 150,
    'long_ride_factor' => 1.5,
    'available_days'   => [1, 3, 5, 6], // Tu, Th, Sa, Su
    'ftp'              => null,
    'max_heartrate'    => null,
];
$past = [
    day(3600, false, false, '2026-04-29'), // Wed, 60 min easy
    day(3600, false, false, '2026-05-03'), // Sun, 60 min easy
];
$mondayView  = schedule($past, $sUser, '2026-05-04'); // Monday, rest day
$tuesdayView = schedule($past, $sUser, '2026-05-05'); // Tuesday, riding day
$byMon = array_column($mondayView,  null, 'date');
$byTue = array_column($tuesdayView, null, 'date');
foreach (['2026-05-05', '2026-05-07', '2026-05-09', '2026-05-10'] as $d) {
    check(
        $byMon[$d]['type']     === $byTue[$d]['type']
     && $byMon[$d]['duration'] === $byTue[$d]['duration'],
        "$d: same type+duration in mon and tue view"
    );
}

// ------------------------------------------------------------
// 14. Calendar-week deficit: ride done earlier in the week reduces
//     the remaining target distributed across remaining ride days.
// ------------------------------------------------------------
echo "\ncalendar-week deficit subtracts done minutes\n";
// Past: Tuesday this week, 60 min easy (no hard/long flag).
// View from Wed (rest day for this user).
$past = [day(3600, false, false, '2026-05-05')]; // Tue 60 min
$r    = schedule($past, $sUser, '2026-05-06');   // Wed
// Remaining riding days this week: Thu, Sat, Sun. With no past hard/long,
// plan is Thu=hard, Sat=long, Sun=easy.
// done = 60, remaining = 90, units = 1 + 1.5 + 1 = 3.5, base = 90/3.5 ≈ 25.71
// hard/easy = 26, long = round(25.71 * 1.5) = 39
$byDate = array_column($r, null, 'date');
check($byDate['2026-05-07']['type']     === 'hard', 'Thu hard (no past hard ride)');
check($byDate['2026-05-07']['duration'] === 26,     'Thu hard duration 26 (90/3.5)');
check($byDate['2026-05-09']['type']     === 'long', 'Sat long');
check($byDate['2026-05-09']['duration'] === 39,     'Sat long duration 39 (90/3.5*1.5)');
check($byDate['2026-05-10']['duration'] === 26,     'Sun easy duration 26 (90/3.5)');

// ------------------------------------------------------------
// 14b. Missed riding day redistributes minutes upward
// ------------------------------------------------------------
echo "\nmissed riding day → remaining days scale up\n";
// Tue 05-05 was a riding day. Compare:
//   miss: no ride happened → done=0, plan Thu/Sat/Sun absorbs full 150
//   with: 60 min ride happened → done=60, remaining=90 spread over 3 days
$miss   = schedule([], $sUser, '2026-05-06');                                   // Wed view, Tue missed
$with   = schedule([day(3600, false, false, '2026-05-05')], $sUser, '2026-05-06');
$missBy = array_column($miss, null, 'date');
$withBy = array_column($with, null, 'date');
// miss: 150/3.5 = 42.86 → hard=43, long=64, easy=43.  Sum = 150
// with: 90/3.5  = 25.71 → hard=26, long=39, easy=26.  Sum ≈ 91
check($missBy['2026-05-07']['duration'] === 43, 'missed: Thu hard 43 (150/3.5)');
check($missBy['2026-05-09']['duration'] === 64, 'missed: Sat long 64');
check($missBy['2026-05-10']['duration'] === 43, 'missed: Sun easy 43');
check($missBy['2026-05-07']['duration'] > $withBy['2026-05-07']['duration'],
      'missed Tue → Thu duration > with-Tue-ride scenario');
check(
    $missBy['2026-05-07']['duration']
  + $missBy['2026-05-09']['duration']
  + $missBy['2026-05-10']['duration'] === 150,
    'missed: remaining 3 days sum to weekly target'
);

// ------------------------------------------------------------
// 14c. Last week's surplus reduces this week's target (carry-over)
// ------------------------------------------------------------
echo "\nlast week's surplus rolls forward as a target reduction\n";
$sCarry = [
    'target_minutes'   => 210,
    'long_ride_factor' => 1.5,
    'available_days'   => [1, 3, 5, 6],
    'ftp'              => null,
    'max_heartrate'    => null,
];
// Last week Thu 04-30: 240 min easy → overshoot 30 → 50% carry = 15
// effective = 210 − 15 = 195; plan total ≈ 195 (within rounding)
$past = [day(14400, false, false, '2026-04-30')];
$r    = schedule($past, $sCarry, '2026-05-04'); // Mon 05-04
$total = array_sum(array_map(fn($d) => $d['duration'] ?? 0, $r));
check($total >= 193 && $total <= 196, "this week totals ≈195 (210 − half of 30 surplus, got $total)");

// Under-riding last week does NOT roll forward (no deficit accumulation)
$rUnder = schedule([day(3600, false, false, '2026-04-30')], $sCarry, '2026-05-04'); // 60 min
$rZero  = schedule([], $sCarry, '2026-05-04');                                       // no rides
$tUnder = array_sum(array_map(fn($d) => $d['duration'] ?? 0, $rUnder));
$tZero  = array_sum(array_map(fn($d) => $d['duration'] ?? 0, $rZero));
check($tUnder === $tZero, 'under-rode last week → same total as no past rides (no deficit carry)');

// Massive surplus is capped at 50% of target (so this week still has a meaningful plan)
$rHuge  = schedule([day(60000, false, false, '2026-04-30')], $sCarry, '2026-05-04'); // 1000 min last week
$tHuge  = array_sum(array_map(fn($d) => $d['duration'] ?? 0, $rHuge));
// Surplus uncapped = 790; capped to 105 (50% of 210). Effective = 105. Total ≈ 105.
check($tHuge >= 104 && $tHuge <= 106, "massive overshoot capped at 50% (total ≈ 105, got $tHuge)");

// ------------------------------------------------------------
// 15. daysBetween: date-only, DST-safe, ignores time component
// ------------------------------------------------------------
echo "\ndaysBetween\n";
check(daysBetween('2026-05-03', '2026-05-04') === 1,  'consecutive days');
check(daysBetween('2026-05-04', '2026-05-04') === 0,  'same day');
check(daysBetween('2026-05-04', '2026-05-11') === 7,  '7-day gap');
check(daysBetween('2026-05-04', '2026-05-03') === -1, 'reverse direction');
check(daysBetween('2026-10-24', '2026-10-26') === 2,  'across DST end');
check(daysBetween('2026-03-28', '2026-03-30') === 2,  'across DST start');
check(daysBetween('2026-05-03 21:00:00', '2026-05-04 06:00:00') === 1,
      'evening yesterday → morning today === 1');

// ------------------------------------------------------------
// 16. Calendar-week helpers
// ------------------------------------------------------------
echo "\ncalendar-week helpers\n";
check(startOfCalendarWeek('2026-05-05') === '2026-05-04', 'Tue → Mon (this week)');
check(startOfCalendarWeek('2026-05-04') === '2026-05-04', 'Mon → Mon (same)');
check(startOfCalendarWeek('2026-05-10') === '2026-05-04', 'Sun → Mon (start of week)');
check(endOfCalendarWeek  ('2026-05-05') === '2026-05-10', 'Tue → Sun');
check(endOfCalendarWeek  ('2026-05-10') === '2026-05-10', 'Sun → Sun');

// ------------------------------------------------------------
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
