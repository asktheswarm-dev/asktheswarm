<?php
// lib/markdown.php — small safe markdown renderer (escapes HTML first)

function md_render(string $text): string {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // fenced code blocks → placeholders
    $blocks = [];
    $text = preg_replace_callback('/```(\w*)\n(.*?)```/s', function ($m) use (&$blocks) {
        $i = count($blocks);
        $lang = $m[1] ? ' class="lang-' . $m[1] . '"' : '';
        $blocks[$i] = '<pre><code' . $lang . '>' . $m[2] . '</code></pre>';
        return "\x00CODE$i\x00";
    }, $text);

    // inline code
    $text = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $text);

    // block-level: headings, lists, paragraphs
    $lines = explode("\n", $text);
    $out = '';
    $inList = false;
    $para = '';
    $flush = function () use (&$out, &$para, &$inList) {
        if ($para !== '') { $out .= '<p>' . md_inline($para) . '</p>'; $para = ''; }
        if ($inList) { $out .= '</ul>'; $inList = false; }
    };
    foreach ($lines as $line) {
        $t = trim($line);
        if (preg_match('/^\x00CODE\d+\x00$/', $t)) { $flush(); $out .= $t; continue; }
        if ($t === '') { $flush(); continue; }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            $flush();
            $lvl = min(strlen($m[1]) + 1, 6);
            $out .= "<h$lvl>" . md_inline($m[2]) . "</h$lvl>";
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
            if ($para !== '') { $out .= '<p>' . md_inline($para) . '</p>'; $para = ''; }
            if (!$inList) { $out .= '<ul>'; $inList = true; }
            $out .= '<li>' . md_inline($m[1]) . '</li>';
            continue;
        }
        if ($inList) { $out .= '</ul>'; $inList = false; }
        $para .= ($para === '' ? '' : ' ') . $t;
    }
    $flush();

    // restore code blocks
    $out = preg_replace_callback('/\x00CODE(\d+)\x00/', function ($m) use ($blocks) {
        return $blocks[(int)$m[1]];
    }, $out);
    return $out;
}

function md_inline(string $s): string {
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<!\w)\*([^*\n]+)\*(?!\w)/', '<em>$1</em>', $s);
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (!preg_match('#^https?://#i', $url)) return $m[1];
        return '<a href="' . h($url) . '" rel="nofollow ugc" target="_blank">' . $m[1] . '</a>';
    }, $s);
    return $s;
}
