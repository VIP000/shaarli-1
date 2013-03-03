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

// Compute the thumbnail for a link.
//
// with a link to the original URL.
// Understands various services (youtube.com...)
// Input: $url = url for which the thumbnail must be found.
//        $href = if provided, this URL will be followed instead of $url
// Returns an associative array with thumbnail attributes (src,href,width,height,style,alt)
// Some of them may be missing.
// Return an empty array if no thumbnail available.
function computeThumbnail($url,$href=false)
{
    if (!$GLOBALS['config']['ENABLE_THUMBNAILS']) return array();
    if ($href==false) $href=$url;

    // For most hosts, the URL of the thumbnail can be easily deduced from the URL of the link.
    // (eg. http://www.youtube.com/watch?v=spVypYk4kto --->  http://img.youtube.com/vi/spVypYk4kto/default.jpg )
    //                                     ^^^^^^^^^^^                                 ^^^^^^^^^^^
    $domain = parse_url($url,PHP_URL_HOST);
    if ($domain=='youtube.com' || $domain=='www.youtube.com')
    {
        parse_str(parse_url($url,PHP_URL_QUERY), $params); // Extract video ID and get thumbnail
        if (!empty($params['v'])) return array('src'=>'http://img.youtube.com/vi/'.$params['v'].'/default.jpg',
                                               'href'=>$href,'width'=>'120','height'=>'90','alt'=>'YouTube thumbnail');
    }
    if ($domain=='youtu.be') // Youtube short links
    {
        $path = parse_url($url,PHP_URL_PATH);
        return array('src'=>'http://img.youtube.com/vi'.$path.'/default.jpg',
                     'href'=>$href,'width'=>'120','height'=>'90','alt'=>'YouTube thumbnail');
    }
    if ($domain=='pix.toile-libre.org') // pix.toile-libre.org image hosting
    {
        parse_str(parse_url($url,PHP_URL_QUERY), $params); // Extract image filename.
        if (!empty($params) && !empty($params['img'])) return array('src'=>'http://pix.toile-libre.org/upload/thumb/'.urlencode($params['img']),
                                                                    'href'=>$href,'style'=>'max-width:120px; max-height:150px','alt'=>'pix.toile-libre.org thumbnail');
    }

    if ($domain=='imgur.com')
    {
        $path = parse_url($url,PHP_URL_PATH);
        if (startsWith($path,'/a/')) return array(); // Thumbnails for albums are not available.
        if (startsWith($path,'/r/')) return array('src'=>'http://i.imgur.com/'.basename($path).'s.jpg',
                                                  'href'=>$href,'width'=>'90','height'=>'90','alt'=>'imgur.com thumbnail');
        if (startsWith($path,'/gallery/')) return array('src'=>'http://i.imgur.com'.substr($path,8).'s.jpg',
                                                        'href'=>$href,'width'=>'90','height'=>'90','alt'=>'imgur.com thumbnail');

        if (substr_count($path,'/')==1) return array('src'=>'http://i.imgur.com/'.substr($path,1).'s.jpg',
                                                     'href'=>$href,'width'=>'90','height'=>'90','alt'=>'imgur.com thumbnail');
    }
    if ($domain=='i.imgur.com')
    {
        $pi = pathinfo(parse_url($url,PHP_URL_PATH));
        if (!empty($pi['filename'])) return array('src'=>'http://i.imgur.com/'.$pi['filename'].'s.jpg',
                                                  'href'=>$href,'width'=>'90','height'=>'90','alt'=>'imgur.com thumbnail');
    }
    if ($domain=='dailymotion.com' || $domain=='www.dailymotion.com')
    {
        if (strpos($url,'dailymotion.com/video/')!==false)
        {
            $thumburl=str_replace('dailymotion.com/video/','dailymotion.com/thumbnail/video/',$url);
            return array('src'=>$thumburl,
                         'href'=>$href,'width'=>'120','style'=>'height:auto;','alt'=>'DailyMotion thumbnail');
        }
    }
    if (endsWith($domain,'.imageshack.us'))
    {
        $ext=strtolower(pathinfo($url,PATHINFO_EXTENSION));
        if ($ext=='jpg' || $ext=='jpeg' || $ext=='png' || $ext=='gif')
        {
            $thumburl = substr($url,0,strlen($url)-strlen($ext)).'th.'.$ext;
            return array('src'=>$thumburl,
                         'href'=>$href,'width'=>'120','style'=>'height:auto;','alt'=>'imageshack.us thumbnail');
        }
    }

    // Some other hosts are SLOW AS HELL and usually require an extra HTTP request to get the thumbnail URL.
    // So we deport the thumbnail generation in order not to slow down page generation
    // (and we also cache the thumbnail)

    if (!$GLOBALS['config']['ENABLE_LOCALCACHE']) return array(); // If local cache is disabled, no thumbnails for services which require the use a local cache.

    if ($domain=='flickr.com' || endsWith($domain,'.flickr.com')
        || $domain=='vimeo.com'
        || $domain=='ted.com' || endsWith($domain,'.ted.com')
        || $domain=='xkcd.com' || endsWith($domain,'.xkcd.com')
    )
    {
        if ($domain=='vimeo.com')
        {   // Make sure this vimeo url points to a video (/xxx... where xxx is numeric)
            $path = parse_url($url,PHP_URL_PATH);
            if (!preg_match('!/\d+.+?!',$path)) return array(); // This is not a single video URL.
        }
        if ($domain=='xkcd.com' || endsWith($domain,'.xkcd.com'))
        {   // Make sure this url points to a single comic (/xxx... where xxx is numeric)
            $path = parse_url($url,PHP_URL_PATH);
            if (!preg_match('!/\d+.+?!',$path)) return array();
        }
        if ($domain=='ted.com' || endsWith($domain,'.ted.com'))
        {   // Make sure this TED url points to a video (/talks/...)
            $path = parse_url($url,PHP_URL_PATH);
            if ("/talks/" !== substr($path,0,7)) return array(); // This is not a single video URL.
        }
        $sign = hash_hmac('sha256', $url, $GLOBALS['salt']); // We use the salt to sign data (it's random, secret, and specific to each installation)
        return array('src'=>indexUrl().'?do=genthumbnail&hmac='.htmlspecialchars($sign).'&url='.urlencode($url),
                     'href'=>$href,'width'=>'120','style'=>'height:auto;','alt'=>'thumbnail');
    }

    // For all other, we try to make a thumbnail of links ending with .jpg/jpeg/png/gif
    // Technically speaking, we should download ALL links and check their Content-Type to see if they are images.
    // But using the extension will do.
    $ext=strtolower(pathinfo($url,PATHINFO_EXTENSION));
    if ($ext=='jpg' || $ext=='jpeg' || $ext=='png' || $ext=='gif')
    {
        $sign = hash_hmac('sha256', $url, $GLOBALS['salt']); // We use the salt to sign data (it's random, secret, and specific to each installation)
        return array('src'=>indexUrl().'?do=genthumbnail&hmac='.htmlspecialchars($sign).'&url='.urlencode($url),
                     'href'=>$href,'width'=>'120','style'=>'height:auto;','alt'=>'thumbnail');
    }
    return array(); // No thumbnail.

}


