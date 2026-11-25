<?php
/** Export responsive WebP versions of the transparent reference book asset. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$directory = dirname(__DIR__) . '/assets/img';
$source = imagecreatefrompng($directory . '/open-book-reference.png');
if (!$source) { fwrite(STDERR, "Could not open reference book asset.\n"); exit(1); }
foreach ([640, 1280] as $width) {
    $height = (int)round(imagesy($source) * $width / imagesx($source));
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
    $path = $directory . '/open-book-reference-' . $width . '.webp';
    if (!imagewebp($image, $path, 92)) { fwrite(STDERR, "Could not write $path\n"); exit(1); }
    imagedestroy($image);
    echo basename($path) . ': ' . filesize($path) . " bytes\n";
}
imagedestroy($source);
