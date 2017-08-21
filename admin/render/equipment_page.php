<?php

/**
*
*/
class Equipment_Page extends Item_Mgmt
{

    public static function admin_bind_rental()
    {
      global $wpdb;
      $e = $_POST['ev'];
      if (isset($e['nid'])) {
        $res_id = $wpdb->get_results($wpdb->prepare("SELECT Reservation_ID FROM ".IAM_RESERVATION_TABLE." WHERE NI_ID=%s",$e['nid']))[0]->Reservation_ID;

        $wpdb->query($wpdb->prepare("UPDATE ".IAM_EQUIPMENT_TABLE." SET Checked_Out=%d WHERE Name=%s", $res_id, $e['equipment']));
        $wpdb->query($wpdb->prepare("UPDATE ".IAM_RESERVATION_TABLE." SET Status=%d WHERE NI_ID=%s",ACTIVE,$e['nid']));
      } else {
        $nid = make_nid();
        $equipment_id = $wpdb->get_results($wpdb->prepare("SELECT Equipment_ID FROM ".IAM_EQUIPMENT_TABLE." WHERE Name=%s",$e['equipment']))[0]->Equipment_ID;
        $iam_id = get_user_for_email($e['user'])->IAM_ID;

        $wpdb->query($wpdb->prepare("INSERT INTO ".IAM_RESERVATION_TABLE." (NI_ID,IAM_ID,Equipment_ID,Status,Start_Time,End_Time) VALUES (%s,%d,%d,%d,%s,%s)",$nid,$iam_id,$equipment_id,ACTIVE,$e['start'],$e['end']));

        $res_id = $wpdb->get_results($wpdb->prepare("SELECT Reservation_ID FROM ".IAM_RESERVATION_TABLE." WHERE NI_ID=%s AND IAM_ID=%d",$nid,$iam_id))[0]->Reservation_ID;
        $wpdb->query($wpdb->prepare("UPDATE ".IAM_EQUIPMENT_TABLE." SET Checked_Out=%d WHERE Name=%s", $res_id, $e['equipment']));
      }
      iam_respond(SUCCESS);
    }

    public static function admin_end_rental()
    {
      global $wpdb;
      $e = IAM_Sec::textfield_cleaner($_POST['equipment']);
      $res_id = $wpdb->get_results($wpdb->prepare("SELECT Checked_Out FROM ".IAM_EQUIPMENT_TABLE." WHERE Name=%s",$e))[0]->Checked_Out;
      $wpdb->query($wpdb->prepare("UPDATE ".IAM_EQUIPMENT_TABLE." SET Checked_Out=0 WHERE Checked_Out=%d",$res_id));

      $status = $wpdb->get_results($wpdb->prepare("SELECT Status FROM ".IAM_RESERVATION_TABLE." WHERE Reservation_ID=%d",$res_id))[0]->Status;

      if ($status==ACTIVE) {
        $wpdb->query($wpdb->prepare("UPDATE ".IAM_RESERVATION_TABLE." SET Status=%d WHERE Reservation_ID=%d",COMPLETED,$res_id));
      }

      iam_respond(SUCCESS);
    }

    public static function admin_get_tags_callback()
    {
        IAM_Tags::get_all_tags();
    }

