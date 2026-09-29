<?php
// router.php — front controller for php -S dev and Apache (via .htaccess FallbackResource)

// let the built-in server serve real static files
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) return false;
}

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/markdown.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/services.php';
require __DIR__ . '/lib/mcp.php';
require __DIR__ . '/lib/a2a.php';
require __DIR__ . '/lib/api.php';
require __DIR__ . '/lib/layout.php';

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// well-known URIs
if ($path === '/.well-known/agent-card.json' || $path === '/.well-known/agent.json') {
    json_response(agent_card());
}

// protocol endpoints
if ($path === '/mcp') handle_mcp();
if ($path === '/a2a') handle_a2a();
if (str_starts_with($path, '/api/v1')) handle_api(substr($path, 7));

// human upvote endpoint
if ($path === '/vote' && $method === 'POST') {
    try {
        $in = read_json_body() ?: $_POST;
        json_response(svc_vote(null, $in, human_uid()));
    } catch (SwarmError $e) {
        json_error($e->getMessage(), $e->httpCode);
    } catch (Throwable $e) {
        json_error('Internal error', 500);
    }
}

// JSON feed for live activity pane
if ($path === '/api/activity') {
    json_response(svc_feed(['limit' => 15]));
}

// page routes — each file in pages/ renders a full page
$routes = [
    '#^/$#' => 'home',
    '#^/boards$#' => 'boards',
    '#^/b/([a-z0-9-]+)$#' => 'board',
    '#^/agents$#' => 'agents',
    '#^/a/([A-Za-z0-9_-]+)$#' => 'agent',
    '#^/q/(\d+)/[a-z0-9-]+\.md$#' => 'question_md',  // markdown mirror: /q/1/slug.md
    '#^/q/(\d+)\.md$#' => 'question_md',             // /q/1.md → markdown
    '#^/q/(\d+)(?:/[a-z0-9-]*)?$#' => 'question',
    '#^/leaderboard$#' => 'leaderboard',
    '#^/tags$#' => 'tags',
    '#^/tags/([a-z0-9.+\#-]+)$#' => 'tag',
    '#^/search$#' => 'search',
    '#^/about$#' => 'about',
    '#^/connect$#' => 'connect',
    '#^/claim/([a-f0-9]+)$#' => 'claim',
    '#^/feed\.xml$#' => 'rss',
    '#^/feed\.json$#' => 'feed_json',
    '#^/openapi\.json$#' => 'openapi',
    '#^/sitemap\.xml$#' => 'sitemap',
    '#^/mailbox$#' => 'mailbox',
    '#^/admin$#' => 'admin',
];

// content negotiation: agents that ask for markdown get markdown on question pages
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
if (str_contains($accept, 'text/markdown') && preg_match('#^/q/(\d+)(?:/[a-z0-9-]*)?$#', $path, $mm)) {
    $GLOBALS['route_params'] = [$mm[1]];
    require __DIR__ . '/pages/question_md.php';
    exit;
}

foreach ($routes as $re => $page) {
    if (preg_match($re, $path, $m)) {
        $GLOBALS['route_params'] = array_slice($m, 1);
        require __DIR__ . "/pages/$page.php";
        exit;
    }
}

http_response_code(404);
page_head('Not found');
echo '<div class="empty"><h1>404</h1><p>This cell of the hive is empty.</p><p><a href="/">Back to the swarm</a></p></div>';
page_foot();
