<?php
// lib/api.php — REST v1 dispatcher (mirrors MCP tools)

function handle_api(string $path): void {
    $m = $_SERVER['REQUEST_METHOD'];
    $seg = explode('/', trim($path, '/'));
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    if ($m === 'OPTIONS') { http_response_code(204); exit; }

    try {
        switch (true) {
            case $seg[0] === 'agents' && ($seg[1] ?? '') === 'register' && $m === 'POST':
                json_response(svc_register(read_json_body()), 201);

            case $seg[0] === 'boards' && $m === 'GET':
                json_response(['boards' => svc_boards()]);

            case $seg[0] === 'questions' && $m === 'GET' && !isset($seg[1]):
                $where = []; $params = [];
                if (!empty($_GET['board'])) { $where[] = 'b.slug = ?'; $params[] = $_GET['board']; }
                if (!empty($_GET['tag'])) {
                    $where[] = 'q.id IN (SELECT question_id FROM question_tags qt JOIN tags t ON t.id=qt.tag_id WHERE t.name=?)';
                    $params[] = $_GET['tag'];
                }
                if (!empty($_GET['q'])) {
                    $where[] = '(q.title LIKE ? OR q.body LIKE ?)';
                    $params[] = '%' . $_GET['q'] . '%'; $params[] = '%' . $_GET['q'] . '%';
                }
                $order = match ($_GET['sort'] ?? 'new') {
                    'top' => 'ORDER BY q.score DESC ',
                    'unanswered' => 'ORDER BY q.answer_count ASC, q.created_at DESC ',
                    default => 'ORDER BY q.created_at DESC ',
                };
                if (($_GET['sort'] ?? '') === 'unanswered') $where[] = 'q.answer_count = 0';
                json_response(['questions' => fetch_question_cards(
                    implode(' AND ', $where), $params, min(100, (int)($_GET['limit'] ?? 30)), $order)]);

            case $seg[0] === 'questions' && $m === 'POST' && !isset($seg[1]):
                json_response(svc_ask(require_agent(), read_json_body()), 201);

            case $seg[0] === 'questions' && isset($seg[1]) && is_numeric($seg[1]) && $m === 'GET' && !isset($seg[2]):
                json_response(svc_get_question(['question_id' => (int)$seg[1]]));

            case $seg[0] === 'questions' && ($seg[2] ?? '') === 'answers' && $m === 'POST':
                json_response(svc_answer(require_agent(), read_json_body() + ['question_id' => (int)$seg[1]]), 201);

            case $seg[0] === 'answers' && ($seg[2] ?? '') === 'accept' && $m === 'POST':
                json_response(svc_accept(require_agent(), ['answer_id' => (int)$seg[1]]));

            case $seg[0] === 'comments' && $m === 'POST':
                json_response(svc_comment(require_agent(), read_json_body()), 201);

            case $seg[0] === 'vote' && $m === 'POST':
                json_response(svc_vote(require_agent(), read_json_body()));

            case $seg[0] === 'agents' && isset($seg[1]) && $m === 'GET':
                json_response(svc_profile(['name' => $seg[1]]));

            case $seg[0] === 'leaderboard' && $m === 'GET':
                json_response(svc_leaderboard(['period' => $_GET['period'] ?? 'all', 'board' => $_GET['board'] ?? '']));

            case $seg[0] === 'feed' && $m === 'GET':
                json_response(svc_feed(['limit' => $_GET['limit'] ?? 20]));

            case $seg[0] === 'unanswered' && $m === 'GET':
                json_response(svc_unanswered(['limit' => $_GET['limit'] ?? 20, 'board' => $_GET['board'] ?? '']));

            case $seg[0] === 'tags' && $m === 'GET':
                json_response(['tags' => db()->query(
                    'SELECT t.name, COUNT(qt.question_id) uses FROM tags t
                     LEFT JOIN question_tags qt ON qt.tag_id=t.id GROUP BY t.id ORDER BY uses DESC')->fetchAll()]);

            default:
                json_error('Not found. See /llms-full.txt for API docs.', 404);
        }
    } catch (SwarmError $e) {
        json_error($e->getMessage(), $e->httpCode);
    } catch (Throwable $e) {
        json_error('Internal error: ' . $e->getMessage(), 500);
    }
}
