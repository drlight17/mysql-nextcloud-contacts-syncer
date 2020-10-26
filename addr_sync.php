#!/usr/bin/php

<?php
/**
 * mysql with Nextcloud contacts app syncer
 *
 * @link https://github.com/drlight17/mysql-nextcloud-contacts-syncer
 * @author Samoilov Yuri
 * @version 0.5
*/

include("db-connect.inc"); // access db
include("vCard.php"); // for vcard parsing
error_reporting(0); // comment out to show errors

//***********set config variables************
$db_src=$mysql_connect['tbl_src'];      // addressbook view from mail
$db_src_2=$mysql_connect['tbl_src_2'];  // auth from mail
$db_dst=$mysql_connect['tbl_dst'];      //cards
$db_dst_2=$mysql_connect['tbl_dst_2']; //cards_properties
$db_dst_3=$mysql_connect['tbl_dst_3']; //addressbookchanges
$db_dst_4=$mysql_connect['tbl_dst_4']; //addressbooks
$db_mail=$mysql_connect['db_mail'];
$db_cloud=$mysql_connect['db_cloud'];
$host_db=$mysql_connect['host_db'];     //host with exim db
$host_cloud=$mysql_connect['host_cloud']; //host with cloud db
$user=$mysql_connect['user'];
$passwd=$mysql_connect['pass'];
$addressbookid=23; // change book id from NC database. !!!in order to start sync there must be at least 1 contact in this addressbook!!!
$temp_file='temp.vcf';
$log="/var/log/addr_sync.log";
// ldap settings
$srv = "dc.example.loc"; // ldap server address
$uname = "user@dc.example.loc"; // ldap username with read permissions
$upasswd = "password"; // ldap user password
$dn = "dc=example,dc=loc"; // ldap main DN
//******************************************

function get_ldap_photo () {
//    $search = "(&(jpegPhoto=*))";
    $search = "(&(mail=*))";
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

function OutputvCard_mail(vCard $vCard)
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
                   /* if ($Photo['Value']=='VCARD') {
                        //return $Photo['Type'][0].';base64,'.'nothing';
                        return false;
                    }*/
                    return $Photo['Type'][0].';base64,'.$Photo['Value'];
                }
                else
                {
                    return $Photo['Value'];
                }
            }
        } else {

            return false;
        }
    }

