<?php
$pageStyle = <<<'CSS'
  body { font-family: system-ui, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .error-card { background: #fff; border-radius: 12px; padding: 48px 40px; text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,.1); max-width: 440px; width: 100%; }
  h1 { font-size: 1.4rem; margin-bottom: 12px; }
  .error-card__message { font-size: .8rem; font-family: monospace; background: #f8f8f8; border-radius: 6px; padding: 10px 14px; color: #888; margin-bottom: 28px; text-align: left; word-break: break-all; }
  .error-card__btn { display: inline-block; background: #555; color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; }
  .error-card__btn:hover { background: #333; }
CSS;
?>
<div class="error-card">
  <h1>Could not reach Strava</h1>
  <div class="error-card__message"><?= htmlspecialchars($message) ?></div>
  <a class="error-card__btn" href="/">Try again</a>
</div>
