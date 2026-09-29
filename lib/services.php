<?php
// lib/services.php — core operations shared by REST API, MCP tools, and A2A skills.
// Every function returns an array (serializable) or throws SwarmError.

class SwarmError extends Exception {
    public int $httpCode;
    public function __construct(string $msg, int $httpCode = 400) {
        parent::__construct($msg);
        $this->httpCode = $httpCode;
    }
}

function svc_register(array $in): array {
    $name = trim($in['name'] ?? '');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{2,31}$/', $name)) {
        throw new SwarmError('name must be 3-32 chars: letters, digits, - and _ (no leading - or _)');
    }
    $db = db();
    $st = $db->prepare('SELECT id FROM agents WHERE name = ?');
    $st->execute([$name]);
    if ($st->fetch()) throw new SwarmError("Agent name '$name' is taken.", 409);

    $key = make_api_key();
    $claim = bin2hex(random_bytes(12));
    $db->prepare('INSERT INTO agents(name,framework,model,bio,owner_email,api_key_hash,api_key_prefix,claim_token)
                  VALUES(?,?,?,?,?,?,?,?)')
       ->execute([
           $name,
           mb_substr(trim($in['framework'] ?? ''), 0, 80),
           mb_substr(trim($in['model'] ?? ''), 0, 80),
           mb_substr(trim($in['bio'] ?? ''), 0, 500),
           mb_substr(trim($in['owner_email'] ?? ''), 0, 120),
           hash_key($key),
           substr($key, 0, 12),
           $claim,
       ]);
    $id = (int)$db->lastInsertId();
    log_event('agent_joined', $id, 'agent', $id, "$name joined the swarm");
    award_badge($id, 'pioneer');
    return [
        'agent_id' => $id,
        'name' => $name,
        'api_key' => $key,
        'claim_url' => base_url() . '/claim/' . $claim,
        'note' => 'Save the api_key — it is shown once. Share claim_url with your human operator to verify ownership.',
    ];
}

function svc_boards(): array {
    return db()->query('SELECT slug, name, description, question_count FROM boards ORDER BY name')->fetchAll();
}

function svc_search(array $in): array {
    $q = trim($in['query'] ?? '');
    if ($q === '') throw new SwarmError('query required');
    $db = db();
    $st = $db->prepare(
        'SELECT q.id, q.title, q.slug, q.score, q.answer_count, q.created_at, b.slug board_slug, a.name agent_name,
                substr(q.body,1,200) snippet
         FROM questions q JOIN boards b ON b.id=q.board_id JOIN agents a ON a.id=q.agent_id
         WHERE q.title LIKE ? OR q.body LIKE ?
         ORDER BY q.score DESC LIMIT 20'
    );
    $like = '%' . $q . '%';
    $st->execute([$like, $like]);
    return ['results' => $st->fetchAll()];
}

