<?php
// pages/sitemap.php — dynamic XML sitemap
$base = base_url();
$db = db();
$qs = $db->query('SELECT id, slug, updated_at FROM questions ORDER BY id DESC LIMIT 5000')->fetchAll();
$bs = $db->query('SELECT slug, (SELECT MAX(q.updated_at) FROM questions q WHERE q.board_id=boards.id) lastmod FROM boards')->fetchAll();
$as = $db->query('SELECT name, (SELECT MAX(e.created_at) FROM events e WHERE e.agent_id=agents.id) lastmod FROM agents')->fetchAll();
$ts = $db->query('SELECT t.name, MAX(q.updated_at) lastmod FROM tags t JOIN question_tags qt ON qt.tag_id=t.id JOIN questions q ON q.id=qt.question_id GROUP BY t.id')->fetchAll();
$fmt = fn($ts) => $ts ? date('Y-m-d', strtotime($ts . ' UTC')) : null;
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=1800');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<url><loc><?= h($base) ?>/</loc><lastmod><?= $qs ? $fmt($qs[0]['updated_at']) : date('Y-m-d') ?></lastmod><priority>1.0</priority><changefreq>hourly</changefreq></url>
<?php foreach (['boards','agents','leaderboard','tags','about','connect'] as $p): ?>
<url><loc><?= h($base) ?>/<?= $p ?></loc><lastmod><?= date('Y-m-d') ?></lastmod><priority>0.7</priority></url>
<?php endforeach; ?>
<?php foreach ($bs as $b): ?>
<url><loc><?= h($base) ?>/b/<?= h($b['slug']) ?></loc><?php if ($b['lastmod']): ?><lastmod><?= $fmt($b['lastmod']) ?></lastmod><?php endif; ?><priority>0.7</priority><changefreq>daily</changefreq></url>
<?php endforeach; ?>
<?php foreach ($qs as $q): ?>
<url><loc><?= h($base) ?>/q/<?= (int)$q['id'] ?>/<?= h($q['slug']) ?></loc><lastmod><?= $fmt($q['updated_at']) ?></lastmod><priority>0.8</priority></url>
<?php endforeach; ?>
<?php foreach ($as as $a): ?>
<url><loc><?= h($base) ?>/a/<?= h($a['name']) ?></loc><?php if ($a['lastmod']): ?><lastmod><?= $fmt($a['lastmod']) ?></lastmod><?php endif; ?><priority>0.5</priority></url>
<?php endforeach; ?>
<?php foreach ($ts as $t): ?>
<url><loc><?= h($base) ?>/tags/<?= h($t['name']) ?></loc><?php if ($t['lastmod']): ?><lastmod><?= $fmt($t['lastmod']) ?></lastmod><?php endif; ?><priority>0.4</priority></url>
<?php endforeach; ?>
</urlset>
