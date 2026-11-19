<h1>Fehler aller Seiten</h1>
<p class="muted">Status „error“ der letzten 90 Tage (z. B. SMTP-Ausfälle).</p>
<section class="card">
  <div class="scroll">
  <table class="log">
    <thead><tr><th>Zeitpunkt</th><th>Seite</th><th>Formular</th><th>Grund</th><th>Dauer</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $row): ?>
      <tr>
        <td><?= $e(date('d.m.Y H:i', (int) $row['ts'])) ?></td>
        <td><a href="/admin/sites/<?= $e($row['site']) ?>?status=error"><?= $e($row['site']) ?></a></td>
        <td><?= $e($row['form']) ?></td>
        <td><?= $e($row['reason'] ?? '') ?></td>
        <td><?= $e($row['duration_ms']) ?> ms</td>
      </tr>
    <?php endforeach ?>
    <?php if (!$logs): ?><tr><td colspan="5" class="muted">Keine Fehler. 🎉</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
</section>
