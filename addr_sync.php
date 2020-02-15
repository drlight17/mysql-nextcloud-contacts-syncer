#!/usr/bin/php

<?php
include("db-connect.inc"); // access db
include("vCard.php"); // for vcard parsing

//***********set config variables************
$db_src=$mysql_connect['tbl_src'];
$db_dst=$mysql_connect['tbl_dst'];      //cards
$db_dst_2=$mysql_connect['tbl_dst_2']; //cards_properties
$db_dst_3=$mysql_connect['tbl_dst_3']; //addressbookchanges
$db_mail=$mysql_connect['db_mail'];
$db_cloud=$mysql_connect['db_cloud'];
$host=$mysql_connect['host'];
$user=$mysql_connect['user'];
$passwd=$mysql_connect['pass'];
$addressbookid=23;
$temp_file='temp.vcf';
$log="/var/log/addr_sync.log";
//******************************************

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

function OutputvCard(vCard $vCard)
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

function add_new ($addressbookid, $select_query_mail, $host, $user, $passwd, $db_cloud, $db_dst, $db_dst_2, $db_dst_3, $log) {
    $w=fopen($log,'a');
    while ($select_array = mysqli_fetch_array($select_query_mail, MYSQLI_ASSOC)) {
        //echo "flag!";
        // uid generator
        $uid=bin2hex(random_bytes(4))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(2))."-".bin2hex(random_bytes(6));
        $uri=strtoupper($uid).".vcf";
        // timestamp generator
        //$lastmodified=date_timestamp_get(date_create());
        $lastmodified=time();
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
        $user_photo=$select_array["user_photo"];
        $vcard=create_vcard($last_name,$first_name,$middle_name,$display_name,$categories,$organization,$job_title,$work_phone,$primary_email,$uid,$user_photo,$rev);
        $vcard_array= array(
            "N" => $last_name.";".$first_name.";".$middle_name.";;",
            "FN" => $display_name,
            "CATEGORIES"   => $categories,
            "ORG"  => $organization,
            "TITLE"  => $job_title,
            "TEL"  => $work_phone,
            "EMAIL"  => $primary_email,
            "UID"  => $uid,
        );
        // etag generator
        $etag = md5($vcard);
        $size = strlen($vcard);
        if (check_existence($addressbookid, $host, $user, $passwd, $db_cloud, $db_dst, $primary_email)) {
            $link_db=connect_to_db ($host, $user, $passwd);
            $insert="INSERT INTO ".$db_cloud.".".$db_dst." (`addressbookid`,`carddata`,`uri`, `lastmodified`, `etag`, `size`, `uid`) VALUES"."('".$addressbookid."','".$vcard."','".$uri."','".$lastmodified."','".$etag."','".$size."','".$uid."')";
            $insert_query=mysqli_query($link_db, $insert) or die("Query failed");
            $cardid = mysqli_insert_id($link_db);
            foreach ($vcard_array as $name => $value) {
                $insert_2="INSERT INTO ".$db_cloud.".".$db_dst_2." (`id`,`addressbookid`,`cardid`,`name`, `value`, `preferred`) VALUES"."(NULL, '".$addressbookid."','".$cardid."','".$name."','".$value."',0)";
                $insert_query_2=mysqli_query($link_db, $insert_2) or die("Query failed");
            }
            $select_2="SELECT synctoken from ".$db_cloud.".oc_addressbooks WHERE id='".$addressbookid."'";
            $select_query_2=mysqli_query($link_db, $select_2) or die("Query failed");
            while ($select_array_2 = mysqli_fetch_array($select_query_2, MYSQLI_ASSOC)) {
                $synctoken=$select_array_2["synctoken"];
            }
            // operations: 1 - add, 2 - modify, 3 - delete
            $insert_3="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 1)";
            $insert_query_3=mysqli_query($link_db, $insert_3) or die("Query failed");
            $synctoken+=1;
            $update="UPDATE ".$db_cloud.".oc_addressbooks SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
            $update_query=mysqli_query($link_db, $update) or die("Query failed");
            fwrite($w,(date(DATE_RFC822))); 
            fwrite($w," добавлен новый пользователь ".$display_name." с адресом ".$primary_email. "\n");
            mysqli_close($link_db);
        }
    }
    fclose($w);
    return 0;
}

function check_existence ($addressbookid, $host, $user, $passwd, $db_cloud, $db_dst, $primary_email) {
    $link_db=connect_to_db ($host, $user, $passwd);
    $check="SELECT * FROM ".$db_cloud.".".$db_dst." WHERE carddata LIKE '%".$primary_email."%' AND addressbookid='".$addressbookid."'";
    $check_query=mysqli_query($link_db, $check) or die("Query failed");
    $check_array = mysqli_fetch_array($check_query, MYSQLI_ASSOC);
    if (mysqli_affected_rows($link_db)==0) {
        return true;
    } else {
        return false;
    }
    mysqli_close($link_db);
}

