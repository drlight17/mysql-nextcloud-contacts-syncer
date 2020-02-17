<?php
  $mysql_connect = array
  (
    "host"                      => "localhost", // db host address
        "user"                  => "username", // db user pass
        "pass"                  => "userpassword", // db user password
        "db_mail"               => "exim", // src mail db name
        "db_cloud"              => "nextcloud", // dst nextcloud db name
        "tbl_src"               => "users", // src mail users table name for read
        "tbl_src_2"             => "auth", // src mail users table name for write
        "tbl_dst"               => "oc_cards", // dst nextcloud vcard table name
        "tbl_dst_2"             => "oc_cards_properties", // dst vcard properties table name
        "tbl_dst_3"             => "oc_addressbookchanges", // dst addressbookchanges table name
        "tbl_dst_4"             => "oc_addressbooks" // dst addressbooks table name

  );
?>
