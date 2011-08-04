#!/usr/bin/php
<?php

/*
 * Cpanel account importer
 *
 * The purpose of this script is to import cpanel backups into a system that uses no cpanel
 *
 * usage: cpanel-import.php file [options]
 *
 * this script currently only supports:
 *   creation of users and assigning to a group
 *   creation of apache2 host file and symlinking it to active
 *   moving of all homedir files
 *   creationg of log directories
 *   creation of mysql dbs and users
 */


// look for some errors
error_reporting(E_ALL ^ E_NOTICE);
ini_set('display_errors',true);
set_time_limit(0);
date_default_timezone_set('America/Los_Angeles');


// set up the config
$config = new CpanelImport_Config;
$config->loadArgs($argv);

// import!
$import = new CpanelImport($config);
$import->import();


class CpanelImport {
	public function __construct(CpanelImport_Config $config) {
		$this->config = $config;
	}
	
	public function import() {
		$this->files = new CpanelImport_Files($this);
		$this->files->extract();
		
		$this->group = new CpanelImport_Group($this);
		$this->group->create();
		
		$this->user = new CpanelImport_User($this);
		$this->user->create();
		
		$this->vhost = new CpanelImport_Vhost($this);
		$this->vhost->write();
		
		$this->mysql = new CpanelImport_Mysql($this);
		$this->mysql->create();
		
		$this->files->move();
		//$this->files->remove();

		// restart apache
		CpanelImport::exec('/etc/init.d/httpd restart');
	}
	
	public static function exec($cmd) {
		if (defined('CPI_DEBUG')) {
			echo "Running command:\n".$cmd."\n\n";
		}
		return exec($cmd);
	}
	
	public static function message($message) {
		if (defined('CPI_VERBOSE')) {
			echo $message."\n";
		}
	}
	
	public function usage() {
		echo "fuck you\n\n";
	}
}


class CpanelImport_Config {
	
	// the source of where the backups are stored. backups need to be named like: USER.tar.gz
	public $source = '/home/devin/backup/';

	// the path to your apache files. usually httpd or apache2
	public $http = '/etc/httpd/';

	// where you want your users created
	public $dest = '/home/';

	// your mysql username that has grant and create db permissions. probably root
	public $mysqlUser = 'root';

	// your mysql password
	public $mysqlPass = null;

	// this template is created for apache in the sites-available path. edit this template if you want some suexec or something
	public $hostTemplate = '
	<VirtualHost _IP_>
		ServerName _DOMAIN_
		ServerAlias *._DOMAIN_ _DOMAINS_
		DocumentRoot /home/_USER_/www
		ServerAdmin webmaster@_DOMAIN_
		UseCanonicalName Off
		ErrorLog /home/_USER_/logs/error_log
		CustomLog /home/_USER_/logs/access_log combined
	</VirtualHost>
	';

	// the files to NOT copy over form the homedir
	public $ignoreFiles = array(
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
	
	//default values;
	public $password;
	public $username;
	public $ip;
	public $filename;
	public $debug = false;
	public $forceuser = false;
	public $verbose = false;
	
	public function loadArgs($args) {
		$args = $this->parseArgs($args);
		if (!$args) {
			throw new CpanelImport_Exception;
		}
		
		$this->filename = array_shift($args);
		foreach ($args as $key => $value) {

				switch ($key) {
					case 'pasword':
						$this->password = $value;
						break;
					case 'ip':
						$this->ip = $value;
						break;
					case 'debug':
						define('CPI_DEBUG',true);
						$this->debug = true;
						break;
					case 'v':
					case 'verbose':
						define('CPI_VERBOSE',true);
						$this->verbose = true;
						break;
					case 'forceuser':
						$this->forceuser = true;
						break;
				}

		}
	
	}
	
	private function parseArgs($argv){
		array_shift($argv); $o = array();
		foreach ($argv as $a){
			if (substr($a,0,2) == '--'){ $eq = strpos($a,'=');
				if ($eq !== false){ $o[substr($a,2,$eq-2)] = substr($a,$eq+1); }
				else { $k = substr($a,2); if (!isset($o[$k])){ $o[$k] = true; } } }
			else if (substr($a,0,1) == '-'){
				if (substr($a,2,1) == '='){ $o[substr($a,1,1)] = substr($a,3); }
				else { foreach (str_split(substr($a,1)) as $k){ if (!isset($o[$k])){ $o[$k] = true; } } } }
			else { $o[] = $a; } }
		return $o;
	}
}


class CpanelImport_Group {
	public $groupname;

