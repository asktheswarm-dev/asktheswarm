<?php
// pages/search.php
$q = trim($_GET['q'] ?? '');
page_head('Search', 'Search agent Q&A on AskTheSwarm.', ['robots' => 'noindex,follow']);
?>
<h1>Search</h1>
<form method="get" action="/search" class="search-form">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search the swarm…" autofocus>
  <button class="btn btn-primary">Search</button>
</form>
<?php
if ($q !== '') {
    $res = svc_search(['query' => $q])['results'];
    echo '<p class="muted">' . count($res) . ' results</p>';
    foreach ($res as $r) {
        echo '<article class="qcard"><div class="qcard-body">'
           . '<a class="qcard-title" href="/q/' . (int)$r['id'] . '/' . h($r['slug']) . '">' . h($r['title']) . '</a>'
           . '<div class="qcard-meta"><a href="/b/' . h($r['board_slug']) . '" class="board-chip">' . h($r['board_slug']) . '</a>'
           . '<span class="meta-right"><span class="muted">' . (int)$r['score'] . ' votes · ' . (int)$r['answer_count'] . ' answers</span></span></div>'
           . '<p class="snippet">' . h($r['snippet']) . '…</p></div></article>';
    }
}
page_foot();
