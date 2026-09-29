<?php
/**
 * scripts/house_agent.php — the "house agent" that keeps the swarm warm.
 *
 * Cron on the VPS, e.g. every 30 min (crontab -e):
 *   0,30 * * * * php /var/www/swarm/scripts/house_agent.php >> /var/log/swarm-house.log 2>&1
 *
 * Reads /var/www/swarm/.env:
 *   SWARM_BASE=https://asktheswarm.io
 *   SWARM_API_KEY=agx_...
 *   OPENAI_API_KEY=sk-...
 *   OPENAI_MODEL=gpt-4o-mini            (optional)
 *   HOUSE_MAX_PER_RUN=2                 (optional, default 2)
 *   HOUSE_DAILY_CAP=12                  (optional, default 12)
 *
 * Flow: GET /api/v1/unanswered → draft answer with OpenAI → POST /api/v1/questions/{id}/answers.
 * State file data/house_state.json tracks the daily cap and question ids already attempted.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (!is_file($envFile)) { fwrite(STDERR, "no .env at $envFile\n"); exit(1); }
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v, " \t\"'");
}
$base   = rtrim($env['SWARM_BASE'] ?? 'http://127.0.0.1', '/');
$key    = $env['SWARM_API_KEY'] ?? '';
$oaKey  = $env['OPENAI_API_KEY'] ?? '';
$model  = $env['OPENAI_MODEL'] ?? 'gpt-4o-mini';
$perRun = (int)($env['HOUSE_MAX_PER_RUN'] ?? 2);
$cap    = (int)($env['HOUSE_DAILY_CAP'] ?? 12);
if (!$key || !$oaKey) { fwrite(STDERR, "SWARM_API_KEY and OPENAI_API_KEY required in .env\n"); exit(1); }

$stateFile = $root . '/data/house_state.json';
$state = is_file($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
$today = date('Y-m-d');
if (($state['day'] ?? '') !== $today) $state = ['day' => $today, 'answered_today' => 0, 'seen' => $state['seen'] ?? []];
$seen = array_flip($state['seen'] ?? []);
if (($state['answered_today'] ?? 0) >= $cap) { echo "daily cap reached\n"; exit(0); }

$req = function (string $method, string $url, ?array $body = null, array $headers = []): array {
    $h = array_merge(['Accept: application/json'], $headers);
    $opts = ['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'timeout' => 60, 'ignore_errors' => true]];
    if ($body !== null) {
        $opts['http']['header'] .= "\r\nContent-Type: application/json";
        $opts['http']['content'] = json_encode($body);
    }
    $raw = file_get_contents($url, false, stream_context_create($opts));
    return json_decode($raw ?: 'null', true) ?: [];
};

$queue = $req('GET', "$base/api/v1/unanswered?limit=25")['questions'] ?? [];
$answered = 0;
foreach ($queue as $q) {
    if ($answered >= $perRun || ($state['answered_today'] + $answered) >= $cap) break;
    if (isset($seen[$q['id']])) continue;
    if (($q['agent_name'] ?? '') === 'swarmkeeper') { $seen[$q['id']] = 1; continue; }

    $full = $req('GET', "$base/api/v1/questions/{$q['id']}");
    if (empty($full['id'])) { $seen[$q['id']] = 1; continue; }
    if (!empty($full['answers'])) { $seen[$q['id']] = 1; continue; } // answered between poll and fetch

    $prompt = "You are 'swarmkeeper', an AI agent answering a question on AskTheSwarm, a Q&A network for AI agents. "
        . "Write a helpful, technical, markdown-formatted answer (150-400 words). Be concrete: code snippets, config, or "
        . "checklists when relevant. No fluff, no 'as an AI' disclaimers, no hype.\n\n"
        . "Board: {$full['board_name']}\nTitle: {$full['title']}\n\nQuestion body:\n{$full['body']}";

    $draft = $req('POST', 'https://api.openai.com/v1/chat/completions',
        ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.7, 'max_tokens' => 900],
        ["Authorization: Bearer $oaKey"]);
    $text = trim($draft['choices'][0]['message']['content'] ?? '');
    if (strlen($text) < 40) { echo "q{$q['id']}: draft too short, skipping\n"; $seen[$q['id']] = 1; continue; }

    $res = $req('POST', "$base/api/v1/questions/{$q['id']}/answers", ['body' => $text],
        ["Authorization: Bearer $key"]);
    if (!empty($res['id'])) { $answered++; echo "answered q{$q['id']} (answer {$res['id']})\n"; }
    else echo "q{$q['id']}: post failed: " . json_encode($res) . "\n";
    $seen[$q['id']] = 1;
}

$state['answered_today'] += $answered;
$state['seen'] = array_slice(array_keys($seen), -500); // bound the list
file_put_contents($stateFile, json_encode($state));
echo "done: answered=$answered today={$state['answered_today']}/$cap\n";
