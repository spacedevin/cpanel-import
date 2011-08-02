#!/usr/bin/php
<?php

/*
 * Cpanel account importer
 *
 * The purpose of this script is to import cpanel backups into a system that uses no cpanel
 *
 * usage: script.php username password [domainname] [groupname] [mysqluser] [mysqlpass]
 *
 * this script currently only supports:
 *   creation of users and assigning to a grou
 *   creation of apache2 host file and symlinking it to active
 *   moving of all publi_html files
 *   creationg of log directories
 *   creation of mysql dbs and users
 */

$config = new stdClass;
$config->user = $argv[1];
$config->source = '/home/devin/backups/';
$config->file = $config->source.$config->user.'.tar.gz';
$config->dest = '/home/';
$config->groupname = $argv[4] ? $argv[4] : 'web';
$config->mysqlAuth = $argv[5] ? ' -u '.$argv[5].' -p'.$argv[6].' ' : ' -u root -proot';

$config->http = '/etc/httpd/';

$config->hostTemplate = '
<VirtualHost *>
	ServerName _DOMAIN_
	ServerAlias *._DOMAIN_ _DOMAINS_
	DocumentRoot /home/_USER_/www
	ServerAdmin webmaster@_DOMAIN_
	UseCanonicalName Off
</VirtualHost>
';

if (!$config->user) {
	die('usage: script.php username password [domainname] [groupname]');
}

if (!file_exists($config->file)) {
	die('file "'.$config->file.'" does not exist.');
}

// create the user and give it a password
exec('groupadd '.$config->groupname);
exec('useradd '.$config->user.' -p '.$argv[2].' -g '.$config->groupname);


// create the directory for working with
exec('rm -Rf '.$config->source.$config->user);
exec('mkdir '.$config->source.$config->user);


// create the user directory
exec('rm -Rf '.$config->dest.$config->user);
exec('mkdir '.$config->dest.$config->user);
exec('mkdir '.$config->dest.$config->user.'/www');
exec('mkdir '.$config->dest.$config->user.'/logs');


// extract the files
exec('tar xzf '.$config->file.' -C '.$config->source.$config->user);
exec('tar xf '.$config->source.$config->user.'/*/homedir.tar -C '.$config->source.$config->user.'/*/homedir');


// set the extracted path
$config->extracted = exec('cd '.$config->source.$config->user.'/*/ && pwd').'/';


// move all web files
exec('mv '.$config->extracted.'homedir/public_html/* '.$config->dest.$config->user.'/www/');
@exec('mv '.$config->extracted.'homedir/.git '.$config->dest.$config->user);
@exec('mv '.$config->extracted.'homedir/.gitconfig '.$config->dest.$config->user);


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

foreach ($databases as $database) {
	$sql = "CREATE DATABASE `".$database->name."`;\n";
	$sql .= "GRANT ALL PRIVILEGES ON *.".$database->name." TO '".$config->user."'@'localhost';\n";
	sql_write($sql,$database->name);
	exec('mysql'.$config->mysqlAuth.' "'.$database->name.'" < "'.$config->extracted.'mysql/'.$database->file.'"');
}


// remove extracted files
exec('rm -Rf '.$config->source.$config->user);


// restart apache
exec('/etc/init.d/httpd restart');