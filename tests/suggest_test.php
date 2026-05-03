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
function day(int $sec, bool $hasHard = false, bool $hasLong = false): array {
    return ['moving_time' => $sec, 'has_hard' => $hasHard, 'has_long' => $hasLong];
}

$s = [
    'target_rides'     => 4,
    'target_minutes'   => 300,
    'long_ride_factor' => 1.5,
    'ftp'              => null,
    'max_heartrate'    => null,
];

// ------------------------------------------------------------
// 1. Empty week → single long ride (100 min with defaults: 1.5 × 300 / 4.5)
// ------------------------------------------------------------
echo "empty week (long ride)\n";
$r = suggest([], $s);
check(!empty($r),                      'not complete');
check(count($r) === 1,                 'one suggestion');
check($r[0]['type'] === 'long',        'type is long');
check($r[0]['duration'] === 100,       'duration = long ride slot (100 min)');

// ------------------------------------------------------------
// 2. Below floor but rides done → normal structure (floor only fires on 0 rides)
// ------------------------------------------------------------
echo "\nbelow floor but rides done — normal structure\n";
$r = suggest([day(1800), day(1800)], $s); // 2 × 30 min = 60 min
check(!empty($r),                      'not complete');
check(count($r) === 2,                 'two suggestions (remaining slots, not floor override)');
check($r[0]['type'] === 'hard',        'first slot is hard');

// ------------------------------------------------------------
// 3. All 4 rides done, above target → complete
// ------------------------------------------------------------
echo "\nweek complete\n";
$r = suggest([day(4800), day(4800), day(4800), day(4800)], $s); // 4 × 80 min = 320 min
check(empty($r),                       'complete');

// ------------------------------------------------------------
// 3b. All 4 rides done but below target time → easy gap
//     4 × 50 min = 200 min, gap = 100 min
// ------------------------------------------------------------
echo "\nall rides done, time short\n";
$r = suggest([day(3000), day(3000), day(3000), day(3000)], $s);
check(count($r) === 1,                 'one suggestion');
check($r[0]['type'] === 'easy',        'type is easy');
check($r[0]['duration'] === 100,       'gap is 100 min');

// ------------------------------------------------------------
// 4. 1 ride done (160 min), no hard, no long → hard + long + easy
//    remainingMin = 140, units = [1, 1.5, 1] = 3.5 → perUnit = 40
//    hard = 40, long = 60, easy = 40
// ------------------------------------------------------------
echo "\n1 ride done — all three slots needed\n";
$r = suggest([day(9600)], $s); // 160 min
check(!empty($r),                      'not complete');
check(count($r) === 3,                 'three suggestions');
check($r[0]['type'] === 'hard',        'first slot is hard');
check($r[1]['type'] === 'long',        'second slot is long');
check($r[2]['type'] === 'easy',        'third slot is easy');
check($r[0]['duration'] === 40,        'hard duration');
check($r[1]['duration'] === 60,        'long duration (1.5×)');
check($r[2]['duration'] === 40,        'easy duration');

// ------------------------------------------------------------
// 5. Has hard, 2 rides (160 min) → long + easy
//    remainingMin = 140, units = [1.5, 1] = 2.5 → perUnit = 56
//    long = 84, easy = 56
// ------------------------------------------------------------
echo "\nhas hard — long + easy remaining\n";
$r = suggest([day(4800, true), day(4800)], $s); // 2 × 80 min, first is hard
check(count($r) === 2,                 'two suggestions');
check($r[0]['type'] === 'long',        'first slot is long');
check($r[1]['type'] === 'easy',        'second slot is easy');
check($r[0]['duration'] === 84,        'long duration');
check($r[1]['duration'] === 56,        'easy duration');

// ------------------------------------------------------------
// 6. Has hard + long, 2 rides (160 min) → 2 easy
//    remainingMin = 140, units = [1, 1] = 2 → perUnit = 70
// ------------------------------------------------------------
echo "\nhas hard + long — two easy remaining\n";
$r = suggest([day(4800, true, true), day(4800)], $s);
check(count($r) === 2,                 'two suggestions');
check($r[0]['type'] === 'easy',        'first is easy');
check($r[1]['type'] === 'easy',        'second is easy');
check($r[0]['duration'] === 70,        'first easy duration');
check($r[1]['duration'] === 70,        'second easy duration');

// ------------------------------------------------------------
// 7. Custom settings respected (target_rides = 3)
//    1 ride done (160 min), remaining = 2 → hard + long only
// ------------------------------------------------------------
echo "\ncustom settings (3 rides target)\n";
$r = suggest([day(9600)], array_merge($s, ['target_rides' => 3]));
check(count($r) === 2,                 'two suggestions');
check($r[0]['type'] === 'hard',        'hard');
check($r[1]['type'] === 'long',        'long');

// ------------------------------------------------------------
// Summary
// ------------------------------------------------------------
echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
