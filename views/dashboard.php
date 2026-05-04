<?php
$pageStyle = <<<'CSS'
  body { font-family: system-ui, sans-serif; background: #f5f5f5; color: #222; padding: 24px 16px; }
  .page { max-width: 720px; margin: 0 auto; }
  h1 { font-size: 1.5rem; }
  h2 { font-size: 1.1rem; margin-bottom: 12px; color: #444; }

  /* Page header */
  .page-header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
  .page-header__title { margin-bottom: 0; }
  .page-header__links { margin-left: auto; display: flex; align-items: center; gap: 12px; }
  .page-header__link { font-size: .8rem; color: #aaa; text-decoration: none; }
  .page-header__link:hover { color: #555; }

  /* Stats bar */
  .stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 28px; }
  .stats__item { background: #fff; border-radius: 10px; padding: 16px 20px; flex: 1 1 120px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  .stats__value { font-size: 1.6rem; font-weight: 700; }
  .stats__value--complete { color: #1e8449; }
  .stats__value--missing { color: #c0392b; }
  .stats__label { font-size: .8rem; color: #888; margin-top: 2px; }

  /* Banners */
  .banner { border-radius: 10px; padding: 16px 20px; font-weight: 600; }
  .banner--complete { background: #d5f5e3; border: 1px solid #a9dfbf; margin-bottom: 28px; color: #1e8449; }
  .banner--warning { background: #fef9e7; border: 1px solid #f9e79f; margin-bottom: 16px; color: #9a7d0a; }

  /* Suggestions */
  .suggestions { display: flex; flex-direction: column; gap: 12px; margin-bottom: 32px; }
  .suggestions__item { background: #fff; border-radius: 10px; padding: 18px 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); display: flex; align-items: flex-start; gap: 16px; }
  .suggestions__body { flex: 1; }
  .suggestions__duration { font-weight: 600; margin-bottom: 4px; }
  .suggestions__desc { font-size: .875rem; color: #555; line-height: 1.4; }

  /* Badge (shared) */
  .badge { color: #fff; border-radius: 6px; padding: 3px 10px; font-size: .75rem; font-weight: 700; text-transform: uppercase; white-space: nowrap; }

  /* Rides table */
  .rides { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .rides__table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); font-size: .875rem; }
  .rides__table th { background: #f0f0f0; text-align: left; padding: 10px 14px; font-size: .8rem; color: #555; white-space: nowrap; }
  .rides__table td { padding: 10px 14px; border-top: 1px solid #f0f0f0; vertical-align: middle; }
  .rides__table td:first-child { white-space: nowrap; }
  .rides__table tr:last-child td { border-bottom: none; }
  .rides__na { color: #aaa; }
  .ride__types { display: flex; gap: 4px; flex-wrap: wrap; }
  .rides__divider td { background: #f8f8f8; text-align: center; font-size: .75rem; color: #bbb; padding: 5px 14px; border-top: 2px solid #e8e8e8; }
  .rides__row--past td { color: #bbb; }
  .rides__row--past .badge { opacity: 0.4; }
  .rides__row--past .rides__na { color: #ddd; }

  @media (max-width: 560px) {
    .rides__table, thead, tbody, th, td { display: block; }
    thead { display: none; }
    .rides__table { background: transparent; box-shadow: none; border-radius: 0; }

    tbody tr:not(.rides__divider) {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 5px 12px;
      padding: 12px 14px;
      background: #fff;
      border-radius: 10px;
      margin-bottom: 10px;
      box-shadow: 0 1px 4px rgba(0,0,0,.08);
    }
    tbody tr:not(.rides__divider):last-child { margin-bottom: 0; }
    tbody tr:not(.rides__divider) td { padding: 0; border: none; }
    tbody tr:not(.rides__divider) td:nth-child(1) { grid-column: 1; grid-row: 1; font-size: .8rem; color: #888; }
    tbody tr:not(.rides__divider) td:nth-child(2) { grid-column: 1 / 3; grid-row: 2; }
    tbody tr:not(.rides__divider) td:nth-child(3) { grid-column: 1; grid-row: 3 / 5; align-self: start; }
    tbody tr:not(.rides__divider) td:nth-child(4) { grid-column: 2; grid-row: 1; font-size: .8rem; color: #888; text-align: right; white-space: nowrap; }
    tbody tr:not(.rides__divider) td:nth-child(5) { grid-column: 2; grid-row: 3; font-size: .8rem; color: #888; text-align: right; white-space: nowrap; }
    tbody tr:not(.rides__divider) td:nth-child(6) { grid-column: 2; grid-row: 4; font-size: .8rem; color: #888; text-align: right; white-space: nowrap; }
    tbody tr:not(.rides__divider) td:nth-child(4)::before { content: "⏱\00a0"; }
    tbody tr:not(.rides__divider) td:nth-child(5)::before { content: "♥\00a0"; }
    tbody tr:not(.rides__divider) td:nth-child(6)::before { content: "⚡\00a0"; }
  }
CSS;
?>
<div class="page">
  <div class="page-header">
    <h1 class="page-header__title">Weekly Ride Planner</h1>
    <div class="page-header__links">
      <a class="page-header__link" href="/refresh" title="Fetch fresh data from Strava">
        ↻ <?= $cacheAge > 0 ? 'updated ' . round($cacheAge / 60) . ' min ago' : 'just updated' ?>
      </a>
      <a class="page-header__link" href="/settings">settings</a>
      <a class="page-header__link" href="/logout">sign out</a>
    </div>
  </div>

  <div class="stats">
    <div class="stats__item">
      <div class="stats__value"><?= $minutesDone ?> / <?= $settings['target_minutes'] ?></div>
      <div class="stats__label">minutes this week</div>
    </div>
    <div class="stats__item">
      <div class="stats__value"><?= $ridesDone ?> / <?= $settings['target_rides'] ?></div>
      <div class="stats__label">rides this week</div>
    </div>
    <div class="stats__item">
      <div class="stats__value <?= $hasHard ? 'stats__value--complete' : 'stats__value--missing' ?>"><?= $hasHard ? '✓' : '✗' ?></div>
      <div class="stats__label">hard ride</div>
    </div>
    <div class="stats__item">
      <div class="stats__value <?= $hasLong ? 'stats__value--complete' : 'stats__value--missing' ?>"><?= $hasLong ? '✓' : '✗' ?></div>
      <div class="stats__label">long ride</div>
    </div>
  </div>

  <?php if ($suggestions): ?>
  <?php $s = $suggestions[0]; ?>
  <h2>Today's suggested workout</h2>
  <div class="suggestions">
    <div class="suggestions__item">
      <span class="badge" style="background:<?= $colors[$s['type']] ?? '#888' ?>"><?= htmlspecialchars($s['type']) ?></span>
      <div class="suggestions__body">
        <?php if ($s['duration'] !== null): ?>
        <div class="suggestions__duration"><?= $s['duration'] ?> min</div>
        <?php endif; ?>
        <div class="suggestions__desc"><?= htmlspecialchars($s['description']) ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($allRides): ?>
  <h2>Recent rides</h2>
  <div class="rides">
  <table class="rides__table">
    <thead>
      <tr>
        <th>Date</th>
        <th>Ride</th>
        <th>Type</th>
        <th>⏱ Duration</th>
        <th>♥ Avg HR</th>
        <th>⚡ Avg Watts</th>
      </tr>
    </thead>
    <tbody>
      <?php $cutoff = date('Y-m-d', strtotime('-7 days')); $pastWindow = false; ?>
      <?php foreach (array_reverse($allRides) as $r): ?>
      <?php if (!$pastWindow && $r['date'] < $cutoff): $pastWindow = true; ?>
      <tr class="rides__divider"><td colspan="6">older than 7 days</td></tr>
      <?php endif; ?>
      <tr<?= $pastWindow ? ' class="rides__row--past"' : '' ?>>
        <?php $daysAgo = (int) floor((strtotime(date('Y-m-d')) - strtotime($r['date'])) / 86400); ?>
        <td data-label="Date"><?= $daysAgo === 0 ? 'today' : ($daysAgo === 1 ? 'yesterday' : $daysAgo . ' days ago') ?></td>
        <td data-label="Ride"><?= htmlspecialchars(implode(', ', $r['names'])) ?></td>
        <td data-label="Type">
          <div class="ride__types">
            <?php foreach ($r['_types'] as $t): ?>
            <span class="badge" style="background:<?= $colors[$t] ?? '#888' ?>"><?= $t ?></span>
            <?php endforeach; ?>
          </div>
        </td>
        <td data-label="Duration"><?= (int) round($r['moving_time'] / 60) ?> min</td>
        <td data-label="Avg HR"><?= $r['avg_heartrate'] !== null ? round($r['avg_heartrate']) . ' bpm' : '<span class="rides__na">—</span>' ?></td>
        <td data-label="Avg Watts"><?= ($r['device_watts'] && $r['avg_watts'] !== null) ? round($r['avg_watts']) . ' W' : '<span class="rides__na">—</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <p class="rides__na">No rides recorded in the last 3 weeks.</p>
  <?php endif; ?>

</div>
