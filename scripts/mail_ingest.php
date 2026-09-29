<?php
// scripts/mail_ingest.php — postfix pipe transport: stdin = raw RFC822, appends parsed JSONL
// to data/mailbox.jsonl (www-data-writable). Extracts links + numeric codes for verification flows.
$store = '/var/spool/swarm-mail/mailbox.jsonl'; // swarmmail:www-data 2775 — postfix forward-pipe writes, FPM reads
$raw = stream_get_contents(STDIN);
$recipient = $argv[1] ?? '';

[$head, $body] = preg_split("/\r?\n\r?\n/", $raw, 2) + [1 => ''];
$hdrs = [];
foreach (preg_split('/\r?\n/', $head) as $line) {
    if (preg_match('/^(\S+):\s*(.*)/', $line, $m)) $hdrs[strtolower($m[1])] = $m[2];
    elseif (preg_match('/^\s+(.*)/', $line, $m) && $hdrs) $hdrs[array_key_last($hdrs)] .= ' ' . trim($m[1]);
}
$dec = fn($h) => trim(mb_decode_mimeheader($h ?? ''));

// extract best text body
$text = '';
if (preg_match('/boundary="?([^";\s]+)/i', $head, $bm)) {
    foreach (explode('--' . $bm[1], $body) as $part) {
        if (preg_match('/Content-Type:\s*text\/plain/i', $part)) {
            [$ph, $pb] = preg_split("/\r?\n\r?\n/", $part, 2) + [1 => ''];
            $text = stripos($ph, 'base64') !== false ? base64_decode($pb) :
                   (stripos($ph, 'quoted-printable') !== false ? quoted_printable_decode($pb) : $pb);
            break;
        }
    }
    if ($text === '') { // html-only multipart
        foreach (explode('--' . $bm[1], $body) as $part) {
            if (preg_match('/Content-Type:\s*text\/html/i', $part)) {
                [$ph, $pb] = preg_split("/\r?\n\r?\n/", $part, 2) + [1 => ''];
                $hb = stripos($ph, 'base64') !== false ? base64_decode($pb) :
                     (stripos($ph, 'quoted-printable') !== false ? quoted_printable_decode($pb) : $pb);
                $text = strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/tr)[^>]*>/i', "\n", $hb));
                break;
            }
        }
    }
}
if ($text === '') {
    $cte = strtolower($hdrs['content-transfer-encoding'] ?? '');
    $text = $cte === 'base64' ? base64_decode($body) : ($cte === 'quoted-printable' ? quoted_printable_decode($body) : $body);
    if (stripos($hdrs['content-type'] ?? '', 'text/html') !== false)
        $text = strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/tr)[^>]*>/i', "\n", $text));
}
$text = trim(str_replace("\r", '', (string)$text));

preg_match_all('#https?://[^\s<>"\')]+#', $text, $urls);
preg_match_all('/\b\d{4,8}\b/', $text, $codes);

$msg = [
    'ts' => time(), 'date' => date('c'),
    'from' => $dec($hdrs['from'] ?? ''), 'to' => $dec($hdrs['x-original-to'] ?? $hdrs['to'] ?? $recipient),
    'subject' => $dec($hdrs['subject'] ?? ''),
    'body' => mb_substr($text, 0, 4000),
    'links' => array_values(array_unique($urls[0] ?? [])),
    'codes' => array_values(array_unique($codes[0] ?? [])),
];
file_put_contents($store, json_encode($msg, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
@chmod($store, 0664); // postfix pipes with umask 077 — www-data group needs read
