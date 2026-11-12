<?php
// PASSWORD
const ADMIN_PASSWORD = 'Ulan2005!';
// 

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Robots-Tag: noindex');

$file = __DIR__ . '/../orders.json';   // same file router.php uses (outside /public)

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function peso($n) { return '₱' . number_format((float)$n, 2); }
function manila($iso) {
    if (!$iso) return '—';
    try {
        $d = new DateTime($iso);
        $d->setTimezone(new DateTimeZone('Asia/Manila'));
        return $d->format('M j, g:i A');
    } catch (Exception $e) { return h($iso); }
}

if (ADMIN_PASSWORD === 'change-this-password') {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:24px">'
       . '<h2>Palitan muna ang password</h2><p>Buksan ang <code>public/admin.php</code> at palitan ang '
       . '<code>ADMIN_PASSWORD</code> sa taas ng file.</p></body>';
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ---------- LIVE COUNT (the page asks this every few seconds to spot new orders) ----------
if (isset($_GET['count'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (empty($_SESSION['admin'])) { http_response_code(401); echo '{}'; exit; }
    $all = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    if (!is_array($all)) $all = [];
    $c = ['awaiting' => 0, 'pending' => 0, 'paid' => 0, 'rejected' => 0];
    foreach ($all as $o) {
        switch ($o['status'] ?? '') {
            case 'awaiting_confirmation': $c['awaiting']++; break;
            case 'pending_payment':       $c['pending']++;  break;
            case 'paid': case 'paid_demo':$c['paid']++;     break;
            case 'rejected':              $c['rejected']++; break;
        }
    }
    echo json_encode($c + ['sig' => implode('|', $c)]);
    exit;
}

$err = '';
if (isset($_POST['login'])) {
    if (hash_equals(ADMIN_PASSWORD, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: admin.php');
        exit;
    }
    sleep(2);               // slows down password guessing
    $err = 'Mali ang password.';
}

$css = '
*{box-sizing:border-box}body{margin:0;font-family:system-ui,Segoe UI,Roboto,sans-serif;background:#0f1226;color:#e8eaf6}
.wrap{max-width:760px;margin:0 auto;padding:16px}
h1{font-size:20px;margin:0}
.top{display:flex;justify-content:space-between;align-items:center;margin:8px 0 14px}
.top a{color:#9aa4d6;font-size:14px}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.tabs a{padding:8px 12px;border-radius:999px;background:#1b1f3d;color:#c5cbee;text-decoration:none;font-size:14px}
.tabs a.on{background:#ffb300;color:#1a1a1a;font-weight:600}
.card{background:#1b1f3d;border:1px solid #2a2f5a;border-radius:14px;padding:14px;margin-bottom:12px}
.row{display:flex;justify-content:space-between;gap:10px;font-size:14px;padding:3px 0}
.row span:first-child{color:#9aa4d6}
.id{font-weight:700;font-size:16px}
.amt{font-size:22px;font-weight:800;color:#ffb300;margin:6px 0}
.badge{font-size:12px;padding:3px 9px;border-radius:999px;font-weight:600}
.b-awaiting{background:#ffb300;color:#1a1a1a}.b-pending{background:#2a2f5a;color:#c5cbee}
.b-paid{background:#1f8f4e;color:#fff}.b-rejected{background:#a33;color:#fff}
.btns{display:flex;gap:8px;margin-top:10px}
button{border:0;border-radius:10px;padding:11px 14px;font-size:15px;font-weight:600;cursor:pointer;flex:1}
.ok{background:#1f8f4e;color:#fff}.no{background:#33385f;color:#ffb3b3}
input[type=password]{width:100%;padding:12px;border-radius:10px;border:1px solid #2a2f5a;background:#0f1226;color:#fff;font-size:16px}
.err{color:#ff8a8a;margin:8px 0}.muted{color:#9aa4d6;font-size:13px}
.alertbar{display:flex;gap:10px;align-items:center;justify-content:space-between;background:#1b1f3d;border:1px dashed #2a2f5a;border-radius:12px;padding:10px 12px;margin-bottom:14px;font-size:13px;color:#9aa4d6}
.alertbar button{flex:none;padding:8px 12px;font-size:13px;background:#33385f;color:#fff}
';

// ---------- LOGIN PAGE ----------
if (empty($_SESSION['admin'])) {
    ?><!doctype html><html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin</title>
    <style><?= $css ?></style></head><body><div class="wrap" style="max-width:380px;margin-top:12vh">
    <div class="card"><h1>Admin login</h1>
    <form method="post"><p><input type="password" name="password" placeholder="Password" autofocus required></p>
    <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
    <button class="ok" name="login" value="1" style="width:100%">Sign in</button></form></div></div></body></html><?php
    exit;
}

// ---------- APPROVE / REJECT ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Bad token'); }
    $id = strtoupper(trim((string)($_POST['id'] ?? '')));
    $map = ['approve' => 'paid', 'reject' => 'rejected'];
    $action = (string)$_POST['action'];

    if ($id !== '' && isset($map[$action])) {
        $fp = fopen($file, 'c+');
        if ($fp) {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $orders = json_decode($raw ? $raw : '[]', true);
            if (!is_array($orders)) $orders = [];
            foreach ($orders as &$o) {
                if (($o['id'] ?? '') === $id && in_array($o['status'] ?? '', ['pending_payment', 'awaiting_confirmation'], true)) {
                    $o['status'] = $map[$action];
                    if ($action === 'approve') $o['paidAt'] = gmdate('Y-m-d\TH:i:s\Z');
                    else $o['rejectedAt'] = gmdate('Y-m-d\TH:i:s\Z');
                    break;
                }
            }
            unset($o);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
    header('Location: admin.php?f=' . urlencode((string)($_POST['f'] ?? 'awaiting')));
    exit;
}

// ---------- LIST ----------
$orders = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
if (!is_array($orders)) $orders = [];
usort($orders, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

$groups = [
    'awaiting' => ['Waiting for confirmation', ['awaiting_confirmation']],
    'pending'  => ['Not yet paid', ['pending_payment']],
    'paid'     => ['Paid', ['paid', 'paid_demo']],
    'rejected' => ['Rejected', ['rejected']],
    'all'      => ['All', null],
];
$f = $_GET['f'] ?? 'awaiting';
if (!isset($groups[$f])) $f = 'awaiting';

$count = [];
foreach ($groups as $k => [$label, $sts]) {
    $count[$k] = 0;
    foreach ($orders as $o) if ($sts === null || in_array($o['status'] ?? '', $sts, true)) $count[$k]++;
}
$shown = array_filter($orders, fn($o) => $groups[$f][1] === null || in_array($o['status'] ?? '', $groups[$f][1], true));

function badge($s) {
    switch ($s) {
        case 'awaiting_confirmation': return ['b-awaiting', 'Waiting for confirmation'];
        case 'pending_payment':       return ['b-pending', 'Not yet paid'];
        case 'paid': case 'paid_demo':return ['b-paid', 'Paid'];
        case 'rejected':              return ['b-rejected', 'Rejected'];
        default:                      return ['b-pending', $s];
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Admin – Orders</title><style><?= $css ?></style></head><body><div class="wrap">
<div class="top"><h1>Orders</h1><a href="admin.php?logout=1">Log out</a></div>
<div class="alertbar"><span id="alertstatus">Alerts off</span><button type="button" id="alertbtn">Turn on alerts</button></div>
<div id="list">
<div class="tabs"><?php foreach ($groups as $k => [$label]): ?>
  <a class="<?= $k === $f ? 'on' : '' ?>" href="admin.php?f=<?= h($k) ?>"><?= h($label) ?> (<?= (int)$count[$k] ?>)</a>
<?php endforeach; ?></div>

<?php if (!$shown): ?><p class="muted">Walang order dito.</p><?php endif; ?>

<?php foreach ($shown as $o): [$bc, $bt] = badge($o['status'] ?? ''); $open = in_array($o['status'] ?? '', ['pending_payment', 'awaiting_confirmation'], true); ?>
<div class="card">
  <div class="row"><span class="id"><?= h($o['id'] ?? '') ?></span><span class="badge <?= $bc ?>"><?= h($bt) ?></span></div>
  <div class="amt"><?= peso($o['total'] ?? 0) ?></div>
  <div class="row"><span>Game</span><span><?= h($o['game'] ?? '') ?></span></div>
  <div class="row"><span>Item</span><span><?= h($o['item'] ?? $o['pack'] ?? '') ?></span></div>
  <?php foreach (($o['account'] ?? []) as $k => $v): ?>
  <div class="row"><span><?= h($k) ?></span><span><?= h($v) ?></span></div>
  <?php endforeach; ?>
  <div class="row"><span>Payment</span><span><?= h($o['payment'] ?? '') ?></span></div>
  <?php if (!empty($o['payerMobile'])): ?><div class="row"><span>SIM to charge</span><span><?= h($o['payerMobile']) ?></span></div><?php endif; ?>
  <?php if (!empty($o['email'])): ?><div class="row"><span>Email</span><span><?= h($o['email']) ?></span></div><?php endif; ?>
  <div class="row"><span>Created</span><span><?= manila($o['createdAt'] ?? '') ?></span></div>
  <?php if (!empty($o['reportedAt'])): ?><div class="row"><span>Customer said paid</span><span><?= manila($o['reportedAt']) ?></span></div><?php endif; ?>
  <?php if (!empty($o['paidAt'])): ?><div class="row"><span>Approved</span><span><?= manila($o['paidAt']) ?></span></div><?php endif; ?>
  <?php if ($open): ?>
  <form method="post" class="btns">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <input type="hidden" name="id" value="<?= h($o['id'] ?? '') ?>">
    <input type="hidden" name="f" value="<?= h($f) ?>">
    <button class="ok" name="action" value="approve" onclick="return confirm('Pumasok na ba talaga ang <?= h(peso($o['total'] ?? 0)) ?> sa GCash/InstaPay mo?')">Mark as paid</button>
    <button class="no" name="action" value="reject" onclick="return confirm('I-reject ang order na ito?')">Reject</button>
  </form>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<p class="muted">Kusang nag-a-update ito kapag may bagong order. I-check muna sa GCash/InstaPay mo bago mag-Mark as paid.</p>
</div>
<script>
(function () {
  var awaiting = <?= (int)$count['awaiting'] ?>;
  var sig = <?= json_encode(implode('|', [(int)$count['awaiting'], (int)$count['pending'], (int)$count['paid'], (int)$count['rejected']])) ?>;
  var baseTitle = document.title, flashT = null, ctx = null;
  var on = false;
  try { on = localStorage.getItem('alerts') === '1'; } catch (e) {}
  var btn = document.getElementById('alertbtn'), label = document.getElementById('alertstatus');

  function paint() {
    btn.textContent = on ? 'Turn off alerts' : 'Turn on alerts';
    label.textContent = on ? 'Alerts on: sound + notification when a customer says they paid' : 'Alerts off';
  }
  function unlock() {          // browsers only allow sound after a click
    if (!on) return;
    try { ctx = ctx || new (window.AudioContext || window.webkitAudioContext)(); ctx.resume(); } catch (e) {}
  }
  function beep() {
    if (!ctx) return;
    try {
      ctx.resume();
      var t = ctx.currentTime;
      [880, 1175].forEach(function (f, i) {
        var o = ctx.createOscillator(), g = ctx.createGain(), s = t + i * 0.2;
        o.frequency.value = f; o.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.0001, s);
        g.gain.exponentialRampToValueAtTime(0.25, s + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, s + 0.18);
        o.start(s); o.stop(s + 0.2);
      });
    } catch (e) {}
  }
  function flash(n) {
    if (!document.hidden) return;
    clearInterval(flashT);
    var k = 0;
    flashT = setInterval(function () { document.title = (k++ % 2) ? baseTitle : '(' + n + ') New payment!'; }, 900);
  }
  function stopFlash() { clearInterval(flashT); document.title = baseTitle; }
  function alertNew(n) {
    beep(); flash(n);
    if ('Notification' in window && Notification.permission === 'granted') {
      try { new Notification('TopUp Ulan', { body: n + ' order(s) waiting for your confirmation' }); } catch (e) {}
    }
  }

  btn.onclick = function () {
    on = !on;
    try { localStorage.setItem('alerts', on ? '1' : '0'); } catch (e) {}
    if (on) {
      unlock(); beep();
      if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    }
    paint();
  };
  document.addEventListener('click', unlock);
  window.addEventListener('focus', stopFlash);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) stopFlash(); });

  async function refreshList() {
    var r = await fetch(location.href, { cache: 'no-store' });
    var d = new DOMParser().parseFromString(await r.text(), 'text/html');
    var fresh = d.getElementById('list');
    if (fresh) document.getElementById('list').innerHTML = fresh.innerHTML;
  }
  async function tick() {
    try {
      var r = await fetch('admin.php?count=1', { cache: 'no-store' });
      if (r.status === 401) { location.reload(); return; }
      var c = await r.json();
      if (c.sig !== sig) { sig = c.sig; await refreshList(); }
      if (on && c.awaiting > awaiting) alertNew(c.awaiting);
      awaiting = c.awaiting;
    } catch (e) { /* offline for a moment: try again next time */ }
  }

  paint();
  setInterval(tick, 8000);
})();
</script>
</body></html>