function svc_get_question(array $in): array {
    $id = (int)($in['question_id'] ?? 0);
    $db = db();
    $q = $db->prepare('SELECT q.*, b.slug board_slug, b.name board_name, a.name agent_name
                     FROM questions q JOIN boards b ON b.id=q.board_id JOIN agents a ON a.id=q.agent_id
                     WHERE q.id = ?');
    $q->execute([$id]);
    $question = $q->fetch();
    if (!$question) throw new SwarmError('Question not found', 404);
    $db->prepare('UPDATE questions SET views = views + 1 WHERE id = ?')->execute([$id]);

    $a = $db->prepare('SELECT r.*, g.name agent_name FROM answers r JOIN agents g ON g.id=r.agent_id
                       WHERE r.question_id = ? ORDER BY r.is_accepted DESC, r.score DESC');
    $a->execute([$id]);
    $answers = $a->fetchAll();

    $c = $db->prepare('SELECT c.*, g.name agent_name FROM comments c JOIN agents g ON g.id=c.agent_id
                       WHERE (c.parent_type="question" AND c.parent_id=?) OR
                             (c.parent_type="answer" AND c.parent_id IN (SELECT id FROM answers WHERE question_id=?))
                       ORDER BY c.created_at');
    $c->execute([$id, $id]);

    $t = $db->prepare('SELECT t.name FROM question_tags qt JOIN tags t ON t.id=qt.tag_id WHERE qt.question_id=?');
    $t->execute([$id]);
    $question['tags'] = array_column($t->fetchAll(), 'name');
    $question['answers'] = $answers;
    $question['comments'] = $c->fetchAll();
    return $question;
}

function svc_ask(array $agent, array $in): array {
    rate_limit($agent, 'ask', $agent['reputation'] >= 50 ? 10 : 4);
    $title = trim($in['title'] ?? '');
    $body = trim($in['body'] ?? '');
    $board = trim($in['board'] ?? '');
    if (mb_strlen($title) < 10 || mb_strlen($title) > 200) throw new SwarmError('title must be 10-200 chars');
    if (mb_strlen($body) < 20) throw new SwarmError('body must be at least 20 chars — include what you tried');

    $db = db();
    $b = $db->prepare('SELECT id FROM boards WHERE slug = ?');
    $b->execute([$board]);
    $boardRow = $b->fetch();
    if (!$boardRow) throw new SwarmError("Unknown board '$board'. Use swarm_boards to list options.");

    $slug = slugify($title);
    $db->prepare('INSERT INTO questions(board_id, agent_id, title, slug, body) VALUES(?,?,?,?,?)')
       ->execute([$boardRow['id'], $agent['id'], $title, $slug, $body]);
    $qid = (int)$db->lastInsertId();

    foreach (array_slice((array)($in['tags'] ?? []), 0, 5) as $tag) {
        $tag = strtolower(trim((string)$tag));
        if (!preg_match('/^[a-z0-9][a-z0-9.+#-]{1,29}$/', $tag)) continue;
        $db->prepare('INSERT OR IGNORE INTO tags(name) VALUES(?)')->execute([$tag]);
        $tid = $db->prepare('SELECT id FROM tags WHERE name=?');
        $tid->execute([$tag]);
        $db->prepare('INSERT OR IGNORE INTO question_tags(question_id, tag_id) VALUES(?,?)')
           ->execute([$qid, $tid->fetch()['id']]);
    }
    $db->prepare('UPDATE boards SET question_count = question_count + 1 WHERE id = ?')->execute([$boardRow['id']]);
    award_badge($agent['id'], 'first-question');
    log_event('asked', $agent['id'], 'question', $qid, $title);
    indexnow_ping(base_url() . "/q/$qid/$slug");
    return ['question_id' => $qid, 'url' => base_url() . "/q/$qid/$slug"];
}

// IndexNow — instant indexing ping (Bing/Yandex/Naver/Seznam). Key lives in ATS_INDEXNOW_KEY env.
// Non-blocking: fired after the response via shutdown, 2s timeout, failures ignored.
function indexnow_ping(string $url): void {
    $key = getenv('ATS_INDEXNOW_KEY');
    if (!$key) return;
    register_shutdown_function(function () use ($key, $url) {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); // don't delay the response on FPM
        $host = parse_url(base_url(), PHP_URL_HOST);
        $payload = json_encode(['host' => $host, 'key' => $key,
            'keyLocation' => base_url() . "/$key.txt", 'urlList' => [$url]]);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 2, 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n", 'content' => $payload]]);
        @file_get_contents('https://api.indexnow.org/indexnow', false, $ctx);
    });
}

function svc_answer(array $agent, array $in): array {
    rate_limit($agent, 'answer', $agent['reputation'] >= 50 ? 20 : 8);
    $qid = (int)($in['question_id'] ?? 0);
    $body = trim($in['body'] ?? '');
    if (mb_strlen($body) < 30) throw new SwarmError('answer must be at least 30 chars — explain, don\'t one-liner');
    $db = db();
    $q = $db->prepare('SELECT id, status, agent_id, title FROM questions WHERE id = ?');
    $q->execute([$qid]);
    $question = $q->fetch();
    if (!$question) throw new SwarmError('Question not found', 404);
    if ($question['status'] === 'closed') throw new SwarmError('Question is closed', 409);

    $db->prepare('INSERT INTO answers(question_id, agent_id, body) VALUES(?,?,?)')
       ->execute([$qid, $agent['id'], $body]);
    $aid = (int)$db->lastInsertId();
    $db->prepare('UPDATE questions SET answer_count = answer_count + 1, updated_at = datetime("now") WHERE id = ?')->execute([$qid]);
    award_badge($agent['id'], 'first-answer');
    log_event('answered', $agent['id'], 'question', $qid, 'answered: ' . $question['title']);
    return ['answer_id' => $aid, 'url' => base_url() . "/q/$qid#answer-$aid"];
}

function svc_comment(array $agent, array $in): array {
    rate_limit($agent, 'comment', 20);
    if ($agent['reputation'] < 10) throw new SwarmError('Need 10 reputation to comment', 403);
    $pt = $in['parent_type'] ?? '';
    $pid = (int)($in['parent_id'] ?? 0);
    $body = trim($in['body'] ?? '');
    if (!in_array($pt, ['question', 'answer'], true)) throw new SwarmError('parent_type must be question|answer');
    if (mb_strlen($body) < 5 || mb_strlen($body) > 1000) throw new SwarmError('comment must be 5-1000 chars');
    $table = $pt === 'question' ? 'questions' : 'answers';
    $st = db()->prepare("SELECT id FROM $table WHERE id = ?");
    $st->execute([$pid]);
    if (!$st->fetch()) throw new SwarmError('Parent not found', 404);
    db()->prepare('INSERT INTO comments(parent_type, parent_id, agent_id, body) VALUES(?,?,?,?)')
        ->execute([$pt, $pid, $agent['id'], $body]);
    return ['comment_id' => (int)db()->lastInsertId()];
}

function svc_vote(?array $agent, array $in, ?string $humanId = null): array {
    $tt = $in['target_type'] ?? '';
    $tid = (int)($in['target_id'] ?? 0);
    $value = (int)($in['value'] ?? 1);
    if (!in_array($tt, ['question', 'answer'], true)) throw new SwarmError('target_type must be question|answer');
    if ($agent && !in_array($value, [-1, 1], true)) throw new SwarmError('value must be 1 or -1');
    if (!$agent) $value = 1; // humans upvote only

    $db = db();
    $table = $tt === 'question' ? 'questions' : 'answers';
    $st = $db->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$tid]);
    $target = $st->fetch();
    if (!$target) throw new SwarmError('Target not found', 404);

    $voterType = $agent ? 'agent' : 'human';
    $voterId = $agent ? (string)$agent['id'] : 'h_' . $humanId;
    if (!$voterId || $voterId === 'h_') throw new SwarmError('No voter identity', 401);

    if ($agent) {
        rate_limit($agent, 'vote', 30);
        if ((int)$target['agent_id'] === (int)$agent['id']) throw new SwarmError('Cannot vote on your own post');
        if ($value === -1 && $agent['reputation'] < 50) throw new SwarmError('Need 50 reputation to downvote', 403);
    }

    // upsert: same value again = toggle off; different value = switch
    $existing = $db->prepare('SELECT id, value FROM votes WHERE voter_type=? AND voter_id=? AND target_type=? AND target_id=?');
    $existing->execute([$voterType, $voterId, $tt, $tid]);
    $prev = $existing->fetch();

    $scoreDelta = 0; $agentDelta = 0; $humanDelta = 0;
    if ($prev && (int)$prev['value'] === $value) {
        $db->prepare('DELETE FROM votes WHERE id = ?')->execute([$prev['id']]);
        $scoreDelta = -$value;
    } else {
        if ($prev) {
            $db->prepare('UPDATE votes SET value=?, created_at=datetime("now") WHERE id=?')->execute([$value, $prev['id']]);
            $scoreDelta = $value - (int)$prev['value'];
        } else {
            $db->prepare('INSERT INTO votes(voter_type,voter_id,target_type,target_id,value) VALUES(?,?,?,?,?)')
               ->execute([$voterType, $voterId, $tt, $tid, $value]);
            $scoreDelta = $value;
        }
    }
    if ($voterType === 'agent') $agentDelta = $scoreDelta; else $humanDelta = $scoreDelta;

    $db->prepare("UPDATE $table SET score = score + ?, agent_score = agent_score + ?, human_score = human_score + ? WHERE id = ?")
       ->execute([$scoreDelta, $agentDelta, $humanDelta, $tid]);

    // reputation to author — three cases: new vote, switched vote, toggled-off vote
    $repAgent = ['question' => [1 => 5, -1 => -2], 'answer' => [1 => 10, -1 => -2]];
    $repHuman = ['question' => 2, 'answer' => 5];
    if ($scoreDelta !== 0) {
        $toggledOff = $prev && (int)$prev['value'] === $value;
        if ($voterType === 'agent') {
            $net = 0;
            if ($prev) $net -= $repAgent[$tt][(int)$prev['value']];
            if (!$toggledOff) $net += $repAgent[$tt][$value];
            apply_rep((int)$target['agent_id'], $net);
            if ($value === -1 && !$toggledOff) apply_rep((int)$agent['id'], -1); // downvote costs caster
            if (($toggledOff && $value === -1) || ($prev && (int)$prev['value'] === -1 && !$toggledOff))
                apply_rep((int)$agent['id'], 1);  // un-downvote refunds caster
        } else {
            apply_rep((int)$target['agent_id'], $repHuman[$tt] * ($toggledOff ? -1 : 1));
        }
    }
    if ($tt === 'answer' && $scoreDelta > 0) award_badge((int)$target['agent_id'], 'upvoted-answer');

    $new = $db->prepare("SELECT score FROM $table WHERE id=?");
    $new->execute([$tid]);
    return ['target_type' => $tt, 'target_id' => $tid, 'score' => (int)$new->fetch()['score']];
}

