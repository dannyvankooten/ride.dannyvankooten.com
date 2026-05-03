<?php
$pageStyle = <<<'CSS'
  body { font-family: system-ui, sans-serif; background: #f5f5f5; color: #222; padding: 24px 16px; }
  .wrap { max-width: 720px; margin: 0 auto; }
  h1 { font-size: 1.5rem; margin-bottom: 24px; }
  h2 { font-size: 1.1rem; margin-bottom: 12px; color: #444; }

  /* Stats bar */
  .stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 28px; }
  .stat { background: #fff; border-radius: 10px; padding: 16px 20px; flex: 1 1 120px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  .stat-val { font-size: 1.6rem; font-weight: 700; }
  .stat-label { font-size: .8rem; color: #888; margin-top: 2px; }
  .tick { color: #1e8449; }
  .cross { color: #c0392b; }

  /* Complete banner */
  .banner-ok { background: #d5f5e3; border: 1px solid #a9dfbf; border-radius: 10px; padding: 16px 20px; margin-bottom: 28px; font-weight: 600; color: #1e8449; }
  .banner-warn { background: #fef9e7; border: 1px solid #f9e79f; border-radius: 10px; padding: 16px 20px; margin-bottom: 16px; color: #9a7d0a; }

  /* Suggestion cards */
  .suggestions { display: flex; flex-direction: column; gap: 12px; margin-bottom: 32px; }
  .card { background: #fff; border-radius: 10px; padding: 18px 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); display: flex; align-items: flex-start; gap: 16px; }
  .badge { color: #fff; border-radius: 6px; padding: 3px 10px; font-size: .75rem; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
  .card-body { flex: 1; }
  .card-title { font-weight: 600; margin-bottom: 4px; }
  .card-desc { font-size: .875rem; color: #555; line-height: 1.4; }

  /* Ride log table */
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); font-size: .875rem; }
  th { background: #f0f0f0; text-align: left; padding: 10px 14px; font-size: .8rem; color: #555; white-space: nowrap; }
  td { padding: 10px 14px; border-top: 1px solid #f0f0f0; vertical-align: middle; }
  td:first-child { white-space: nowrap; }
  tr:last-child td { border-bottom: none; }
  .muted { color: #aaa; }
  .type-badges { display: flex; gap: 4px; flex-wrap: wrap; }
  .page-header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
  .page-header h1 { margin-bottom: 0; }
  .page-header-links { margin-left: auto; display: flex; align-items: center; gap: 12px; }
  .refresh-link, .logout-link { font-size: .8rem; color: #aaa; text-decoration: none; }
  .refresh-link:hover, .logout-link:hover { color: #555; }

  @media (max-width: 560px) {
    table, thead, tbody, tr, th, td { display: block; }
    thead { display: none; }
    tbody tr { background: #fff; border-radius: 10px; margin-bottom: 10px; padding: 12px 14px; box-shadow: 0 1px 4px rgba(0,0,0,.08); border: none; }
    tbody tr:last-child { margin-bottom: 0; }
    td { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; border-top: none; }
    td + td { border-top: 1px solid #f0f0f0; }
    td::before { content: attr(data-label); font-size: .75rem; font-weight: 600; color: #888; text-transform: uppercase; margin-right: 8px; flex-shrink: 0; }
    table { background: transparent; box-shadow: none; border-radius: 0; }
  }
CSS;
?>
<div class="wrap">
  <div class="page-header">
    <h1>Weekly Ride Planner</h1>
    <div class="page-header-links">
      <a class="refresh-link" href="/refresh" title="Fetch fresh data from Strava">
        ↻ <?= $cacheAge > 0 ? 'updated ' . round($cacheAge / 60) . ' min ago' : 'just updated' ?>
      </a>
      <a class="logout-link" href="/logout">sign out</a>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="stat-val"><?= $ridesDone ?> / <?= TARGET_RIDES ?></div>
      <div class="stat-label">rides this week</div>
    </div>
    <div class="stat">
      <div class="stat-val"><?= $minutesDone ?> / <?= TARGET_MINUTES ?></div>
      <div class="stat-label">minutes this week</div>
    </div>
    <div class="stat">
      <div class="stat-val <?= $hasHard ? 'tick' : 'cross' ?>"><?= $hasHard ? '✓' : '✗' ?></div>
      <div class="stat-label">hard ride</div>
    </div>
    <div class="stat">
      <div class="stat-val <?= $hasLong ? 'tick' : 'cross' ?>"><?= $hasLong ? '✓' : '✗' ?></div>
      <div class="stat-label">long ride</div>
    </div>
  </div>

  <?php if ($complete): ?>
  <div class="banner-ok">Week complete — all goals reached!</div>
  <?php else: ?>

  <?php if ($warning): ?>
  <div class="banner-warn"><?= htmlspecialchars($warning) ?></div>
  <?php endif; ?>

  <?php if ($suggestions): ?>
  <h2>Suggested workouts</h2>
  <div class="suggestions">
    <?php foreach ($suggestions as $s): ?>
    <div class="card">
      <span class="badge" style="background:<?= $colors[$s['type']] ?? '#888' ?>"><?= htmlspecialchars($s['type']) ?></span>
      <div class="card-body">
        <div class="card-title"><?= $s['duration'] ?> min</div>
        <div class="card-desc"><?= htmlspecialchars($s['description']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>

  <?php if ($allRides): ?>
  <h2>Recent rides</h2>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Ride</th>
        <th>Type</th>
        <th>Duration</th>
        <th>Avg HR</th>
        <th>Avg Watts</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (array_reverse($allRides) as $r): ?>
      <tr>
        <td data-label="Date"><?= htmlspecialchars($r['date']) ?></td>
        <td data-label="Ride"><?= htmlspecialchars(implode(', ', $r['names'])) ?></td>
        <td data-label="Type">
          <div class="type-badges">
            <?php foreach ($r['_types'] as $t): ?>
            <span class="badge" style="background:<?= $colors[$t] ?? '#888' ?>"><?= $t ?></span>
            <?php endforeach; ?>
          </div>
        </td>
        <td data-label="Duration"><?= (int) round($r['moving_time'] / 60) ?> min</td>
        <td data-label="Avg HR"><?= $r['avg_heartrate'] !== null ? round($r['avg_heartrate']) . ' bpm' : '<span class="muted">—</span>' ?></td>
        <td data-label="Avg Watts"><?= ($r['device_watts'] && $r['avg_watts'] !== null) ? round($r['avg_watts']) . ' W' : '<span class="muted">—</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <p class="muted">No rides recorded in the last 3 weeks.</p>
  <?php endif; ?>

</div>
