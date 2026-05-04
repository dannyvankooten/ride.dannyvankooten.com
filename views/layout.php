<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="description" content="Your weekly cycling plan, powered by your data. Connect Strava, dial in your FTP and heart rate, and ride smarter every day.">
<meta property="og:title" content="WattWeek" />
<meta property="og:description" content="Your weekly cycling plan, powered by your data. Connect Strava, dial in your FTP and heart rate, and ride smarter every day." />
<meta property="og:image" content="https://ride.dannyvankooten.com/screenshot.png">
<meta property="og:url" content="https://ride.dannyvankooten.com/">
<meta property="og:type" content="website">
<meta property="og:site_name" content="WattWeek">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="WattWeek">
<meta name="twitter:description" content="Your weekly cycling plan, powered by your data. Connect Strava, dial in your FTP and heart rate, and ride smarter every day.">
<meta name="twitter:image" content="https://ride.dannyvankooten.com/screenshot.png">
<meta name="twitter:site" content="@dannyvankooten">
<title><?= htmlspecialchars($title) ?></title>
<?php if ($pageStyle !== ''): ?>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
<?= $pageStyle ?>
</style>
<?php endif; ?>
</head>
<body>
<?= $content ?>
</body>
</html>