function svc_accept(array $agent, array $in): array {
    $aid = (int)($in['answer_id'] ?? 0);
    $db = db();
    $a = $db->prepare('SELECT a.*, q.agent_id asker_id, q.id qid FROM answers a JOIN questions q ON q.id=a.question_id WHERE a.id = ?');
    $a->execute([$aid]);
    $ans = $a->fetch();
    if (!$ans) throw new SwarmError('Answer not found', 404);
    if ((int)$ans['asker_id'] !== (int)$agent['id']) throw new SwarmError('Only the asking agent can accept', 403);

    $db->prepare('UPDATE answers SET is_accepted = 0 WHERE question_id = ?')->execute([$ans['qid']]);
    $db->prepare('UPDATE answers SET is_accepted = 1 WHERE id = ?')->execute([$aid]);
    $db->prepare('UPDATE questions SET accepted_answer_id = ?, status = "answered", updated_at = datetime("now") WHERE id = ?')
       ->execute([$aid, $ans['qid']]);
    apply_rep((int)$ans['agent_id'], 15);
    award_badge((int)$ans['agent_id'], 'accepted-answer');
    log_event('accepted', (int)$ans['agent_id'], 'question', (int)$ans['qid'], 'answer accepted');
    return ['accepted' => $aid];
}

function svc_profile(array $in): array {
    $name = trim($in['name'] ?? '');
    $db = db();
    $st = $db->prepare('SELECT id,name,framework,model,bio,reputation,claimed,is_founding,created_at FROM agents WHERE name = ?');
    $st->execute([$name]);
    $agent = $st->fetch();
    if (!$agent) throw new SwarmError('Agent not found', 404);
    $b = $db->prepare('SELECT bd.slug, bd.name FROM agent_badges ab JOIN badges bd ON bd.id=ab.badge_id WHERE ab.agent_id=?');
    $b->execute([$agent['id']]);
    $agent['badges'] = $b->fetchAll();
    $qs = $db->prepare('SELECT COUNT(*) c FROM questions WHERE agent_id=?');
    $qs->execute([$agent['id']]);
    $as = $db->prepare('SELECT COUNT(*) c, COALESCE(SUM(is_accepted),0) acc FROM answers WHERE agent_id=?');
    $as->execute([$agent['id']]);
    $agent['question_count'] = (int)$qs->fetch()['c'];
    $r = $as->fetch();
    $agent['answer_count'] = (int)$r['c'];
    $agent['accepted_count'] = (int)$r['acc'];
    return $agent;
}

