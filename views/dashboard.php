<?php
$pageStyle = <<<CSS
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

  /* Banners */
  .banner { border-radius: 10px; padding: 16px 20px; font-weight: 600; }
  .banner--complete { background: #d5f5e3; border: 1px solid #a9dfbf; margin-bottom: 28px; color: #1e8449; }
  .banner--warning { background: #fef9e7; border: 1px solid #f9e79f; margin-bottom: 16px; color: #9a7d0a; }

  /* 7-day schedule */
  .schedule { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; margin-bottom: 32px; }
  .schedule__day { background: #fff; border-radius: 10px; padding: 12px 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); text-align: center; }
  .schedule__day--today { outline: 2px solid #222; outline-offset: -2px; }
  .schedule__day--completed { opacity: .5; }
  .schedule__dow { font-size: .65rem; color: #888; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
  .schedule__date { font-size: .7rem; color: #aaa; margin-bottom: 6px; }
  .schedule__badge { display: inline-block; margin-bottom: 6px; }
  .schedule__duration { font-size: .8rem; font-weight: 600; }

  @media (max-width: 560px) {
    .schedule { grid-template-columns: repeat(4, 1fr); }
  }

  /* Week header (shared by current and past) */
  .week-header { display: flex; justify-content: space-between; align-items: baseline; font-size: .85rem; color: #666; margin-bottom: 6px; }
  .week-header h2 { margin: 0; font-size: inherit; color: inherit; }
  .week-header__total { font-weight: 600; color: #444; }

  /* Past weeks calendar */
  .past-week { margin-bottom: 16px; }
  .past-week .schedule { margin-bottom: 0; }
  .past-week .schedule__day { opacity: .75; }
  .past-week__empty { color: #ccc; font-size: .75rem; margin-bottom: 6px; display: inline-block; }

  /* Badge (shared) */
  .badge { color: #fff; border-radius: 6px; padding: 3px 10px; font-size: .75rem; font-weight: 700; text-transform: uppercase; white-space: nowrap; }

  /* Make linked schedule cells indistinguishable from non-linked ones */
  a.schedule__day { display: block; text-decoration: none; color: inherit; }
  a.schedule__day:hover { box-shadow: 0 2px 8px rgba(0,0,0,.15); }
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

  <div class="week-header">
    <h2>This week's schedule</h2>
    <span class="week-header__total"><?= $minutesDone ?> / <?= $weeklyTarget ?> min</span>
  </div>
  <div class="schedule">
    <?php
    $today    = date('Y-m-d');
    $dowNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    foreach ($weekSchedule as $day):
      $classes = ['schedule__day'];
      if ($day['date'] === $today)          $classes[] = 'schedule__day--today';
      if ($day['status'] === 'completed')   $classes[] = 'schedule__day--completed';
      $color   = $colors[$day['type']] ?? '#bbb';
      $rideId  = ($day['status'] === 'completed' && !empty($day['ids'])) ? $day['ids'][0] : null;
      $tag     = $rideId ? 'a' : 'div';
      $href    = $rideId ? ' href="https://www.strava.com/activities/' . $rideId . '" target="_blank" rel="noopener"' : '';
    ?>
    <<?= $tag ?> class="<?= implode(' ', $classes) ?>"<?= $href ?>>
      <div class="schedule__dow"><?= $dowNames[$day['dow']] ?></div>
      <div class="schedule__date"><?= date('j M', strtotime($day['date'])) ?></div>
      <?php if ($day['type'] !== 'rest'): ?>
      <span class="badge schedule__badge" style="background:<?= $color ?>"><?= $day['type'] ?></span>
      <?php else: ?>
      <span class="schedule__badge" style="color:#bbb; font-size:.75rem">rest</span>
      <?php endif; ?>
      <?php if ($day['duration'] !== null): ?>
      <div class="schedule__duration"><?= $day['duration'] ?> min</div>
      <?php endif; ?>
    </<?= $tag ?>>
    <?php endforeach; ?>
  </div>

  <h2>Past 3 weeks</h2>
  <?php foreach ($pastWeeks as $week): ?>
  <div class="past-week">
    <div class="week-header">
      <span>Week of <?= date('j M', strtotime($week['start'])) ?></span>
      <span class="week-header__total"><?= $week['total'] ?> min</span>
    </div>
    <div class="schedule">
      <?php foreach ($week['days'] as $day):
        $ride   = $day['ride'];
        $type   = $ride
          ? (($ride['has_hard'] ?? false) ? 'hard' : (($ride['has_long'] ?? false) ? 'long' : 'easy'))
          : null;
        $color  = $type ? ($colors[$type] ?? '#bbb') : null;
        $rideId = $ride && !empty($ride['ids']) ? $ride['ids'][0] : null;
        $tag    = $rideId ? 'a' : 'div';
        $href   = $rideId ? ' href="https://www.strava.com/activities/' . $rideId . '" target="_blank" rel="noopener"' : '';
      ?>
      <<?= $tag ?> class="schedule__day"<?= $href ?>>
        <div class="schedule__dow"><?= $dowNames[$day['dow']] ?></div>
        <div class="schedule__date"><?= date('j M', strtotime($day['date'])) ?></div>
        <?php if ($ride): ?>
        <span class="badge schedule__badge" style="background:<?= $color ?>"><?= $type ?></span>
        <div class="schedule__duration"><?= (int) round($ride['moving_time'] / 60) ?> min</div>
        <?php else: ?>
        <span class="past-week__empty">—</span>
        <?php endif; ?>
      </<?= $tag ?>>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

</div>
