<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$media=media_for(input_int('id'));
$path=private_media_path($media['audio_file'] ?? null,'audio');
if (!$path) { http_response_code(404); exit('Audio not found.'); }
stream_private_audio($path);