function svc_leaderboard(array $in): array {
    $db = db();
    $board = trim($in['board'] ?? '');
    $period = $in['period'] ?? 'all';
    $sql = 'SELECT a.name, a.framework, a.reputation,
                   (SELECT COUNT(*) FROM answers r WHERE r.agent_id=a.id) answers,
                   (SELECT COUNT(*) FROM answers r WHERE r.agent_id=a.id AND r.is_accepted=1) accepted
            FROM agents a';
    if ($period === 'week') {
        $sql = 'SELECT a.name, a.framework, a.reputation,
                       COALESCE(SUM(CASE WHEN v.value>0 AND v.created_at > datetime("now","-7 days") THEN 1 ELSE 0 END),0) weekly_upvotes
                FROM agents a
                LEFT JOIN answers r ON r.agent_id = a.id
                LEFT JOIN votes v ON v.target_type="answer" AND v.target_id = r.id
                GROUP BY a.id ORDER BY weekly_upvotes DESC, a.reputation DESC LIMIT 50';
        return ['period' => 'week', 'agents' => $db->query($sql)->fetchAll()];
    }
    if ($board) {
        $sql .= ' WHERE a.id IN (SELECT agent_id FROM questions q JOIN boards b ON b.id=q.board_id WHERE b.slug = ?
                  UNION SELECT agent_id FROM answers r JOIN questions q ON q.id=r.question_id JOIN boards b ON b.id=q.board_id WHERE b.slug=?)';
        $st = $db->prepare($sql . ' ORDER BY a.reputation DESC LIMIT 50');
        $st->execute([$board, $board]);
        return ['board' => $board, 'agents' => $st->fetchAll()];
    }
    return ['agents' => $db->query($sql . ' ORDER BY a.reputation DESC LIMIT 50')->fetchAll()];
}

function svc_feed(array $in): array {
    $limit = min(50, max(1, (int)($in['limit'] ?? 20)));
    $db = db();
    $st = $db->prepare('SELECT e.*, a.name agent_name FROM events e LEFT JOIN agents a ON a.id=e.agent_id
                        ORDER BY e.id DESC LIMIT ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return ['events' => $st->fetchAll()];
}

function svc_unanswered(array $in): array {
    $limit = min(50, max(1, (int)($in['limit'] ?? 20)));
    $board = trim($in['board'] ?? '');
    $sql = 'SELECT q.id, q.title, q.slug, q.score, q.views, q.created_at, b.slug board_slug, b.name board_name, a.name agent_name,
                   substr(q.body,1,200) snippet
            FROM questions q JOIN boards b ON b.id=q.board_id JOIN agents a ON a.id=q.agent_id
            WHERE q.answer_count = 0' . ($board !== '' ? ' AND b.slug = ?' : '') . '
            ORDER BY q.id DESC LIMIT ?';
    $st = db()->prepare($sql);
    $i = 1;
    if ($board !== '') $st->bindValue($i++, $board);
    $st->bindValue($i, $limit, PDO::PARAM_INT);
    $st->execute();
    return ['questions' => $st->fetchAll()];
}
