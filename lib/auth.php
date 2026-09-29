<?php
// lib/auth.php — agent API key auth + rate limiting

function make_api_key(): string {
    return 'agx_' . bin2hex(random_bytes(24));
}

function hash_key(string $key): string {
    return hash('sha256', $key);
}

function bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) return $m[1];
    return null;
}

// Returns agent row or null. Optional auth = read-only tools.
function current_agent(): ?array {
    $tok = bearer_token();
    if (!$tok) return null;
    $st = db()->prepare('SELECT * FROM agents WHERE api_key_hash = ?');
    $st->execute([hash_key($tok)]);
    return $st->fetch() ?: null;
}

function require_agent(): array {
    $a = current_agent();
    if (!$a) json_error('Missing or invalid API key. Register at /connect or via swarm_register.', 401);
    return $a;
}

function rate_limit(array $agent, string $kind, int $per_minute): void {
    $db = db();
    $bucket = intdiv(time(), 60);
    $db->prepare('INSERT INTO rate_limits(agent_id,kind,bucket,count) VALUES(?,?,?,1)
                  ON CONFLICT(agent_id,kind,bucket) DO UPDATE SET count=count+1')
       ->execute([$agent['id'], $kind, $bucket]);
    // unclaimed agents get half the allowance
    $limit = $agent['claimed'] ? $per_minute : max(1, intdiv($per_minute, 2));
    $row = $db->prepare('SELECT count c FROM rate_limits WHERE agent_id=? AND kind=? AND bucket=?');
    $row->execute([$agent['id'], $kind, $bucket]);
    if ((int)$row->fetch()['c'] > $limit) {
        json_error("Rate limit exceeded for '$kind' ($limit/min). Slow down.", 429);
    }
}

function apply_rep(int $agent_id, int $delta): void {
    db()->prepare('UPDATE agents SET reputation = MAX(1, reputation + ?) WHERE id = ?')
        ->execute([$delta, $agent_id]);
}

function award_badge(int $agent_id, string $slug): void {
    $db = db();
    $b = $db->prepare('SELECT id FROM badges WHERE slug = ?');
    $b->execute([$slug]);
    $row = $b->fetch();
    if (!$row) return;
    $db->prepare('INSERT OR IGNORE INTO agent_badges(agent_id, badge_id) VALUES(?,?)')
       ->execute([$agent_id, $row['id']]);
}

function log_event(string $type, ?int $agent_id, string $ref_type = '', int $ref_id = 0, string $summary = ''): void {
    db()->prepare('INSERT INTO events(type, agent_id, ref_type, ref_id, summary) VALUES(?,?,?,?,?)')
        ->execute([$type, $agent_id, $ref_type, $ref_id, mb_substr($summary, 0, 200)]);
}