function add_or_update ($ldap_array, $addressbookid, $select_query_mail, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $db_dst_2, $db_dst_3, $db_dst_4, $log) {
    $w=fopen($log,'a');
    while ($select_array = mysqli_fetch_array($select_query_mail, MYSQLI_ASSOC)) {
    $user_photo="";
        //echo "flag!";
        // uid generator
        $uid=bin2hex(random_bytes(4))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(6));
        $uri=strtoupper($uid).".vcf";
        // timestamp generator

        //$lastmodified=time(); //current timestamp
        $last_update_exim=strtotime($select_array["last_update"]); // exim human readable time convert to timestamp
        $lastmodified=$last_update_exim; // sync cloud lastmodified with exim last_update
        //echo $last_update_exim;
        $rev=gmdate('Ymd')."T".gmdate('His')."Z";
        $last_name=$select_array["last_name"];
        $first_name=$select_array["first_name"];
        $middle_name=$select_array["middle_name"];
        $display_name=$select_array["display_name"];
        $categories=$select_array["categories"];
        $organization=$select_array["organization"];
        $job_title=$select_array["job_title"];
        $work_phone=$select_array["work_phone"];
        $primary_email=$select_array["primary_email"];
    foreach($ldap_array as $ldap_array_element){
        if ($primary_email==$ldap_array_element["mail"][0]) {
            if (isset($ldap_array_element["jpegphoto"][0])) {
                $user_photo="ENCODING=b;TYPE=png:".base64_encode($ldap_array_element["jpegphoto"][0]);
            } else {
                $user_photo="ENCODING=b;TYPE=png:nothing";
            };
        };
    };
    if ($user_photo=="") {
//          $user_photo=$select_array["user_photo"];
        $user_photo="ENCODING=b;TYPE=png:nothing";
    };
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
        /*if (check_existence($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $primary_email, $last_update_exim)) {*/
        $check_result = check_existence($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $primary_email);
        //echo $check_result[0];
        if ($check_result[0]===true) {
            // add record
            //echo "add of user!";
            $link_db=connect_to_db ($host_cloud, $user, $passwd);
            $insert="INSERT INTO ".$db_cloud.".".$db_dst." (`addressbookid`,`carddata`,`uri`, `lastmodified`, `etag`, `size`, `uid`) VALUES"."('".$addressbookid."','".$vcard."','".$uri."','".$lastmodified."','".$etag."','".$size."','".$uid."')";
            $insert_query=mysqli_query($link_db, $insert) or die("Query failed");
            $cardid = mysqli_insert_id($link_db);
            foreach ($vcard_array as $name => $value) {
                $insert_2="INSERT INTO ".$db_cloud.".".$db_dst_2." (`id`,`addressbookid`,`cardid`,`name`, `value`, `preferred`) VALUES"."(NULL, '".$addressbookid."','".$cardid."','".$name."','".$value."',0)";
                $insert_query_2=mysqli_query($link_db, $insert_2) or die("Query failed");
            }
            $select_2="SELECT synctoken from ".$db_cloud.".".$db_dst_4." WHERE id='".$addressbookid."'";
            $select_query_2=mysqli_query($link_db, $select_2) or die("Query failed");
            while ($select_array_2 = mysqli_fetch_array($select_query_2, MYSQLI_ASSOC)) {
                $synctoken=$select_array_2["synctoken"];
            }
            // operations: 1 - add, 2 - modify, 3 - delete
            $insert_3="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 1)";
            $insert_query_3=mysqli_query($link_db, $insert_3) or die("Query failed");
            $synctoken+=1;
            $update="UPDATE ".$db_cloud.".".$db_dst_4." SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
            $update_query=mysqli_query($link_db, $update) or die("Query failed");
            fwrite($w,(date(DATE_RFC822)));
            fwrite($w," добавлен новый пользователь ".$display_name." с адресом ".$primary_email. "\n");
            mysqli_close($link_db);
        } else {
            // update record
            // samoilov 23.10.2020 check last_update
            if ($check_result[1] < $last_update_exim) {
                //echo "update of user!";
                $link_db=connect_to_db ($host_cloud, $user, $passwd);
                // sql query to find uid
                $select_4="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata like '%EMAIL;TYPE=\"HOME,INTERNET,pref\":".$primary_email."%' and addressbookid='".$addressbookid."'";
            $select_query_4=mysqli_query($link_db, $select_4) or die("Query failed");
                while ($select_array_4 = mysqli_fetch_array($select_query_4, MYSQLI_ASSOC)) {
                    $cardid=$select_array_4["id"];
                    $uid=$select_array_4["uid"];
                    $uri=$select_array_4["uri"];
                };
                $update="UPDATE ".$db_cloud.".".$db_dst." SET `carddata`='".$vcard."', `lastmodified`='".$lastmodified."', `etag`='".$etag."', `size`='".$size."' WHERE id='".$cardid."'";
                $update_query=mysqli_query($link_db, $update) or die("Query failed");
                //$cardid = mysqli_insert_id($link_db);
                foreach ($vcard_array as $name => $value) {
                    //$update_3="INSERT INTO ".$db_cloud.".".$db_dst_2." (`id`,`addressbookid`,`cardid`,`name`, `value`, `preferred`) VALUES"."(NULL, '".$addressbookid."','".$cardid."','".$name."','".$value."',0)";
                    $update_3="UPDATE ".$db_cloud.".".$db_dst_2." SET `value`='".$value."', `preferred`=0 WHERE `name`='".$name."' AND `addressbookid`='".$addressbookid."' AND `cardid`='".$cardid."'";
                    //echo $update_3;
                    $update_query_3=mysqli_query($link_db, $update_3) or die("Query failed");
                }

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
                fwrite($w,(date(DATE_RFC822)));
                fwrite($w," обновлены данные пользователя ".$display_name." с адресом ".$primary_email. "\n");
                mysqli_close($link_db);
            }
        }

    }
    fclose($w);
    return 0;
}

function check_existence ($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $primary_email) {
    $link_db=connect_to_db ($host_cloud, $user, $passwd);
    $check="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata LIKE '%EMAIL;TYPE=\"HOME,INTERNET,pref\":".$primary_email."%' AND addressbookid='".$addressbookid."'";
    $check_query=mysqli_query($link_db, $check) or die("Query failed");
    $check_array = mysqli_fetch_array($check_query, MYSQLI_ASSOC);
    if (mysqli_affected_rows($link_db)==0) {
        return array(true, '');
    } else {
        return array(false, $check_array["lastmodified"]);
    }
    mysqli_close($link_db);
}

