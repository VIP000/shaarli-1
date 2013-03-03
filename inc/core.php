<?php
/**
 * Shaarli - Shaare your links!
 * ----------------------------
 *
 * This file is part of Shaarli.
 *
 * Personal, minimalist, super-fast, no-database Delicious clone.
 *
 * Copyright (c) 2013 Nikola KOTUR (kotur.org)
 * Copyright (c) 2011 Sébastien SAUVAGE (sebsauvage.net)
 * Released under ZLIB licence, see COPYING file for more details.
 */

// Check php version
function checkphpversion()
{
    if (version_compare(PHP_VERSION, '5.1.0') < 0)
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Your server supports php '.PHP_VERSION.'. Shaarli requires at last php 5.1.0, and thus cannot run. Sorry.';
        exit;
    }
}

// -----------------------------------------------------------------------------------------------
// Simple cache system (mainly for the RSS/ATOM feeds).

class pageCache
{
    private $url; // Full URL of the page to cache (typically the value returned by pageUrl())
    private $shouldBeCached; // boolean: Should this url be cached ?
    private $filename; // Name of the cache file for this url

    /*
         $url = url (typically the value returned by pageUrl())
         $shouldBeCached = boolean. If false, the cache will be disabled.
    */
    public function __construct($url,$shouldBeCached)
    {
        $this->url = $url;
        $this->filename = $GLOBALS['config']['CACHEDIR'] . 'page'.'/'.sha1($url).'.cache';
        $this->shouldBeCached = $shouldBeCached;
    }

    // If the page should be cached and a cached version exists,
    // returns the cached version (otherwise, return null).
    public function cachedVersion()
    {
        if (!$this->shouldBeCached) return null;
        if (is_file($this->filename)) { return file_get_contents($this->filename); exit; }
        return null;
    }

    // Put a page in the cache.
    public function cache($page)
    {
        if (!$this->shouldBeCached) return;
        if (!is_dir($GLOBALS['config']['CACHEDIR'] . 'page')) { mkdir($GLOBALS['config']['CACHEDIR'] . 'page',0705); chmod($GLOBALS['config']['CACHEDIR'] . 'page',0705); }
        file_put_contents($this->filename,$page);
    }

    // Purge the whole cache.
    // (call with pageCache::purgeCache())
    public static function purgeCache()
    {
        if (is_dir($GLOBALS['config']['CACHEDIR'] . 'page'))
        {
            $handler = opendir($GLOBALS['config']['CACHEDIR'] . 'page');
            if ($handle!==false)
            {
                while (($filename = readdir($handler))!==false)
                {
                    if (endsWith($filename,'.cache')) { unlink($GLOBALS['config']['CACHEDIR'] . 'page'.'/'.$filename); }
                }
                closedir($handler);
            }
        }
    }

}


// -----------------------------------------------------------------------------------------------
// Log to text file
function logm($message)
{
    $t = strval(date('Y/m/d_H:i:s')).' - '.$_SERVER["REMOTE_ADDR"].' - '.strval($message)."\n";
    file_put_contents($GLOBALS['config']['DATADIR'].'/log.txt',$t,FILE_APPEND);
}

// Same as nl2br(), but escapes < and >
function nl2br_escaped($html)
{
    return str_replace('>','&gt;',str_replace('<','&lt;',nl2br($html)));
}

/* Returns the small hash of a string
   eg. smallHash('20111006_131924') --> yZH23w
   Small hashes:
     - are unique (well, as unique as crc32, at last)
     - are always 6 characters long.
     - only use the following characters: a-z A-Z 0-9 - _ @
     - are NOT cryptographically secure (they CAN be forged)
   In Shaarli, they are used as a tinyurl-like link to individual entries.
*/
function smallHash($text)
{
    $t = rtrim(base64_encode(hash('crc32',$text,true)),'=');
    $t = str_replace('+','-',$t); // Get rid of characters which need encoding in URLs.
    $t = str_replace('/','_',$t);
    $t = str_replace('=','@',$t);
    return $t;
}

