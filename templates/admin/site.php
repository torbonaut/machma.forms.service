<?php
$labels = ['sent' => 'versendet', 'spam' => 'Spam', 'invalid' => 'ungültig', 'rate_limited' => 'Rate-Limit', 'error' => 'Fehler'];
$query = static function (array $override) use ($filter): string {
    $q = array_filter(array_merge($filter, $override), static fn ($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '';
};
?>
<p class="muted"><a href="/admin">Übersicht</a> / <?= $e($site['id']) ?></p>
<h1><?= $e($site['id']) ?> <?php if ($site['dryRun']): ?><span class="badge">dryRun</span><?php endif ?></h1>

<section class="card">
  <div class="row">
    <h2>Einsendungen pro Tag<?php if ($filter['form']): ?> – <?= $e($filter['form']) ?><?php endif ?></h2>
    <p class="tabs">
      <a href="<?= $e($query(['days' => 30, 'page' => null])) ?>" class="<?= $days === 30 ? 'on' : '' ?>">30 Tage</a>
      <a href="<?= $e($query(['days' => 90, 'page' => null])) ?>" class="<?= $days === 90 ? 'on' : '' ?>">90 Tage</a>
    </p>
  </div>
  <?= $chart ?>
  <p class="legend"><?php foreach ($labels as $k => $l): ?><span class="s-<?= $e($k) ?>"><?= $e($l) ?></span><?php endforeach ?></p>
</section>

<section class="card">
  <h2>Nutzung pro Formular (<?= $e($days) ?> Tage)</h2>
  <table>
    <thead><tr><th>Formular</th><th>Version</th><th>Gesamt</th><th>versendet</th><th>Spam</th><th>Fehler/abgelehnt</th><?php if ($user['role'] === 'admin'): ?><th></th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($site['forms'] as $id => $form):
        $t = $totals[$id] ?? []; $all = array_sum($t);
        $pct = static fn (int $n) => $all ? round($n / $all * 100) . ' %' : '–'; ?>
      <tr>
        <td><a href="<?= $e($query(['form' => $id, 'page' => null])) ?>"><?= $e($id) ?></a></td>
        <td>v<?= $e($form['version']) ?></td>
        <td><?= $e($all) ?></td>
        <td><?= $e($pct($t['sent'] ?? 0)) ?></td>
        <td><?= $e($pct($t['spam'] ?? 0)) ?></td>
        <td><?= $e($pct(($t['error'] ?? 0) + ($t['invalid'] ?? 0) + ($t['rate_limited'] ?? 0))) ?></td>
        <?php if ($user['role'] === 'admin'): ?>
        <td>
          <form method="post" action="/admin/sites/<?= $e($site['id']) ?>/test" class="inline">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="form" value="<?= $e($id) ?>">
            <button type="submit" class="small">Testeinsendung</button>
          </form>
        </td>
        <?php endif ?>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h2>Log (<?= $e($total) ?>)</h2>
  <form method="get" class="filters">
    <input type="hidden" name="days" value="<?= $e($days) ?>">
    <label>Formular <select name="form"><option value="">alle</option>
      <?php foreach (array_keys($site['forms']) as $id): ?><option value="<?= $e($id) ?>" <?= $filter['form'] === $id ? 'selected' : '' ?>><?= $e($id) ?></option><?php endforeach ?>
    </select></label>
    <label>Status <select name="status"><option value="">alle</option>
      <?php foreach ($labels as $k => $l): ?><option value="<?= $e($k) ?>" <?= $filter['status'] === $k ? 'selected' : '' ?>><?= $e($l) ?></option><?php endforeach ?>
    </select></label>
    <label>von <input type="date" name="from" value="<?= $e($filter['from']) ?>"></label>
    <label>bis <input type="date" name="to" value="<?= $e($filter['to']) ?>"></label>
    <button type="submit" class="small">Filtern</button>
  </form>
  <div class="scroll">
  <table class="log">
    <thead><tr><th>Zeitpunkt</th><th>Formular</th><th>Status</th><th>Grund</th><th>IP-Hash</th><th>Sprache</th><th>Dauer</th><th>Autoresponder</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $row): ?>
      <tr>
        <td><?= $e(date('d.m.Y H:i', (int) $row['ts'])) ?></td>
        <td><?= $e($row['form']) ?></td>
        <td><span class="status s-<?= $e($row['status']) ?>"><?= $e($labels[$row['status']] ?? $row['status']) ?></span></td>
        <td><?= $e($row['reason'] ?? '') ?></td>
        <td><code><?= $e(substr($row['ip_hash'], 0, 8)) ?>…</code></td>
        <td><?= $e($row['locale'] ?? '') ?></td>
        <td><?= $e($row['duration_ms']) ?> ms</td>
        <td><?= $row['autoresponder'] ? 'ja' : 'nein' ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$logs): ?><tr><td colspan="8" class="muted">Keine Einträge.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
  <?php if ($pages > 1): ?>
  <p class="pager">
    <?php if ($page > 1): ?><a href="<?= $e($query(['page' => $page - 1])) ?>">← neuer</a><?php endif ?>
    Seite <?= $e($page) ?> von <?= $e($pages) ?>
    <?php if ($page < $pages): ?><a href="<?= $e($query(['page' => $page + 1])) ?>">älter →</a><?php endif ?>
  </p>
  <?php endif ?>
  <p class="muted small">Gespeichert werden nur Metadaten, 90 Tage lang – keine Inhalte, Adressen, IPs oder User-Agents.</p>
</section>
