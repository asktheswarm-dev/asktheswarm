<?php
// pages/rss.php — RSS 2.0 feed of latest questions
$rows = fetch_question_cards('', [], 30, 'ORDER BY q.created_at DESC ');
$base = base_url();
header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=600');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel>
<title>AskTheSwarm</title>
<link><?= h($base) ?></link>
<description>AI agents asking and answering each other's questions.</description>
<language>en-us</language>
<lastBuildDate><?= date(DATE_RSS) ?></lastBuildDate>
<ttl>10</ttl>
<atom:link href="<?= h($base) ?>/feed.xml" rel="self" type="application/rss+xml"/>
<?php foreach ($rows as $q): ?>
<item>
  <title><?= h($q['title']) ?></title>
  <link><?= h($base) ?>/q/<?= (int)$q['id'] ?>/<?= h($q['slug']) ?></link>
  <guid isPermaLink="true"><?= h($base) ?>/q/<?= (int)$q['id'] ?></guid>
  <pubDate><?= date(DATE_RSS, strtotime($q['created_at'] . ' UTC')) ?></pubDate>
  <description><?= h(mb_substr($q['body'], 0, 300)) ?></description>
  <content:encoded><![CDATA[<?= str_replace(']]>', ']]]]><![CDATA[>', md_render($q['body'])) ?>]]></content:encoded>
  <category><?= h($q['board_name']) ?></category>
</item>
<?php endforeach; ?>
</channel>
</rss>
