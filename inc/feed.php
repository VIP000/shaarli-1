<?php

// ------------------------------------------------------------------------------------------
// Ouput the last 50 links in RSS 2.0 format.
function showRSS()
{
    header('Content-Type: application/rss+xml; charset=utf-8');

    // Cache system
    $query = $_SERVER["QUERY_STRING"];
    $cache = new pageCache(pageUrl(),startsWith($query,'do=rss') && !isLoggedIn());
    $cached = $cache->cachedVersion(); if (!empty($cached)) { echo $cached; exit; }

    // If cached was not found (or not usable), then read the database and build the response:
    $LINKSDB=new linkdb(isLoggedIn() || $GLOBALS['config']['OPEN_SHAARLI']);  // Read links from database (and filter private links if used it not logged in).

    // Optionnaly filter the results:
    $linksToDisplay=array();
    if (!empty($_GET['searchterm'])) $linksToDisplay = $LINKSDB->filterFulltext($_GET['searchterm']);
    elseif (!empty($_GET['searchtags']))   $linksToDisplay = $LINKSDB->filterTags(trim($_GET['searchtags']));
    else $linksToDisplay = $LINKSDB;

    $pageaddr=htmlspecialchars(indexUrl());
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">';
    echo '<channel><title>'.htmlspecialchars($GLOBALS['title']).'</title><link>'.$pageaddr.'</link>';
    echo '<description>Shared links</description><language>en-en</language><copyright>'.$pageaddr.'</copyright>'."\n\n";
    $i=0;
    $keys=array(); foreach($linksToDisplay as $key=>$value) { $keys[]=$key; }  // No, I can't use array_keys().
    while ($i<50 && $i<count($keys))
    {
        $link = $linksToDisplay[$keys[$i]];
        $guid = $pageaddr.'?'.smallHash($link['linkdate']);
        $rfc822date = linkdate2rfc822($link['linkdate']);
        $absurl = htmlspecialchars($link['url']);
        if (startsWith($absurl,'?')) $absurl=$pageaddr.$absurl;  // make permalink URL absolute
        echo '<item><title>'.htmlspecialchars($link['title']).'</title><guid>'.$guid.'</guid><link>'.$absurl.'</link>';
        if (!$GLOBALS['config']['HIDE_TIMESTAMPS'] || isLoggedIn()) echo '<pubDate>'.htmlspecialchars($rfc822date)."</pubDate>\n";
        if ($link['tags']!='') // Adding tags to each RSS entry (as mentioned in RSS specification)
        {
            foreach(explode(' ',$link['tags']) as $tag) { echo '<category domain="'.htmlspecialchars($pageaddr).'">'.htmlspecialchars($tag).'</category>'."\n"; }
        }
        echo '<description><![CDATA['.nl2br(keepMultipleSpaces(text2clickable(htmlspecialchars($link['description'])))).']]></description>'."\n</item>\n";
        $i++;
    }
    echo '</channel></rss>';

    $cache->cache(ob_get_contents());
    ob_end_flush();
    exit;
}

// ------------------------------------------------------------------------------------------
// Ouput the last 50 links in ATOM format.
function showATOM()
{
    header('Content-Type: application/atom+xml; charset=utf-8');

    // Cache system
    $query = $_SERVER["QUERY_STRING"];
    $cache = new pageCache(pageUrl(),startsWith($query,'do=atom') && !isLoggedIn());
    $cached = $cache->cachedVersion(); if (!empty($cached)) { echo $cached; exit; }
    // If cached was not found (or not usable), then read the database and build the response:

    $LINKSDB=new linkdb(isLoggedIn() || $GLOBALS['config']['OPEN_SHAARLI']);  // Read links from database (and filter private links if used it not logged in).


    // Optionnaly filter the results:
    $linksToDisplay=array();
    if (!empty($_GET['searchterm'])) $linksToDisplay = $LINKSDB->filterFulltext($_GET['searchterm']);
    elseif (!empty($_GET['searchtags']))   $linksToDisplay = $LINKSDB->filterTags(trim($_GET['searchtags']));
    else $linksToDisplay = $LINKSDB;

    $pageaddr=htmlspecialchars(indexUrl());
    $latestDate = '';
    $entries='';
    $i=0;
    $keys=array(); foreach($linksToDisplay as $key=>$value) { $keys[]=$key; }  // No, I can't use array_keys().
    while ($i<50 && $i<count($keys))
    {
        $link = $linksToDisplay[$keys[$i]];
        $guid = $pageaddr.'?'.smallHash($link['linkdate']);
        $iso8601date = linkdate2iso8601($link['linkdate']);
        $latestDate = max($latestDate,$iso8601date);
        $absurl = htmlspecialchars($link['url']);
        if (startsWith($absurl,'?')) $absurl=$pageaddr.$absurl;  // make permalink URL absolute
        $entries.='<entry><title>'.htmlspecialchars($link['title']).'</title><link href="'.$absurl.'" /><id>'.$guid.'</id>';
        if (!$GLOBALS['config']['HIDE_TIMESTAMPS'] || isLoggedIn()) $entries.='<updated>'.htmlspecialchars($iso8601date).'</updated>';
        $entries.='<content type="html">'.htmlspecialchars(nl2br(keepMultipleSpaces(text2clickable(htmlspecialchars($link['description'])))))."</content>\n";
        if ($link['tags']!='') // Adding tags to each ATOM entry (as mentioned in ATOM specification)
        {
            foreach(explode(' ',$link['tags']) as $tag)
                { $entries.='<category scheme="'.htmlspecialchars($pageaddr,ENT_QUOTES).'" term="'.htmlspecialchars($tag,ENT_QUOTES).'" />'."\n"; }
        }
        $entries.="</entry>\n";
        $i++;
    }
    $feed='<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom">';
    $feed.='<title>'.htmlspecialchars($GLOBALS['title']).'</title>';
    if (!$GLOBALS['config']['HIDE_TIMESTAMPS'] || isLoggedIn()) $feed.='<updated>'.htmlspecialchars($latestDate).'</updated>';
    $feed.='<link rel="self" href="'.htmlspecialchars(serverUrl().$_SERVER["REQUEST_URI"]).'" />';
    $feed.='<author><name>'.htmlspecialchars($pageaddr).'</name><uri>'.htmlspecialchars($pageaddr).'</uri></author>';
    $feed.='<id>'.htmlspecialchars($pageaddr).'</id>'."\n\n"; // Yes, I know I should use a real IRI (RFC3987), but the site URL will do.
    $feed.=$entries;
    $feed.='</feed>';
    echo $feed;

    $cache->cache(ob_get_contents());
    ob_end_flush();
    exit;
}