function get_all_contacts_mail ($host_db, $user, $passwd, $db_mail, $db_src) {
    $link_db=connect_to_db ($host_db, $user, $passwd);
    $select="SELECT * FROM ".$db_mail.".".$db_src;"";
    $select_query=mysqli_query($link_db, $select) or die("Query failed");
    mysqli_close($link_db);
    return $select_query;
}

function get_all_contacts_cloud ($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst) {
    $link_db=connect_to_db ($host_cloud, $user, $passwd);
    $select="SELECT carddata from ".$db_cloud.".".$db_dst." WHERE addressbookid='".$addressbookid."'";
    $select_query=mysqli_query($link_db, $select) or die("Query failed");
    mysqli_close($link_db);
    return $select_query;
}

function update_photos ($temp_file, $ldap_array) {
//      var_dump ($ldap_array);

global $host_db,$host_cloud,$user,$passwd,$db_mail,$db_src, $db_src_2, $db_cloud, $db_dst, $db_dst_2, $db_dst_3, $db_dst_4, $log, $addressbookid;



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
            $user_photo="";
            $email_sel="";
            $email = OutputvCard_mail($vCardPart);
            $photo = OutputvCard_photo($vCardPart);
            //echo $email."\n";
            //echo $photo."\n";
            //if (($photo!="")&&($photo!="png;base64,iVBORw0KGgoAAAANSUhEUgAAAgAAAAIACAYAAAD0eNT6AABAB")&&($photo!="png;base64,iVBORw0KGgoAAAANSUhEUgAAAgAAAAIACAYAAAD0eNT6AABA")) {
            //echo "\n".$email." ".$photo;
            //};
            foreach($ldap_array as $ldap_array_element){
            if ($email==$ldap_array_element["mail"][0]) {
                if (isset($ldap_array_element["jpegphoto"][0])) {
                    //echo $email."\n";
                    //echo base64_encode($ldap_array_element["jpegphoto"][0])."\n";
                    $user_photo="ENCODING=b;TYPE=png:".base64_encode($ldap_array_element["jpegphoto"][0]);

                } else {
                    $user_photo="ENCODING=b;TYPE=png:nothing";
                };
                $email_sel=$email;
                };
            };
//            if (($user_photo=="") || ($photo==false)) {
            if ($user_photo=="") {
            //$user_photo=$select_array["user_photo"];
            $user_photo="ENCODING=b;TYPE=png:nothing";
            };


            // sql query to find row to update photo
            if ($email_sel!="") {

            $link_db=connect_to_db ($host_db, $user, $passwd);
//                      $select_2="SELECT synctoken from ".$db_cloud.".".$db_dst_4." WHERE id='".$addressbookid."'";
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
            $link_db_cloud=connect_to_db ($host_cloud, $user, $passwd);
            if ($user_photo!=$user_photo_old){
                // sql query to find uid
                $select_2="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata like '%EMAIL;TYPE=\"HOME,INTERNET,pref\":".$email_sel."%' and addressbookid='".$addressbookid."'";
                //echo $select_2;
                $select_query_2=mysqli_query($link_db_cloud, $select_2) or die("Query failed");
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
                //echo $update;
                $update_query=mysqli_query($link_db_cloud, $update) or die("Query failed");
                //
                $select_3="SELECT synctoken from ".$db_cloud.".".$db_dst_4." WHERE id='".$addressbookid."'";

                $select_query_3=mysqli_query($link_db_cloud, $select_3) or die("Query failed");
                while ($select_array_3 = mysqli_fetch_array($select_query_3, MYSQLI_ASSOC)) {
                        $synctoken=$select_array_3["synctoken"];
                }
                    // operations: 1 - add, 2 - modify, 3 - delete
                $insert="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 2)";
                $insert_query=mysqli_query($link_db_cloud, $insert) or die("Query failed");
                $synctoken+=1;
                $update_2="UPDATE ".$db_cloud.".".$db_dst_4." SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
                $update_query_2=mysqli_query($link_db_cloud, $update_2) or die("Query failed");
                // update old photo in mail db auth table
                $update_3="UPDATE ".$db_mail.".".$db_src_2." SET `photo`='".$user_photo."',update_by='SYSTEM' WHERE concat(login,'@',domain)='".$primary_email."'";
                $update_query_3=mysqli_query($link_db, $update_3);
                //echo mysqli_errno($link_db) . ": " . mysqli_error($link_db) . "\n";
                fwrite($w,(date(DATE_RFC822)));
                fwrite($w," обновлен аватар пользователя ".$display_name." с адресом ".$primary_email. "\n");
            };
            };
            }
        mysqli_close($link_db);
        mysqli_close($link_db_cloud);
        }
    return 0;
}