// Returns the HTML code to display a thumbnail for a link
// with a link to the original URL.
// Understands various services (youtube.com...)
// Input: $url = url for which the thumbnail must be found.
//        $href = if provided, this URL will be followed instead of $url
// Returns '' if no thumbnail available.
function thumbnail($url,$href=false)
{
    $t = computeThumbnail($url,$href);
    if (count($t)==0) return ''; // Empty array = no thumbnail for this URL.

    $html='<a href="'.htmlspecialchars($t['href']).'"><img src="'.htmlspecialchars($t['src']).'"';
    if (!empty($t['width']))  $html.=' width="'.htmlspecialchars($t['width']).'"';
    if (!empty($t['height'])) $html.=' height="'.htmlspecialchars($t['height']).'"';
    if (!empty($t['style']))  $html.=' style="'.htmlspecialchars($t['style']).'"';
    if (!empty($t['alt']))    $html.=' alt="'.htmlspecialchars($t['alt']).'"';
    $html.='></a>';
    return $html;
}


// Returns the HTML code to display a thumbnail for a link
// for the picture wall (using lazy image loading)
// Understands various services (youtube.com...)
// Input: $url = url for which the thumbnail must be found.
//        $href = if provided, this URL will be followed instead of $url
// Returns '' if no thumbnail available.
function lazyThumbnail($url,$href=false)
{
    $t = computeThumbnail($url,$href);
    if (count($t)==0) return ''; // Empty array = no thumbnail for this URL.

    $html='<a href="'.htmlspecialchars($t['href']).'">';

    // Lazy image (only loaded by javascript when in the viewport).
    $html.='<img class="lazyimage" src="#" data-original="'.htmlspecialchars($t['src']).'"';
    if (!empty($t['width']))  $html.=' width="'.htmlspecialchars($t['width']).'"';
    if (!empty($t['height'])) $html.=' height="'.htmlspecialchars($t['height']).'"';
    if (!empty($t['style']))  $html.=' style="'.htmlspecialchars($t['style']).'"';
    if (!empty($t['alt']))    $html.=' alt="'.htmlspecialchars($t['alt']).'"';
    $html.='>';

    // No-javascript fallback:
    $html.='<noscript><img src="'.htmlspecialchars($t['src']).'"';
    if (!empty($t['width']))  $html.=' width="'.htmlspecialchars($t['width']).'"';
    if (!empty($t['height'])) $html.=' height="'.htmlspecialchars($t['height']).'"';
    if (!empty($t['style']))  $html.=' style="'.htmlspecialchars($t['style']).'"';
    if (!empty($t['alt']))    $html.=' alt="'.htmlspecialchars($t['alt']).'"';
    $html.='></noscript></a>';

    return $html;
}

