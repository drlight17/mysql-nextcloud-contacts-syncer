#!/usr/bin/php

<?php
/**
 * ldap DC jpegPhoto attribute with Nextcloud sync
 *
 * @link https://github.com/drlight17/mysql-nextcloud-contacts-syncer
 * @author Samoilov Yuri
 * @version 0.1.3
*/

include("db-connect.inc"); // access db
include("vCard.php"); // for vcard parsing
//error_reporting(0);

//***********set config variables************
$db_src=$mysql_connect['tbl_src'];      // addressbook view from mail
$db_src_2=$mysql_connect['tbl_src_2'];  // auth from mail
$db_dst=$mysql_connect['tbl_dst'];      //cards
$db_dst_2=$mysql_connect['tbl_dst_2']; //cards_properties
$db_dst_3=$mysql_connect['tbl_dst_3']; //addressbookchanges
$db_dst_4=$mysql_connect['tbl_dst_4']; //addressbooks
$db_mail=$mysql_connect['db_mail'];
$db_cloud=$mysql_connect['db_cloud'];
$host=$mysql_connect['host'];
$user=$mysql_connect['user'];
$passwd=$mysql_connect['pass'];
$addressbookid=23;
$temp_file='temp.vcf';
$log="/var/log/addr_sync.log";
// ldap settings
$srv = "dc.example";  // DC address
$uname = "mail@ksc.loc"; // ldap username with read permissions
$upasswd = "d0M4iLer";  // ldap user password
$dn = "dc=ksc,dc=loc"; // main DN for binding
//******************************************

function get_ldap_photo () {
    $search = "(&(jpegPhoto=*))";
    global $srv, $uname, $upasswd, $dn;
    $ds=ldap_connect($srv);
    if (!$ds) die("error connect to LDAP server $srv");
    $r=ldap_bind($ds, $uname, $upasswd);
    if (!$r) die("error bind!");
    $sr=ldap_search($ds, $dn, iconv("utf-8", "cp1251" ,$search), array('mail','jpegphoto'));
    if (!$sr) die("search error!");
    $info = ldap_get_entries($ds, $sr);
    return $info;
}

function create_vcard($last_name,$first_name,$middle_name,$display_name,$categories,$organization,$job_title,$work_phone,$primary_email,$uid,$user_photo,$rev){
return
'BEGIN:VCARD
VERSION:3.0
N:'.$last_name.';'.$first_name.';'.$middle_name.';;
FN:'.$display_name.'
CATEGORIES:'.$categories.'
ORG:'.$organization.';
TITLE:'.$job_title.'
TEL;TYPE="VOICE,WORK";VALUE=TEXT:'.$work_phone.'
EMAIL;TYPE="HOME,INTERNET,pref":'.$primary_email.'
UID:'.$uid.'
REV;VALUE=DATE-AND-OR-TIME:'.$rev.'
PHOTO;'.$user_photo.'
END:VCARD';
}

function OutputvCard_mail (vCard $vCard)
    {
        if ($vCard -> EMAIL)
        {
            foreach ($vCard -> EMAIL as $Email)
            {
                if (is_scalar($Email))
                {
                    return $Email;
                }
                else
                {
                    return $Email['Value'];
                }
            }
        }
    }
function OutputvCard_photo (vCard $vCard)
    {
    if ($vCard -> PHOTO)
        {
            foreach ($vCard -> PHOTO as $Photo)
            {
                if ($Photo['Encoding'] == 'b')
                {
                    return $Photo['Type'][0].';base64,'.$Photo['Value'];
                }
                else
                {
                    return $Photo['Value'];
                }
            }
        }
    }


function get_all_contacts_cloud ($addressbookid, $host, $user, $passwd, $db_cloud, $db_dst) {
    $link_db=connect_to_db ($host, $user, $passwd);
    $select="SELECT carddata from ".$db_cloud.".".$db_dst." WHERE addressbookid='".$addressbookid."'";
    $select_query=mysqli_query($link_db, $select) or die("Query failed");
    mysqli_close($link_db);
    return $select_query;
}


