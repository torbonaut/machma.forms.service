<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> – machma forms</title>
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<header class="top">
  <a class="brand" href="/admin">machma <b>forms</b></a>
  <?php if ($user): ?>
  <nav>
    <a href="/admin">Übersicht</a>
    <?php if ($user['role'] === 'admin'): ?>
      <a href="/admin/users">Benutzer &amp; SMTP</a>
      <a href="/admin/errors">Fehler</a>
    <?php endif ?>
    <form method="post" action="/admin/logout" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="link" type="submit">Abmelden (<?= $e($user['email']) ?>)</button>
    </form>
  </nav>
  <?php endif ?>
</header>
<main>
<?php if ($flash): ?><p class="flash flash-<?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></p><?php endif ?>
<?= $content ?>
</main>
</body>
</html>