    public static function admin_equipment_callback()
    {
        $interaction = $_POST['method'];
        if (isset($_POST['name']) && isset($_POST['certification']) && isset($_POST['out-of-order']) && isset($_POST['on-slide-show'])  && ($interaction=='u' && isset($_POST['x']) || $interaction=='n') ) {
            global $wpdb;
            $name = IAM_Sec::textfield_cleaner($_POST['name'], true);
            //name checks
            if (gettype($name)!='string') {
                iam_throw_error ('Error - Invalid Input in Field: "Item Name"');
            }
            if (strlen($name)>100) {
                iam_throw_error ( 'Error - Field "Item Name" max length 100 characters');
                exit;
            }
            if (preg_match("/[;'_]/", $name)) {
                iam_throw_error('Error - Item Name cannot contain single quotes, semi colons, or underscores.');
            }
            if (count($wpdb->get_results($wpdb->prepare("SELECT Equipment_ID FROM ".IAM_EQUIPMENT_TABLE." WHERE Name=%s",$name)))>0 && $interaction=='n') {
                iam_throw_error ( 'Error - Equipment of this name already exists');
                exit;
            }

            $certification = IAM_Sec::textfield_cleaner($_POST['certification']);
            //name checks
            if (gettype($name)!='string') {
                iam_throw_error ( 'Error - Invalid Input in Field: "Certification"');
                exit;
            }

            $out_of_order = $_POST['out-of-order'];
            if (IAM_Sec::is_num_val($out_of_order)===false) {
                iam_throw_error ( 'Error - Invalid Input in Field: "Out Of Order"');
                exit;
            }

            $on_slide_show = $_POST['on-slide-show'];
            if (IAM_Sec::is_num_val($on_slide_show)===false) {
                iam_throw_error ( 'Error - Invalid Input in Field: "On Slide Show"');
                exit;
            }

            $description = null;
            if (isset($_POST['description'])) {
                $description = IAM_Sec::textfield_cleaner($_POST['description']);
                //desc checks
                if (gettype($description)!='string') {
                    iam_throw_error ( 'Error - Invalid Input in Field: "Description"');
                    exit;
                }
            }

            $manufacturer_info = null;
            if (isset($_POST['manufacturer-info'])) {
                $manufacturer_info = IAM_Sec::textfield_cleaner($_POST['manufacturer-info']);
                //desc checks
                if (gettype($manufacturer_info)!='string') {
                    iam_throw_error ( 'Error - Invalid Input in Field: "Manufacturer Info"');
                    exit;
                }
            }

            $pricing_description = null;
            if (isset($_POST['pricing-description'])) {
                $pricing_description = IAM_Sec::textfield_cleaner($_POST['pricing-description']);
                //desc checks
                if (gettype($description)!='string') {
                    iam_throw_error ( 'Error - Invalid Input in Field: "Pricing Description"');
                    exit;
                }
            }

            $photo = null;
            if (count($_FILES)>0) {
                $file = $_FILES['photo'];
                if (IAM_Sec::verifyImageFile($file['tmp_name'],['image/jpeg','image/pjpeg','image/jpeg','image/pjpeg','image/png'])){
                    $movefile = wp_handle_upload($file,array( 'test_form' => false ));
                    if ( $movefile && !isset( $movefile['error'] ) ) {
                        $photo = $movefile['url'];
                    } else {
                        /**
                         * Error generated by _wp_handle_upload()
                         * @see _wp_handle_upload() in wp-admin/includes/file.php
                         */
                        iam_throw_error( $movefile['error'] );
                        exit;
                    }

                }
            } else if (isset($_POST['duplicate'])) {

                $url = $_POST['photo'];
                $path = WP_CONTENT_DIR.substr($url, strpos($url, '/uploads'));
                $ext = explode('.', $path)[1];
                $filename = uniqid().'.'.$ext;
                if (copy($path, substr($path, 0, strrpos($path, '/', -1)).'/'.$filename)) {
                    $photo = substr($url, 0, strrpos($url, '/', -1)).'/'.$filename;
                }
            }

            $insert_query = "";
            if ($certification=='') {
                $cert_id = 0;
            } else {
                $cert_query = "SELECT Certification_ID FROM ".IAM_CERTIFICATION_TABLE." WHERE Name='$certification'";
                $cert_id = $wpdb->get_results($cert_query);
                if ($cert_id==null) {
                    $cert_id = 0;
                } else {
                    $cert_id = $cert_id[0]->Certification_ID;
                }
            }

            if ($interaction=='n') {
                //TODO: try again routine for remote case of uniqueid producing a duplicate id
                //TODO: more complex ni_id
                $ni_id = uniqid();
                if ($photo!=null) {
                    $insert_query = $wpdb->prepare("INSERT INTO ".IAM_EQUIPMENT_TABLE." (NI_ID,Certification_ID,Name,Description,Pricing_Description,Manufacturer_Info,Photo,On_Slide_Show,Out_Of_Order) VALUES (%s,%d,%s,%s,%s,%s,%s,%d,%d)",$ni_id,$cert_id,$name,$description,$pricing_description,$manufacturer_info,$photo,$on_slide_show,$out_of_order);
                } else {
                    $insert_query = $wpdb->prepare("INSERT INTO ".IAM_EQUIPMENT_TABLE." (NI_ID,Certification_ID,Name,Description,Pricing_Description,Manufacturer_Info,On_Slide_Show,Out_Of_Order) VALUES (%s,'%d',%s,%s,%s,%s,%d,%d)",$ni_id,$cert_id,$name,$description,$pricing_description,$manufacturer_info,$on_slide_show,$out_of_order);
                }
            } else if ($interaction=='u') {
                $ni_id = IAM_Sec::textfield_cleaner($_POST['x']);
                if ($photo!=null) {
                    $insert_query = $wpdb->prepare("UPDATE ".IAM_EQUIPMENT_TABLE." SET Photo=%s,Certification_ID=%d,Name=%s,Description=%s,Pricing_Description=%s,Manufacturer_Info=%s,On_Slide_Show=%d,Out_Of_Order=%d WHERE NI_ID=%s ",$photo,$cert_id,$name,$description,$pricing_description,$manufacturer_info,$on_slide_show,$out_of_order,$ni_id);
                } else {
                    $insert_query = $wpdb->prepare("UPDATE ".IAM_EQUIPMENT_TABLE." SET Certification_ID=%d,Name=%s,Description=%s,Pricing_Description=%s,Manufacturer_Info=%s,On_Slide_Show=%d,Out_Of_Order=%d WHERE NI_ID=%s ",$cert_id,$name,$description,$pricing_description,$manufacturer_info,$on_slide_show,$out_of_order,$ni_id);
                }
            } else {
                iam_throw_error(INVALID_INPUT_EXCEPTION);
            }

            $result = $wpdb->query($insert_query);

            $tags = $_POST['tags']=='' ? [] : explode(',', $_POST['tags']);
            $equip_id = $wpdb->get_results($wpdb->prepare("SELECT Equipment_ID FROM ".IAM_EQUIPMENT_TABLE." WHERE Name=%s",$name))[0]->Equipment_ID;
            $wpdb->query($wpdb->prepare("DELETE FROM ".IAM_TAGS_EQUIPMENT_TABLE." WHERE Equipment_ID=%d",$equip_id));
            $set_parent = false;
            for ($i=0; $i < count($tags); $i++) {
                if (trim($tags[$i])=='')
                    continue;
                $current = IAM_Sec::textfield_cleaner($tags[$i]);
                if (gettype($current)!='string') {
                    iam_throw_error( 'Error - Field "Tags"');
                    exit;
                }
                $tag_id = $wpdb->get_results($wpdb->prepare("SELECT Tag_ID FROM ".IAM_TAGS_TABLE." WHERE Tag=%s",$current))[0]->Tag_ID;
                $tag_result = $wpdb->query($wpdb->prepare("INSERT INTO ".IAM_TAGS_EQUIPMENT_TABLE." (Equipment_ID, Tag_ID, Unique_ID) VALUES (%d,%d,%d) ",$equip_id,$tag_id,$tag_id.''.$equip_id));

                //root tag is top parent of first tag
                if (!$set_parent) {
                    $set_parent = true;
                    $search_tag = $current;
                    $search_parent = '';
                    while (true) {
                        $search_parent = $wpdb->get_results($wpdb->prepare("SELECT Parent FROM ".IAM_TAGS_TABLE." WHERE Tag=%s",$search_tag))[0]->Parent;
                        if ($search_parent=='') {
                            break;
                        }
                        $search_tag = $search_parent;
                    }
                    $wpdb->query($wpdb->prepare("UPDATE ".IAM_EQUIPMENT_TABLE." SET Root_Tag=%s WHERE Equipment_ID=%d",$search_tag,$equip_id));
                }

                if (!$tag_result) {
                    iam_throw_error("Error - Inserting Tags");
                }
            }

            iam_respond(SUCCESS);
        } else {
            if (!isset($_POST['name'])) {
                iam_throw_error( 'Error - Please fill out required fields: Name' );
            } else if (!isset($_POST['certification'])) {
                iam_throw_error( 'Error - Please fill out required fields: Certification' );
            }
            iam_throw_error(INVALID_INPUT_EXCEPTION);
        }
    }

}