	public function __construct($import) {
		$this->groupname = $this->import->config->groupname ? $this->import->config->groupname : 'web';
	}

	public function create() {
		CpanelImport::exec('groupadd '.$this->groupname);
	}
}


class CpanelImport_User {
	public $username;
	public $password;
	public $shadow;
	public $cpanelUsername;

	public function __construct($import) {
		$this->import = $import;
		$this->cpanelUsername = trim(CpanelImport::exec('ls '.$this->import->files->extracted.'cp'));
		$this->password = $this->import->config->password;
		$this->shadow = trim(file_get_contents($this->import->files->extracted.'shadow'));
		$this->username = $this->import->config->username ? $this->import->config->username : $this->cpanelUsername;
		if (!$this->import->config->forceuser && $this->check()) {
			throw new CpanelImport_Exception('The user "'.$this->username.'" alerady exists!');
		}
	}
	
	public function create() {
		CpanelImport::exec('useradd '.$this->username.' -g'.$this->import->group->groupname);
		if ($this->password) {
			CpanelImport::exec('echo '.$argv[2].' | passwd '.$this->username.' --stdin');
		} else {
			CpanelImport::exec('awk -F: \'/'.$this->username.'/ { OFS=":"; $2="'.$this->shadow.'"; print}\' /etc/shadow');
		}
	}
	
	public function check() {
		return strpos(CpanelImport::exec('id '.$this->username),'No such user') ? false : true;
	}
}


class CpanelImport_Files {
	public $extracted;
	private $basename;

	public function __construct($import) {
		$this->import = $import;
		if (!file_exists($this->import->config->source.$this->import->config->filename)) {
			throw new CpanelImport_Exception('The file "'.$this->import->config->filename.'" does not exist!');
		}
		$this->basename = 'restore-'.substr($this->import->config->filename,0,strpos($this->import->config->filename,'.')).'-'.date('YmdHis').'.tmp';
	}

	public function extract() {
		// extract the files
		mkdir($this->import->config->source.$this->basename);
		CpanelImport::exec('tar xzf '.$this->import->config->source.$this->import->config->filename.' -C '.$this->import->config->source.$this->basename);
		CpanelImport::exec('tar xf '.$this->import->config->source.$this->basename.'/*/homedir.tar -C '.$this->import->config->source.$this->basename.'/*/homedir');
		$this->extracted = exec('cd '.$this->import->config->source.$this->basename.'/*/ && pwd').'/';
	}
	
	public function move() {
		// create the user directory

		if (!$this->import->user->username) {
			throw new CpanelImport_Exception('There is no username!');
		}
		if ($this->import->config->forceuser) {
			CpanelImport::exec('rm -Rf '.$this->import->config->dest.$this->import->user->username);
		} else {
			throw new CpanelImport_Exception('The directory "'.$this->import->config->dest.$this->import->user->username.'" alredy exists!');
		}
		CpanelImport::exec('mkdir '.$this->import->config->dest.$this->import->user->username);
		CpanelImport::exec('mkdir '.$this->import->config->dest.$this->import->user->username.'/logs');
		
		// move all web files
		$iterator = new DirectoryIterator($this->extracted.'homedir');
		foreach ($iterator as $fileinfo) {
			if ($fileinfo->isDot()) {
				continue;
			}
			$copy = true;
			foreach ($this->import->config->ignoreFiles as $ignore) {
				if (preg_match('/'.$ignore.'/',trim($fileinfo->getFilename()))) {
					$copy = false;
				}
			}
			if ($copy) {
				CpanelImport::message('Copying: '.$this->extracted.'homedir/'.$fileinfo->getFilename());
				CpanelImport::exec('mv '.$this->extracted.'homedir/'.$fileinfo->getFilename().' '.$this->import->config->dest.$this->import->user->username.'/');
			}
		}

		// rename public_html to www because its shorter and i hate public_html
		CpanelImport::exec('mv '.$this->import->config->dest.$this->import->user->username.'/public_html/ '.$this->import->config->dest.$this->import->user->username.'/www/');

		// change permissions
		CpanelImport::exec('chown -R '.$this->import->user->username.':'.$this->import->group->groupname.' '.$this->import->config->dest.$this->import->user->username);

	}

