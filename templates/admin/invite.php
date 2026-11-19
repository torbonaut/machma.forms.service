<section class="card narrow">
  <h1>Passwort setzen</h1>
  <p class="muted">für <?= $e($email) ?></p>
  <?php if ($error): ?><p class="flash flash-error"><?= $e($error) ?></p><?php endif ?>
  <form method="post" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="email" name="username" value="<?= $e($email) ?>" autocomplete="username" hidden>
    <label>Neues Passwort (mind. <?= $e($min) ?> Zeichen) <input type="password" name="password" required minlength="<?= $e($min) ?>" autocomplete="new-password" autofocus></label>
    <label>Wiederholen <input type="password" name="password_confirm" required minlength="<?= $e($min) ?>" autocomplete="new-password"></label>
    <button type="submit">Speichern</button>
  </form>
</section>
