<?php
/** Build lightweight cover derivatives without changing the original artwork. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('imagewebp')) { fwrite(STDERR, "PHP GD with WebP support is required.\n"); exit(1); }
$root = dirname(__DIR__);
$destination = $root . '/uploads/covers/previews';
if (!is_dir($destination)) mkdir($destination, 0755, true);
$count = $before = $after = 0;
foreach (glob($root . '/uploads/covers/*.{jpg,jpeg,png,webp}', GLOB_BRACE) as $file) {
    $target = $destination . '/' . pathinfo($file, PATHINFO_FILENAME) . '.webp';
    if (is_file($target) && filemtime($target) >= filemtime($file)) continue;
    $source = @imagecreatefromstring((string)file_get_contents($file));
    if (!$source) continue;
    $width = min(400, imagesx($source));
    $height = (int)round(imagesy($source) * $width / imagesx($source));
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
    if (!imagewebp($image, $target, 88)) { fwrite(STDERR, "Could not write $target\n"); exit(1); }
    $count++;
    $before += filesize($file);
    $after += filesize($target);
    imagedestroy($source);
    imagedestroy($image);
}
echo "$count cover previews built: $before original bytes, $after preview bytes.\n";
