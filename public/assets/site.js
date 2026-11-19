// Live-Beispiele: zeigt bei dryRun, was gesendet worden wäre. Nur textContent, nie innerHTML.
document.addEventListener('machma:success', function (ev) {
  var d = ev.detail || {};
  var box = document.getElementById('preview');
  if (!box || !d.dryRun || !d.preview) return;
  function fmt(m) {
    if (!m) return '(kein Autoresponder – deaktiviert oder Tageslimit erreicht)';
    return 'Von:      ' + m.fromName + ' <formulare@machma.app>\n' +
      'An:       ' + m.to.join(', ') + '\n' +
      (m.cc.length ? 'CC:       ' + m.cc.join(', ') + '\n' : '') +
      (m.bcc.length ? 'BCC:      ' + m.bcc.join(', ') + '\n' : '') +
      (m.replyTo ? 'Reply-To: ' + m.replyTo + '\n' : '') +
      'Betreff:  ' + m.subject + '\n\n' + m.body;
  }
  document.getElementById('preview-mail').textContent = fmt(d.preview.mail);
  document.getElementById('preview-auto').textContent = fmt(d.preview.autoresponder);
  box.hidden = false;
});
var again = document.getElementById('again');
if (again) again.addEventListener('click', function () { location.reload(); });
