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

// Returns the IP address of the client (Used to prevent session cookie hijacking.)
function allIPs()
{
    $ip = $_SERVER["REMOTE_ADDR"];
    // Then we use more HTTP headers to prevent session hijacking from users behind the same proxy.
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) { $ip=$ip.'_'.$_SERVER['HTTP_X_FORWARDED_FOR']; }
    if (isset($_SERVER['HTTP_CLIENT_IP'])) { $ip=$ip.'_'.$_SERVER['HTTP_CLIENT_IP']; }
    return $ip;
}

// Check that user/password is correct.
function check_auth($login,$password)
{
    $hash = sha1($password.$login.$GLOBALS['salt']);
    if ($login==$GLOBALS['login'] && $hash==$GLOBALS['hash'])
    {   // Login/password is correct.
        $_SESSION['uid'] = sha1(uniqid('',true).'_'.mt_rand()); // generate unique random number (different than phpsessionid)
        $_SESSION['ip']=allIPs();                // We store IP address(es) of the client to make sure session is not hijacked.
        $_SESSION['username']=$login;
        $_SESSION['expires_on']=time()+INACTIVITY_TIMEOUT;  // Set session expiration.
        logm('Login successful');
        return True;
    }
    logm('Login failed for user '.$login);
    return False;
}

// Returns true if the user is logged in.
function isLoggedIn()
{
    if ($GLOBALS['config']['OPEN_SHAARLI']) return true;

    // If session does not exist on server side, or IP address has changed, or session has expired, logout.
    if (empty($_SESSION['uid']) || ($GLOBALS['disablesessionprotection']==false && $_SESSION['ip']!=allIPs()) || time()>=$_SESSION['expires_on'])
    {
        logout();
        return false;
    }
    if (!empty($_SESSION['longlastingsession']))  $_SESSION['expires_on']=time()+$_SESSION['longlastingsession']; // In case of "Stay signed in" checked.
    else $_SESSION['expires_on']=time()+INACTIVITY_TIMEOUT; // Standard session expiration date.

    return true;
}

// Force logout.
function logout() { if (isset($_SESSION)) { unset($_SESSION['uid']); unset($_SESSION['ip']); unset($_SESSION['username']);}  }

// Signal a failed login. Will ban the IP if too many failures:
function ban_loginFailed()
{
    $ip=$_SERVER["REMOTE_ADDR"]; $gb=$GLOBALS['IPBANS'];
    if (!isset($gb['FAILURES'][$ip])) $gb['FAILURES'][$ip]=0;
    $gb['FAILURES'][$ip]++;
    if ($gb['FAILURES'][$ip]>($GLOBALS['config']['BAN_AFTER']-1))
    {
        $gb['BANS'][$ip]=time()+$GLOBALS['config']['BAN_DURATION'];
        logm('IP address banned from login');
    }
    $GLOBALS['IPBANS'] = $gb;
    file_put_contents($GLOBALS['config']['IPBANS_FILENAME'], "<?php\n\$GLOBALS['IPBANS']=".var_export($gb,true).";\n?>");
}

// Signals a successful login. Resets failed login counter.
function ban_loginOk()
{
    $ip=$_SERVER["REMOTE_ADDR"]; $gb=$GLOBALS['IPBANS'];
    unset($gb['FAILURES'][$ip]); unset($gb['BANS'][$ip]);
    $GLOBALS['IPBANS'] = $gb;
    file_put_contents($GLOBALS['config']['IPBANS_FILENAME'], "<?php\n\$GLOBALS['IPBANS']=".var_export($gb,true).";\n?>");
}

// Checks if the user CAN login. If 'true', the user can try to login.
function ban_canLogin()
{
    $ip=$_SERVER["REMOTE_ADDR"]; $gb=$GLOBALS['IPBANS'];
    if (isset($gb['BANS'][$ip]))
    {
        // User is banned. Check if the ban has expired:
        if ($gb['BANS'][$ip]<=time())
        {   // Ban expired, user can try to login again.
            logm('Ban lifted.');
            unset($gb['FAILURES'][$ip]); unset($gb['BANS'][$ip]);
            file_put_contents($GLOBALS['config']['IPBANS_FILENAME'], "<?php\n\$GLOBALS['IPBANS']=".var_export($gb,true).";\n?>");
            return true; // Ban has expired, user can login.
        }
        return false; // User is banned.
    }
    return true; // User is not banned.
}

// ------------------------------------------------------------------------------------------
// Token management for XSRF protection
// Token should be used in any form which acts on data (create,update,delete,import...).
if (!isset($_SESSION['tokens'])) {
    $_SESSION['tokens']=array();  // Token are attached to the session.
}

// Returns a token.
function getToken() {
    $rnd = sha1(uniqid('',true));  // We generate a random string.
    $_SESSION['tokens'][$rnd]=1;  // Store it on the server side.
    return $rnd;
}

// Tells if a token is ok. Using this function will destroy the token.
// true=token is ok.
function tokenOk($token)
{
    if (isset($_SESSION['tokens'][$token]))
    {
        unset($_SESSION['tokens'][$token]); // Token is used: destroy it.
        return true; // Token is ok.
    }
    return false; // Wrong token, or already used.
}
