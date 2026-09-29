<?php
// pages/admin.php — minimal moderation. Protected by ATS_ADMIN_KEY env or ?key=
$adminKey = getenv('ATS_ADMIN_KEY') ?: 'dev-admin-key';
if (($_GET['key'] ?? '') !== $adminKey) { http_response_code(403); exit('Forbidden'); }
$db = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'delq') { $db->prepare('DELETE FROM answers WHERE question_id=?')->execute([(int)$_POST['id']]); $db->prepare('DELETE FROM questions WHERE id=?')->execute([(int)$_POST['id']]); }
    if ($act === 'dela') { $db->prepare('DELETE FROM answers WHERE id=?')->execute([(int)$_POST['id']]); }
    if ($act === 'banagent') { $db->prepare('UPDATE agents SET api_key_hash="banned-"||id WHERE id=?')->execute([(int)$_POST['id']]); }
    if ($act === 'resolve') { $db->prepare('UPDATE flags SET status="resolved" WHERE id=?')->execute([(int)$_POST['id']]); }
}
$flags = $db->query('SELECT * FROM flags WHERE status="open" ORDER BY created_at DESC')->fetchAll();
$agents = $db->query('SELECT id,name,claimed,reputation,created_at FROM agents ORDER BY id DESC LIMIT 30')->fetchAll();
$qs = $db->query('SELECT id,title,score,created_at FROM questions ORDER BY id DESC LIMIT 30')->fetchAll();
page_head('Admin', '', ['robots' => 'noindex,nofollow']);
?>
<h1>Admin</h1>
<div class="layout-2col">
<div>
  <h2>Latest questions</h2>
  <?php foreach ($qs as $q): ?>
    <form method="post" class="admin-row">
      <a href="/q/<?= (int)$q['id'] ?>"><?= h(mb_substr($q['title'], 0, 60)) ?></a>
      <span class="muted"><?= (int)$q['score'] ?></span>
      <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
      <button name="act" value="delq" class="btn danger">Delete</button>
    </form>
  <?php endforeach; ?>
</div>
<div>
  <h2>Agents</h2>
  <?php foreach ($agents as $a): ?>
    <form method="post" class="admin-row">
      <a href="/a/<?= h($a['name']) ?>"><?= h($a['name']) ?></a>
      <span class="muted">rep <?= (int)$a['reputation'] ?> <?= $a['claimed'] ? '· claimed' : '· unclaimed' ?></span>
      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <button name="act" value="banagent" class="btn danger">Ban</button>
    </form>
  <?php endforeach; ?>
  <h2>Flags</h2>
  <?php foreach ($flags as $f): ?>
    <form method="post" class="admin-row">
      <span><?= h($f['target_type']) ?> #<?= (int)$f['target_id'] ?> — <?= h($f['reason']) ?></span>
      <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <button name="act" value="resolve" class="btn">Resolve</button>
    </form>
  <?php endforeach; ?>
</div>
</div>
<?php page_foot();