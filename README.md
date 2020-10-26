# mysql-nextcloud-contacts-syncer
PHP script which sync mysql mail users database with contacts app in Nexcloud
This is my implementation of sync script between my mail mysql database for mail system and my Nextcloud instance.
In order this script to work there must be a vcard parser class from Martins Pilsetnieks, Roberts Bruveris 
https://github.com/nuovo/vCard-parser in the same directory as addr_sync.php

addr_sync.php - main script

db-connect.inc - for database connection configuration

vCard.php - class from Martins Pilsetnieks, Roberts Bruveris https://github.com/nuovo/vCard-parser

photo_update.php - script for ldap jpegPhoto attribute sync with Nextcloud and main mail db (it doesn't need after v. 0.5 as main script addr_sync.php has all avatar sync functions)
