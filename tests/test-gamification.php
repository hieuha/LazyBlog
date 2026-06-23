<?php

declare(strict_types=1);

/**
 * Fixture-driven smoke test for GamificationCalculator::longestStreakForUnit().
 * Run: php tests/test-gamification.php
 *
 * Tests cover day/week/month/year units. The "current streak" + at-risk
 * logic was retired with the on-page streak panel — only longest-run
 * math matters for the badge family now.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\GamificationCalculator;

$tz = new DateTimeZone('Asia/Saigon');

/** @return list<array{date:string}> */
function posts(array $dates): array
{
    return array_map(static fn (string $d): array => ['date' => $d], $dates);
}

$cases = [
    // ───── weekly ─────
    ['name' => '[week] empty',       'unit' => 'week',  'now' => '2026-06-23 12:00:00', 'posts' => [], 'expect' => 0],
    ['name' => '[week] 3 in a row',  'unit' => 'week',  'now' => '2026-06-23 12:00:00',
        'posts' => posts(['2026-06-08','2026-06-15','2026-06-22']), 'expect' => 3],
    ['name' => '[week] gap break',   'unit' => 'week',  'now' => '2026-06-23 12:00:00',
        'posts' => posts(['2026-03-30','2026-04-06','2026-04-13','2026-04-20','2026-06-15','2026-06-22']), 'expect' => 4],
    ['name' => '[week] year W52→W1', 'unit' => 'week',  'now' => '2026-01-10 12:00:00',
        'posts' => posts(['2025-12-22','2025-12-29','2026-01-05']), 'expect' => 3],

    // ───── daily ─────
    ['name' => '[day] empty',          'unit' => 'day',   'now' => '2026-06-23 12:00:00', 'posts' => [], 'expect' => 0],
    ['name' => '[day] 5 in a row',     'unit' => 'day',   'now' => '2026-06-23 12:00:00',
        'posts' => posts(['2026-06-19','2026-06-20','2026-06-21','2026-06-22','2026-06-23']), 'expect' => 5],
    ['name' => '[day] gap break',      'unit' => 'day',   'now' => '2026-06-23 12:00:00',
        'posts' => posts(['2026-06-20','2026-06-21','2026-06-23']), 'expect' => 2],
    ['name' => '[day] month boundary', 'unit' => 'day',   'now' => '2026-04-02 12:00:00',
        'posts' => posts(['2026-03-30','2026-03-31','2026-04-01','2026-04-02']), 'expect' => 4],

    // ───── monthly ─────
    ['name' => '[month] empty',          'unit' => 'month', 'now' => '2026-06-15 12:00:00', 'posts' => [], 'expect' => 0],
    ['name' => '[month] 4 in a row',     'unit' => 'month', 'now' => '2026-06-15 12:00:00',
        'posts' => posts(['2026-03-10','2026-04-15','2026-05-20','2026-06-05']), 'expect' => 4],
    ['name' => '[month] year boundary',  'unit' => 'month', 'now' => '2026-02-15 12:00:00',
        'posts' => posts(['2025-11-10','2025-12-10','2026-01-10','2026-02-10']), 'expect' => 4],

    // ───── yearly ─────
    ['name' => '[year] empty',         'unit' => 'year',  'now' => '2030-01-01 12:00:00', 'posts' => [], 'expect' => 0],
    ['name' => '[year] 3 years',       'unit' => 'year',  'now' => '2030-01-01 12:00:00',
        'posts' => posts(['2026-06-15','2027-04-10','2028-09-22'])  , 'expect' => 3],
    ['name' => '[year] gap break',     'unit' => 'year',  'now' => '2030-01-01 12:00:00',
        'posts' => posts(['2024-06-15','2026-04-10','2027-09-22']), 'expect' => 2],

    // ───── unknown unit falls back to week ─────
    ['name' => '[unknown→week] 2 in a row', 'unit' => 'fortnight', 'now' => '2026-06-23 12:00:00',
        'posts' => posts(['2026-06-15','2026-06-22']), 'expect' => 2],
];

$pass = 0;
$fail = 0;

foreach ($cases as $case) {
    $now = new DateTimeImmutable($case['now'], $tz);
    $calc = new GamificationCalculator($tz, $now);
    $got = $calc->longestStreakForUnit($case['unit'], $case['posts']);
    if ($got === $case['expect']) {
        $pass++;
        echo "  PASS: {$case['name']}  → {$got}\n";
    } else {
        $fail++;
        echo "  FAIL: {$case['name']}  want {$case['expect']}, got {$got}\n";
    }
}

echo "\n", $pass, " passed, ", $fail, " failed\n";
exit($fail === 0 ? 0 : 1);
