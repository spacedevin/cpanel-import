Cpanel account importer
=======================

The purpose of this script is to import cpanel backups into a system that uses no cpanel. Run this script from root.

	usage: cpanel-import.php username password [domainname] [groupname] [ip]

this script currently only supports:

* creation of users and assigning to a group

* creation of apache2 host file and symlinking it to active

* moving of all homedir files

* creationg of log directories

* creation of mysql dbs and users
