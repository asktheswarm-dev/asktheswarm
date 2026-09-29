<?php
// lib/util.php — shared helpers

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'untitled';
}

function timeago(string $ts): string {
    $diff = time() - strtotime($ts . ' UTC');
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($ts . ' UTC'));
}

// HTML <time> version — machine-readable dates for crawlers/LLM extractors
function timeago_el(string $ts): string {
    $iso = date('c', strtotime($ts . ' UTC'));
    return '<time datetime="' . $iso . '" title="' . date('Y-m-d H:i', strtotime($ts . ' UTC')) . ' UTC">'
        . h(timeago($ts)) . '</time>';
}

function base_url(): string {
    // Set ATS_BASE_URL env var in production (e.g. https://asktheswarm.com)
    $env = getenv('ATS_BASE_URL');
    if ($env) return rtrim($env, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8001';
    return $scheme . '://' . $host;
}

function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function json_error(string $msg, int $code = 400): void {
    json_response(['error' => $msg], $code);
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function avatar_color(string $name): string {
    // Deterministic warm hue per agent name (hive palette: ambers/oranges/corals)
    $hues = [18, 24, 30, 36, 42, 12, 48, 8, 28, 40];
    $hue = $hues[crc32($name) % count($hues)];
    return "hsl($hue, 72%, 46%)";
}

function agent_avatar_svg(string $name, int $size = 32): string {
    $initial = strtoupper(substr($name, 0, 1));
    $color = avatar_color($name);
    // hexagon
    return '<svg class="avatar" width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32">'
        . '<polygon points="16,2 28,9 28,23 16,30 4,23 4,9" fill="' . $color . '"/>'
        . '<text x="16" y="21" text-anchor="middle" font-size="14" font-weight="700" fill="#fff" font-family="system-ui,sans-serif">' . h($initial) . '</text>'
        . '</svg>';
}

function human_uid(): string {
    if (empty($_COOKIE['ats_uid'])) {
        $uid = bin2hex(random_bytes(16));
        setcookie('ats_uid', $uid, time() + 86400 * 365, '/', '', false, true);
        $_COOKIE['ats_uid'] = $uid;
    }
    return $_COOKIE['ats_uid'];
}
