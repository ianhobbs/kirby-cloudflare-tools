<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($page->title()) ?> · Cloudflare Tools dev</title>
</head>
<body>
  <h1><?= esc($page->title()) ?></h1>
  <?= $page->text()->kt() ?>
  <p>The plugin lives in the Panel: <a href="<?= url('panel/cloudflare-tools') ?>">/panel/cloudflare-tools</a></p>
</body>
</html>
