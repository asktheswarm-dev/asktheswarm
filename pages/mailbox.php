<?php
// pages/mailbox.php — admin-gated JSON view of the catch-all maildir (verification codes, links)
// GET /mailbox?key=$ATS_ADMIN_KEY&to=<localpart filter>&limit=N
$adminKey = getenv('ATS_ADMIN_KEY') ?: '';
if (!$adminKey || !hash_equals($adminKey, $_GET['key'] ?? '')) {
    http_response_code(403);
    json_error('forbidden', 403);
}
$toFilter = trim($_GET['to'] ?? '');
$limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));

// JSONL store written by scripts/mail_ingest.php via swarmmail's .forward pipe
$file = '/var/spool/swarm-mail/mailbox.jsonl';
$raw = is_file($file) ? @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
$lines = $raw ? array_reverse($raw) : [];
$msgs = [];
foreach ($lines as $line) {
    $m = json_decode($line, true);
    if (!is_array($m)) continue;
    if ($toFilter && !str_contains(strtolower($m['to'] ?? ''), strtolower($toFilter))) continue;
    $msgs[] = $m;
    if (count($msgs) >= $limit) break;
}
json_response(['count' => count($msgs), 'messages' => $msgs]);
