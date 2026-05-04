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

function ago(int $days): string { return date('Y-m-d', strtotime("-$days days")); }
function fwd(int $days): string { return date('Y-m-d', strtotime("+$days days")); }
function today(): string        { return date('Y-m-d'); }

// Base settings: all 7 days available, target = 300 min, factor = 1.5
$s = [
    'target_minutes'   => 300,
    'long_ride_factor' => 1.5,
    'available_days'   => [0, 1, 2, 3, 4, 5, 6],
    'ftp'              => null,
    'max_heartrate'    => null,
];

// ------------------------------------------------------------
// 1. No rides last 7 days → effective=600
//    Schedule: hard(0), long(1), easy(2-4), long(5), easy(6)
//    Units = 1 + 2*1.5 + 4 = 8; base = 600/8 = 75; long = 113
// ------------------------------------------------------------
echo "no rides last 7 days\n";
$r = schedule([], $s);
check(count($r) === 7,                  '7 days returned');
check($r[0]['date'] === today(),        'first day is today');
check($r[0]['status'] === 'scheduled',  'today is scheduled');
check($r[0]['type'] === 'hard',         'today gets hard');
check($r[0]['duration'] === 75,         'hard duration 75 min');
check($r[1]['type'] === 'long',         'day 1 gets long');
check($r[1]['duration'] === 113,        'long duration 113 min');
check($r[2]['type'] === 'easy',         'day 2 is easy');
check($r[5]['type'] === 'long',         'day 5 gets long (4 days after day 1)');

// ------------------------------------------------------------
// 2. Rode 150 min last week → effective=450
//    base = 450/8 = 56.25 → 56; long = round(84.375) = 84
// ------------------------------------------------------------
echo "\nrode 150/300 last week\n";
$r = schedule([day(9000, false, false, ago(3))], $s); // 150 min, 3 days ago
check($r[0]['type'] === 'hard',  'hard assigned');
check($r[0]['duration'] === 56,  'base 56 min');
check($r[1]['type'] === 'long',  'long assigned');
check($r[1]['duration'] === 84,  'long 84 min');

// ------------------------------------------------------------
// 3. Rode 360 min last week → deficit clamped to -60 → effective=240
//    base = 240/8 = 30; long = 45
// ------------------------------------------------------------
echo "\nrode 360/300 last week (credit capped at 20%)\n";
$r = schedule([day(21600, false, false, ago(3))], $s);
check($r[0]['duration'] === 30, 'base 30 min (credit capped)');
check($r[1]['duration'] === 45, 'long 45 min');

// ------------------------------------------------------------
// 4. Rode 500 min last week → same credit cap as #3
// ------------------------------------------------------------
echo "\nrode 500/300 last week (still capped at -60)\n";
$r = schedule([day(30000, false, false, ago(3))], $s);
check($r[0]['duration'] === 30, 'base still 30 min');
check($r[1]['duration'] === 45, 'long still 45 min');

// ------------------------------------------------------------
// 5. Hard done today → no further hards (last hard < 7 days);
//    long still scheduled (lastLong=null)
// ------------------------------------------------------------
echo "\nhard done today\n";
$r = schedule([day(4800, true, false, today())], $s);
check($r[0]['status'] === 'completed', 'today is completed');
$sched = array_column(array_filter($r, fn($d) => $d['status'] === 'scheduled'), 'type');
check(!in_array('hard', $sched), 'no hard scheduled (last hard < 7 days ago)');
check(in_array('long', $sched),  'long still scheduled');

// ------------------------------------------------------------
// 6. Long done today → hard scheduled tomorrow; long can repeat after 4 days
//    Schedule: completed-long, hard, easy, easy, long, easy, easy
// ------------------------------------------------------------
echo "\nlong done today\n";
$r = schedule([day(6000, false, true, today())], $s);
check($r[0]['status'] === 'completed', 'today is completed');
check($r[1]['type'] === 'hard',        'hard on tomorrow');
check($r[4]['type'] === 'long',        'long on day 4 (4 days after today)');

// ------------------------------------------------------------
// 7. Both hard and long done today → no hard for 7 days;
//    long can repeat at day 4
// ------------------------------------------------------------
echo "\nboth hard and long done today\n";
$r = schedule([day(6000, true, true, today())], $s);
check($r[0]['status'] === 'completed', 'today is completed');
$sched = array_column(array_filter($r, fn($d) => $d['status'] === 'scheduled'), 'type');
check(!in_array('hard', $sched), 'no hard scheduled (< 7 days)');
check($r[4]['type'] === 'long',  'long on day 4');

