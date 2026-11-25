<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
/** Original, tiny fixtures; no copyrighted book or recording is copied into tests. */
function reading_test_pdf(string $path,int $count=3): void
{
 $font=$count*2+3;$kids=implode(' ',array_map(fn($page)=>($page*2+1).' 0 R',range(1,$count)));
 $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>"<< /Type /Pages /Kids [$kids] /Count $count >>"];
 for($page=1;$page<=$count;$page++){$object=$page*2+1;$stream="BT /F1 22 Tf 60 710 Td (BookTrack reading test - page $page) Tj 0 -42 Td /F1 12 Tf (This original test document checks saved pages and bookmarks.) Tj ET";$objects[$object]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 $font 0 R >> >> /Contents ".($object+1).' 0 R >>';$objects[$object+1]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";}
 $objects[$font]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $id=>$body){$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$body\nendobj\n";}$xref=strlen($pdf);$size=$font+1;$pdf.="xref\n0 $size\n0000000000 65535 f \n";for($i=1;$i<=$font;$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);$pdf.="trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";file_put_contents($path,$pdf);
}
function reading_test_audio(string $path): void
{
 $data=str_repeat("\0\0",8000*12);$header='RIFF'.pack('V',36+strlen($data)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($data));file_put_contents($path,$header.$data);
}
