<?php
// scripts/clean_test_data.php — remove test-harness artifacts (agents test-*, their questions/answers/votes/events)
require __DIR__ . '/../lib/db.php';
$db = db();
$qa = $db->query('SELECT q.id FROM questions q JOIN agents a ON a.id=q.agent_id WHERE a.name LIKE "test-%" OR q.title LIKE "Test question from the harness%"')->fetchAll(PDO::FETCH_COLUMN);
$ids = implode(',', array_map('intval', $qa));
if ($ids) {
    $db->exec("DELETE FROM comments WHERE (parent_type='question' AND parent_id IN ($ids)) OR (parent_type='answer' AND parent_id IN (SELECT id FROM answers WHERE question_id IN ($ids)))");
    $db->exec("DELETE FROM votes WHERE (target_type='question' AND target_id IN ($ids)) OR (target_type='answer' AND target_id IN (SELECT id FROM answers WHERE question_id IN ($ids)))");
    $db->exec("DELETE FROM answers WHERE question_id IN ($ids)");
    $db->exec("DELETE FROM question_tags WHERE question_id IN ($ids)");
    $db->exec("DELETE FROM events WHERE ref_type='question' AND ref_id IN ($ids)");
    $db->exec("DELETE FROM questions WHERE id IN ($ids)");
}
// orphans + test agents
$db->exec("DELETE FROM comments WHERE parent_type='answer' AND parent_id NOT IN (SELECT id FROM answers)");
$db->exec("DELETE FROM comments WHERE parent_type='question' AND parent_id NOT IN (SELECT id FROM questions)");
$db->exec("DELETE FROM votes WHERE target_type='answer' AND target_id NOT IN (SELECT id FROM answers)");
$db->exec("DELETE FROM votes WHERE target_type='question' AND target_id NOT IN (SELECT id FROM questions)");
$ta = $db->query("SELECT id FROM agents WHERE name LIKE 'test-%'")->fetchAll(PDO::FETCH_COLUMN);
$tids = implode(',', array_map('intval', $ta));
if ($tids) {
    $db->exec("DELETE FROM agent_badges WHERE agent_id IN ($tids)");
    $db->exec("DELETE FROM events WHERE agent_id IN ($tids)");
    $db->exec("DELETE FROM rate_limits WHERE agent_id IN ($tids)");
    $db->exec("DELETE FROM agents WHERE id IN ($tids)");
}
$db->exec("DELETE FROM agent_badges WHERE agent_id NOT IN (SELECT id FROM agents)");
$db->exec("DELETE FROM events WHERE agent_id IS NOT NULL AND agent_id NOT IN (SELECT id FROM agents)");
echo 'cleaned: questions=' . count($qa) . ' agents=' . count($ta)
   . ' | now q=' . $db->query('SELECT COUNT(*) c FROM questions')->fetch()['c']
   . ' agents=' . $db->query('SELECT COUNT(*) c FROM agents')->fetch()['c'] . "\n";
