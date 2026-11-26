<?php
/** URL-only tests: no database or server is touched. */
$testBase='/booktrack-v2/';
function base_url():string{return $GLOBALS['testBase'];}
function input(string $key,$default=''):string{return is_string($_POST[$key]??$_GET[$key]??$default)?($_POST[$key]??$_GET[$key]??$default):(string)$default;}
function url(string $path=''):string{return base_url().ltrim($path,'/');}
require __DIR__.'/../includes/navigation.php';
$passed=0;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['passed']++;echo "PASS $message\n";}
$_SERVER=['HTTP_HOST'=>'localhost','REQUEST_URI'=>'/booktrack-v2/book.php?id=7'];$_GET=$_POST=[];
check(navigation_safe_return('/booktrack-v2/discover.php?q=habit&page=2#shelf-h')==='/booktrack-v2/discover.php?q=habit&page=2#shelf-h','local filters and fragments retained');
check(navigation_safe_return('http://localhost/booktrack-v2/discover.php')==='/booktrack-v2/discover.php','same-origin referrer accepted');
foreach(['https://other.example/booktrack-v2/discover.php','//other.example/discover.php','http://localhost:8000/booktrack-v2/discover.php','http://localhost.evil/booktrack-v2/discover.php','javascript:alert(1)','/outside/discover.php','/booktrack-v2/api/favorite.php','/booktrack-v2/logout.php','/booktrack-v2/../discover.php','/booktrack-v2/%2e%2e/discover.php',"/booktrack-v2/discover.php\r\nX-Test:1",'/booktrack-v2\\discover.php'] as $bad)check(navigation_safe_return($bad)===null,'unsafe return refused: '.json_encode($bad));
$_GET=['return_to'=>'/booktrack-v2/discover.php'];check(navigation_back('discover.php?view=all','Back to catalogue')===['href'=>'/booktrack-v2/discover.php','label'=>'Back to dashboard'],'dashboard origin wins over catalogue fallback');
$_GET=['return_to'=>'/booktrack-v2/discover.php?view=all&q=habit&sort=title'];check(navigation_back('discover.php','Back')['href']===$_GET['return_to'],'filtered catalogue origin retained');
$_GET=['return_to'=>'/booktrack-v2/favorites.php'];check(navigation_back('discover.php','Back')['label']==='Back to favorites','favorites origin labelled');
$_GET=[];$_SERVER['HTTP_REFERER']='http://localhost/booktrack-v2/loans.php';check(navigation_back('discover.php','Back')['label']==='Back to My loans','referrer fallback works without script');
$_POST=['return_to'=>'/booktrack-v2/discover.php?category=Fiction'];check(navigation_back('discover.php','Back')['href']===$_POST['return_to'],'form submission retains original source');
$_POST=[];$_SERVER['HTTP_REFERER']='http://localhost/booktrack-v2/book.php?id=7&return_to=%2Fbooktrack-v2%2Fdiscover.php';check(navigation_back('discover.php?view=all','Back')['href']==='/booktrack-v2/discover.php?view=all','same-book referrer cannot create a back loop');
$_GET=['return_to'=>'/booktrack-v2/book.php?id=14&return_to=%2Fbooktrack-v2%2Fdiscover.php'];check(navigation_back('discover.php','Back')['href']===$_GET['return_to'],'nested related-book route retained');
$testBase='/';$_GET=['return_to'=>'/discover.php'];$_SERVER['REQUEST_URI']='/book.php?id=7';check(navigation_back('discover.php?view=all','Back')['href']==='/discover.php','root-hosted deployment works');
$_SERVER['SCRIPT_NAME']='/book.php';
check(member_navigation_section(false,'discover','/discover.php')==='discover','book opened from dashboard keeps Home active');
check(member_navigation_section(false,'discover','/discover.php?view=all&q=habit')==='catalog','book opened from catalogue keeps catalogue active');
check(member_navigation_section(false,'discover','/book.php?id=7&return_to=%2Floans.php')==='loans','nested detail retains original loans section');
check(member_navigation_section(false,'discover','https://outside.example/discover.php')==='catalog','external context cannot select a navigation section');
$_SERVER['SCRIPT_NAME']='/discover.php';
check(member_navigation_section(false,'discover','/book.php?id=7')==='catalog','catalogue filtering remains in the catalogue section');
check(member_navigation_section(true,'discover','')==='discover','dashboard always keeps Home active');
echo "$passed passed, 0 failed\n";
