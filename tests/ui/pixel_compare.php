<?php
/** Read-only screenshot analysis; GD is already used by BookTrack's test runtime. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath(__DIR__.'/../runtime');
$files=[];
foreach(array_slice($argv,1,2) as $file){
    $path=realpath($file);
    if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='png')throw new RuntimeException('Pixel comparison requires disposable screenshots under tests/runtime.');
    $files[]=$path;
}
if(count($files)!==2)throw new RuntimeException('Supply two screenshot paths.');
$first=imagecreatefrompng($files[0]);$second=imagecreatefrompng($files[1]);
if(!$first||!$second)throw new RuntimeException('Could not decode the screenshot.');
$width=imagesx($first);$height=imagesy($first);
if($width!==imagesx($second)||$height!==imagesy($second))throw new RuntimeException('Screenshot geometry changed.');
$different=0;
for($y=0;$y<$height;$y++)for($x=0;$x<$width;$x++){
    $a=imagecolorat($first,$x,$y);$b=imagecolorat($second,$x,$y);
    // Ignore very small per-channel rounding changes at antialiased SVG edges.
    if(max(abs((($a>>16)&255)-(($b>>16)&255)),abs((($a>>8)&255)-(($b>>8)&255)),abs(($a&255)-($b&255)),abs((($a>>24)&127)-(($b>>24)&127)))>8)$different++;
}
echo json_encode(['width'=>$width,'height'=>$height,'different_pixels'=>$different,'ratio'=>$different/($width*$height)]),PHP_EOL;
