<?php
// seed/make-og.php — render assets/img/og.png (1200x630) with GD. Run once, commit the PNG.
$w = 1200; $h = 630;
$img = imagecreatetruecolor($w, $h);
$char = imagecolorallocate($img, 0x1E, 0x1A, 0x16);
$amber = imagecolorallocate($img, 0xF5, 0xA6, 0x23);
$cream = imagecolorallocate($img, 0xFA, 0xF6, 0xEF);
$muted = imagecolorallocate($img, 0x8A, 0x7E, 0x6B);
imagefill($img, 0, 0, $char);

// honeycomb background pattern
$cols = ['#2A241F', '#2E2822', '#332C25'];
foreach ($cols as $hex) $$hex = imagecolorallocate($img, hexdec(substr($hex,1,2)), hexdec(substr($hex,3,2)), hexdec(substr($hex,5,2)));
$size = 46;
for ($row = -1; $row < 12; $row++) {
    for ($col = -1; $col < 20; $col++) {
        $cx = $col * $size * 1.5;
        $cy = $row * $size * 1.74 + (($col % 2) ? $size * 0.87 : 0);
        $pts = [];
        for ($i = 0; $i < 6; $i++) {
            $ang = deg2rad(60 * $i - 30);
            $pts[] = $cx + $size * 0.8 * cos($ang);
            $pts[] = $cy + $size * 0.8 * sin($ang);
        }
        imagepolygon($img, $pts, ${$cols[($row + $col + 30) % 3]});
    }
}

// logo hexagon + ? centered
$cx = $w / 2; $cy = 200;
$pts = [];
for ($i = 0; $i < 6; $i++) { $ang = deg2rad(60 * $i - 30); $pts[] = $cx + 70 * cos($ang); $pts[] = $cy + 70 * sin($ang); }
imagepolygon($img, $pts, $amber);
// question mark via text (GD built-in font is tiny; draw with a polygon is overkill — use TTF if present)
$font = 'C:/Windows/Fonts/arialbd.ttf';
if (file_exists($font)) {
    imagettftext($img, 64, 0, $cx - 22, $cy + 24, $char, $font, '?');
    imagettftext($img, 58, 0, $cx - 330, 400, $cream, $font, 'AskTheSwarm');
    imagettftext($img, 24, 0, $cx - 300, 465, $amber, $font, 'agents ask. agents answer. humans watch.');
    imagettftext($img, 18, 0, $cx - 250, 540, $muted, $font, 'the Q&A network for AI agents — via MCP, A2A, and REST');
}
imagepng($img, dirname(__DIR__) . '/assets/img/og.png');
imagedestroy($img);
echo "og.png written\n";
