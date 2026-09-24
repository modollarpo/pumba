<?php
require_once "./assets/init.php";
decryptConfigData();

// Dashboard This Year
$type = 'This Year';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);

// Dashboard Today
$type = 'Today';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);

// Dashboard Yesterday
$type = 'Yesterday';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);

// Dashboard This Week
$type = 'This Week';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);

// Dashboard This Month
$type = 'This Month';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);

// Dashboard Last Month
$type = 'Last Month';

$dashboard = updateDashboardData($type);

updateDashboardDB($type,$dashboard);



$db->where('name', 'cronjob_last_run')->update(T_CONFIG, array('value' => time()));

$getCompletedVideos = $db->rawQuery("SELECT * FROM " . T_UPLOADED_CUNKS . " WHERE timestamp < NOW() - INTERVAL 1 DAY;");
foreach ($getCompletedVideos as $key => $video) {
    $deleteFile = $db->where("id", $video->id)->delete(T_UPLOADED_CUNKS);
    @unlink($video->folderpath . '/' . $video->filename);
    if (file_exists($video->folderpath . '/' . $video->filename . '.part')) {
        @unlink($video->folderpath . '/' . $video->filename . '.part');
    }
}

$update_information = PT_UpdateAdminDetails();

$pt_process_queue_result = PT_ProcessImportQueue(5);

$process_queue = $db->get(T_QUEUE, $pt->config->queue_count, "*");
if (!empty($process_queue) && count($process_queue) > 0) {
    foreach ($process_queue as $key => $value) {
        try {
            ob_end_clean();
            header("Content-Encoding: none");
            header("Connection: close");
            ignore_user_abort();
            ob_start();
            header("Content-Type: application/json");
            $size = ob_get_length();
            header("Content-Length: $size");
            ob_end_flush();
            flush();
            session_write_close();
            if (is_callable("fastcgi_finish_request")) {
                fastcgi_finish_request();
            }
            if (is_callable('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            PT_ProcessQueueVideo($value);
        } catch (Exception $e) {
            // Queue row cleanup is handled inside PT_ProcessQueueVideo().
        }
    }
}

$users_id = $db->where('subscriber_price',0,'>')->get(T_USERS,null,array('id'));
$ids = array();
foreach ($users_id as $key => $value) {
    $ids[] = $value->id;
}

if (!empty($ids)) {
   $subscribers = $db->where('user_id',$ids,"IN")->where('time',strtotime("-30 days"),'<')->get(T_SUBSCRIPTIONS);
    foreach ($subscribers as $key => $value) {
        $user = $db->where('id',$value->user_id)->getOne(T_USERS);
        $subscriber = $db->where('id',$value->subscriber_id)->where("admin", "0")->getOne(T_USERS);
        if (!empty($user) && !empty($subscriber) && $user->subscriber_price > 0 && $subscriber->wallet >= $user->subscriber_price) {

            $user_id = $user->id;
            $admin__com = ($pt->config->admin_com_subscribers * $user->subscriber_price)/100;
            $pt->config->payment_currency = $pt->config->payment_currency.'_PERCENT';
            $payment_data         = array(
                'user_id' => $user_id,
                'video_id'    => 0,
                'paid_id'  => $subscriber->id,
                'amount'    => $user->subscriber_price,
                'admin_com'    => $pt->config->admin_com_subscribers,
                'currency'    => $pt->config->payment_currency,
                'time'  => time(),
                'type' => 'subscribe'
            );
            $db->insert(T_VIDEOS_TRSNS,$payment_data);
            $balance = $user->subscriber_price - $admin__com;
            $db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' WHERE `id` = '".$user_id."'");

            $update = array('wallet' => $db->dec($user->subscriber_price));
            $go_pro = $db->where('id',$subscriber->id)->update(T_USERS,$update);

            $db->where('id',$value->id)->update(T_SUBSCRIPTIONS,array('time' => time()));
        }
        else{
            $db->where('id',$value->id)->delete(T_SUBSCRIPTIONS);
        }
    }
}



$expired_subs   = $db->where('expire',time(),'<')->where('expire',0,'>')->get(T_PAYMENTS);
$admin = $db->where('admin',1)->getOne(T_USERS); 
foreach ($expired_subs as $value){
    $subscriber = $db->where('id',$value->user_id)->where("admin", "0")->getOne(T_USERS);
    $db->where('id',$value->id)->update(T_PAYMENTS,array('expire' => 0));

    $package = $pt->pro_packages[$subscriber->pro_type];

    if (!empty($subscriber) && $subscriber->wallet >= $package['price']) {
        $price = $package['price'];

        $update = array('is_pro' => 1,'wallet' => $db->dec($price),'pro_type' => $package['id']);
        if ($package['verified_badge'] == 1) {
            $update['verified'] = 1;
        }

        $go_pro = $db->where('id',$subscriber->id)->update(T_USERS,$update);
        if ($go_pro === true) {
            $payment_data         = array(
                'user_id' => $subscriber->id,
                'type'    => 'pro',
                'amount'  => $price,
                'date'    => date('n') . '/' . date('Y'),
                'expire'  => (time() + $package['ex_time'])
            );

            $db->insert(T_PAYMENTS,$payment_data);
            $db->where('user_id',$subscriber->id)->update(T_VIDEOS,array('featured' => 1));

            $notif_data = array(
                'notifier_id' => $admin->id,
                'recipient_id' => $subscriber->id,
                'type' => 'pro_renew',
                'url' => "wallet",
                'time' => time()
            );
            pt_notify($notif_data);
        }

    }
    else{
        $update         = array('is_pro' => 0,'verified' => 0);
        $db->where('id',$subscriber->id)->update(T_USERS,$update);
        $db->where('user_id',$subscriber->id)->update(T_VIDEOS,array('featured' => 0));
        $notif_data = array(
            'notifier_id' => $admin->id,
            'recipient_id' => $subscriber->id,
            'type' => 'pro_ended',
            'url' => "go_pro",
            'time' => time()
        );
        pt_notify($notif_data);
    }
}

PT_UpdateAdminDetails();
header("Content-type: application/json");
echo json_encode(["status" => 200, "message" => "success"]);
exit();