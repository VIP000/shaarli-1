<?php
// Shaarli 0.0.40 beta - Shaare your links...
// The personal, minimalist, super-fast, no-database delicious clone. By sebsauvage.net
// http://sebsauvage.net/wiki/doku.php?id=php:shaarli
// Licence: http://www.opensource.org/licenses/zlib-license.php
// Requires: php 5.1.x  (but autocomplete fields will only work if you have php 5.2.x)
// -----------------------------------------------------------------------------------------------
// Hardcoded parameter (These parameters can be overwritten by creating the file /config/options.php)
$GLOBALS['config']['DATADIR'] = 'data'; // Data subdirectory
$GLOBALS['config']['CONFIG_FILE'] = $GLOBALS['config']['DATADIR'].'/config.php'; // Configuration file (user login/password)
$GLOBALS['config']['DATASTORE'] = $GLOBALS['config']['DATADIR'].'/datastore.php'; // Data storage file.
$GLOBALS['config']['LINKS_PER_PAGE'] = 20; // Default links per page.
$GLOBALS['config']['IPBANS_FILENAME'] = $GLOBALS['config']['DATADIR'].'/ipbans.php'; // File storage for failures and bans.
$GLOBALS['config']['BAN_AFTER'] = 4;        // Ban IP after this many failures.
$GLOBALS['config']['BAN_DURATION'] = 1800;  // Ban duration for IP address after login failures (in seconds) (1800 sec. = 30 minutes)
$GLOBALS['config']['OPEN_SHAARLI'] = false; // If true, anyone can add/edit/delete links without having to login
$GLOBALS['config']['HIDE_TIMESTAMPS'] = false; // If true, the moment when links were saved are not shown to users that are not logged in.
$GLOBALS['config']['ENABLE_THUMBNAILS'] = true; // Enable thumbnails in links.
$GLOBALS['config']['CACHEDIR'] = 'cache'; // Cache directory for thumbnails for SLOW services (like flickr)
$GLOBALS['config']['PAGECACHE'] = 'pagecache'; // Page cache directory.
$GLOBALS['config']['ENABLE_LOCALCACHE'] = true; // Enable Shaarli to store thumbnail in a local cache. Disable to reduce webspace usage.
$GLOBALS['config']['PUBSUBHUB_URL'] = ''; // PubSubHubbub support. Put an empty string to disable, or put your hub url here to enable.
$GLOBALS['config']['UPDATECHECK_FILENAME'] = $GLOBALS['config']['DATADIR'].'/lastupdatecheck.txt'; // For updates check of Shaarli.
$GLOBALS['config']['UPDATECHECK_INTERVAL'] = 86400 ; // Updates check frequency for Shaarli. 86400 seconds=24 hours
                                          // Note: You must have publisher.php in the same directory as Shaarli index.php
// -----------------------------------------------------------------------------------------------
// You should not touch below (or at your own risks !)

// Optionnal config file.
if (is_file($GLOBALS['config']['DATADIR'].'/options.php')) require($GLOBALS['config']['DATADIR'].'/options.php');

include "inc/core.php";
include "inc/storage.php";
include "inc/user.php";
include "inc/thumb.php";
include "inc/theme.php";
include "inc/feed.php";

define('shaarli_version','0.0.40 beta');
define('PHPPREFIX','<?php /* '); // Prefix to encapsulate data in php code.
define('PHPSUFFIX',' */ ?>'); // Suffix to encapsulate data in php code.

// Force cookie path (but do not change lifetime)
$cookie=session_get_cookie_params();
session_set_cookie_params($cookie['lifetime'],dirname($_SERVER["SCRIPT_NAME"]).'/'); // Default cookie expiration and path.

// PHP Settings
ini_set('max_input_time','60');  // High execution time in case of problematic imports/exports.
ini_set('memory_limit', '128M');  // Try to set max upload file size and read (May not work on some hosts).
ini_set('post_max_size', '16M');
ini_set('upload_max_filesize', '16M');
checkphpversion();
error_reporting(E_ALL^E_WARNING);  // See all error except warnings.
//error_reporting(-1); // See all errors (for debugging only)

include "inc/rain.tpl.class.php"; //include Rain TPL
raintpl::$tpl_dir = "tpl/"; // template directory
if (!is_dir('tmp')) { mkdir('tmp',0705); chmod('tmp',0705); }
raintpl::$cache_dir = "tmp/"; // cache directory

ob_start();  // Output buffering for the page cache.


// In case stupid admin has left magic_quotes enabled in php.ini:
if (get_magic_quotes_gpc())
{
    function stripslashes_deep($value) { $value = is_array($value) ? array_map('stripslashes_deep', $value) : stripslashes($value); return $value; }
    $_POST = array_map('stripslashes_deep', $_POST);
    $_GET = array_map('stripslashes_deep', $_GET);
    $_COOKIE = array_map('stripslashes_deep', $_COOKIE);
}

// Prevent caching on client side or proxy: (yes, it's ugly)
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Directories creations (Note that your web host may require differents rights than 705.)
if (!is_dir($GLOBALS['config']['DATADIR'])) { mkdir($GLOBALS['config']['DATADIR'],0705); chmod($GLOBALS['config']['DATADIR'],0705); }
if (!is_dir('tmp')) { mkdir('tmp',0705); chmod('tmp',0705); } // For RainTPL temporary files.
if (!is_file($GLOBALS['config']['DATADIR'].'/.htaccess')) { file_put_contents($GLOBALS['config']['DATADIR'].'/.htaccess',"Allow from none\nDeny from all\n"); } // Protect data files.
if ($GLOBALS['config']['ENABLE_LOCALCACHE'])
{
    if (!is_dir($GLOBALS['config']['CACHEDIR'])) { mkdir($GLOBALS['config']['CACHEDIR'],0705); chmod($GLOBALS['config']['CACHEDIR'],0705); }
    if (!is_file($GLOBALS['config']['CACHEDIR'].'/.htaccess')) { file_put_contents($GLOBALS['config']['CACHEDIR'].'/.htaccess',"Allow from none\nDeny from all\n"); } // Protect data files.
}

