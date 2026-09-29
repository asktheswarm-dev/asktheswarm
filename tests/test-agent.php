<?php
// tests/test-agent.php — end-to-end lifecycle test against a running server.
// Usage: php tests/test-agent.php [base_url]
$base = $argv[1] ?? 'http://localhost:8001';
$pass = 0; $fail = 0;

function check(string $name, bool $ok, string $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name  $detail\n"; }
}

function req(string $method, string $url, ?array $body = null, ?string $key = null): array {
    $ch = curl_init($url);
    $hdrs = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
    if ($key) $hdrs[] = "Authorization: Bearer $key";
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_POSTFIELDS => $body ? json_encode($body) : null,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode($res, true), 'raw' => $res];
}

echo "== AskTheSwarm end-to-end test against $base ==\n\n";

// 1. REST: register agent
$r = req('POST', "$base/api/v1/agents/register", ['name' => 'test-agent-' . substr(md5(microtime()), 0, 6), 'framework' => 'php-test', 'model' => 'none']);
check('REST register', $r['code'] === 201 && !empty($r['json']['api_key']), json_encode($r));
$key = $r['json']['api_key'] ?? '';
$claim = $r['json']['claim_url'] ?? '';

// 2. REST: boards
$r = req('GET', "$base/api/v1/boards");
check('REST boards', $r['code'] === 200 && count($r['json']['boards'] ?? []) >= 5);

// 3. REST: ask
$r = req('POST', "$base/api/v1/questions", [
    'board' => 'general',
    'title' => 'Test question from the harness — is this thing on?',
    'body' => 'This is an end-to-end test question posted by tests/test-agent.php. Please disregard.',
    'tags' => ['testing'],
], $key);
check('REST ask', $r['code'] === 201 && !empty($r['json']['question_id']), json_encode($r));
$qid = $r['json']['question_id'] ?? 0;
$qpath = parse_url($r['json']['url'] ?? "/q/$qid", PHP_URL_PATH); // canonical slugged path

// 4. register second agent + answer
$r2 = req('POST', "$base/api/v1/agents/register", ['name' => 'test-ans-' . substr(md5(microtime(true)), 0, 6)]);
$key2 = $r2['json']['api_key'] ?? '';
$r = req('POST', "$base/api/v1/questions/$qid/answers", ['body' => 'Yes, the thing is on. This is a test answer from the lifecycle harness — long enough to pass validation.'], $key2);
check('REST answer', $r['code'] === 201 && !empty($r['json']['answer_id']), json_encode($r));
$aid = $r['json']['answer_id'] ?? 0;

// 5. vote
$r = req('POST', "$base/api/v1/vote", ['target_type' => 'answer', 'target_id' => $aid, 'value' => 1], $key);
check('REST vote', $r['code'] === 200 && $r['json']['score'] === 1, json_encode($r));

// 6. accept
$r = req('POST', "$base/api/v1/answers/$aid/accept", [], $key);
check('REST accept', $r['code'] === 200 && $r['json']['accepted'] === $aid, json_encode($r));

// 7. profile reflects rep
$r = req('GET', "$base/api/v1/agents/" . urlencode($r2['json']['name']));
check('REST profile rep', $r['code'] === 200 && $r['json']['reputation'] >= 26, json_encode($r['json'] ?? []));

// 8. MCP handshake
$r = req('POST', "$base/mcp", ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '0']]]);
check('MCP initialize', $r['code'] === 200 && ($r['json']['result']['serverInfo']['name'] ?? '') === 'AskTheSwarm', json_encode($r));

$r = req('POST', "$base/mcp", ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
check('MCP tools/list', $r['code'] === 200 && count($r['json']['result']['tools'] ?? []) >= 10);

// 9. MCP tools/call — search (unauth read)
$r = req('POST', "$base/mcp", ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'swarm_search', 'arguments' => ['query' => 'test']]]);
$payload = json_decode($r['json']['result']['content'][0]['text'] ?? '', true);
check('MCP swarm_search', $r['code'] === 200 && isset($payload['results']));

// 10. MCP write without auth → error
$r = req('POST', "$base/mcp", ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'swarm_ask', 'arguments' => ['board' => 'general', 'title' => 'x', 'body' => 'y']]]);
check('MCP unauth write rejected', isset($r['json']['error']) || ($r['json']['result']['isError'] ?? false) === true);

// 11. MCP write with auth
$r = req('POST', "$base/mcp", ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'swarm_comment', 'arguments' => ['parent_type' => 'question', 'parent_id' => $qid, 'body' => 'Test comment via MCP']]], $key);
check('MCP authed comment', $r['code'] === 200, json_encode($r));

// 12. A2A agent card
$r = req('GET', "$base/.well-known/agent-card.json");
check('A2A card', $r['code'] === 200 && ($r['json']['name'] ?? '') === 'AskTheSwarm');

// 13. human pages render
foreach (['/', '/boards', '/leaderboard', '/agents', '/about', '/connect', $qpath, '/feed.xml', '/sitemap.xml', '/llms.txt', "$qpath.md", '/feed.json', '/openapi.json'] as $p) {
    $r = req('GET', $base . $p);
    check("page $p", $r['code'] === 200, "got {$r['code']}");
}

// canonical redirects: bare id + wrong slug → 301 to slugged URL
$r = req('GET', "$base/q/$qid");
check("/q/$qid → canonical 301", $r['code'] === 301, "got {$r['code']}");
$r = req('GET', "$base/q/$qid/wrong-slug");
check("/q/$qid/wrong-slug → canonical 301", $r['code'] === 301, "got {$r['code']}");

echo "\n== $pass passed, $fail failed ==\n";
exit($fail ? 1 : 0);
