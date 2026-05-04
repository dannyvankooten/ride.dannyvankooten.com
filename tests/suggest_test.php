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

$s = [
    'target_rides'     => 4,
    'target_minutes'   => 300,
    'long_ride_factor' => 1.5,
    'ftp'              => null,
    'max_heartrate'    => null,
];

// base = 300 / (4 - 1 + 1.5) = 300 / 4.5 = 66.67 → 67 min
// long = round(66.67 × 1.5) = round(100.0) = 100 min

// ------------------------------------------------------------
// 1. Empty week → long ride (100 min)
// ------------------------------------------------------------
echo "empty week\n";
$r = suggest([], $s);
check(count($r) === 1,           'one suggestion');
check($r[0]['type'] === 'long',  'type is long');
check($r[0]['duration'] === 100, 'duration 100 min');

// ------------------------------------------------------------
// 2. Hard not done (any rides, time remaining) → hard
// ------------------------------------------------------------
echo "\nhard not done\n";
$r = suggest([day(1800), day(1800)], $s); // 2 × 30 min, no flags
check(count($r) === 1,           'one suggestion');
check($r[0]['type'] === 'hard',  'type is hard');
check($r[0]['duration'] === 67,  'duration 67 min');

// ------------------------------------------------------------
// 3. Hard done, long not done → long
// ------------------------------------------------------------
echo "\nlong not done\n";
$r = suggest([day(4800, true), day(4800)], $s); // 2 × 80 min, first is hard
check(count($r) === 1,           'one suggestion');
check($r[0]['type'] === 'long',  'type is long');
check($r[0]['duration'] === 100, 'duration 100 min');

// ------------------------------------------------------------
// 4. Hard + long done, volume remaining → easy (min of base or remaining)
//    2 × 80 min = 160 min done, remaining = 140 min → easy 67 min
// ------------------------------------------------------------
echo "\neasy volume remaining\n";
$r = suggest([day(4800, true, true), day(4800)], $s); // 160 min, has hard + long
check(count($r) === 1,           'one suggestion');
check($r[0]['type'] === 'easy',  'type is easy');
check($r[0]['duration'] === 67,  'duration capped at base (67 min)');

// ------------------------------------------------------------
// 5. Volume target met → rest
// ------------------------------------------------------------
echo "\nvolume met — rest\n";
$r = suggest([day(4800), day(4800), day(4800), day(4800)], $s); // 4 × 80 min = 320 min
check(count($r) === 1,           'one suggestion');
check($r[0]['type'] === 'rest',  'type is rest');
check($r[0]['duration'] === null,'no duration for rest');

// ------------------------------------------------------------
// 6. Volume met, long ride only → rest (hard not forced when above target)
// ------------------------------------------------------------
echo "\nvolume met with single long ride — rest\n";
$r = suggest([day(19200, false, true)], $s); // 320 min long ride
check(count($r) === 1,           'one suggestion');
check($r[0]['type'] === 'rest',  'type is rest');

// ------------------------------------------------------------
// 7. Already rode enough today → no suggestion
//    base = 67 min; today's ride = 320 min → suppress
// ------------------------------------------------------------
echo "\nalready rode today\n";
$today = date('Y-m-d');
$r = suggest([day(19200, false, false, $today)], $s); // 320 min today
check(empty($r), 'no suggestion when already rode today');

// ------------------------------------------------------------
// 8. Rode today but below base → suggestion still shown
//    30 min < 67 min base → still suggest hard
// ------------------------------------------------------------
echo "\nshort ride today — still suggest\n";
$r = suggest([day(1800, false, false, $today)], $s); // 30 min today
check(count($r) === 1,          'one suggestion');
check($r[0]['type'] === 'hard', 'hard suggested despite short ride today');

// ------------------------------------------------------------
// 9. All rides done (has hard+long), gap remaining → easy gap
//    4 × 50 min = 200 min, remaining = 100 min → easy min(67, 100) = 67 min
// ------------------------------------------------------------
echo "\nall rides done, time short\n";
$r = suggest([day(3000, true, false), day(3000, false, true), day(3000), day(3000)], $s);
check(count($r) === 1,           'one suggestion');
check($r[0]['type'] === 'easy',  'type is easy');
check($r[0]['duration'] === 67,  'duration capped at base (67 min)');

// ------------------------------------------------------------
// 10. Small gap (below base) → easy with exact remaining duration
//     remaining = 30 min < 67 min base → easy 30 min
// ------------------------------------------------------------
echo "\nsmall gap below base\n";
$r = suggest([day(16200, true, true)], $s); // 270 min done, remaining = 30
check(count($r) === 1,           'one suggestion');
check($r[0]['type'] === 'easy',  'type is easy');
check($r[0]['duration'] === 30,  'duration = exact remaining (30 min)');

// ------------------------------------------------------------
// 11. Custom settings (target_rides = 3)
//     base = 300 / (3 - 1 + 1.5) = 300 / 3.5 = 85.71 → 86 min
// ------------------------------------------------------------
echo "\ncustom settings (3 rides target)\n";
$r = suggest([day(9600)], array_merge($s, ['target_rides' => 3])); // 160 min, no flags
check(count($r) === 1,           'one suggestion');
check($r[0]['type'] === 'hard',  'type is hard');
check($r[0]['duration'] === 86,  'duration 86 min (base for 3-ride plan)');

// ------------------------------------------------------------
// 12. Volume met, last ride 3+ days ago → easy (3-day rule)
// ------------------------------------------------------------
echo "\nvolume met, 3-day rule fires\n";
$ago3 = date('Y-m-d', strtotime('-3 days'));
$r = suggest([day(19200, false, true, $ago3)], $s); // 320 min, 3 days ago
check(count($r) === 1,          'one suggestion');
check($r[0]['type'] === 'easy', 'type is easy');
check($r[0]['duration'] === 67, 'base easy duration');

// ------------------------------------------------------------
// 13. Volume met, last ride 2 days ago → rest
// ------------------------------------------------------------
echo "\nvolume met, within rest window\n";
$ago2 = date('Y-m-d', strtotime('-2 days'));
$r = suggest([day(19200, false, true, $ago2)], $s); // 320 min, 2 days ago
check(count($r) === 1,          'one suggestion');
check($r[0]['type'] === 'rest', 'type is rest');

// ------------------------------------------------------------
// Summary
// ------------------------------------------------------------
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