// ------------------------------------------------------------
// 7b. Hard+long yesterday → no hard until day 6 (7 days after yesterday);
//     long appears at day 3 (4 days after yesterday)
// ------------------------------------------------------------
echo "\nhard+long yesterday\n";
$r = schedule([day(4800, true, true, ago(1))], $s);
check($r[0]['type'] === 'easy',  'today is easy (hard+long yesterday)');
check($r[3]['type'] === 'long',  'long on day 3 (4 days after yesterday)');
check($r[6]['type'] === 'hard',  'hard on day 6 (7 days after yesterday)');

// ------------------------------------------------------------
// 7c. Hard+long 7 days ago → outside spacing windows
// ------------------------------------------------------------
echo "\nhard+long 7 days ago\n";
$r = schedule([day(4800, true, true, ago(7))], $s);
check($r[0]['type'] === 'hard',  'hard available today (7 days since last hard)');
$types = array_column($r, 'type');
check(in_array('long', $types),  'long still scheduled');

// ------------------------------------------------------------
// 8. Rode today (easy) → today excluded; hard tomorrow, long day 2
// ------------------------------------------------------------
echo "\nrode today (easy)\n";
$r = schedule([day(3600, false, false, today())], $s); // 60 min easy
check($r[0]['status'] === 'completed', 'today is completed');
check($r[0]['duration'] === 60,        'today duration 60 min');
check($r[1]['type'] === 'hard',        'hard on tomorrow');
check($r[2]['type'] === 'long',        'long on day 2');

// ------------------------------------------------------------
// 9. Only 1 available day → hard takes priority
// ------------------------------------------------------------
echo "\nonly 1 available day\n";
$sOne = array_merge($s, ['available_days' => [dayOfWeek(fwd(3))]]);
$r    = schedule([], $sOne);
$scheduled = array_filter($r, fn($d) => $d['status'] === 'scheduled');
check(count($scheduled) === 1,                        'exactly 1 scheduled slot');
check(array_values($scheduled)[0]['type'] === 'hard', 'single slot gets hard');

// ------------------------------------------------------------
// 10. No available days → all 7 days are rest
// ------------------------------------------------------------
echo "\nno available days\n";
$sNone = array_merge($s, ['available_days' => []]);
$r     = schedule([], $sNone);
$types = array_unique(array_column($r, 'type'));
check($types === ['rest'], 'all days are rest');

// ------------------------------------------------------------
// 11. Unavailable day in window → that day is rest
// ------------------------------------------------------------
echo "\nunavailable day is rest\n";
$sMon = array_merge($s, ['available_days' => [0]]);
$r    = schedule([], $sMon);
foreach ($r as $day) {
    if ($day['dow'] !== 0) {
        check($day['status'] === 'rest', 'non-Mon day is rest (dow=' . $day['dow'] . ')');
        break;
    }
}

// ------------------------------------------------------------
// 12. All 7 result entries have required keys and dates are today..+6
// ------------------------------------------------------------
echo "\nschedule covers today through +6 days\n";
$r    = schedule([], $s);
$keys = ['date', 'dow', 'status', 'type', 'duration', 'description'];
check($r[0]['date'] === today(), 'first entry is today');
check($r[6]['date'] === fwd(6),  'last entry is today+6');
$allKeys = array_reduce($r, fn($ok, $d) => $ok && !array_diff($keys, array_keys($d)), true);
check($allKeys, 'all entries have required keys');

// ------------------------------------------------------------
// 13. Today's hard ride shows as completed with type=hard
// ------------------------------------------------------------
echo "\ntoday's hard ride shows as completed\n";
$r = schedule([day(4800, true, false, today())], $s);
check($r[0]['status'] === 'completed', 'today status is completed');
check($r[0]['type']   === 'hard',      'today type is hard');
check($r[0]['duration'] === 80,        'today duration 80 min');

// ------------------------------------------------------------
// 14. daysBetween: date-only, DST-safe, ignores time component
// ------------------------------------------------------------
echo "\ndaysBetween\n";
check(daysBetween('2026-05-03', '2026-05-04') === 1, 'consecutive days');
check(daysBetween('2026-05-04', '2026-05-04') === 0, 'same day');
check(daysBetween('2026-05-04', '2026-05-11') === 7, '7-day gap');
check(daysBetween('2026-05-04', '2026-05-03') === -1, 'reverse direction');
// EU DST transitions: end 2026-10-25 (25h day), start 2026-03-29 (23h day)
check(daysBetween('2026-10-24', '2026-10-26') === 2, 'across DST end');
check(daysBetween('2026-03-28', '2026-03-30') === 2, 'across DST start');
// Defensive: any time component on the input is ignored
check(daysBetween('2026-05-03 21:00:00', '2026-05-04 06:00:00') === 1,
      'evening yesterday → morning today === 1');

// ------------------------------------------------------------
// Summary
// ------------------------------------------------------------
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
