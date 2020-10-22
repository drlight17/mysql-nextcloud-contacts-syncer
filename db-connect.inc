<?php 
  $mysql_connect = array
  (
    "host_db" 			=> "localhost", //host with exim db
    "host_cloud"		=> "localhost", // host with cloud db
	"user"			=> "username",
	"pass"			=> "password",
	"db_mail"		=> "mail",
	"db_cloud"		=> "nextcloud",
	"tbl_src" 		=> "addressbook_all_with_aliases", //table with addressbooks
	"tbl_src_2" 		=> "auth", // table with mail accounts
	"tbl_dst" 		=> "oc_cards",
	"tbl_dst_2" 		=> "oc_cards_properties",
	"tbl_dst_3" 		=> "oc_addressbookchanges",
	"tbl_dst_4" 		=> "oc_addressbooks"
  );
?>