/* Because some f*cking services like Flickr require an extra HTTP request to get the thumbnail URL,
   I have deported the thumbnail URL code generation here, otherwise this would slow down page generation.
   The following function takes the URL a link (eg. a flickr page) and return the proper thumbnail.
   This function is called by passing the url:
   http://mywebsite.com/shaarli/?do=genthumbnail&hmac=[HMAC]&url=[URL]
   [URL] is the URL of the link (eg. a flickr page)
   [HMAC] is the signature for the [URL] (so that these URL cannot be forged).
   The function below will fetch the image from the webservice and store it in the cache.
*/
function genThumbnail()
{
    // Make sure the parameters in the URL were generated by us.
    $sign = hash_hmac('sha256', $_GET['url'], $GLOBALS['salt']);
    if ($sign!=$_GET['hmac']) die('Naughty boy !');

    // Let's see if we don't already have the image for this URL in the cache.
    $thumbname=hash('sha1',$_GET['url']).'.jpg';
    if (is_file($GLOBALS['config']['CACHEDIR'].'/'.$thumbname))
    {   // We have the thumbnail, just serve it:
        header('Content-Type: image/jpeg');
        echo file_get_contents($GLOBALS['config']['CACHEDIR'].'/'.$thumbname);
        return;
    }
    // We may also serve a blank image (if service did not respond)
    $blankname=hash('sha1',$_GET['url']).'.gif';
    if (is_file($GLOBALS['config']['CACHEDIR'].'/'.$blankname))
    {
        header('Content-Type: image/gif');
        echo file_get_contents($GLOBALS['config']['CACHEDIR'].'/'.$blankname);
        return;
    }

    // Otherwise, generate the thumbnail.
    $url = $_GET['url'];
    $domain = parse_url($url,PHP_URL_HOST);

    if ($domain=='flickr.com' || endsWith($domain,'.flickr.com'))
    {
        // Crude replacement to handle new Flickr domain policy (They prefer www. now)
        $url = str_replace('http://flickr.com/','http://www.flickr.com/',$url);

        // Is this a link to an image, or to a flickr page ?
        $imageurl='';
        if (endswith(parse_url($url,PHP_URL_PATH),'.jpg'))
        {  // This is a direct link to an image. eg. http://farm1.staticflickr.com/5/5921913_ac83ed27bd_o.jpg
            preg_match('!(http://farm\d+\.staticflickr\.com/\d+/\d+_\w+_)\w.jpg!',$url,$matches);
            if (!empty($matches[1])) $imageurl=$matches[1].'m.jpg';
        }
        else // this is a flickr page (html)
        {
            list($httpstatus,$headers,$data) = getHTTP($url,20); // Get the flickr html page.
            if (strpos($httpstatus,'200 OK')!==false)
            {
                // Flickr now nicely provides the URL of the thumbnail in each flickr page.
                preg_match('!<link rel=\"image_src\" href=\"(.+?)\"!',$data,$matches);
                if (!empty($matches[1])) $imageurl=$matches[1];

                // In albums (and some other pages), the link rel="image_src" is not provided,
                // but flickr provides:
                // <meta property="og:image" content="http://farm4.staticflickr.com/3398/3239339068_25d13535ff_z.jpg" />
                if ($imageurl=='')
                {
                    preg_match('!<meta property=\"og:image\" content=\"(.+?)\"!',$data,$matches);
                    if (!empty($matches[1])) $imageurl=$matches[1];
                }
            }
        }

        if ($imageurl!='')
        {   // Let's download the image.
            list($httpstatus,$headers,$data) = getHTTP($imageurl,10); // Image is 240x120, so 10 seconds to download should be enough.
            if (strpos($httpstatus,'200 OK')!==false)
            {
                file_put_contents($GLOBALS['config']['CACHEDIR'].'/'.$thumbname,$data); // Save image to cache.
                header('Content-Type: image/jpeg');
                echo $data;
                return;
            }
        }
    }

    elseif ($domain=='vimeo.com' )
    {
        // This is more complex: we have to perform a HTTP request, then parse the result.
        // Maybe we should deport this to javascript ? Example: http://stackoverflow.com/questions/1361149/get-img-thumbnails-from-vimeo/4285098#4285098
        $vid = substr(parse_url($url,PHP_URL_PATH),1);
        list($httpstatus,$headers,$data) = getHTTP('http://vimeo.com/api/v2/video/'.htmlspecialchars($vid).'.php',5);
        if (strpos($httpstatus,'200 OK')!==false)
        {
            $t = unserialize($data);
            $imageurl = $t[0]['thumbnail_medium'];
            // Then we download the image and serve it to our client.
            list($httpstatus,$headers,$data) = getHTTP($imageurl,10);
            if (strpos($httpstatus,'200 OK')!==false)
            {
                file_put_contents($GLOBALS['config']['CACHEDIR'].'/'.$thumbname,$data); // Save image to cache.
                header('Content-Type: image/jpeg');
                echo $data;
                return;
            }
        }
    }

    elseif ($domain=='ted.com' || endsWith($domain,'.ted.com'))
    {
        // The thumbnail for TED talks is located in the <link rel="image_src" [...]> tag on that page
        // http://www.ted.com/talks/mikko_hypponen_fighting_viruses_defending_the_net.html
        // <link rel="image_src" href="http://images.ted.com/images/ted/28bced335898ba54d4441809c5b1112ffaf36781_389x292.jpg" />
        list($httpstatus,$headers,$data) = getHTTP($url,5);
        if (strpos($httpstatus,'200 OK')!==false)
        {
            // Extract the link to the thumbnail
            preg_match('!link rel="image_src" href="(http://images.ted.com/images/ted/.+_\d+x\d+\.jpg)"!',$data,$matches);
            if (!empty($matches[1]))
            {   // Let's download the image.
                $imageurl=$matches[1];
                list($httpstatus,$headers,$data) = getHTTP($imageurl,20); // No control on image size, so wait long enough.
                if (strpos($httpstatus,'200 OK')!==false)
                {
                    $filepath=$GLOBALS['config']['CACHEDIR'].'/'.$thumbname;
                    file_put_contents($filepath,$data); // Save image to cache.
                    if (resizeImage($filepath))
                    {
                        header('Content-Type: image/jpeg');
                        echo file_get_contents($filepath);
                        return;
                    }
                }
            }
        }
    }

    elseif ($domain=='xkcd.com' || endsWith($domain,'.xkcd.com'))
    {
        // There is no thumbnail available for xkcd comics, so download the whole image and resize it.
        // http://xkcd.com/327/
        // <img src="http://imgs.xkcd.com/comics/exploits_of_a_mom.png" title="<BLABLA>" alt="<BLABLA>" />
        list($httpstatus,$headers,$data) = getHTTP($url,5);
        if (strpos($httpstatus,'200 OK')!==false)
        {
            // Extract the link to the thumbnail
            preg_match('!<img src="(http://imgs.xkcd.com/comics/.*)" title="[^s]!',$data,$matches);
            if (!empty($matches[1]))
            {   // Let's download the image.
                $imageurl=$matches[1];
                list($httpstatus,$headers,$data) = getHTTP($imageurl,20); // No control on image size, so wait long enough.
                if (strpos($httpstatus,'200 OK')!==false)
                {
                    $filepath=$GLOBALS['config']['CACHEDIR'].'/'.$thumbname;
                    file_put_contents($filepath,$data); // Save image to cache.
                    if (resizeImage($filepath))
                    {
                        header('Content-Type: image/jpeg');
                        echo file_get_contents($filepath);
                        return;
                    }
                }
            }
        }
    }

    else
    {
        // For all other domains, we try to download the image and make a thumbnail.
        list($httpstatus,$headers,$data) = getHTTP($url,30);  // We allow 30 seconds max to download (and downloads are limited to 4 Mb)
        if (strpos($httpstatus,'200 OK')!==false)
        {
            $filepath=$GLOBALS['config']['CACHEDIR'].'/'.$thumbname;
            file_put_contents($filepath,$data); // Save image to cache.
            if (resizeImage($filepath))
            {
                header('Content-Type: image/jpeg');
                echo file_get_contents($filepath);
                return;
            }
        }
    }


    // Otherwise, return an empty image (8x8 transparent gif)
    $blankgif = base64_decode('R0lGODlhCAAIAIAAAP///////yH5BAEKAAEALAAAAAAIAAgAAAIHjI+py+1dAAA7');
    file_put_contents($GLOBALS['config']['CACHEDIR'].'/'.$blankname,$blankgif); // Also put something in cache so that this URL is not requested twice.
    header('Content-Type: image/gif');
    echo $blankgif;
}

