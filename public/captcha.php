<?php

declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');

$a = random_int(1, 9);
$b = random_int(1, 9);
$_SESSION['captcha_sum'] = $a + $b;

$text = $a . ' + ' . $b;

$width = 140;
$height = 48;
$image = imagecreatetruecolor($width, $height);

$bg = imagecolorallocate($image, 244, 246, 251);
imagefilledrectangle($image, 0, 0, $width, $height, $bg);

for ($i = 0; $i < 8; $i++) {
    $lineColor = imagecolorallocate($image, random_int(190, 225), random_int(190, 225), random_int(190, 225));
    imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $lineColor);
}

$font = 5;
$charWidth = imagefontwidth($font);
$charHeight = imagefontheight($font);
$totalWidth = $charWidth * strlen($text);
$x = (int) (($width - $totalWidth) / 2);
$y = (int) (($height - $charHeight) / 2);

for ($i = 0, $len = strlen($text); $i < $len; $i++) {
    $charColor = imagecolorallocate($image, random_int(20, 70), random_int(20, 70), random_int(30, 100));
    imagechar($image, $font, $x + $i * $charWidth, $y + random_int(-4, 4), $text[$i], $charColor);
}

for ($i = 0; $i < 60; $i++) {
    $dotColor = imagecolorallocate($image, random_int(140, 200), random_int(140, 200), random_int(140, 200));
    imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $dotColor);
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
imagepng($image);
imagedestroy($image);