function delete_nonexistent ($addressbookid, $temp_file, $host_db, $host_cloud, $user, $passwd, $db_mail, $db_cloud, $db_src, $db_dst, $db_dst_3, $db_dst_4, $log) {
    $email_string='';
    $i=0;
    $j=0;
    $select_query_mail = get_all_contacts_mail($host_db, $user, $passwd, $db_mail, $db_src);

$w=fopen($log,'a');

    while ($select_array = mysqli_fetch_array($select_query_mail, MYSQLI_ASSOC)) {
        $email_string.=";".$select_array["primary_email"].";";
    };


    $vCard = new vCard($temp_file);
    //echo $email_string;
    if (count($vCard) == 0)
        {
            throw new Exception('vCard test: empty vCard!');
        }
    // if the file contains a single vCard, it is accessible directly.
        elseif (count($vCard) == 1)
        {
            OutputvCard_mail($vCard);
        }
    // if the file contains multiple vCards, they are accessible as elements of an array
        else
        {
            foreach ($vCard as $Index => $vCardPart)
            {   //echo OutputvCard_mail($vCardPart)."\n";
        $template=";".OutputvCard_mail($vCardPart).";";
                if(strpos($email_string,$template) !== false){
                    $i=$i+1;

                } else {
                    $j=$j+1;

                    $link_db=connect_to_db ($host_cloud, $user, $passwd);

                    $select_3="SELECT uri FROM ".$db_cloud.".".$db_dst." WHERE carddata LIKE '%EMAIL;TYPE=\"HOME,INTERNET,pref\":".OutputvCard_mail($vCardPart)."%' AND addressbookid='".$addressbookid."'";
            //echo $select_3;
                    $select_query_3=mysqli_query($link_db, $select_3) or die("Query failed");
                    while ($select_array_3 = mysqli_fetch_array($select_query_3, MYSQLI_ASSOC)) {
                        $uri=$select_array_3["uri"];
                    };

                    $select_2="SELECT synctoken from ".$db_cloud.".".$db_dst_4." WHERE id='".$addressbookid."'";
                    $select_query_2=mysqli_query($link_db, $select_2) or die("Query failed");
                    while ($select_array_2 = mysqli_fetch_array($select_query_2, MYSQLI_ASSOC)) {
                        $synctoken=$select_array_2["synctoken"];
                    };

                    $delete="DELETE FROM ".$db_cloud.".".$db_dst." WHERE carddata LIKE '%EMAIL;TYPE=\"HOME,INTERNET,pref\":".OutputvCard_mail($vCardPart)."%' AND addressbookid='".$addressbookid."'";
                    $delete_query=mysqli_query($link_db, $delete) or die("Query failed");

                    // operations: 1 - add, 2 - modify, 3 - delete
                    $insert="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 3)";
                    $insert_query=mysqli_query($link_db, $insert) or die("Query failed");

                    $synctoken+=1;

                    $update="UPDATE ".$db_cloud.".".$db_dst_4." SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
                    $update_query=mysqli_query($link_db, $update) or die("Query failed");

                    mysqli_close($link_db);
                    fwrite($w,(date(DATE_RFC822)));
                    fwrite($w," удален пользователь с адресом ".OutputvCard_mail($vCardPart). "\n");

                }
            }
//          echo "remained: ".$i."\n";
//          echo "deleted: ".$j."\n";
        }
    fclose($w);

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

$select_query_cloud = get_all_contacts_cloud ($addressbookid, $host_cloud, $user, $passwd, $db_cloud, $db_dst);

create_temp_file ($temp_file,$select_query_cloud);

$ldap_array = get_ldap_photo();

update_photos($temp_file, $ldap_array);

$select_query_mail = get_all_contacts_mail($host_db, $user, $passwd, $db_mail, $db_src);

add_or_update ($ldap_array, $addressbookid, $select_query_mail, $host_cloud, $user, $passwd, $db_cloud, $db_dst, $db_dst_2, $db_dst_3, $db_dst_4, $log);

delete_nonexistent ($addressbookid, $temp_file, $host_db, $host_cloud, $user, $passwd, $db_mail, $db_cloud, $db_src, $db_dst, $db_dst_3, $db_dst_4, $log);

delete_temp_file($temp_file);

//***********************************************************************************************

$w=fopen($log,'a');
fwrite($w,"Завершена синхронизация ".(date(DATE_RFC822))."\n"."\n");
fclose($w);

?>