function update_photos ($temp_file, $ldap_array) {
//      var_dump ($ldap_array);

global $host,$user,$passwd,$db_mail,$db_src, $db_src_2, $db_cloud, $db_dst, $db_dst_2, $db_dst_3, $db_dst_4, $log, $addressbookid;

$w=fopen($log,'a');

        $vCard = new vCard($temp_file);
        if (count($vCard) == 0)
        {
            throw new Exception('vCard test: empty vCard!');
        }
    // if the file contains a single vCard, it is accessible directly.
        elseif (count($vCard) == 1)
        {
            OutputvCard_photo($vCard);
        }
    // if the file contains multiple vCards, they are accessible as elements of an array
        else
        {
            foreach ($vCard as $Index => $vCardPart)
            {
                    $email_sel="";
                    $email = OutputvCard_mail($vCardPart);
                    $photo = OutputvCard_photo($vCardPart);
                    foreach($ldap_array as $ldap_array_element){
                        if ($email==$ldap_array_element["mail"][0]) {
                            $user_photo="ENCODING=b;TYPE=png:".base64_encode($ldap_array_element["jpegphoto"][0]);
                            $email_sel=$email;
                        };
                    };
                    if ($user_photo=="") {
                        //$user_photo=$select_array["user_photo"];
                        $user_photo="ENCODING=b;TYPE=png:nothing";
                    };
                    // sql query to find row to update photo
                    if ($email_sel!="") {

                        $link_db=connect_to_db ($host, $user, $passwd);
                        $select="SELECT * FROM ".$db_mail.".".$db_src." WHERE primary_email = '".$email_sel."'";
                        $select_query=mysqli_query($link_db, $select) or die("Query failed");
                        while ($select_array = mysqli_fetch_array($select_query, MYSQLI_ASSOC)) {
                            $last_name=$select_array["last_name"];
                            $first_name=$select_array["first_name"];
                            $middle_name=$select_array["middle_name"];
                            $display_name=$select_array["display_name"];
                            $categories=$select_array["categories"];
                            $organization=$select_array["organization"];
                            $job_title=$select_array["job_title"];
                            $work_phone=$select_array["work_phone"];
                            $primary_email=$select_array["primary_email"];
                            $user_photo_old=$select_array["user_photo"];
                        };
                        if ($user_photo!=$user_photo_old){
                            // sql query to find uid
                            $select_2="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata like '%".$email_sel."%' and addressbookid='".$addressbookid."'";
                            $select_query_2=mysqli_query($link_db, $select_2) or die("Query failed");
                            while ($select_array_2 = mysqli_fetch_array($select_query_2, MYSQLI_ASSOC)) {
                                $cardid=$select_array_2["id"];
                                $uid=$select_array_2["uid"];
                                $uri=$select_array_2["uri"];
                            };

                            $lastmodified=time();
                            $rev=gmdate('Ymd')."T".gmdate('His')."Z";
                            $vcard=create_vcard($last_name,$first_name,$middle_name,$display_name,$categories,$organization,$job_title,$work_phone,$primary_email,$uid,$user_photo,$rev);
                            $vcard_array= array(
                                "N" => $last_name.";".$first_name.";".$middle_name.";;",
                                "FN" => $display_name,
                                "CATEGORIES"   => $categories,
                                "ORG"  => $organization,
                                "TITLE"  => $job_title,
                                "TEL"  => $work_phone,
                                "EMAIL"  => $primary_email,
                                "PHOTO"  => $user_photo,
                                "UID"  => $uid,
                        );
                            // etag generator
                            $etag = md5($vcard);
                            $size = strlen($vcard);
                            // update queries
                            $update="UPDATE ".$db_cloud.".".$db_dst." SET `carddata`='".$vcard."', `lastmodified`='".$lastmodified."', `etag`='".$etag."', `size`='".$size."' WHERE id='".$cardid."'";
                            $update_query=mysqli_query($link_db, $update) or die("Query failed");
                            $select_3="SELECT synctoken from ".$db_cloud.".".$db_dst_4." WHERE id='".$addressbookid."'";

                            $select_query_3=mysqli_query($link_db, $select_3) or die("Query failed");
                            while ($select_array_3 = mysqli_fetch_array($select_query_3, MYSQLI_ASSOC)) {
                                $synctoken=$select_array_3["synctoken"];
                            }
                            // operations: 1 - add, 2 - modify, 3 - delete
                            $insert="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 2)";
                            $insert_query=mysqli_query($link_db, $insert) or die("Query failed");
                            $synctoken+=1;
                            $update_2="UPDATE ".$db_cloud.".".$db_dst_4." SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
                            $update_query_2=mysqli_query($link_db, $update_2) or die("Query failed");
                            // update old photo in mail db auth table
                            $update_3="UPDATE ".$db_mail.".".$db_src_2." SET `photo`='".$user_photo."' WHERE concat(login,'@',domain)='".$primary_email."'";
                            $update_query_3=mysqli_query($link_db, $update_3);
                            fwrite($w,(date(DATE_RFC822)));
                            fwrite($w," обновлен аватар пользователя ".$display_name." с адресом ".$primary_email. "\n");
                        };
                    };
            }
            mysqli_close($link_db);
        }
        return 0;
}

function connect_to_db ($host, $user, $passwd) {
    $link_db = mysqli_connect($host, $user, $passwd)
       or die("Could not connect: " . mysqli_error());
    return $link_db;
}

function create_temp_file($temp_file,$select_query_cloud) {
    $w=fopen($temp_file,'wa+');
    while ($select_array = mysqli_fetch_array($select_query_cloud, MYSQLI_ASSOC)) {
//    echo $select_array["carddata"];
    fwrite($w,$select_array["carddata"]."\n");
    }
    fclose($w);
    return 0;
}

function delete_temp_file ($temp_file) {
if (!unlink($temp_file)) {
    echo ("temp file cannot be deleted due to an error");
}
else {
    //echo ("temp file has been deleted");
}
}

//***********************************************************************************************
$w=fopen($log,'a');
fwrite($w,"Запущена синхронизация ".(date(DATE_RFC822))."\n");
fclose($w);

$select_query_cloud = get_all_contacts_cloud($addressbookid, $host, $user, $passwd, $db_cloud, $db_dst);

create_temp_file($temp_file,$select_query_cloud);

$ldap_array = get_ldap_photo();

update_photos($temp_file, $ldap_array);

delete_temp_file($temp_file);

//***********************************************************************************************

$w=fopen($log,'a');
fwrite($w,"Завершена синхронизация ".(date(DATE_RFC822))."\n"."\n");
fclose($w);

?>
