<?php
$pageStyle = <<<'CSS'
  body { font-family: system-ui, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .card { background: #fff; border-radius: 12px; padding: 48px 40px; text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,.1); max-width: 400px; width: 100%; }
  h1 { font-size: 1.5rem; margin-bottom: 8px; }
  p { color: #666; margin-bottom: 32px; }
  a.btn { display: inline-block; background: #FC4C02; color: #fff; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: 600; font-size: 1rem; }
  a.btn:hover { background: #e04400; }
CSS;
?>
<div class="card">
  <h1>Workout Planner</h1>
  <p>Connect your Strava account to get a personalised weekly ride plan.</p>
  <a class="btn" href="<?= htmlspecialchars($authUrl) ?>">Connect with Strava</a>
</div>
