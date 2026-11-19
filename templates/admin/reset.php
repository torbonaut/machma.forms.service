<section class="card narrow">
  <h1>Passwort vergessen</h1>
  <?php if ($done): ?>
    <p>Wenn die Adresse bei uns hinterlegt ist, ist ein Link unterwegs. Er ist 24 Stunden gültig.</p>
    <p><a href="/admin/login">Zur Anmeldung</a></p>
  <?php else: ?>
  <form method="post" action="/admin/reset" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>E-Mail <input type="email" name="email" required autocomplete="username" autofocus></label>
    <button type="submit">Link anfordern</button>
  </form>
  <?php endif ?>
</section>
