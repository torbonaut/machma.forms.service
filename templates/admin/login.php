<section class="card narrow">
  <h1>Anmelden</h1>
  <?php if ($error): ?><p class="flash flash-error"><?= $e($error) ?></p><?php endif ?>
  <form method="post" action="/admin/login" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>E-Mail <input type="email" name="email" value="<?= $e($email) ?>" required autocomplete="username" autofocus></label>
    <label>Passwort <input type="password" name="password" required autocomplete="current-password"></label>
    <button type="submit">Anmelden</button>
  </form>
  <p class="muted"><a href="/admin/reset">Passwort vergessen?</a></p>
</section>
