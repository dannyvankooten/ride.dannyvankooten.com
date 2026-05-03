<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
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