// In a string, converts urls to clickable links.
// Function inspired from http://www.php.net/manual/en/function.preg-replace.php#85722
function text2clickable($url)
{
    $redir = empty($GLOBALS['redirector']) ? '' : $GLOBALS['redirector'];
    return preg_replace('!(((?:https?|ftp|file)://|apt:)\S+[[:alnum:]]/?)!si','<a href="'.$redir.'$1" rel="nofollow">$1</a>',$url);
}

// This function inserts &nbsp; where relevant so that multiple spaces are properly displayed in HTML
// even in the absence of <pre>  (This is used in description to keep text formatting)
function keepMultipleSpaces($text)
{
    return str_replace('  ',' &nbsp;',$text);

}
// ------------------------------------------------------------------------------------------
// Sniff browser language to display dates in the right format automatically.
// (Note that is may not work on your server if the corresponding local is not installed.)
function autoLocale()
{
    $loc='en_US'; // Default if browser does not send HTTP_ACCEPT_LANGUAGE
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) // eg. "fr,fr-fr;q=0.8,en;q=0.5,en-us;q=0.3"
    {   // (It's a bit crude, but it works very well. Prefered language is always presented first.)
        if (preg_match('/([a-z]{2}(-[a-z]{2})?)/i',$_SERVER['HTTP_ACCEPT_LANGUAGE'],$matches)) $loc=$matches[1];
    }
    setlocale(LC_TIME,$loc);  // LC_TIME = Set local for date/time format only.
}

// Returns the server URL (including port and http/https), without path.
// eg. "http://myserver.com:8080"
// You can append $_SERVER['SCRIPT_NAME'] to get the current script URL.
function serverUrl()
{
    $https = (!empty($_SERVER['HTTPS']) && (strtolower($_SERVER['HTTPS'])=='on')) || $_SERVER["SERVER_PORT"]=='443'; // HTTPS detection.
    $serverport = ($_SERVER["SERVER_PORT"]=='80' || ($https && $_SERVER["SERVER_PORT"]=='443') ? '' : ':'.$_SERVER["SERVER_PORT"]);
    return 'http'.($https?'s':'').'://'.$_SERVER["SERVER_NAME"].$serverport;
}

// Returns the absolute URL of current script, without the query.
// (eg. http://links.kotur.org/)
function indexUrl()
{
    return serverUrl() . ($_SERVER["SCRIPT_NAME"] == '/index.php' ? '/' : $_SERVER["SCRIPT_NAME"]);
}

// Returns the absolute URL of current script, WITH the query.
// (eg. http://links.kotur.org/?toto=titi&spamspamspam=humbug)
function pageUrl()
{
    return indexUrl().(!empty($_SERVER["QUERY_STRING"]) ? '?'.$_SERVER["QUERY_STRING"] : '');
}

