<?php
// Shaarli 0.0.40 beta - Shaare your links...
// The personal, minimalist, super-fast, no-database delicious clone. By sebsauvage.net
// http://sebsauvage.net/wiki/doku.php?id=php:shaarli
// Licence: http://www.opensource.org/licenses/zlib-license.php
// Requires: php 5.1.x  (but autocomplete fields will only work if you have php 5.2.x)
// -----------------------------------------------------------------------------------------------

/*
Default parameters. You can override these parameters by creating a file
config/options.php and placing them inside.
*/

// Data subdirectory.
$GLOBALS['config']['DATADIR'] = 'data';
// Configuration file.
$GLOBALS['config']['CONFIG_FILE'] = $GLOBALS['config']['DATADIR'].'/config.php';
// Data storage file.
$GLOBALS['config']['DATASTORE'] = $GLOBALS['config']['DATADIR'].'/datastore.php';
// Default number of links per page.
$GLOBALS['config']['LINKS_PER_PAGE'] = 20;
// File storage for login failures and IP bans.
$GLOBALS['config']['IPBANS_FILENAME'] = $GLOBALS['config']['DATADIR'].'/ipbans.php';
// Ban after this many login failures.
$GLOBALS['config']['BAN_AFTER'] = 4;
// Ban duration for IP address in seconds (1800s = 30min).
$GLOBALS['config']['BAN_DURATION'] = 1800;
// Disable authentication. If true, anyone can add/edit/delete links without login.
$GLOBALS['config']['OPEN_SHAARLI'] = false;
// Hide link creation date. If true, links creation timestamp is not shown to anonymous users.
$GLOBALS['config']['HIDE_TIMESTAMPS'] = false;
// Enable thumbnails in links.
$GLOBALS['config']['ENABLE_THUMBNAILS'] = true;
// Thumbnails cache directory.
$GLOBALS['config']['CACHEDIR'] = 'cache';
// Page cache directory.
$GLOBALS['config']['PAGECACHE'] = 'pagecache';
// Enable thumbnail caching. Disable to reduce webspace usage.
$GLOBALS['config']['ENABLE_LOCALCACHE'] = true;
// For updates check of Shaarli.
$GLOBALS['config']['UPDATECHECK_FILENAME'] = $GLOBALS['config']['DATADIR'].'/lastupdatecheck.txt';
// Updates check frequency for Shaarli. 86400 seconds=24 hours
$GLOBALS['config']['UPDATECHECK_INTERVAL'] = 86400 ;

// Optional config file.
if ($conf_file = $GLOBALS['config']['DATADIR'].'/options.php' and is_file($conf_file)) {
    require($conf_file);
}

// Session management.
define('INACTIVITY_TIMEOUT',3600);
ini_set('session.use_cookies', 1);
ini_set('session.use_trans_sid', false);
session_name('shaarli');
session_start();
// Force cookie path (but do not change lifetime)
$cookie=session_get_cookie_params();
// Default cookie expiration and path.
session_set_cookie_params($cookie['lifetime'],dirname($_SERVER["SCRIPT_NAME"]).'/');

// Shaarli constants.
define('shaarli_version','0.0.40 beta');
define('PHPPREFIX','<?php /* '); // Prefix to encapsulate data in php code.
define('PHPSUFFIX',' */ ?>'); // Suffix to encapsulate data in php code.

// Include Shaarli files.
include "inc/core.php";
include "inc/storage.php";
include "inc/user.php";
include "inc/thumb.php";
include "inc/theme.php";
include "inc/feed.php";
include "inc/rain.tpl.class.php";

// PHP Settings.
ini_set('max_input_time','60');
ini_set('memory_limit', '128M');
ini_set('post_max_size', '16M');
ini_set('upload_max_filesize', '16M');
checkphpversion();
error_reporting(E_ALL^E_WARNING);

 // Template directory.
raintpl::$tpl_dir = "tpl/";
 // Cache directory.
if (!is_dir('tmp')) {
    mkdir('tmp', 0705);
    chmod('tmp', 0705);
}
raintpl::$cache_dir = "tmp/";

// Output buffering for the page cache.
ob_start();

// In case stupid admin has left magic_quotes enabled in php.ini.
if (get_magic_quotes_gpc()) {
    function stripslashes_deep($value) {
        $value = is_array($value) ? array_map('stripslashes_deep', $value) : stripslashes($value);
        return $value;
    }
    $_POST = array_map('stripslashes_deep', $_POST);
    $_GET = array_map('stripslashes_deep', $_GET);
    $_COOKIE = array_map('stripslashes_deep', $_COOKIE);
}