// Make a thumbnail of the image (to width: 120 pixels)
// Returns true if success, false otherwise.
function resizeImage($filepath)
{
    if (!function_exists('imagecreatefromjpeg')) return false; // GD not present: no thumbnail possible.

    // Trick: some stupid people rename GIF as JPEG... or else.
    // So we really try to open each image type whatever the extension is.
    $header=file_get_contents($filepath,false,NULL,0,256); // Read first 256 bytes and try to sniff file type.
    $im=false;
    $i=strpos($header,'GIF8'); if (($i!==false) && ($i==0)) $im = imagecreatefromgif($filepath); // Well this is crude, but it should be enough.
    $i=strpos($header,'PNG'); if (($i!==false) && ($i==1)) $im = imagecreatefrompng($filepath);
    $i=strpos($header,'JFIF'); if ($i!==false) $im = imagecreatefromjpeg($filepath);
    if (!$im) return false;  // Unable to open image (corrupted or not an image)
    $w = imagesx($im);
    $h = imagesy($im);
    $ystart = 0; $yheight=$h;
    if ($h>$w) { $ystart= ($h/2)-($w/2); $yheight=$w/2; }
    $nw = 120;   // Desired width
    $nh = min(floor(($h*$nw)/$w),120); // Compute new width/height, but maximum 120 pixels height.
    // Resize image:
    $im2 = imagecreatetruecolor($nw,$nh);
    imagecopyresampled($im2, $im, 0, 0, 0, $ystart, $nw, $nh, $w, $yheight);
    imageinterlace($im2,true); // For progressive JPEG.
    $tempname=$filepath.'_TEMP.jpg';
    imagejpeg($im2, $tempname, 90);
    imagedestroy($im);
    imagedestroy($im2);
    rename($tempname,$filepath);  // Overwrite original picture with thumbnail.
    return true;
}

// Invalidate caches when the database is changed or the user logs out.
// (eg. tags cache).
function invalidateCaches()
{
    unset($_SESSION['tags']);  // Purge cache attached to session.
    pageCache::purgeCache();   // Purge page cache shared by sessions.
}
