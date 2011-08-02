*Cpanel account importer*

The purpose of this script is to import cpanel backups into a system that uses no cpanel

	usage: script.php username password [domainname] [groupname] [mysqluser] [mysqlpass]

this script currently only supports:
* creation of users and assigning to a group
* creation of apache2 host file and symlinking it to active
* moving of all publi_html files
* creationg of log directories
* creation of mysql dbs and users
