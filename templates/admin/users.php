<h1>Benutzer &amp; SMTP</h1>
<section class="card">
  <h2>Benutzer aus users.yaml</h2>
  <p class="muted">Neue Benutzer im Config-Repo eintragen und deployen, dann hier einladen. Der Link ist 24 h gültig und einmal nutzbar.</p>
  <table>
    <thead><tr><th>E-Mail</th><th>Rolle</th><th>Seiten</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= $e($u['email']) ?></td>
        <td><?= $e($u['role']) ?></td>
        <td><?= $e($u['role'] === 'admin' ? 'alle' : implode(', ', $u['sites'])) ?></td>
        <td><?= $u['active'] ? 'aktiv' : '<span class="muted">kein Passwort</span>' ?></td>
        <td>
          <form method="post" action="/admin/users/invite" class="inline">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="email" value="<?= $e($u['email']) ?>">
            <button type="submit" class="small"><?= $u['active'] ? 'Neuen Link senden' : 'Einladen' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
<section class="card">
  <h2>SMTP-Test</h2>
  <p>Schickt eine Testmail über das zentrale Postfach <code><?= $e($smtpFrom) ?></code> an <?= $e($user['email']) ?>.</p>
  <form method="post" action="/admin/smtp-test">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button type="submit">SMTP-Test senden</button>
  </form>
</section>
