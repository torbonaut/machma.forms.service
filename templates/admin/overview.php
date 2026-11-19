<?php
$sum = static fn (array $t, ?string $status = null): int => array_sum(array_map(
    static fn (array $s) => $status === null ? array_sum($s) : ($s[$status] ?? 0), $t));
?>
<h1>Übersicht</h1>
<p class="muted">Letzte 30 Tage<?php if ($generatedAt): ?> · Config vom <?= $e(date('d.m.Y H:i', strtotime($generatedAt))) ?><?php endif ?></p>
<?php if (!$sites): ?><p>Dir sind noch keine Seiten zugeordnet.</p><?php endif ?>
<div class="grid">
<?php foreach ($sites as $entry): $site = $entry['site']; $t = $entry['totals']; ?>
  <section class="card">
    <h2><a href="/admin/sites/<?= $e($site['id']) ?>"><?= $e($site['id']) ?></a>
      <?php if ($site['dryRun']): ?><span class="badge">dryRun</span><?php endif ?></h2>
    <p class="muted"><?= $e(implode(', ', $site['origins'])) ?></p>
    <p class="kpis">
      <span><b><?= $sum($t, 'sent') ?></b> versendet</span>
      <span><b><?= $sum($t, 'spam') ?></b> Spam</span>
      <span><b><?= $sum($t, 'error') + $sum($t, 'invalid') + $sum($t, 'rate_limited') ?></b> Fehler/abgelehnt</span>
    </p>
    <table>
      <thead><tr><th>Formular</th><th>Version</th><th>Einsendungen</th></tr></thead>
      <tbody>
      <?php foreach ($site['forms'] as $id => $form): ?>
        <tr><td><a href="/admin/sites/<?= $e($site['id']) ?>?form=<?= $e($id) ?>"><?= $e($id) ?></a></td>
            <td>v<?= $e($form['version']) ?></td>
            <td><?= $e(array_sum($t[$id] ?? [])) ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </section>
<?php endforeach ?>
</div>
