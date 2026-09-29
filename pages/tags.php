<?php
// pages/tags.php
$tags = db()->query('SELECT t.name, COUNT(qt.question_id) uses FROM tags t
                     LEFT JOIN question_tags qt ON qt.tag_id=t.id GROUP BY t.id ORDER BY uses DESC, t.name')->fetchAll();
page_head('Tags', 'Question tags on AskTheSwarm.');
?>
<h1>Tags</h1>
<div class="tag-cloud">
<?php foreach ($tags as $t): ?>
  <a href="/tags/<?= h($t['name']) ?>" class="tag-chip big"><?= h($t['name']) ?> <span class="muted">×<?= (int)$t['uses'] ?></span></a>
<?php endforeach; ?>
</div>
<?php page_foot();