// Prevent caching on client side or proxy (yes, it's ugly).
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Directories creations (Note that your web host may require differents rights than 705.)
if (!is_dir($GLOBALS['config']['DATADIR'])) {
    mkdir($GLOBALS['config']['DATADIR'], 0705);
    chmod($GLOBALS['config']['DATADIR'], 0705);
}
if (!is_dir('tmp')) {
    mkdir('tmp', 0705);
    chmod('tmp', 0705);
}
if (!is_file($GLOBALS['config']['DATADIR'].'/.htaccess')) {
    file_put_contents($GLOBALS['config']['DATADIR'].'/.htaccess',"Allow from none\nDeny from all\n");
}
if ($GLOBALS['config']['ENABLE_LOCALCACHE']) {
    if (!is_dir($GLOBALS['config']['CACHEDIR'])) {
        mkdir($GLOBALS['config']['CACHEDIR'], 0705);
        chmod($GLOBALS['config']['CACHEDIR'], 0705);
    }
    if (!is_file($GLOBALS['config']['CACHEDIR'].'/.htaccess')) {
        file_put_contents($GLOBALS['config']['CACHEDIR'].'/.htaccess',"Allow from none\nDeny from all\n");
    }
}

// Run config screen if first run:
if (!is_file($GLOBALS['config']['CONFIG_FILE'])) {
    install();
}

// Read login/password hash into $GLOBALS.
require $GLOBALS['config']['CONFIG_FILE'];

// Handling of old config file which do not have the new parameters.
if (empty($GLOBALS['title'])) {
    $GLOBALS['title']='Shared links on '.htmlspecialchars(indexUrl());
}
if (empty($GLOBALS['timezone'])) {
    $GLOBALS['timezone']=date_default_timezone_get();
}
if (empty($GLOBALS['disablesessionprotection'])) {
    $GLOBALS['disablesessionprotection']=false;
}

// Sniff browser language and set date format accordingly.
autoLocale();
header('Content-Type: text/html; charset=utf-8');

// Brute force protection system.
if (!is_file($GLOBALS['config']['IPBANS_FILENAME'])) {
    file_put_contents(
        $GLOBALS['config']['IPBANS_FILENAME'],
        "<?php\n\$GLOBALS['IPBANS']=" . var_export(array('FAILURES'=>array(), 'BANS'=>array()), true) . ";\n?>"
    );
}
include $GLOBALS['config']['IPBANS_FILENAME'];

// Process login form.
if (isset($_POST['login'])) {
    if (!ban_canLogin()) {
        die('I said: NO. You are banned for the moment. Go away.');
    }

    if (isset($_POST['password']) && tokenOk($_POST['token']) && (check_auth($_POST['login'], $_POST['password']))) {
        // Login/password is ok.
        ban_loginOk();
        // If user wants to keep the session cookie even after the browser closes:
        $cookie_path = dirname($_SERVER["SCRIPT_NAME"]);
        if (substr($cookie_path, -1) !== '/') {
            $cookie_path .= '/';
        }
        if (!empty($_POST['longlastingsession'])) {
            // (31536000 seconds = 1 year)
            $_SESSION['longlastingsession']=31536000;
            // Set session expiration on server-side.
            $_SESSION['expires_on']=time()+$_SESSION['longlastingsession'];
            // Set session cookie expiration on client side.
            session_set_cookie_params($_SESSION['longlastingsession'], $cookie_path);
        } else {
            // Standard session expiration (when browser closes).
            session_set_cookie_params(0, $cookie_path);
        }
        session_regenerate_id(true);
        // Optional redirect after login.
        if (isset($_GET['post'])) {
            header('Location: ?post='.urlencode($_GET['post']).(!empty($_GET['title'])?'&title='.urlencode($_GET['title']):'').(!empty($_GET['source'])?'&source='.urlencode($_GET['source']):''));
            exit;
        }
        if (isset($_POST['returnurl'])) {
             // Prevent loops over login screen.
            if (endsWith($_POST['returnurl'],'?do=login')) {
                header('Location: ?'); exit;
            }
            header('Location: '.$_POST['returnurl']); exit;
        }
        header('Location: ?'); exit;
    } else {
        ban_loginFailed();
        echo '<script language="JavaScript">alert("Wrong login/password.");document.location=\'?do=login\';</script>'; // Redirect to login screen.
        exit;
    }
}

// Thumbnail generation/cache does not need the link database.
if (isset($_SERVER["QUERY_STRING"]) && startswith($_SERVER["QUERY_STRING"],'do=genthumbnail')) {
    genThumbnail();
    exit;
}

if (isset($_SERVER["QUERY_STRING"]) && startswith($_SERVER["QUERY_STRING"],'do=rss')) {
    showRSS();
    exit;
}

if (isset($_SERVER["QUERY_STRING"]) && startswith($_SERVER["QUERY_STRING"],'do=atom')) {
    showATOM();
    exit;
}

// Webservices (for jQuery/jQueryUI)
if (isset($_SERVER["QUERY_STRING"]) && startswith($_SERVER["QUERY_STRING"],'ws=')) {
    processWS();
    exit;
}

if (!isset($_SESSION['LINKS_PER_PAGE'])) {
    $_SESSION['LINKS_PER_PAGE']=$GLOBALS['config']['LINKS_PER_PAGE'];
}

renderPage();
