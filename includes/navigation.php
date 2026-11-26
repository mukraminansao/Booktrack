<?php
/** Return destinations are local, read-only application pages, never arbitrary redirects. */
function navigation_safe_return(string $value): ?string
{
    if ($value === '' || strlen($value)>8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $value) || str_starts_with($value,'//')) return null;
    $parts=parse_url($value);
    if ($parts===false || isset($parts['user']) || isset($parts['pass'])) return null;
    if (isset($parts['host']) || isset($parts['scheme'])) {
        $scheme=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http';
        $origin=parse_url($scheme.'://'.($_SERVER['HTTP_HOST']??''));
        if (($parts['scheme']??'')!==$scheme || strcasecmp($parts['host']??'', $origin['host']??'')!==0 || ($parts['port']??($scheme==='https'?443:80))!==($origin['port']??($scheme==='https'?443:80))) return null;
    }
    $path=$parts['path']??'';$base=base_url();
    if (!str_starts_with($path,$base) || preg_match('/%|\.\./',$path)) return null;
    $page=substr($path,strlen($base));
    $pages=['index.php','discover.php','book.php','reader.php','listen.php','reviews.php','loans.php','favorites.php','collection.php','profile.php','messages.php','fines.php','admin/index.php','admin/books.php','admin/media.php','admin/content.php','admin/members.php','admin/loans.php','admin/desk.php','admin/reports.php'];
    if (!in_array($page,$pages,true)) return null;
    return $path.(isset($parts['query'])?'?'.$parts['query']:'').(isset($parts['fragment'])?'#'.$parts['fragment']:'');
}

function navigation_back(string $fallback, string $label): array
{
    $current=navigation_safe_return($_SERVER['REQUEST_URI']??'');
    $samePage=static function(?string $target)use($current):bool{
        if(!$target||!$current||parse_url($target,PHP_URL_PATH)!==parse_url($current,PHP_URL_PATH))return false;
        parse_str((string)parse_url($target,PHP_URL_QUERY),$before);parse_str((string)parse_url($current,PHP_URL_QUERY),$now);
        return ($before['id']??null)===($now['id']??null)&&($before['book']??null)===($now['book']??null);
    };
    $target=navigation_safe_return(input('return_to'));
    if (!$target || $samePage($target)) $target=navigation_safe_return($_SERVER['HTTP_REFERER']??'');
    if (!$target || $samePage($target)) return ['href'=>url($fallback),'label'=>$label];
    $page=substr((string)parse_url($target,PHP_URL_PATH),strlen(base_url()));
    parse_str((string)parse_url($target,PHP_URL_QUERY),$query);
    $labels=['book.php'=>'Back to book','reader.php'=>'Back to reader','listen.php'=>'Back to listening','loans.php'=>'Back to My loans','favorites.php'=>'Back to favorites','collection.php'=>'Back to collection','reviews.php'=>'Back to reviews','admin/books.php'=>'Back to books','admin/content.php'=>'Back to library content'];
    if ($page==='discover.php') $label=empty($query['view'])&&empty($query['q'])&&empty($query['category'])&&empty($query['available'])?'Back to dashboard':'Back to catalogue';
    else $label=$labels[$page]??'Back to previous page';
    return ['href'=>$target,'label'=>$label];
}

function navigation_back_html(string $fallback,string $label):string
{
    $back=navigation_back($fallback,$label);
    return '<a class="back-link" data-navigation-back href="'.e($back['href']).'">'.icon('arrow-left').' <span>'.e($back['label']).'</span></a>';
}

/** Detail pages keep the navigation section of the page that opened them. */
function member_navigation_section(bool $home,string $active,string $returnTo):string
{
    if($home)return 'discover';
    if($active!=='discover')return $active;
    if(!in_array(basename($_SERVER['SCRIPT_NAME']??''),['book.php','reader.php','listen.php','reviews.php','collection.php'],true))return 'catalog';
    for($depth=0;$depth<8;$depth++){
        $safe=navigation_safe_return($returnTo);if(!$safe)break;
        $page=substr((string)parse_url($safe,PHP_URL_PATH),strlen(base_url()));
        parse_str((string)parse_url($safe,PHP_URL_QUERY),$query);
        if($page==='discover.php')return empty($query['view'])&&empty($query['q'])&&empty($query['category'])&&empty($query['available'])?'discover':'catalog';
        $sections=['loans.php'=>'loans','favorites.php'=>'favorites','profile.php'=>'profile','messages.php'=>'messages'];
        if(isset($sections[$page]))return $sections[$page];
        $returnTo=is_string($query['return_to']??null)?$query['return_to']:'';
    }
    return 'catalog';
}