// Run config screen if first run:
if (!is_file($GLOBALS['config']['CONFIG_FILE'])) install();

require $GLOBALS['config']['CONFIG_FILE'];  // Read login/password hash into $GLOBALS.

// Handling of old config file which do not have the new parameters.
if (empty($GLOBALS['title'])) $GLOBALS['title']='Shared links on '.htmlspecialchars(indexUrl());
if (empty($GLOBALS['timezone'])) $GLOBALS['timezone']=date_default_timezone_get();
if (empty($GLOBALS['disablesessionprotection'])) $GLOBALS['disablesessionprotection']=false;

autoLocale(); // Sniff browser language and set date format accordingly.
header('Content-Type: text/html; charset=utf-8'); // We use UTF-8 for proper international characters handling.

// ------------------------------------------------------------------------------------------
// Session management
define('INACTIVITY_TIMEOUT',3600); // (in seconds). If the user does not access any page within this time, his/her session is considered expired.
ini_set('session.use_cookies', 1);       // Use cookies to store session.
ini_set('session.use_only_cookies', 1);  // Force cookies for session (phpsessionID forbidden in URL)
ini_set('session.use_trans_sid', false); // Prevent php to use sessionID in URL if cookies are disabled.
session_name('shaarli');
session_start();

// ------------------------------------------------------------------------------------------
// Brute force protection system
// Several consecutive failed logins will ban the IP address for 30 minutes.
if (!is_file($GLOBALS['config']['IPBANS_FILENAME'])) file_put_contents($GLOBALS['config']['IPBANS_FILENAME'], "<?php\n\$GLOBALS['IPBANS']=".var_export(array('FAILURES'=>array(),'BANS'=>array()),true).";\n?>");
include $GLOBALS['config']['IPBANS_FILENAME'];

// ------------------------------------------------------------------------------------------
// Process login form: Check if login/password is correct.
if (isset($_POST['login']))
{
    if (!ban_canLogin()) die('I said: NO. You are banned for the moment. Go away.');
    if (isset($_POST['password']) && tokenOk($_POST['token']) && (check_auth($_POST['login'], $_POST['password'])))
    {   // Login/password is ok.
        ban_loginOk();
        // If user wants to keep the session cookie even after the browser closes:
        if (!empty($_POST['longlastingsession']))
        {
            $_SESSION['longlastingsession']=31536000;  // (31536000 seconds = 1 year)
            $_SESSION['expires_on']=time()+$_SESSION['longlastingsession'];  // Set session expiration on server-side.
            session_set_cookie_params($_SESSION['longlastingsession'],dirname($_SERVER["SCRIPT_NAME"]).'/'); // Set session cookie expiration on client side
            // Note: Never forget the trailing slash on the cookie path !
            session_regenerate_id(true);  // Send cookie with new expiration date to browser.
        }
        else // Standard session expiration (=when browser closes)
        {
            session_set_cookie_params(0,dirname($_SERVER["SCRIPT_NAME"]).'/'); // 0 means "When browser closes"
            session_regenerate_id(true);
        }
        // Optional redirect after login:
        if (isset($_GET['post'])) { header('Location: ?post='.urlencode($_GET['post']).(!empty($_GET['title'])?'&title='.urlencode($_GET['title']):'').(!empty($_GET['source'])?'&source='.urlencode($_GET['source']):'')); exit; }
        if (isset($_POST['returnurl']))
        {
            if (endsWith($_POST['returnurl'],'?do=login')) { header('Location: ?'); exit; } // Prevent loops over login screen.
            header('Location: '.$_POST['returnurl']); exit;
        }
        header('Location: ?'); exit;
    }
    else
    {
        ban_loginFailed();
        echo '<script language="JavaScript">alert("Wrong login/password.");document.location=\'?do=login\';</script>'; // Redirect to login screen.
        exit;
    }
}


if (isset($_SERVER["QUERY_STRING"]) && startswith($_SERVER["QUERY_STRING"],'do=genthumbnail')) { genThumbnail(); exit; }  // Thumbnail generation/cache does not need the link database.
if (isset($_SERVER["QUERY_STRING"]) && startswith($_SERVER["QUERY_STRING"],'do=rss')) { showRSS(); exit; }
if (isset($_SERVER["QUERY_STRING"]) && startswith($_SERVER["QUERY_STRING"],'do=atom')) { showATOM(); exit; }
if (isset($_SERVER["QUERY_STRING"]) && startswith($_SERVER["QUERY_STRING"],'do=dailyrss')) { showDailyRSS(); exit; }
if (isset($_SERVER["QUERY_STRING"]) && startswith($_SERVER["QUERY_STRING"],'do=daily')) { showDaily(); exit; }
if (isset($_SERVER["QUERY_STRING"]) && startswith($_SERVER["QUERY_STRING"],'ws=')) { processWS(); exit; } // Webservices (for jQuery/jQueryUI)
if (!isset($_SESSION['LINKS_PER_PAGE'])) $_SESSION['LINKS_PER_PAGE']=$GLOBALS['config']['LINKS_PER_PAGE'];
renderPage();
?>