// Convert post_max_size/upload_max_filesize (eg.'16M') parameters to bytes.
function return_bytes($val)
{
    $val = trim($val); $last=strtolower($val[strlen($val)-1]);
    switch($last)
    {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

// Try to determine max file size for uploads (POST).
// Returns an integer (in bytes)
function getMaxFileSize()
{
    $size1 = return_bytes(ini_get('post_max_size'));
    $size2 = return_bytes(ini_get('upload_max_filesize'));
    // Return the smaller of two:
    $maxsize = min($size1,$size2);
    // FIXME: Then convert back to readable notations ? (eg. 2M instead of 2000000)
    return $maxsize;
}

// Tells if a string start with a substring or not.
function startsWith($haystack,$needle,$case=true)
{
    if($case){return (strcmp(substr($haystack, 0, strlen($needle)),$needle)===0);}
    return (strcasecmp(substr($haystack, 0, strlen($needle)),$needle)===0);
}

// Tells if a string ends with a substring or not.
function endsWith($haystack,$needle,$case=true)
{
    if($case){return (strcmp(substr($haystack, strlen($haystack) - strlen($needle)),$needle)===0);}
    return (strcasecmp(substr($haystack, strlen($haystack) - strlen($needle)),$needle)===0);
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a timestamp (Unix epoch)
    (used to build the ADD_DATE attribute in Netscape-bookmarks file)
    PS: I could have used strptime(), but it does not exist on Windows. I'm too kind. */
function linkdate2timestamp($linkdate)
{
    $Y=$M=$D=$h=$m=$s=0;
    $r = sscanf($linkdate,'%4d%2d%2d_%2d%2d%2d',$Y,$M,$D,$h,$m,$s);
    return mktime($h,$m,$s,$M,$D,$Y);
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a RFC822 date.
    (used to build the pubDate attribute in RSS feed.)  */
function linkdate2rfc822($linkdate)
{
    return date('r',linkdate2timestamp($linkdate)); // 'r' is for RFC822 date format.
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a ISO 8601 date.
    (used to build the updated tags in ATOM feed.)  */
function linkdate2iso8601($linkdate)
{
    return date('c',linkdate2timestamp($linkdate)); // 'c' is for ISO 8601 date format.
}

/*  Converts a linkdate time (YYYYMMDD_HHMMSS) of an article to a localized date format.
    (used to display link date on screen)
    The date format is automatically chosen according to locale/languages sniffed from browser headers (see autoLocale()). */
function linkdate2locale($linkdate)
{
    return utf8_encode(strftime('%c',linkdate2timestamp($linkdate))); // %c is for automatic date format according to locale.
    // Note that if you use a local which is not installed on your webserver,
    // the date will not be displayed in the chosen locale, but probably in US notation.
}

// Parse HTTP response headers and return an associative array.
function http_parse_headers_shaarli( $headers )
{
    $res=array();
    foreach($headers as $header)
    {
        $i = strpos($header,': ');
        if ($i!==false)
        {
            $key=substr($header,0,$i);
            $value=substr($header,$i+2,strlen($header)-$i-2);
            $res[$key]=$value;
        }
    }
    return $res;
}

/* GET an URL.
   Input: $url : url to get (http://...)
          $timeout : Network timeout (will wait this many seconds for an anwser before giving up).
   Output: An array.  [0] = HTTP status message (eg. "HTTP/1.1 200 OK") or error message
                      [1] = associative array containing HTTP response headers (eg. echo getHTTP($url)[1]['Content-Type'])
                      [2] = data
    Example: list($httpstatus,$headers,$data) = getHTTP('http://sebauvage.net/');
             if (strpos($httpstatus,'200 OK')!==false)
                 echo 'Data type: '.htmlspecialchars($headers['Content-Type']);
             else
                 echo 'There was an error: '.htmlspecialchars($httpstatus)
*/
function getHTTP($url,$timeout=30)
{
    try
    {
        $options = array('http'=>array('method'=>'GET','timeout' => $timeout)); // Force network timeout
        $context = stream_context_create($options);
        $data=file_get_contents($url,false,$context,-1, 4000000); // We download at most 4 Mb from source.
        if (!$data) { return array('HTTP Error',array(),''); }
        $httpStatus=$http_response_header[0]; // eg. "HTTP/1.1 200 OK"
        $responseHeaders=http_parse_headers_shaarli($http_response_header);
        return array($httpStatus,$responseHeaders,$data);
    }
    catch (Exception $e)  // getHTTP *can* fail silentely (we don't care if the title cannot be fetched)
    {
        return array($e->getMessage(),'','');
    }
}

// Extract title from an HTML document.
// (Returns an empty string if not found.)
function html_extract_title($html)
{
  return preg_match('!<title>(.*?)</title>!is', $html, $matches) ? trim(str_replace("\n",' ', $matches[1])) : '' ;
}


// -----------------------------------------------------------------------------------------------
// Installation
// This function should NEVER be called if the file data/config.php exists.
function install()
{
    if (!empty($_POST['setlogin']) && !empty($_POST['setpassword']))
    {
        $tz = 'UTC';
        if (!empty($_POST['continent']) && !empty($_POST['city']))
            if (isTZvalid($_POST['continent'],$_POST['city']))
                $tz = $_POST['continent'].'/'.$_POST['city'];
        $GLOBALS['timezone'] = $tz;
        // Everything is ok, let's create config file.
        $GLOBALS['login'] = $_POST['setlogin'];
        $GLOBALS['salt'] = sha1(uniqid('',true).'_'.mt_rand()); // Salt renders rainbow-tables attacks useless.
        $GLOBALS['hash'] = sha1($_POST['setpassword'].$GLOBALS['login'].$GLOBALS['salt']);
        $GLOBALS['title'] = (empty($_POST['title']) ? 'Shared links on '.htmlspecialchars(indexUrl()) : $_POST['title'] );
        writeConfig();
        echo '<script language="JavaScript">alert("Shaarli is now configured. Please enter your login/password and start shaaring your links !");document.location=\'?do=login\';</script>';
        exit;
    }

    // Display config form:
    list($timezone_form,$timezone_js) = templateTZform();
    $timezone_html=''; if ($timezone_form!='') $timezone_html='<tr><td valign="top"><b>Timezone:</b></td><td>'.$timezone_form.'</td></tr>';

    $PAGE = new pageBuilder;
    $PAGE->assign('timezone_html',$timezone_html);
    $PAGE->assign('timezone_js',$timezone_js);
    $PAGE->renderPage('install');
    exit;
}

// Generates the timezone selection form and javascript.
// Input: (optional) current timezone (can be 'UTC/UTC'). It will be pre-selected.
// Output: array(html,js)
// Example: list($htmlform,$js) = templateTZform('Europe/Paris');  // Europe/Paris pre-selected.
// Returns array('','') if server does not support timezones list. (eg. PHP 5.1)
function templateTZform($ptz=false)
{
    if (function_exists('timezone_identifiers_list'))
    {
        // PHP 5.1 support.

        // Try to split the provided timezone.
        if ($ptz==false) { $l=timezone_identifiers_list(); $ptz=$l[0]; }
        $spos=strpos($ptz,'/'); $pcontinent=substr($ptz,0,$spos); $pcity=substr($ptz,$spos+1);

        // Display config form:
        $timezone_form = '';
        $timezone_js = '';
        // The list is in the forme "Europe/Paris", "America/Argentina/Buenos_Aires"...
        // We split the list in continents/cities.
        $continents = array();
        $cities = array();
        foreach(timezone_identifiers_list() as $tz)
        {
            if ($tz=='UTC') $tz='UTC/UTC';
            $spos = strpos($tz,'/');
            if ($spos!==false)
            {
                $continent=substr($tz,0,$spos); $city=substr($tz,$spos+1);
                $continents[$continent]=1;
                if (!isset($cities[$continent])) $cities[$continent]='';
                $cities[$continent].='<option value="'.$city.'"'.($pcity==$city?'selected':'').'>'.$city.'</option>';
            }
        }
        $continents_html = '';
        $continents = array_keys($continents);
        foreach($continents as $continent)
            $continents_html.='<option  value="'.$continent.'"'.($pcontinent==$continent?'selected':'').'>'.$continent.'</option>';
        $cities_html = $cities[$pcontinent];
        $timezone_form = "Continent: <select name=\"continent\" id=\"continent\" onChange=\"onChangecontinent();\">${continents_html}</select><br /><br />";
        $timezone_form .= "City: <select name=\"city\" id=\"city\">${cities[$pcontinent]}</select><br /><br />";
        $timezone_js = "<script language=\"JavaScript\">";
        $timezone_js .= "function onChangecontinent(){document.getElementById(\"city\").innerHTML = citiescontinent[document.getElementById(\"continent\").value];}";
        $timezone_js .= "var citiescontinent = ".json_encode($cities).";" ;
        $timezone_js .= "</script>" ;
        return array($timezone_form,$timezone_js);
    }
    return array('','');
}

// Tells if a timezone is valid or not.
// If not valid, returns false.
// If system does not support timezone list, returns false.
function isTZvalid($continent,$city)
{
    $tz = $continent.'/'.$city;
    if (function_exists('timezone_identifiers_list')) // PHP 5.1 support.
    {
        if (in_array($tz, timezone_identifiers_list())) // it's a valid timezone ?
                    return true;
    }
    return false;
}


// Webservices (for use with jQuery/jQueryUI)
// eg.  index.php?ws=tags&term=minecr
function processWS()
{
    if (empty($_GET['ws']) || empty($_GET['term'])) return;
    $term = $_GET['term'];
    $LINKSDB=new linkdb(isLoggedIn() || $GLOBALS['config']['OPEN_SHAARLI']);  // Read links from database (and filter private links if used it not logged in).
    header('Content-Type: application/json; charset=utf-8');

    // Search in tags (case insentitive, cumulative search)
    if ($_GET['ws']=='tags')
    {
        $tags=explode(' ',str_replace(',',' ',$term)); $last = array_pop($tags); // Get the last term ("a b c d" ==> "a b c", "d")
        $addtags=''; if ($tags) $addtags=implode(' ',$tags).' '; // We will pre-pend previous tags
        $suggested=array();
        /* To speed up things, we store list of tags in session */
        if (empty($_SESSION['tags'])) $_SESSION['tags'] = $LINKSDB->allTags();
        foreach($_SESSION['tags'] as $key=>$value)
        {
            if (startsWith($key,$last,$case=false) && !in_array($key,$tags)) $suggested[$addtags.$key.' ']=0;
        }
        echo json_encode(array_keys($suggested));
        exit;
    }

    // Search a single tag (case sentitive, single tag search)
    if ($_GET['ws']=='singletag')
    {
        /* To speed up things, we store list of tags in session */
        if (empty($_SESSION['tags'])) $_SESSION['tags'] = $LINKSDB->allTags();
        foreach($_SESSION['tags'] as $key=>$value)
        {
            if (startsWith($key,$term,$case=true)) $suggested[$key]=0;
        }
        echo json_encode(array_keys($suggested));
        exit;
    }
}

// Re-write configuration file according to globals.
// Requires some $GLOBALS to be set (login,hash,salt,title).
// If the config file cannot be saved, an error message is dislayed and the user is redirected to "Tools" menu.
// (otherwise, the function simply returns.)
function writeConfig() {
    if (is_file($GLOBALS['config']['CONFIG_FILE']) && !isLoggedIn())
        die('You are not authorized to alter config.'); // Only logged in user can alter config.
    if (empty($GLOBALS['redirector'])) $GLOBALS['redirector']='';
    if (empty($GLOBALS['disablesessionprotection'])) $GLOBALS['disablesessionprotection']=false;
    $config  = '<?php'."\n";
    $config .= '$GLOBALS[\'login\']='.var_export($GLOBALS['login'],true).";\n";
    $config .= '$GLOBALS[\'hash\']='.var_export($GLOBALS['hash'],true).";\n";
    $config .= '$GLOBALS[\'salt\']='.var_export($GLOBALS['salt'],true).";\n";
    $config .= '$GLOBALS[\'timezone\']='.var_export($GLOBALS['timezone'],true).";\n";
    $config .= 'date_default_timezone_set('.var_export($GLOBALS['timezone'],true).')'.";\n";
    $config .= '$GLOBALS[\'title\']='.var_export($GLOBALS['title'],true).";\n";
    $config .= '$GLOBALS[\'redirector\']='.var_export($GLOBALS['redirector'],true).";\n";
    $config .= '$GLOBALS[\'disablesessionprotection\']='.var_export($GLOBALS['disablesessionprotection'],true).";\n";
    $config .= '?>';
    if (!file_put_contents($GLOBALS['config']['CONFIG_FILE'],$config) || strcmp(file_get_contents($GLOBALS['config']['CONFIG_FILE']),$config)!=0)
    {
        echo '<script language="JavaScript">alert("Shaarli could not create the config file. Please make sure Shaarli has the right to write in the folder is it installed in.");document.location=\'?\';</script>';
        exit;
    }
}

// -----------------------------------------------------------------------------------------------
// Process the import file form.
function importFile()
{
    if (!(isLoggedIn() || $GLOBALS['config']['OPEN_SHAARLI'])) { die('Not allowed.'); }
    $LINKSDB=new linkdb(isLoggedIn() || $GLOBALS['config']['OPEN_SHAARLI']);  // Read links from database (and filter private links if used it not logged in).
    $filename=$_FILES['filetoupload']['name'];
    $filesize=$_FILES['filetoupload']['size'];
    $data=file_get_contents($_FILES['filetoupload']['tmp_name']);
    $private = (empty($_POST['private']) ? 0 : 1); // Should the links be imported as private ?
    $overwrite = !empty($_POST['overwrite']) ; // Should the imported links overwrite existing ones ?
    $import_count=0;

    // Sniff file type:
    $type='unknown';
    if (startsWith($data,'<!DOCTYPE NETSCAPE-Bookmark-file-1>')) $type='netscape'; // Netscape bookmark file (aka Firefox).

    // Then import the bookmarks.
    if ($type=='netscape')
    {
        // This is a standard Netscape-style bookmark file.
        // This format is supported by all browsers (except IE, of course), also delicious, diigo and others.
        foreach(explode('<DT>',$data) as $html) // explode is very fast
        {
            $link = array('linkdate'=>'','title'=>'','url'=>'','description'=>'','tags'=>'','private'=>0);
            $d = explode('<DD>',$html);
            if (startswith($d[0],'<A '))
            {
                $link['description'] = (isset($d[1]) ? html_entity_decode(trim($d[1]),ENT_QUOTES,'UTF-8') : '');  // Get description (optional)
                preg_match('!<A .*?>(.*?)</A>!i',$d[0],$matches); $link['title'] = (isset($matches[1]) ? trim($matches[1]) : '');  // Get title
                $link['title'] = html_entity_decode($link['title'],ENT_QUOTES,'UTF-8');
                preg_match_all('! ([A-Z_]+)=\"(.*?)"!i',$html,$matches,PREG_SET_ORDER);  // Get all other attributes
                $raw_add_date=0;
                foreach($matches as $m)
                {
                    $attr=$m[1]; $value=$m[2];
                    if ($attr=='HREF') $link['url']=html_entity_decode($value,ENT_QUOTES,'UTF-8');
                    elseif ($attr=='ADD_DATE') $raw_add_date=intval($value);
                    elseif ($attr=='PRIVATE') $link['private']=($value=='0'?0:1);
                    elseif ($attr=='TAGS') $link['tags']=html_entity_decode(str_replace(',',' ',$value),ENT_QUOTES,'UTF-8');
                }
                if ($link['url']!='')
                {
                    if ($private==1) $link['private']=1;
                    $dblink = $LINKSDB->getLinkFromUrl($link['url']); // See if the link is already in database.
                    if ($dblink==false)
                    {  // Link not in database, let's import it...
                       if (empty($raw_add_date)) $raw_add_date=time(); // In case of shitty bookmark file with no ADD_DATE

                       // Make sure date/time is not already used by another link.
                       // (Some bookmark files have several different links with the same ADD_DATE)
                       // We increment date by 1 second until we find a date which is not used in db.
                       // (so that links that have the same date/time are more or less kept grouped by date, but do not conflict.)
                       while (!empty($LINKSDB[date('Ymd_His',$raw_add_date)])) { $raw_add_date++; }// Yes, I know it's ugly.
                       $link['linkdate']=date('Ymd_His',$raw_add_date);
                       $LINKSDB[$link['linkdate']] = $link;
                       $import_count++;
                    }
                    else // link already present in database.
                    {
                        if ($overwrite)
                        {   // If overwrite is required, we import link data, except date/time.
                            $link['linkdate']=$dblink['linkdate'];
                            $LINKSDB[$link['linkdate']] = $link;
                            $import_count++;
                        }
                    }

                }
            }
        }
        $LINKSDB->savedb();

        echo '<script language="JavaScript">alert("File '.$filename.' ('.$filesize.' bytes) was successfully processed: '.$import_count.' links imported.");document.location=\'?\';</script>';
    }
    else
    {
        echo '<script language="JavaScript">alert("File '.$filename.' ('.$filesize.' bytes) has an unknown file format. Nothing was imported.");document.location=\'?\';</script>';
    }
}
