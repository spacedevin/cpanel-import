#!/usr/bin/php
<?php

/*
 * Cpanel account importer
 *
 * The purpose of this script is to import cpanel backups into a system that uses no cpanel
 *
 * usage: cpanel-import.php username password [domainname] [groupname] [ip]
 *
 * this script currently only supports:
 *   creation of users and assigning to a group
 *   creation of apache2 host file and symlinking it to active
 *   moving of all homedir files
 *   creationg of log directories
 *   creation of mysql dbs and users
 */
 
error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors',true);
set_time_limit(0);
$config = new stdClass;


/*
 * edit this config to properly use this script
 */

// the source of where the backups are stored. backups need to be named like: USER.tar.gz
$config->source = '/home/devin/backups/';
// the path to your apache files. usually httpd or apache2
$config->http = '/etc/httpd/';
// where you want your users created
$config->dest = '/home/';
// your mysql username that has grant and create db permissions. probably root
$config->mysqlUser = 'root';
// your mysql password
$config->mysqlPass = null;


/*
 * you probably wont need to edit anything below this line
 */
 
// this template is created for apache in the sites-available path. edit this template if you want some suexec or something
$config->hostTemplate = '
<VirtualHost '.($config->ip ? $config->ip.':80' : '*').'>
	ServerName _DOMAIN_
	ServerAlias *._DOMAIN_ _DOMAINS_
	DocumentRoot /home/_USER_/www
	ServerAdmin webmaster@_DOMAIN_
	UseCanonicalName Off
	ErrorLog /home/_USER_/logs/error_log
	CustomLog /home/_USER_/logs/access_log combined
</VirtualHost>
';
// optional: if you want a different format of file name
$config->file = $config->source.$config->user.'.tar.gz';
// the groupname. you can pass this in arguments
$config->groupname = $argv[4] ? $argv[4] : 'web';
// the ip to bind to. you can pass this in arguments
$config->ip = $argv[5] ? $argv[5] : null;
// the files to NOT copy over form the homedir
$config->ignoreFiles = array(
	'mail',
	'public_ftp',
	'cpbackup.*',
	'backup.*',
	'access-logs',
	'tmp',
	'logs',
	'www',
	'.lastlogin',
	'.htpasswds',
	'.cpanel',
	'etc',
	'.ftpquota',
	'.autorespond',
	'.filter',
	'.spamkey',
	'.lang',
	'cpmove.psql',
	'.cpaddons',
	'account-locked.tpl',
	'.bash_history'
);
// you can add a prefix or something to the user if you would like
$config->user = $argv[1];
// the command to auth. probably wont need to edit this
$config->mysqlAuth = ' -u '.$config->mysqlUser.($config->mysqlPass ? ' -p '.$config->mysqlPass .' ' : '');


if (!$config->user) {
	die('usage: cpanel-import.php username password [domainname] [groupname] [ip]');
}

if (!file_exists($config->file)) {
	die('file "'.$config->file.'" does not exist.');
}


// create the user and give it a password
exec('groupadd '.$config->groupname);
exec('useradd '.$config->user.' -g '.$config->groupname);
exec('echo '.$argv[2].' | passwd '.$config->user.' --stdin');


// create the directory for working with
exec('rm -Rf '.$config->source.$config->user);
exec('mkdir '.$config->source.$config->user);


// create the user directory
exec('rm -Rf '.$config->dest.$config->user);
exec('mkdir '.$config->dest.$config->user);
exec('mkdir '.$config->dest.$config->user.'/logs');


// extract the files
exec('tar xzf '.$config->file.' -C '.$config->source.$config->user);
exec('tar xf '.$config->source.$config->user.'/*/homedir.tar -C '.$config->source.$config->user.'/*/homedir');


// set the extracted path
$config->extracted = exec('cd '.$config->source.$config->user.'/*/ && pwd').'/';


// move all web files
$iterator = new DirectoryIterator($config->extracted.'homedir');
foreach ($iterator as $fileinfo) {
	if ($fileinfo->isDot()) {
		continue;
	}
	$copy = true;
	foreach ($config->ignoreFiles as $ignore) {
		if (preg_match('/'.$ignore.'/',trim($fileinfo->getFilename()))) {
			$copy = false;
		}
	}
	if ($copy) {
		echo 'Copying: '.$config->extracted.'homedir/'.$fileinfo->getFilename()."\n";
		exec('mv '.$config->extracted.'homedir/'.$fileinfo->getFilename().' '.$config->dest.$config->user.'/');
	}
}


// rename public_html to www because its shorter and i hate public_html
exec('mv '.$config->dest.$config->user.'/public_html/ '.$config->dest.$config->user.'/www/');


// set up the host template
$domains = file($config->extracted.'cp/'.$config->user);
foreach ($domains as $line) {
	if (preg_match('/^DNS([0-9]+)?=.*$/',$line)) {
		$doms[] = trim(preg_replace('/^DNS([0-9]+)?=(.*)$/','\\2',$line));
	}
}
$config->domain = $argv[3] ? $argv[3] : array_shift($doms);
$domains = implode($doms,' ');

$find = array(
	'/_USER_/',
	'/_DOMAIN_/',
	'/_DOMAINS_/'
);
$replace = array(
	$config->user,
	$config->domain,
	$domains
);
$template = preg_replace($find, $replace, $config->hostTemplate);
file_put_contents($config->http.'sites-available/'.$config->user.'.conf', $template);
exec('cd '.$config->http.'sites-enabled && ln -s ../sites-available/'.$config->user.'.conf');


// change permissions
exec('chown -R '.$config->user.':'.$config->groupname.' '.$config->dest.$config->user);


// create mysql databases and grant permissions
$iterator = new DirectoryIterator($config->extracted.'mysql/');
foreach ($iterator as $fileinfo) {
	if ($fileinfo->isFile() && $fileinfo->getFilename() != 'horde.sql') {
		$db = new stdClass;
		$db->name = $fileinfo->getBasename('.sql');
		$db->file = $fileinfo->getFilename();
		$databases[] = $db;
	}
}

function sql_write($sql, $dbname = '') {
	global $config;
	$tmpfile = tempnam('/tmp', 'temp-sql-import-');
	file_put_contents($tmpfile,$sql);

	if ($dbname) {
		exec('mysql'.$config->mysqlAuth.' "'.$database->name.'" < "'.$tmpfile.'"');
	} else {
		exec('mysql'.$config->mysqlAuth.' < "'.$tmpfile.'"');
	}
	unlink($tmpfile);
}

sql_write("CREATE USER '".$config->user."'@'localhost' IDENTIFIED BY '".$argv[2]."';\n");

if ($databases) {
	foreach ($databases as $database) {
		$sql = "CREATE DATABASE `".$database->name."`;\n";
		$sql .= "GRANT ALL ON `".$database->name."`.* TO '".$config->user."'@'localhost';\n";
		sql_write($sql,$database->name);
		exec('mysql'.$config->mysqlAuth.' "'.$database->name.'" < "'.$config->extracted.'mysql/'.$database->file.'"');
	}
} else {
	echo "no databases.\n";
}


// remove extracted files
exec('rm -Rf '.$config->source.$config->user);


// restart apache
exec('/etc/init.d/httpd restart');