	public function remove() {
		// remove extracted files
		CpanelImport::exec('rm -Rf '.$this->import->config->source.$this->basename);
	}
}


class CpanelImport_Vhost {
	public $domain;
	public $domains;

	public function __construct($import) {
		$this->import = $import;
		$this->read();
	}
	
	public function read() {
		$domains = file($this->import->files->extracted.'cp/'.$this->import->user->username);
		foreach ($domains as $line) {
			if (preg_match('/^DNS([0-9]+)?=.*$/',$line)) {
				$doms[] = trim(preg_replace('/^DNS([0-9]+)?=(.*)$/','\\2',$line));
			}
		}
		$this->domain = $this->import->config->domain ? $this->import->config->domain : array_shift($doms);
		$this->domains = implode($doms,' ');
	}
	
	public function write() {
		CpanelImport::message("Writing vhosts ...");
		$find = array(
			'/_IP_/',
			'/_USER_/',
			'/_DOMAIN_/',
			'/_DOMAINS_/'
		);
		$replace = array(
			($this->import->config->ip ? $this->import->config->ip.':80' : '*'),
			$this->import->user->username,
			$this->domain,
			$this->domains
		);
		$template = preg_replace($find, $replace, $this->import->config->hostTemplate);
		file_put_contents($this->import->config->http.'sites-available/'.$this->import->user->username.'.conf', $template);
		CpanelImport::exec('cd '.$this->import->config->http.'sites-enabled && ln -s ../sites-available/'.$this->import->user->username.'.conf');
	}
}


class CpanelImport_Mysql {
	private $auth;

	public function __construct($import) {
		$this->import = $import;
		$this->auth = ' -u '.$this->import->config->mysqlUser.($this->import->config->mysqlPass ? ' -p'.$this->import->config->mysqlPass .' ' : '');
	}
	
	public function create() {
		
		// create mysql databases and grant permissions
		$iterator = new DirectoryIterator($this->import->files->extracted.'mysql/');
		foreach ($iterator as $fileinfo) {
			if ($fileinfo->isFile() && $fileinfo->getFilename() != 'horde.sql') {
				$db = new stdClass;
				$db->name = $fileinfo->getBasename('.sql');
				$db->file = $fileinfo->getFilename();
				$databases[] = $db;
			}
		}
		
		//$this->sqlWrite("CREATE USER '".$this->import->user->username."'@'localhost' IDENTIFIED BY '".$argv[2]."';\n");
		
		if ($databases) {
			CpanelImport::message("Creating databases ...");
			$sql = '';
			foreach ($databases as $database) {
				$sql .= "CREATE DATABASE `".$database->name."`;\n";
				//$sql .= "GRANT ALL ON `".$database->name."`.* TO '".$this->import->user->username."'@'localhost';\n";
			}
			$this->sqlWrite($sql);
			$sql = file_get_contents($this->import->files->extracted.'mysql.sql');
			if ($this->import->user->cpanelUsername != $this->import->user->username) {
				$sql = str_replace("'".$this->import->user->cpanelUsername.'_',"'".$this->import->user->username.'_');
				$sql = str_replace('`'.$this->import->user->cpanelUsername.'\_','`'.$this->import->user->username.'\_');
			}
			$this->sqlWrite($sql);
			foreach ($databases as $database) {
				CpanelImport::exec('mysql'.$this->import->config->mysqlAuth.' "'.$database->name.'" < "'.$this->import->files->extracted.'mysql/'.$database->file.'"');
			}
		} else {
			CpanelImport::message("No databases to create.");
		}
	}

	private function sqlWrite($sql, $dbname = '') {
		$tmpfile = tempnam('/tmp', 'temp-sql-import-');
		file_put_contents($tmpfile,$sql);
		if ($dbname) {
			CpanelImport::exec('mysql'.$this->auth.' "'.$database->name.'" < "'.$tmpfile.'"');
		} else {
			CpanelImport::exec('mysql'.$this->auth.' < "'.$tmpfile.'"');
		}
		unlink($tmpfile);
	}

}


class CpanelImport_Exception extends Exception {
	public function __construct($e = null) {
		if ($e) echo $e."\n";
		CpanelImport::usage();
		exit;
	}
}
