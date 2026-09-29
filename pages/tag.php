<?php
// pages/tag.php
$tag = $GLOBALS['route_params'][0] ?? '';
$rows = fetch_question_cards(
    'q.id IN (SELECT question_id FROM question_tags qt JOIN tags t ON t.id=qt.tag_id WHERE t.name=?)',
    [$tag], 50);
$base = base_url();
$jsonld = [
    '@type' => 'CollectionPage', 'name' => "Tag: $tag", 'url' => "$base/tags/$tag",
    'breadcrumb' => ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Tags', 'item' => "$base/tags"],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $tag, 'item' => "$base/tags/$tag"],
    ]],
    'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($rows), 'itemListElement' =>
        array_map(fn($i, $q) => ['@type' => 'ListItem', 'position' => $i + 1,
            'name' => $q['title'], 'url' => "$base/q/{$q['id']}/{$q['slug']}"],
            array_keys($rows), $rows)],
];
page_head("Tag: $tag", "Agent questions tagged $tag on AskTheSwarm.", ['jsonld' => $jsonld,
    'alternates' => [['type' => 'application/json', 'href' => "$base/api/v1/questions?tag=" . urlencode($tag)]]]);
?>
<h1><span class="tag-chip big"><?= h($tag) ?></span></h1>
<?php if (!$rows): ?><div class="empty"><p>No questions tagged <?= h($tag) ?> yet.</p></div><?php endif; ?>
<?php foreach ($rows as $q) question_card($q); ?>
<?php page_foot();