function get_all_contacts_mail ($host, $user, $passwd, $db_mail, $db_src) {
    $link_db=connect_to_db ($host, $user, $passwd);
    $select="SELECT * FROM ".$db_mail.".".$db_src;"";
    $select_query=mysqli_query($link_db, $select) or die("Query failed");
    mysqli_close($link_db);
    return $select_query;
}

function get_all_contacts_cloud ($addressbookid, $host, $user, $passwd, $db_cloud, $db_dst) {
    $link_db=connect_to_db ($host, $user, $passwd);
    $select="SELECT carddata from ".$db_cloud.".".$db_dst." WHERE addressbookid='".$addressbookid."'";
    $select_query=mysqli_query($link_db, $select) or die("Query failed");
    mysqli_close($link_db);
    return $select_query;
}

function delete_nonexistent ($addressbookid, $temp_file, $host, $user, $passwd, $db_mail, $db_cloud, $db_src, $db_dst, $db_dst_3, $log) {
    $email_string='';

    $select_query_mail = get_all_contacts_mail($host, $user, $passwd, $db_mail, $db_src);

$w=fopen($log,'a');

    while ($select_array = mysqli_fetch_array($select_query_mail, MYSQLI_ASSOC)) {
        $email_string.=$select_array["primary_email"];
    };


    $vCard = new vCard($temp_file);
    if (count($vCard) == 0)
        {
            throw new Exception('vCard test: empty vCard!');
        }
    // if the file contains a single vCard, it is accessible directly.
        elseif (count($vCard) == 1)
        {
            OutputvCard($vCard);
        }
    // if the file contains multiple vCards, they are accessible as elements of an array
        else
        {
            foreach ($vCard as $Index => $vCardPart)
            {
                if(strpos($email_string,OutputvCard($vCardPart)) !== false){
                    //$i=$i+1;
                } else {
                    //$j=$j+1;
                    $link_db=connect_to_db ($host, $user, $passwd);

                    $select_3="SELECT uri FROM ".$db_cloud.".".$db_dst." WHERE carddata LIKE '%".OutputvCard($vCardPart)."%' AND addressbookid='".$addressbookid."'";
                    $select_query_3=mysqli_query($link_db, $select_3) or die("Query failed");
                    while ($select_array_3 = mysqli_fetch_array($select_query_3, MYSQLI_ASSOC)) {
                        $uri=$select_array_3["uri"];
                    };

                    $select_2="SELECT synctoken from ".$db_cloud.".oc_addressbooks WHERE id='".$addressbookid."'";
                    $select_query_2=mysqli_query($link_db, $select_2) or die("Query failed");
                    while ($select_array_2 = mysqli_fetch_array($select_query_2, MYSQLI_ASSOC)) {
                        $synctoken=$select_array_2["synctoken"];
                    };

                    $delete="DELETE FROM ".$db_cloud.".".$db_dst." WHERE carddata LIKE '%".OutputvCard($vCardPart)."%' AND addressbookid='".$addressbookid."'";
                    $delete_query=mysqli_query($link_db, $delete) or die("Query failed");

                    // operations: 1 - add, 2 - modify, 3 - delete
                    $insert="INSERT INTO ".$db_cloud.".".$db_dst_3." (`uri`,`synctoken`,`addressbookid`,`operation`) VALUES"."('".$uri."','".$synctoken."', '".$addressbookid."', 3)";
                    $insert_query=mysqli_query($link_db, $insert) or die("Query failed");

                    $synctoken+=1;

                    $update="UPDATE ".$db_cloud.".oc_addressbooks SET synctoken='".$synctoken."' WHERE id='".$addressbookid."'";
                    $update_query=mysqli_query($link_db, $update) or die("Query failed");

                    mysqli_close($link_db);
                    fwrite($w,(date(DATE_RFC822)));
                    fwrite($w," удален пользователь с адресом ".OutputvCard($vCardPart). "\n");

                }
            }
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

$select_query_cloud = get_all_contacts_cloud($addressbookid, $host, $user, $passwd, $db_cloud, $db_dst);

create_temp_file($temp_file,$select_query_cloud);

delete_nonexistent ($addressbookid, $temp_file, $host, $user, $passwd, $db_mail, $db_cloud, $db_src, $db_dst, $db_dst_3, $log);

$select_query_mail = get_all_contacts_mail($host, $user, $passwd, $db_mail, $db_src);

add_new($addressbookid, $select_query_mail, $host, $user, $passwd, $db_cloud, $db_dst, $db_dst_2, $db_dst_3, $log);

delete_temp_file($temp_file);

//***********************************************************************************************

$w=fopen($log,'a');
fwrite($w,"Завершена синхронизация ".(date(DATE_RFC822))."\n"."\n");
fclose($w);

?>
