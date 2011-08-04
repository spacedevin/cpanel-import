Cpanel account importer
=======================

The purpose of this script is to import cpanel backups into a system that uses no cpanel. Run this script from root.

This script currently only supports:

* creation of users and assigning to a group

* creation of apache2 host file and symlinking it to active

* moving of all homedir files

* creationg of log directories

* creation of mysql dbs and users

<br />
Usage
-----
	Usage: cpanel-import.php file [options]
	Ex: cpanel-import.php devin.tar.gz --ip=100.100.100.100 --forceuser

**--username** - Import as a different username.

**--password** - Overwrite the users password.

**--ip** - The IP to bind the vhost to.

**--mysql-user** - The root or super admin for mysql.

**--mysql-pass** - The root or super admin password for mysql.

**--source** - Where to find the file.

**--dest** - Where to find put the user.

**--ignore** - Comma separate list of files to ignore in homedir.

**--httpd** - Apache path.

**--debug** - Show commands.

**--verbose** - Show additional info.

**--forceuser** - If the user exists, keep going, and delete their home directory.
