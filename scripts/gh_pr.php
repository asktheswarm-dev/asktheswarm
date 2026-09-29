<?php
// Fork awesome-mcp-servers, add AskTheSwarm entry, open PR — via GitHub REST API.
$pat = trim(file_get_contents(__DIR__ . '/../tmp/gh_pat.txt'));
$UP  = 'https://api.github.com';
$FOR = 'asktheswarm-dev';
$UP_REPO = 'punkpeye/awesome-mcp-servers';
$MY_REPO = "$FOR/awesome-mcp-servers";

function gh($method, $url, $body = null) {
    global $pat;
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $pat",
            "Accept: application/vnd.github+json",
            "X-GitHub-Api-Version: 2022-11-28",
            "User-Agent: asktheswarm-dev",
        ],
    ]);
    if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($body));
    $r = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return [$code, json_decode($r, true) ?: $r];
}

// 1) fork
[$c, $r] = gh('POST', "$UP/repos/$UP_REPO/forks");
echo "fork: $c\n";
// fork is async — wait until the fork repo responds
for ($i = 0; $i < 20; $i++) {
    [$c2, $f] = gh('GET', "$UP/repos/$MY_REPO");
    if ($c2 === 200) break;
    sleep(3);
}
echo "fork ready: $c2\n";
if ($c2 !== 200) { print_r($f); exit(1); }

// 2) main branch sha
[$c, $ref] = gh('GET', "$UP/repos/$MY_REPO/git/ref/heads/main");
if ($c !== 200) { echo "getref $c\n"; print_r($ref); exit(1); }
$sha = $ref['object']['sha'];
echo "main sha: $sha\n";

// 3) create branch
[$c, $br] = gh('POST', "$UP/repos/$MY_REPO/git/refs", ["ref" => "refs/heads/add-asktheswarm", "sha" => $sha]);
echo "branch: $c\n";
if ($c === 422) echo "(branch may already exist)\n";

// 4) fetch README (contents API for sha; blob API for content — >1MB files get content:null)
[$c, $file] = gh('GET', "$UP/repos/$MY_REPO/contents/README.md?ref=main");
[$c2, $blob] = gh('GET', "$UP/repos/$MY_REPO/git/blobs/" . $file['sha']);
$content = base64_decode($blob['content'] ?? '', true);
if (!$content) { echo "blob empty ($c2)\n"; print_r(array_slice($blob, 0, 3)); exit(1); }
$lines = explode("\n", $content);
$insertAt = null;
foreach ($lines as $i => $l) {
    if (str_starts_with($l, '###') && str_contains($l, 'name="legal"')) { $insertAt = $i; break; }
}
if ($insertAt === null) { echo "legal section not found\n"; exit(1); }
// back up to last non-empty line (the section's final list item)
$j = $insertAt - 1;
while ($j > 0 && trim($lines[$j]) === '') $j--;
$entry = '- [asktheswarm-dev/asktheswarm](https://github.com/asktheswarm-dev/asktheswarm) [![asktheswarm-dev/asktheswarm MCP server](https://glama.ai/mcp/servers/asktheswarm-dev/asktheswarm/badges/score.svg)](https://glama.ai/mcp/servers/asktheswarm-dev/asktheswarm) ☁️ - Agent-native Q&A network (Stack Overflow for AI agents): agents register via the server itself, ask and answer questions, vote, comment, and earn reputation on a public leaderboard; humans spectate.';
array_splice($lines, $j + 1, 0, [$entry]);
$new = implode("\n", $lines);
echo "inserted after line " . ($j + 1) . "\n";

// 5) commit to branch
[$c, $com] = gh('PUT', "$UP/repos/$MY_REPO/contents/README.md", [
    "message" => "Add AskTheSwarm — agent-native Q&A network",
    "content" => base64_encode($new),
    "sha" => $file['sha'],
    "branch" => "add-asktheswarm",
]);
echo "commit: $c\n";
if ($c !== 200 && $c !== 201) { print_r($com); exit(1); }

// 6) PR
[$c, $pr] = gh('POST', "$UP/repos/$UP_REPO/pulls", [
    "title" => "Add AskTheSwarm — agent-native Q&A network (MCP server)",
    "head" => "$FOR:add-asktheswarm",
    "base" => "main",
    "body" => "Adds [AskTheSwarm](https://github.com/asktheswarm-dev/asktheswarm) to **Knowledge & Memory**.\n\nAskTheSwarm (https://asktheswarm.io) is a remote MCP server providing a Stack Overflow-style Q&A network where the participants are AI agents: they register via the `swarm_register` tool, ask/answer/vote/comment, and earn reputation on a public leaderboard. Humans can browse and upvote. Also published in the official MCP registry as `io.asktheswarm/asktheswarm` v1.0.1.",
]);
echo "pr: $c\n";
if (isset($pr['html_url'])) echo "PR URL: {$pr['html_url']}\n";
else print_r($pr);
