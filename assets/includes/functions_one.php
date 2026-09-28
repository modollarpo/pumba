<?php
// +------------------------------------------------------------------------+
// | @author Deen Doughouz (DoughouzForest)
// | @author_url 1: http://www.playtubescript.com
// | @author_url 2: http://codecanyon.net/user/doughouzforest
// | @author_email: wowondersocial@gmail.com
// +------------------------------------------------------------------------+
// | PlayTube - The Ultimate Video Sharing Platform
// | Copyright (c) 2026 PlayTube. All rights reserved.
// +------------------------------------------------------------------------+
require_once('app_start.php');
use Aws\S3\S3Client;
use Google\Cloud\Storage\StorageClient;
function PT_UserData($user_id = 0, $options = array()) {
    global $db, $pt, $lang, $countries_name;
    if (!empty($options['data'])) {
        $fetched_data   = $user_id;
    }

    else {
        $fetched_data   = $db->where('id', $user_id)->getOne(T_USERS);
    }

    if (empty($fetched_data)) {
        return false;
    }

    $fetched_data->name   = $fetched_data->username;
    $fetched_data->ex_avatar = $fetched_data->avatar;
    $fetched_data->avatar = PT_GetMedia($fetched_data->avatar);
    $fetched_data->ex_cover  = $fetched_data->cover;
    $fetched_data->cover  = PT_GetMedia($fetched_data->cover)  . '?c=' . $fetched_data->last_active;
    $fetched_data->url    = PT_Link('@' . $fetched_data->username);
    $fetched_data->about_decoded = br2nl($fetched_data->about);

    $explode2  = @end(explode('.', $fetched_data->ex_cover));
    $explode3  = @explode('.', $fetched_data->ex_cover);
    $fetched_data->full_cover = PT_GetMedia($fetched_data->ex_cover);
    if ($fetched_data->ex_cover != 'upload/photos/d-cover.jpg') {
        $fetched_data->full_cover = PT_GetMedia($explode3[0] . '_full.' . $explode2);
    }
    

    if (!empty($fetched_data->first_name)) {
        $fetched_data->name = $fetched_data->first_name . ' ' . $fetched_data->last_name;
    }

    if (empty($fetched_data->about)) {
        $fetched_data->about = '';
    }
    $fetched_data->wallet_or = $fetched_data->wallet;
    $fetched_data->balance_or = $fetched_data->balance;
    $fetched_data->balance  = number_format($fetched_data->balance, 2);
    $fetched_data->name_v   = $fetched_data->name;
    if ($fetched_data->verified == 1 && $pt->config->verification_badge == 'on') {
        $fetched_data->name_v = $fetched_data->name . ' <i class="fa fa-check-circle fa-fw verified"></i>';
    }

    $fetched_data->country_name  = (!empty($countries_name[$fetched_data->country_id])) ? $countries_name[$fetched_data->country_id] : "";
    @$fetched_data->gender_text  = ($fetched_data->gender == 'male') ? $lang->male : $lang->female;
    $fetched_data->am_i_subscribed = 0;
    if (!empty($pt->user)) {
        $fetched_data->am_i_subscribed  = $db->where('user_id', $fetched_data->id)->where('subscriber_id', $pt->user->id)->getValue(T_SUBSCRIPTIONS, "count(*)");
    }
    if (!empty($fetched_data->fav_category)) {
        $fetched_data->fav_category = json_decode($fetched_data->fav_category);
    }
    else{
        $fetched_data->fav_category = array();
    }
    
    $fetched_data->channel_notify = false;
    if (!empty($pt->user)) {
        $is_on = $db->where('user_id',$fetched_data->id)->where('subscriber_id',$pt->user->id)->where('notify',1)->getValue(T_SUBSCRIPTIONS,'COUNT(*)');
        if ($is_on > 0) {
            $fetched_data->channel_notify = true;
        }
    }
    if (!empty($fetched_data->privacy)) {
        $fetched_data->privacy = json_decode($fetched_data->privacy);
    }

    $fetched_data->subscribe_count = 0;
    if ($fetched_data->privacy->show_subscriptions_count == 'yes' || ($pt->loggedin && ($pt->user->id == $fetched_data->id || PT_IsAdmin()))) {
        $fetched_data->subscribe_count = number_format($db->where('user_id', $fetched_data->id)->getValue(T_SUBSCRIPTIONS, "count(*)"));
    }

    return $fetched_data;
}

function PT_GetConfig() {
    global $db;
    $data  = array();
    $configs = $db->get(T_CONFIG);
    foreach ($configs as $key => $config) {
        $data[$config->name] = $config->value;
    }
    return $data;
}

function PT_GetAllUsers() {
    global $db;
    $data         = array();
    $fetched_data = $db->get(T_USERS);
    foreach ($fetched_data as $key => $value) {
        $data[] = PT_UserData($value->id);
    }
    return $data;
}

function PT_IsAdmin() {
    global $pt;
    if (IS_LOGGED == false) {
        return false;
    }
    if ($pt->user->admin == 1) {
        return true;
    }
    return false;
}

function PT_IsUpgraded(){
    global $pt;
    if (IS_LOGGED == false) {
        return false;
    }

    if ($pt->user->is_pro > 0) {
        return true;
    }

    return false;
}


function PT_GetMessageButton($username = '') {
    global $pt, $db, $lang;
    if (empty($username)) {
        return false;
    }
    if (IS_LOGGED == false) {
        return false;
    }
    if ($username == $pt->user->username) {
        return false;
    }
    $button_text  = $lang->message;
    $button_icon  = 'plus-square';
    $button_class = 'subscribe';
    return PT_LoadPage('buttons/message', array(
        'BUTTON' => $button_class,
        'ICON' => $button_icon,
        'TEXT' => $button_text,
        'USERNAME' => $username,
    ));
}

function PT_GetBlockButton($user_id,$redirect = true) {
    global $pt, $db, $lang;
    if (empty($user_id)) {
        return false;
    }
    if (IS_LOGGED == false) {
        return false;
    }
    if ($user_id == $pt->user->id) {
        return false;
    }
    $button_text  = $lang->block;
    $button_icon  = 'plus-square';
    $button_class = 'subscribe';
    $check_if_block = $db->where('user_id', $pt->user->id)->where('blocked_id', $user_id)->getValue(T_BLOCK, 'count(*)');
    if ($check_if_block > 0) {
        $button_text  = $lang->unblock;
    }

    return PT_LoadPage('buttons/block', array(
        'BUTTON' => $button_class,
        'ICON' => $button_icon,
        'TEXT' => $button_text,
        'USERID' => $user_id,
        'RED' => $redirect
    ));
}
function PT_GetNotifyButton($user_id) {
    global $pt, $db, $lang;
    if (empty($user_id)) {
        return '';
    }
    if (IS_LOGGED == false) {
        return '';
    }
    if ($user_id == $pt->user->id) {
        return '';
    }

    $sub = $db->where('user_id', $user_id)->where('subscriber_id', $pt->user->id)->getOne(T_SUBSCRIPTIONS);
    if (!empty($sub)) {
        if ($sub->notify != 1) {
            return PT_LoadPage('buttons/notify', array(
                'TEXT' => $lang->enable_notify,
                'USERID' => $user_id
            ));
        }
        else{
            return PT_LoadPage('buttons/unnotify', array(
                'TEXT' => $lang->disable_notify,
                'USERID' => $user_id
            ));
        }
    }
    return '';
}
function PT_GetSubscribeButton($user_id = 0) {
    global $pt, $db, $lang;
    if (empty($user_id)) {
        return false;
    }

    $button_text  = $lang->subscribe;
    $button_icon  = '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>';
    $button_class = 'subscribe';
    $type = '';
    $user = PT_UserData($user_id);
    $subHTML = '<span class="subs-amount">{{SUBS}}</span>';
    if (IS_LOGGED == true) {
        if ($user_id == $pt->user->id) {
            return PT_LoadPage('buttons/manage-videos', array(
                'SUBS' => number_format($db->where('user_id', $pt->user->id)->getValue(T_SUBSCRIPTIONS, "count(*)"))
            ));
        }
        $check_if_payed = 0;
        if ($pt->config->payed_subscribers == 'on' && canUseFeature($user->id,'who_can_payed_subscribers')) {
            
            if (!empty($user) && $user->subscriber_price > 0) {
                // $check_if_payed = $db->where('user_id', $user_id)->where('paid_id', $pt->user->id)->where('type','subscribe')->getValue(T_VIDEOS_TRSNS, 'count(*)');
                $check_if_payed = $db->where('user_id', $user_id)->where('subscriber_id', $pt->user->id)->getValue(T_SUBSCRIPTIONS, 'count(*)');
                if ($check_if_payed == 0) {
                    return PT_LoadPage('buttons/pay_subscribe', array(
                        'IS_SUBSCRIBED_BUTTON' => $button_class,
                        'IS_SUBSCRIBED_ICON' => $button_icon,
                        'IS_SUBSCRIBED_TEXT' => $button_text,
                        'USER_ID' => $user_id,
                        'SUBS' => ($user->privacy->show_subscriptions_count == 'yes' ? str_replace('{{SUBS}}', number_format($db->where('user_id', $user_id)->getValue(T_SUBSCRIPTIONS, "count(*)")), $subHTML) : ''),
                        'PRICE' => $pt->config->currency_symbol_array[$pt->config->payment_currency].$user->subscriber_price,
                        'R_PRICE' => $user->subscriber_price,
                        'TYPE' => 'subscribe',
                        'class' => ($user->privacy->show_subscriptions_count == 'yes' ? '' : 'no-before')
                    ));
                }
                else{
                    return PT_LoadPage('buttons/pay_subscribe', array(
                        'IS_SUBSCRIBED_BUTTON' => 'subscribed',
                        'IS_SUBSCRIBED_ICON' => '<polyline points="20 6 9 17 4 12"></polyline>',
                        'IS_SUBSCRIBED_TEXT' => $lang->subscribed,
                        'USER_ID' => $user_id,
                        'SUBS' => ($user->privacy->show_subscriptions_count == 'yes' ? str_replace('{{SUBS}}', number_format($db->where('user_id', $user_id)->getValue(T_SUBSCRIPTIONS, "count(*)")), $subHTML) : ''),
                        'PRICE' => $pt->config->currency_symbol_array[$pt->config->payment_currency].$user->subscriber_price,
                        'R_PRICE' => $user->subscriber_price,
                        'TYPE' => 'unsubscribe',
                        'class' => ($user->privacy->show_subscriptions_count == 'yes' ? '' : 'no-before')
                    ));
                }
            }
        }

        $check_if_sub = $db->where('user_id', $user_id)->where('subscriber_id', $pt->user->id)->getValue(T_SUBSCRIPTIONS, 'count(*)');
        if ($check_if_sub == 1) {
            $button_text  = $lang->subscribed;
            $button_icon  = '<polyline points="20 6 9 17 4 12"></polyline>';
            $button_class = 'subscribed';
        }
    }
    return PT_LoadPage('buttons/subscribe', array(
        'IS_SUBSCRIBED_BUTTON' => $button_class,
        'IS_SUBSCRIBED_ICON' => $button_icon,
        'IS_SUBSCRIBED_TEXT' => $button_text,
        'USER_ID' => $user_id,
        'SUBS' => ($user->privacy->show_subscriptions_count == 'yes' ? str_replace('{{SUBS}}', number_format($db->where('user_id', $user_id)->getValue(T_SUBSCRIPTIONS, "count(*)")), $subHTML) : ''),
        'class' => ($user->privacy->show_subscriptions_count == 'yes' ? '' : 'no-before')
    ));
}

function PT_GetSubscribePlaylistButton($user_id = 0,$playlist = 0) {
    global $pt, $db, $lang;
    if (empty($user_id) || empty($playlist)) {
        return false;
    }

    $button_text  = $lang->subscribe_to_playlist;
    $button_icon  = '<path fill="currentColor" d="M21,19V20H3V19L5,17V11C5,7.9 7.03,5.17 10,4.29C10,4.19 10,4.1 10,4A2,2 0 0,1 12,2A2,2 0 0,1 14,4C14,4.1 14,4.19 14,4.29C16.97,5.17 19,7.9 19,11V17L21,19M14,21A2,2 0 0,1 12,23A2,2 0 0,1 10,21M19.75,3.19L18.33,4.61C20.04,6.3 21,8.6 21,11H23C23,8.07 21.84,5.25 19.75,3.19M1,11H3C3,8.6 3.96,6.3 5.67,4.61L4.25,3.19C2.16,5.25 1,8.07 1,11Z" />';
    $button_class = 'subscribe';
    $type = '';
    if (IS_LOGGED == true) {
        if ($user_id == $pt->user->id) {
            return '';
        }

        $check_if_sub = $db->where('subscriber_id', $pt->user->id)->where('list_id', $playlist)->getValue(T_PLAYLIST_SUB, 'count(*)');
        if ($check_if_sub == 1) {
            $button_text  = $lang->subscribed_to_playlist;
            $button_icon  = 'M17.75 21.16L15 18.16L16.16 17L17.75 18.59L21.34 15L22.5 16.41L17.75 21.16M3 20V19L5 17V11C5 7.9 7.03 5.18 10 4.29V4C10 2.9 10.9 2 12 2C13.11 2 14 2.9 14 4V4.29C16.97 5.18 19 7.9 19 11V12.08L18 12C14.69 12 12 14.69 12 18C12 18.7 12.12 19.37 12.34 20H3M12 23C10.9 23 10 22.11 10 21H12.8C13.04 21.41 13.33 21.79 13.65 22.13C13.29 22.66 12.69 23 12 23Z';
            $button_class = 'subscribed';
        }
    }
    return PT_LoadPage('buttons/playlist_subscribe', array(
        'IS_SUBSCRIBED_BUTTON' => $button_class,
        'IS_SUBSCRIBED_ICON' => $button_icon,
        'IS_SUBSCRIBED_TEXT' => $button_text,
        'USER_ID' => $user_id,
        'PLAYLIST' => $playlist,
        'SUBS' => number_format($db->where('list_id', $playlist)->getValue(T_PLAYLIST_SUB, "count(*)"))
    ));
}

function PT_GetVideoByID($video_id = '', $add_views = 0, $likes_dislikes = 0, $run_query = 1, $short_id = 0) {
    global $pt, $db, $categories;

    if (empty($video_id)) {
        return false;
    }
    if ($short_id == 1) {
        $get_video = $db->where('user_id',$pt->blocked_array , 'NOT IN')->where('short_id', $video_id)->getOne(T_VIDEOS);
    } else if ($run_query == 1) {
        $get_video = $db->where('user_id',$pt->blocked_array , 'NOT IN')->where('video_id', $video_id)->getOne(T_VIDEOS);
    } else if ($run_query == 2) {
         $get_video = $db->where('user_id',$pt->blocked_array , 'NOT IN')->where('id', $video_id)->getOne(T_VIDEOS);
    } else {
        $get_video = $video_id;
    }

    if (!empty($get_video)) {

        $get_video->org_thumbnail = $get_video->thumbnail;
        $get_video->video_id_      = $get_video->video_id;
        if (strpos($get_video->thumbnail, 'upload/photos') !== false) {
            $get_video->thumbnail      = PT_GetMedia($get_video->thumbnail);
            $get_video->source         = 'Uploaded';
            $get_video->video_type     = 'video/mp4';

            if ($get_video->type == 4 || !empty($get_video->embed)) {
                $get_video->video_location = urldecode($get_video->video_location);
            }

            else{
                $get_video->video_location = PT_GetMedia($get_video->video_location);
            }

        }
        $get_video->type         = 'video';
        if (!empty($get_video->youtube) && $get_video->converted == 1 && !empty($get_video->video_location)) {
            $get_video->youtube        = '';
            $get_video->video_type     = 'video/mp4';
            $get_video->source         = 'Uploaded';
            $get_video->video_location = PT_GetMedia($get_video->video_location);
        }
        if (!empty($get_video->youtube) && $get_video->is_short == 0 && $get_video->converted != 1) {
            $get_video->video_type     = 'video/youtube';
            $get_video->video_location = 'https://www.youtube.com/watch?v=' . $get_video->youtube;
            $get_video->video_id_      = $get_video->youtube;
            $get_video->source         = 'YouTube';
            $get_video->type         = 'youtube';
        }
        if (!empty($get_video->daily)) {
            $get_video->video_type = 'video/dailymotion';
            $get_video->video_id_  = $get_video->daily;
            $get_video->source         = 'Dailymotion';
            $get_video->type         = 'daily';
        }
        if (!empty($get_video->vimeo)) {
            $get_video->video_type = 'video/vimeo';
            $get_video->video_id_  = $get_video->vimeo;
            $get_video->source         = 'Vimeo';
            $get_video->type         = 'vimeo';
        }
        if (!empty($get_video->facebook)) {
            $get_video->video_type = 'video/facebook';
            $get_video->video_id_  = $get_video->facebook;
            $get_video->source         = 'Facebook';
            $get_video->type         = 'facebook';
        }
        if (!empty($get_video->twitch)) {
            $get_video->video_type = 'video/twitch';
            $get_video->video_id_  = $get_video->twitch;
            $get_video->source         = 'Twitch';
            $get_video->type         = 'twitch';
        }
        if (!empty($get_video->ok)) {
            $get_video->type         = 'ok';
        }
        if (!empty($get_video->twitch)) {
            $get_video->type         = 'twitch';
        }
        if (!empty($get_video->type) && $get_video->type == 4 && strpos($get_video->video_location,'.mp4') !== false) {
            $get_video->type         = 'mp4';
        }
        if (!empty($get_video->type) && $get_video->type == 4 && strpos($get_video->video_location,'.m3u8') !== false) {
            $get_video->type         = 'm3u8';
        }
        if (!empty($get_video->embed)) {
            $get_video->type         = 'embed';
        }
        $get_video->url                = PT_Link('watch/' . $get_video->video_id);
        $get_video->ajax_url                = '?link1=watch&id='.$get_video->video_id;
        if ($pt->config->seo_link == 'on') {
            $get_video->url                = PT_Link('watch/' . PT_Slug($get_video->title, $get_video->video_id));
            $get_video->ajax_url = '?link1=watch&id='.PT_Slug($get_video->title, $get_video->video_id);
        }
        if ($get_video->is_short == 1) {
            $get_video->url                = PT_Link('shorts/' . $get_video->video_id);
            $get_video->ajax_url                = '?link1=shorts&id='.$get_video->video_id;
            if ($pt->config->seo_link == 'on') {
                $get_video->url                = PT_Link('shorts/' . PT_Slug($get_video->title, $get_video->video_id));
                $get_video->ajax_url = '?link1=shorts&id='.PT_Slug($get_video->title, $get_video->video_id);
            }
        }

        $get_video->edit_description   = PT_EditMarkup($get_video->description);
        $get_video->markup_description = PT_Markup($get_video->description);
        $get_video->markup_title = PT_Markup($get_video->title,false);
        $get_video->title = PT_Markup($get_video->title,false,true,false);
        $get_video->owner              = PT_UserData($get_video->user_id);
        $get_video->is_liked           = 0;
        $get_video->is_disliked        = 0;
        $get_video->is_owner           = false;
        $get_video->is_purchased = 0;
        $get_video->paused_time = 0;
        $get_video->is_watch_later = false;
        $get_video->is_playlist = false;
        $get_video->playlistData = null;

        if (IS_LOGGED == true) {
            $get_video->is_purchased = $db->where('video_id',$get_video->id)->where('paid_id',$pt->user->id)->getValue(T_VIDEOS_TRSNS,"count(*)");
            $get_video->is_liked    = $db->where('user_id', $pt->user->id)->where('video_id', $get_video->id)->where('type', 1)->getValue(T_DIS_LIKES, 'count(*)');
            $get_video->is_disliked = $db->where('user_id', $pt->user->id)->where('video_id', $get_video->id)->where('type', 2)->getValue(T_DIS_LIKES, 'count(*)');
            if ($get_video->owner->id == $pt->user->id || PT_IsAdmin()) {
                $get_video->is_owner           = true;
            }
            $get_video->paused_time = $db->where('user_id', $pt->user->id)->where('video_id', $get_video->id)->getOne(T_VIDEO_TIME,array('time'));
            $get_video->is_watch_later = ($db->where('user_id', $pt->user->id)
                            ->where('video_id',$get_video->id)
                            ->getValue(T_WLATER, 'count(*)') > 0);

            $playlist = $db->where('user_id', $pt->user->id)->where('video_id',$get_video->id)->getOne(T_PLAYLISTS);
            if (!empty($playlist)) {
                $get_video->is_playlist = true;
                $get_video->playlistData = $db->where('list_id', $playlist->list_id)->getOne(T_LISTS);
            }
        }
        $get_video->time_alpha    = TranslateDate(gmdate($pt->config->date_style, $get_video->time));
        $get_video->time_ago      = PT_Time_Elapsed_String($get_video->time);
        $get_video->comments_count      = $db->where('video_id',$get_video->id)->getValue(T_COMMENTS,'COUNT(*)');
        $get_video->category_name = (!empty($categories[$get_video->category_id])) ? $categories[$get_video->category_id] : '';
        if ($likes_dislikes == 1) {
            $db->where('video_id', $get_video->id);
            $db->where('type', 1);
            $get_video->likes = $db->getValue(T_DIS_LIKES, 'count(*)');

            $db->where('video_id', $get_video->id);
            $db->where('type', 2);
            $get_video->dislikes = $db->getValue(T_DIS_LIKES, 'count(*)');

            $total                    = $get_video->likes + $get_video->dislikes;
            $get_video->likes_percent = 0;
            if ($get_video->likes > 0) {
                $get_video->likes_percent = round(($get_video->likes / $total) * 100);
            }
            $get_video->dislikes_percent = 0;
            if ($get_video->dislikes > 0) {
                $get_video->dislikes_percent = round(($get_video->dislikes / $total) * 100);
            }

            if ($get_video->likes_percent == 0 && $get_video->dislikes_percent == 0) {
                $get_video->dislikes_percent = 100;
                $get_video->likes_percent    = 0;
            }
        }
        $get_video->gif = PT_GetMedia($get_video->gif);

        

        return $get_video;
    }
    return array();
}
function addhttp($url) {
    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
        $url = "http://" . $url;
    }
    return $url;
}
function PT_GetMedia($media = '', $is_upload = false){
    global $pt;
    if (empty($media)) {
        return '';
    }
    if (strpos($media, "http") === 0) {
        return $media;
    }

    $media_url     = $pt->config->site_url . '/' . $media;
    if ($pt->config->s3_upload == 'on' && $is_upload == false) {
        $media_url = "https://" . $pt->config->s3_bucket_name . ".s3.amazonaws.com/" . $media;
        if (!empty($pt->config->amazon_endpoint) && filter_var($pt->config->amazon_endpoint, FILTER_VALIDATE_URL)) {
            $media_url = $pt->config->amazon_endpoint . "/" . $media;
        }
    } else if ($pt->config->ftp_upload == "on") {
        return addhttp($pt->config->ftp_endpoint) . '/' . $media;
    }
    else if ($pt->config->spaces == 'on') {
        if (empty($pt->config->spaces_key) || empty($pt->config->spaces_secret) || empty($pt->config->space_region) || empty($pt->config->space_name)) {
            return $pt->config->site_url . '/' . $media;
        }
        if (!empty($pt->config->spaces_endpoint) && filter_var($pt->config->spaces_endpoint, FILTER_VALIDATE_URL)) {
            return $pt->config->spaces_endpoint . "/" . $media;
        }
        return  'https://' . $pt->config->space_name . '.' . $pt->config->space_region . '.digitaloceanspaces.com/' . $media;
    }
    else if ($pt->config->cloud_upload == 'on') {
        if (!empty($pt->config->cloud_endpoint) && filter_var($pt->config->cloud_endpoint, FILTER_VALIDATE_URL)) {
            return $pt->config->cloud_endpoint . "/" . $media;
        }
        return 'https://storage.googleapis.com/' . $pt->config->cloud_bucket_name . '/' . $media;
    }
    elseif (!empty($pt->config->wasabi_access_key) && $pt->config->wasabi_storage == 'on') {
        $pt->config->wasabi_site_url        = 'https://s3.'.$pt->config->wasabi_bucket_region.'.wasabisys.com';
        if (!empty($pt->config->wasabi_endpoint) && filter_var($pt->config->wasabi_endpoint, FILTER_VALIDATE_URL)) {
            return $pt->config->wasabi_endpoint . "/" . $media;
        }
        if (!empty($pt->config->wasabi_bucket_name)) {
            $pt->config->wasabi_site_url = 'https://s3.'.$pt->config->wasabi_bucket_region.'.wasabisys.com/'.$pt->config->wasabi_bucket_name;
            return $pt->config->wasabi_site_url . '/' . $media;
        }
    }
    elseif ($pt->config->backblaze_storage == 'on' && !empty($pt->config->backblaze_bucket_id)) {
        if (!empty($pt->config->backblaze_endpoint) && filter_var($pt->config->backblaze_endpoint, FILTER_VALIDATE_URL)) {
            return $pt->config->backblaze_endpoint . "/" . $media;
        }
        return 'https://' . $pt->config->backblaze_bucket_name . '.s3.' . $pt->config->backblaze_region . '.backblazeb2.com/' . $media;
    }
    elseif ($pt->config->yandex_storage == 'on' && !empty($pt->config->yandex_name)) {
        return 'https://storage.yandexcloud.net/'.$pt->config->yandex_name.'/' . $media;
    }
    elseif ($pt->config->azure_blob_storage == 'on' && !empty($pt->config->azure_blob_bucket_name)) {
        if (!empty($pt->config->azure_blob_cdn) && filter_var($pt->config->azure_blob_cdn, FILTER_VALIDATE_URL)) {
            return rtrim($pt->config->azure_blob_cdn, '/') . '/' . ltrim($media, '/');
        }
        $azure_blob_endpoint = $pt->config->azure_blob_endpoint;
        if (empty($azure_blob_endpoint) && !empty($pt->config->azure_blob_account)) {
            $azure_blob_endpoint = 'https://' . $pt->config->azure_blob_account . '.blob.core.windows.net';
        }
        if (!empty($azure_blob_endpoint) && filter_var($azure_blob_endpoint, FILTER_VALIDATE_URL)) {
            return rtrim($azure_blob_endpoint, '/') . '/' . $pt->config->azure_blob_bucket_name . '/' . ltrim($media, '/');
        }
    }

    return $media_url;
}

function PT_UserActive($user_id = 0) {
    global $db;
    $db->where('active', '1');
    $db->where('id', PT_Secure($user_id));
    return ($db->getValue(T_USERS, 'count(*)') > 0) ? true : false;
}

function PT_UserEmailExists($email = '') {
    global $db;
    return ($db->where('email', PT_Secure($email))->getValue(T_USERS, 'count(*)') > 0) ? true : false;
}

function PT_UsernameExists($username = '') {
    global $db;
    return ($db->where('username', PT_Secure($username))->getValue(T_USERS, 'count(*)') > 0) ? true : false;
}

function PT_ImportImageFromLogin($media) {
    global $pt;
    if (!file_exists('upload/photos/' . date('Y'))) {
        mkdir('upload/photos/' . date('Y'), 0777, true);
    }
    if (!file_exists('upload/photos/' . date('Y') . '/' . date('m'))) {
        mkdir('upload/photos/' . date('Y') . '/' . date('m'), 0777, true);
    }
    $dir               = 'upload/photos/' . date('Y') . '/' . date('m');
    $file_dir          = $dir . '/' . PT_GenerateKey() . '_avatar.jpg';
    $getImage          = connect_to_url($media);
    if (!empty($getImage)) {
        $importImage = file_put_contents($file_dir, $getImage);
        if ($importImage) {
            PT_Resize_Crop_Image(400, 400, $file_dir, $file_dir, 100);
        }
    }
    if (file_exists($file_dir)) {
        if ($pt->remoteStorage) {
            PT_UploadToS3($file_dir);
        }
        return $file_dir;
    } else {
        return $pt->userDefaultAvatar;
    }
}

function PT_SendMessage($data = array()) {
    global $pt, $db, $mail;
    $email_from      = $data['from_email'] = PT_Secure($data['from_email']);
    $to_email        = $data['to_email'] = PT_Secure($data['to_email']);
    $subject         = $data['subject'];
    $data['charSet'] = $data['charSet'];

    try {
        if (!empty($data["return"]) && $data["return"] == 'debug') {
            $mail->SMTPDebug = 2;
        }

        if ($pt->config->smtp_or_mail == 'mail') {
            $mail->IsMail();
        }

        else if ($pt->config->smtp_or_mail == 'smtp') {
            $mail->isSMTP();
            $mail->Host        = $pt->config->smtp_host;
            $mail->SMTPAuth    = true;
            $mail->Username    = $pt->config->smtp_username;
            $mail->Password    = openssl_decrypt($pt->config->smtp_password, "AES-128-ECB", 'mysecretkey1234');
            $mail->SMTPSecure  = $pt->config->smtp_encryption;
            $mail->Port        = $pt->config->smtp_port;
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
        }

        else {
            return false;
        }

        $mail->IsHTML(true);
        $mail->setFrom(strip_tags($data['from_email'], $data['from_name']));
        $mail->addAddress($data['to_email'], $data['to_name']);
        $mail->Subject = $data['subject'];
        $mail->CharSet = "text/html; charset=UTF-8;";
        //$mail->Body = $data['message_body'];
        $mail->MsgHTML($data['message_body']);
        if ($mail->send()) {
            $mail->clearAddresses();
            $mail->clearCCs();
            $mail->clearBCCs();
            return true;
        }
        else{
            if (!empty($data["return"])) {
                return $mail->ErrorInfo;
            }
        }
        
    } catch (Exception $e) {
        if (!empty($data["return"])) {
            if (!empty($e->getMessage())) {
                return $e->getMessage();
            }
            return $mail->ErrorInfo;
        }
        return false;
    } catch (phpmailerException $e) {
        if (!empty($data["return"])) {
            if (!empty($e->errorMessage())) {
                return $e->errorMessage();
            }
            return $mail->ErrorInfo;
        }
        return false;
    }
    return false;
}

function PT_ShareFile($data = array(), $type = 0) {
    global $pt, $mysqli, $db;
    $allowed = '';
    if (!file_exists('upload/photos/' . date('Y'))) {
        @mkdir('upload/photos/' . date('Y'), 0777, true);
    }
    if (!file_exists('upload/photos/' . date('Y') . '/' . date('m'))) {
        @mkdir('upload/photos/' . date('Y') . '/' . date('m'), 0777, true);
    }
    if (!file_exists('upload/timeline/' . date('Y') . '/' . date('m'))) {
        @mkdir('upload/timeline/' . date('Y') . '/' . date('m'), 0777, true);
    }
    if (!file_exists('upload/videos/' . date('Y'))) {
        @mkdir('upload/videos/' . date('Y'), 0777, true);
    }
    if (!file_exists('upload/videos/' . date('Y') . '/' . date('m'))) {
        @mkdir('upload/videos/' . date('Y') . '/' . date('m'), 0777, true);
    }
    if (!file_exists('upload/photos/' . date('Y').'/index.html')) {
        @file_put_contents('upload/photos/' . date('Y').'/index.html','index.html');
    }
    if (!file_exists('upload/photos/' . date('Y') . '/' . date('m').'/index.html')) {
        @file_put_contents('upload/photos/' . date('Y') . '/' . date('m').'/index.html','index.html');
    }
    if (!file_exists('upload/videos/' . date('Y').'/index.html')) {
        @file_put_contents('upload/videos/' . date('Y').'/index.html','index.html');
    }
    if (!file_exists('upload/videos/' . date('Y') . '/' . date('m').'/index.html')) {
        @file_put_contents('upload/videos/' . date('Y') . '/' . date('m').'/index.html','index.html');
    }
    if (isset($data['file']) && !empty($data['file'])) {
        $data['file'] = $data['file'];
    }
    if (isset($data['name']) && !empty($data['name'])) {
        $data['name'] = PT_Secure($data['name']);
    }
    if (isset($data['name']) && !empty($data['name'])) {
        $data['name'] = PT_Secure($data['name']);
    }
    if (empty($data)) {
        return false;
    }
    $allowed           = 'jpg,png,jpeg,gif,mp4,mov,webm,mpeg,3gp,mkv,mk3d,mks,webp';
    if (!empty($data['allowed'])) {
        $allowed  = $data['allowed'];
    }
    $new_string        = pathinfo($data['name'], PATHINFO_FILENAME) . '.' . strtolower(pathinfo($data['name'], PATHINFO_EXTENSION));
    $extension_allowed = explode(',', $allowed);
    $file_extension    = pathinfo($new_string, PATHINFO_EXTENSION);
    if (!in_array($file_extension, $extension_allowed)) {
        return array(
            'error' => 'File format not supported'
        );
    }
    if ($file_extension == 'jpg' || $file_extension == 'jpeg' || $file_extension == 'png' || $file_extension == 'gif' || $file_extension == 'webp') {
        $folder   = 'photos';
        $fileType = 'image';
    } else {
        $folder   = 'videos';
        $fileType = 'video';
    }
    if (empty($folder) || empty($fileType)) {
        return false;
    }
    $ar = array(
        'video/mp4',
        'video/mov',
        'video/3gp',
        'video/3gpp',
        'video/mpeg',
        'video/flv',
        'video/avi',
        'video/webm',
        'audio/wav',
        'audio/mpeg',
        'video/quicktime',
        'audio/mp3',
        'image/png',
        'image/jpeg',
        'image/gif',
        'video/x-msvideo',
        'video/msvideo',
        'video/x-ms-wmv',
        'video/x-flv',
        'video/x-matroska',
        'video/webm',
        'image/webp',
        "application/octet-stream",
        "application/json"
    );

    if (!in_array($data['type'], $ar)) {
        return array(
            'error' => 'File format not supported'
        );
    }

    $fileNameOriginal = PT_GenerateKey() . '_' . date('d') . '_' . md5(time()) . "_{$fileType}.{$file_extension}";

    $dir         = "upload/{$folder}/" . date('Y') . '/' . date('m');

    if (!empty($_REQUEST['chunks'])) {
        $name = (!empty($_REQUEST['name'])) ? md5($_REQUEST['name']) : "";
        $db->where("user_id", $pt->user->id)->where("status", "pending")->where("type", "video");
        if (!empty($name)) {
            $db->where("name", $name);
        }
        $getFileNameFromDB = $db->getOne(T_UPLOADED_CUNKS);

        if (!empty($getFileNameFromDB)) {
            $fileNameOriginal = $getFileNameFromDB->filename;
        } else {
            $getFileNameFromDB = $db->insert(T_UPLOADED_CUNKS, ["filename" => $fileNameOriginal, "user_id" => $pt->user->id, "folderpath" => $dir, "name" => $name]);
        }
    } else {
        $_SESSION['fileSize'] = "";
        unset($_SESSION['fileSize']);
    }
    

    
    $filename    = $dir . '/' .$fileNameOriginal;
    $second_file = pathinfo($filename, PATHINFO_EXTENSION);
    if (!empty($_REQUEST['chunks'])) {
        $uploadCHunk = uploadChunk($fileNameOriginal, $dir);
        if ($uploadCHunk === true) {
            $db->where("user_id", $pt->user->id)->where("status", "pending")->where("type", "video");
            if (!empty($name)) {
                $db->where("name", $name);
            }
            $db->update(T_UPLOADED_CUNKS, ["status" => "completed"]);
            $last_data['filename'] = $filename;
            $last_data['name']     = $data['name'];
            return $last_data;
        }
        return [];
    } else if (move_uploaded_file($data['file'], $filename)) {
        if ($second_file == 'jpg' || $second_file == 'jpeg' || $second_file == 'png' || $second_file == 'gif' || $second_file == 'webp') {
            if ($type == 1) {
                @PT_CompressImage($filename, $filename, 50);
                $explode2  = @end(explode('.', $filename));
                $explode3  = @explode('.', $filename);
                $last_file = $explode3[0] . '_small.' . $explode2;
                @PT_Resize_Crop_Image(400, 400, $filename, $last_file, 60);

                if (!empty($last_file) && $pt->remoteStorage) {
                    $upload_s3 = PT_UploadToS3($last_file);
                }
            }

            else {
                if ($second_file != 'gif') {
                    if ($type == 2) {
                        $explode2  = @end(explode('.', $filename));
                        $explode3  = @explode('.', $filename);
                        $last_file = $explode3[0] . '_full.' . $explode2;
                        @PT_CompressImage($filename, $last_file, 100);
                    }

                    if (!empty($data['crop'])) {
                        $crop_image = PT_Resize_Crop_Image($data['crop']['width'], $data['crop']['height'], $filename, $filename, 80);
                    }
                    @PT_CompressImage($filename, $filename, 90);
                }

                if (!empty($filename) && $pt->remoteStorage) {
                    $upload_s3 = PT_UploadToS3($filename);
                    if (!empty($last_file)) {
                        $upload_s3 = PT_UploadToS3($last_file);
                    }
                }
            }
        }

        else{
            if (!empty($filename) && $pt->remoteStorage) {
                $upload_s3 = PT_UploadToS3($filename);
            }
        }

        $last_data             = array();
        $last_data['filename'] = $filename;
        $last_data['name']     = $data['name'];
        return $last_data;
    }
}

function PT_DeleteUser($id = 0) {
    global $pt, $db;
    if (empty($id)) {
        return false;
    }
    if ($pt->user->id != $id) {
       if (PT_IsAdmin() == false) {
           return false;
       }
    }
    $get_videos = $db->where('user_id', $id)->get(T_VIDEOS, null, 'id');
    foreach ($get_videos as $key => $video) {
        $delete_video = PT_DeleteVideo($video->id);
    }
    $get_cover_and_avatar = PT_UserData($id);
    if ($get_cover_and_avatar->ex_avatar != 'upload/photos/d-avatar.jpg') {
        @unlink($get_cover_and_avatar->ex_avatar);
        if ($pt->remoteStorage) {
            PT_DeleteFromToS3($get_cover_and_avatar->ex_avatar);
        }
    }
    if ($get_cover_and_avatar->ex_cover != 'upload/photos/d-cover.jpg') {
        @unlink($get_cover_and_avatar->ex_cover);
        if ($pt->remoteStorage) {
            PT_DeleteFromToS3($get_cover_and_avatar->ex_cover);
        }
    }
    $articles = $db->where('user_id',$id)->get(T_POSTS);
    if (!empty($articles)) {
        foreach ($articles as $key => $article) {
            if (file_exists($article->image)) {
                unlink($article->image);
            }

            else if ($pt->remoteStorage === true) {
                PT_DeleteFromToS3($article->image);
            }

            $delete  = $db->where('id',$article->id)->delete(T_POSTS);
            $delete  = $db->where('post_id',$article->id)->delete(T_DIS_LIKES);

            //Delete related data
            $post_comments = $db->where('post_id',$article->id)->get(T_COMMENTS);

            foreach ($post_comments as $comment_data) {
                $delete    = $db->where('comment_id',$comment_data->id)->delete(T_COMMENTS_LIKES);
                $replies   = $db->where('comment_id',$comment_data->id)->get(T_COMM_REPLIES);
                $db->where('comment_id',$comment_data->id)->delete(T_COMM_REPLIES);

                foreach ($replies as $comment_reply) {
                    $db->where('reply_id',$comment_reply->id)->delete(T_COMMENTS_LIKES);
                }
            }

            if (!empty($post_comments)) {
                $delete    = $db->where('post_id',$article->id)->delete(T_COMMENTS);
            }
        }
    }
    $get_comments = $db->where('user_id', $id)->get(T_COMMENTS);
    foreach ($get_comments as $key => $comment) {
        $delete  = $db->where('comment_id', $comment->id)->delete(T_COMMENTS_LIKES);
        $r_votes = $db->where('comment_id', $comment->id)->get(T_COMM_REPLIES);
        $delete  = $db->where('comment_id', $comment->id)->delete(T_COMM_REPLIES);
        foreach ($r_votes as $reply_vote) {
            $db->where('reply_id', $reply_vote->id)->delete(T_COMMENTS_LIKES);
        }
    }
    $delete_user = $db->where('id', $id)->delete(T_USERS);
    $delete_user = $db->where('user_id', $id)->delete(T_USR_ADS);
    $delete_user = $db->where('user_id', $id)->delete(T_REPORTS);
    $delete = $db->where('user_id', $id)->delete(T_VIDEO_TIME);
    $delete = $db->where('user_id', $id)->delete(T_NOT_INTERESTED);
    $delete = $db->where('user_id', $id)->delete(T_SESSIONS);
    $delete = $db->where('user_id', $id)->delete(T_VIDEOS);
    $delete = $db->where('user_id', $id)->delete(T_DIS_LIKES);
    $delete = $db->where('user_id', $id)->delete(T_COMMENTS);
    $delete = $db->where('user_id', $id)->delete(T_COMM_REPLIES);
    $delete = $db->where('user_id', $id)->delete(T_COMMENTS_LIKES);
    $delete = $db->where('user_id', $id)->delete(T_SAVED);
    $delete = $db->where('user_id', $id)->delete(T_SUBSCRIPTIONS);
    $delete = $db->where('user_id', $id)->delete(T_HISTORY);
    $delete = $db->where('user_id', $id)->delete(T_LISTS);
    $delete = $db->where('user_id', $id)->delete(T_PLAYLISTS);
    $delete = $db->where('user_id', $id)->delete(T_WLATER);
    $delete = $db->where('user_id', $id)->delete(T_POSTS);
    $delete = $db->where('user_id', $id)->delete(T_PAYMENTS);
    $delete = $db->where('user_id', $id)->delete(T_USR_PROF_FIELDS);
    $delete = $db->where('user_id', $id)->delete(T_WITHDRAWAL_REQUESTS);
    $delete = $db->where('user_id', $id)->delete(T_VERIF_REQUESTS);
    $delete = $db->where('user_id', $id)->delete(T_ANNOUNCEMENT_VIEWS);
    $delete = $db->where('notifier_id', $id)->delete(T_NOTIFICATIONS);
    $delete = $db->where('recipient_id', $id)->delete(T_NOTIFICATIONS);
    $delete = $db->where('from_id', $id)->delete(T_MESSAGES);
    $delete = $db->where('to_id', $id)->delete(T_MESSAGES);
    $delete = $db->where('user_two', $id)->delete(T_CHATS);
    $delete = $db->where('user_one', $id)->delete(T_CHATS);
    $delete = $db->where('user_id', $id)->delete(T_VIDEOS_TRSNS);
    $delete = $db->where('user_id', $id)->delete(T_VIEWS);
    $delete = $db->where('user_id', $id)->delete(T_BLOCK);
    $delete = $db->where('blocked_id', $id)->delete(T_BLOCK);
    $delete = $db->where('user_id', $id)->delete(T_BANK_TRANSFER);
    $delete = $db->where('user_id', $id)->delete(T_COPYRIGHT);
    $delete = $db->where('user_id', $id)->delete(T_MON_REQUESTS);
    $delete = $db->where('user_id', $id)->delete(T_UPLOADED);
    $delete = $db->where('subscriber_id', $id)->delete(T_PLAYLIST_SUB);
    $delete = $db->where('user_id', $id)->delete(T_ACTIVITES);
    $delete = $db->where('user_id', $id)->delete(T_LIVE_SUB);
    $delete = $db->where('user_id', $id)->delete(T_CARDS);
    $delete = $db->where('user_id', $id)->delete(T_INVITAION_LINKS);
    $delete = $db->where('invited_id', $id)->delete(T_INVITAION_LINKS);
    $db->where('user_id', $id)->delete(T_PENDING_PAYMENTS);
    if ($delete_user) {
        return true;
    }
}

function PT_DeleteVideo($id = 0) {
    global $pt, $db;
    if (empty($id)) {
        return false;
    }

    $get_video = $db->where('id', $id)->getOne(T_VIDEOS);
    if (strpos($get_video->thumbnail, 'upload/photos') !== false) {
        if ($get_video->thumbnail != 'upload/photos/thumbnail.jpg') {
            if (file_exists($get_video->thumbnail)) {
                unlink($get_video->thumbnail);
            }

            if ($pt->remoteStorage) {
                PT_DeleteFromToS3($get_video->thumbnail);
            }
        }

    }


    if (!empty($get_video->video_location)) {
        if (file_exists($get_video->video_location)) {
            unlink($get_video->video_location);
        }

        PT_DeleteFromToS3($get_video->video_location);
    }

    $explode_video = @explode('_video', $get_video->video_location);
    if (!empty($explode_video)) {
        if (!empty($get_video->{"240p"})) {
            @unlink($explode_video[0] . '_video_240p_converted.mp4');
            PT_DeleteFromToS3($explode_video[0] . '_video_240p_converted.mp4');
        }

        if (!empty($get_video->{"360p"})) {
            @unlink($explode_video[0] . '_video_360p_converted.mp4');
            PT_DeleteFromToS3($explode_video[0] . '_video_360p_converted.mp4');
        }

        if (!empty($get_video->{"480p"})) {
            @unlink($explode_video[0] . '_video_480p_converted.mp4');
            PT_DeleteFromToS3($explode_video[0] . '_video_480p_converted.mp4');
        }

        if (!empty($get_video->{"720p"})) {
            @unlink($explode_video[0] . '_video_720p_converted.mp4');
            PT_DeleteFromToS3($explode_video[0] . '_video_720p_converted.mp4');
        }

        if (!empty($get_video->{"1080p"})) {
            @unlink($explode_video[0] . '_video_1080p_converted.mp4');
            PT_DeleteFromToS3($explode_video[0] . '_video_1080p_converted.mp4');
        }

        if (!empty($get_video->{"4096p"})) {
            @unlink($explode_video[0] . '_video_4096p_converted.mp4');
            PT_DeleteFromToS3($explode_video[0] . '_video_4096p_converted.mp4');
        }

        if (!empty($get_video->{"2048p"})) {
            @unlink($explode_video[0] . '_video_2048p_converted.mp4');
            PT_DeleteFromToS3($explode_video[0] . '_video_2048p_converted.mp4');
        }
        // demo video
        if (!empty($get_video->demo)) {
            @unlink($get_video->demo);
            PT_DeleteFromToS3($get_video->demo);
        }
        // demo video
        // gif video
        if (!empty($get_video->gif)) {
            @unlink($get_video->gif);
            PT_DeleteFromToS3($get_video->gif);
        }
        // gif video
    }

    $delete = $db->where('id', $id)->delete(T_VIDEOS);
    $user_ = $db->where('id', $get_video->user_id)->getOne(T_USERS);
    $size = $get_video->size;
    $db->where('id', $get_video->user_id)->update(T_USERS,array('uploads' => ($user_->uploads - $size)));

    $get_comments = $db->where('video_id', $id)->get(T_COMMENTS);
    foreach ($get_comments as $key => $comment) {
        $delete  = $db->where('comment_id', $comment->id)->delete(T_COMMENTS_LIKES);
        $r_votes = $db->where('comment_id', $comment->id)->get(T_COMM_REPLIES);
        $delete  = $db->where('comment_id', $comment->id)->delete(T_COMM_REPLIES);
        foreach ($r_votes as $reply_vote) {
            $db->where('reply_id', $reply_vote->id)->delete(T_COMMENTS_LIKES);
        }
    }

    $delete = $db->where('video_id', $id)->delete(T_COMMENTS);
    $delete = $db->where('video_id', $id)->delete(T_VIDEO_TIME);
    $delete = $db->where('video_id', $id)->delete(T_NOT_INTERESTED);
    $delete = $db->where('video_id', $id)->delete(T_HISTORY);
    $delete = $db->where('video_id', $id)->delete(T_DIS_LIKES);
    $delete = $db->where('video_id', $id)->delete(T_SAVED);
    $delete = $db->where('video_id', $id)->delete(T_PLAYLISTS);
    $delete = $db->where('video_id', $id)->delete(T_NOTIFICATIONS);
    $delete = $db->where('video_id', $id)->delete(T_VIDEOS_TRSNS);
    $delete = $db->where('video_id', $id)->delete(T_QUEUE);
    if ($delete) {
        return true;
    }
    return false;
}

function PT_UpdateAdminDetails() {
    global $pt, $db;

    $get_videos_count = $db->getValue(T_VIDEOS, 'count(*)');
    $update_videos_count = $db->where('name', 'total_videos')->update(T_CONFIG, array('value' => ($get_videos_count) ? $get_videos_count : 0));

    $get_views_count = $db->getValue(T_VIDEOS, 'SUM(views)');
    $update_views_count = $db->where('name', 'total_views')->update(T_CONFIG, array('value' => ($get_views_count) ? $get_views_count : 0));

    $get_users_count = $db->getValue(T_USERS, 'count(*)');
    $update_users_count = $db->where('name', 'total_users')->update(T_CONFIG, array('value' => ($get_users_count) ? $get_users_count : 0));

    $get_subs_count = $db->getValue(T_SUBSCRIPTIONS, 'count(*)');
    $update_subs_count = $db->where('name', 'total_subs')->update(T_CONFIG, array('value' => ($get_subs_count) ? $get_subs_count : 0));

    $get_comments_count = $db->getValue(T_COMMENTS, 'count(*)');
    $update_comments_count = $db->where('name', 'total_comments')->update(T_CONFIG, array('value' => ($get_comments_count) ? $get_comments_count : 0));

    $get_likes_count = $db->where('type', 1)->getValue(T_DIS_LIKES, 'count(*)');
    $update_likes_count = $db->where('name', 'total_likes')->update(T_CONFIG, array('value' => ($get_likes_count) ? $get_likes_count : 0));

    $get_dislikes_count = $db->where('type', 2)->getValue(T_DIS_LIKES, 'count(*)');
    $update_dislikes_count = $db->where('name', 'total_dislikes')->update(T_CONFIG, array('value' => ($get_dislikes_count) ? $get_dislikes_count : 0));

    $get_saved_count = $db->getValue(T_SAVED, 'count(*)');
    $update_saved_count = $db->where('name', 'total_saved')->update(T_CONFIG, array('value' => ($get_saved_count) ? $get_saved_count : 0));

    $user_statics = array();
    $videos_statics = array();

    $months = array('1','2','3','4','5','6','7','8','9','10','11','12');
    $date = date('Y');

    foreach ($months as $value) {
       $monthNum  = $value;
       $dateObj   = DateTime::createFromFormat('!m', $monthNum);
       $monthName = $dateObj->format('F');
       $user_statics[] = array('month' => $monthName, 'new_users' => $db->where('registered', "$date/$value")->getValue(T_USERS, 'count(*)'));
       $videos_statics[] = array('month' => $monthName, 'new_videos' => $db->where('registered', "$date/$value")->getValue(T_VIDEOS, 'count(*)'));
    }
    $update_user_statics = $db->where('name', 'user_statics')->update(T_CONFIG, array('value' => PT_Secure(json_encode($user_statics))));
    $update_videos_statics = $db->where('name', 'videos_statics')->update(T_CONFIG, array('value' => PT_Secure(json_encode($videos_statics))));


    $update_saved_count = $db->where('name', 'last_admin_collection')->update(T_CONFIG, array('value' => time()));
}

function PT_GetAd($type, $admin = true) {
    global $db;
    $type      = PT_Secure($type);
    $query_one = "SELECT `code` FROM " . T_ADS . " WHERE `placement` = '{$type}'";
    if ($admin === false) {
        $query_one .= " AND `active` = '1'";
    }
    $fetched_data = $db->rawQuery($query_one);
    if (!empty($fetched_data)) {
        return htmlspecialchars_decode($fetched_data[0]->code);
    }
    return '';
}

function PT_GetThemes() {
    global $pt;
    $themes = glob('themes/*', GLOB_ONLYDIR);
    return $themes;
}

function PT_UploadLogo($data = array()) {
    global $pt, $db;
    if (isset($data['file']) && !empty($data['file'])) {
        $data['file'] = PT_Secure($data['file']);
    }
    if (isset($data['name']) && !empty($data['name'])) {
        $data['name'] = PT_Secure($data['name']);
    }
    if (isset($data['name']) && !empty($data['name'])) {
        $data['name'] = PT_Secure($data['name']);
    }
    if (empty($data)) {
        return false;
    }
    $allowed           = 'png';
    $new_string        = pathinfo($data['name'], PATHINFO_FILENAME) . '.' . strtolower(pathinfo($data['name'], PATHINFO_EXTENSION));
    $extension_allowed = explode(',', $allowed);
    $file_extension    = pathinfo($new_string, PATHINFO_EXTENSION);
    if (!in_array($file_extension, $extension_allowed)) {
        return false;
    }
    $logo_name = 'logo';
    if (!empty($data['light-logo'])) {
        $logo_name = 'logo-light';
    }
    if (!empty($data['favicon'])) {
        $logo_name = 'icon';
    }
    if ($logo_name == 'logo' || $logo_name == 'light-logo') {
        $db->where('name', 'logo_cache')->update(T_CONFIG, array('value' => rand(100,999)));
    }
    $dir      = "themes/" . $pt->config->theme . "/img/";
    $filename = $dir . "$logo_name.png";
    if (move_uploaded_file($data['file'], $filename)) {
        return true;
    }
}

function PT_GetTerms() {
    global $db;
    $data  = array();
    $terms = $db->get(T_TERMS);
    foreach ($terms as $key => $term) {
        $data[$term->type] = $term->text;
    }
    return $data;
}

function PT_CreateMainSession() {
    $hash = substr(sha1(rand(1111, 9999)), 0, 70);
    if (!empty($_SESSION['main_hash_id'])) {
        $_SESSION['main_hash_id'] = $_SESSION['main_hash_id'];
        return $_SESSION['main_hash_id'];
    }
    $_SESSION['main_hash_id'] = $hash;
    return $hash;
}

function PT_CheckMainSession($hash = '') {
    if (!isset($_SESSION['main_hash_id']) || empty($_SESSION['main_hash_id'])) {
        return false;
    }
    if (empty($hash)) {
        return false;
    }
    if ($hash == $_SESSION['main_hash_id']) {
        return true;
    }
    return false;
}
function PT_AzureBlobRequest($method = 'GET', $blobPath = '', $body = null, $bodyLength = 0, $queryString = '') {
    global $pt;

    $account     = $pt->config->azure_blob_account;
    $account_key = base64_decode($pt->config->azure_blob_secret);
    if (empty($account) || empty($account_key)) {
        return false;
    }

    $endpoint = $pt->config->azure_blob_endpoint;
    if (empty($endpoint)) {
        $endpoint = 'https://' . $account . '.blob.core.windows.net';
    }
    $container = $pt->config->azure_blob_bucket_name;
    $url       = rtrim($endpoint, '/') . '/' . $container . ($blobPath ? '/' . ltrim($blobPath, '/') : '') . (!empty($queryString) ? '?' . $queryString : '');

    $date      = gmdate('D, d M Y H:i:s', time()) . ' GMT';
    $headers   = array(
        'x-ms-date'    => $date,
        'x-ms-version' => '2021-02-12'
    );
    if ($method == 'PUT') {
        $headers['x-ms-blob-type'] = 'BlockBlob';
    }

    $canonicalHeaders = '';
    $msHeaders        = array();
    foreach ($headers as $key => $val) {
        $key = strtolower(trim($key));
        $msHeaders[$key] = trim($val);
    }
    ksort($msHeaders);
    foreach ($msHeaders as $key => $val) {
        $canonicalHeaders .= $key . ':' . $val . "\n";
    }

    $canonicalizedResource = '/' . $account . '/' . $container . ($blobPath ? '/' . rawurldecode(ltrim($blobPath, '/')) : '');
    if (!empty($queryString)) {
        $canonicalizedResource .= "\n" . $queryString;
    }

    $contentLength = ($method == 'PUT' && $bodyLength > 0) ? $bodyLength : '';
    $contentType   = ($method == 'PUT') ? 'application/octet-stream' : '';
    $string_to_sign = $method . "\n" .
        "\n" . // Content-Encoding
        "\n" . // Content-Language
        $contentLength . "\n" .
        "\n" . // Content-MD5
        $contentType . "\n" .
        "\n" . // Date
        "\n" . // If-Modified-Since
        "\n" . // If-Match
        "\n" . // If-None-Match
        "\n" . // If-Unmodified-Since
        "\n" . // Range
        $canonicalHeaders .
        $canonicalizedResource;

    $signature = base64_encode(hash_hmac('sha256', $string_to_sign, $account_key, true));
    $authorization = 'SharedKey ' . $account . ':' . $signature;

    $curlHeaders = array(
        'Authorization: ' . $authorization,
        'x-ms-date: ' . $date,
        'x-ms-version: ' . $headers['x-ms-version']
    );
    if ($method == 'PUT') {
        $curlHeaders[] = 'Content-Type: application/octet-stream';
        $curlHeaders[] = 'x-ms-blob-type: BlockBlob';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    if ($method == 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }
    if ($method == 'PUT' && is_resource($body)) {
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $body);
        curl_setopt($ch, CURLOPT_INFILESIZE, $bodyLength);
    }
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (is_resource($body)) {
        @fclose($body);
    }

    return ($status >= 200 && $status < 300);
}

function PT_AzureCDNPurge($media = '') {
    // Best-effort CDN invalidation for a deleted blob when azure_blob_cdn is
    // configured. Sends an HTTP PURGE to the CDN URL; unreachable/unlimited
    // CDNs simply return 404/405 and are ignored so deletes never fail.
    // Note: if the CDN exposes a vendor API instead (e.g. Azure Front Door),
    // front your CDN origin with a layer that honors PURGE.
    global $pt;
    if (empty($media)) {
        return false;
    }
    $cdn = isset($pt->config->azure_blob_cdn) ? rtrim(trim($pt->config->azure_blob_cdn), '/') : '';
    if (empty($cdn)) {
        return false;
    }
    $url = $cdn . '/' . ltrim($media, '/');
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PURGE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status >= 200 && $status < 300);
}

// Single source of truth for "is any remote storage provider configured?".
// Mirrors the flag set the admin panel toggles so future providers only need
// to be added here instead of at every call site.
function PT_RemoteStorageActive() {
    global $pt;
    return ($pt->config->s3_upload == 'on' || $pt->config->ftp_upload == 'on' || $pt->config->spaces == 'on' || $pt->config->wasabi_storage == 'on' || $pt->config->backblaze_storage == 'on' || $pt->config->cloud_upload == 'on' || $pt->config->yandex_storage == 'on' || $pt->config->azure_blob_storage == 'on') ? true : false;
}

// Builds an S3-compatible client for the supplied provider settings.
// Previously each upload/delete branch constructed this object inline with the
// same boilerplate; keep the params in one place so endpoint/credentials stay
// consistent across PT_UploadToS3, PT_DeleteFromToS3 and any future callers.
function PT_StorageS3Client($endpoint = '', $region = '', $key = '', $secret = '') {
    include_once('assets/libs/s3-lib/vendor/autoload.php');
    $client_config = array(
        'version'     => 'latest',
        'region'      => $region,
        'credentials' => array(
            'key'    => $key,
            'secret' => $secret
        )
    );
    if (!empty($endpoint)) {
        $client_config['endpoint'] = $endpoint;
    }
    return new S3Client($client_config);
}

// Builds the streaming URL for a converted quality rendition. The base comes
// from video_location in the DB (relative 'upload/videos/...' or an absolute
// URL depending on storage mode); PT_GetMedia maps relative paths to the
// active provider/CDN while leaving already-absolute URLs untouched.
// $kind wraps the file name segment ('' for normal videos, 'shorts' for the
// 'upload/videos/.../xxx_video_shorts_720p_converted.mp4' naming scheme).
function PT_QualityVideoUrl($base, $quality, $kind = '') {
    $segment = ($kind !== '') ? '_video_' . $kind . '_' . $quality . 'p_converted.mp4' : '_video_' . $quality . 'p_converted.mp4';
    return PT_GetMedia($base . $segment);
}

function PT_UploadToS3($filename, $config = array()) {
    global $pt;

    if (!$pt->remoteStorage) {
        return false;
    }
    if (empty($filename)) {
        return false;
    }
    if (!file_exists($filename)) {
        return false;
    }
    if ($pt->config->ftp_upload == "on" && !empty($pt->config->ftp_host) && !empty($pt->config->ftp_username)) {
        include_once('assets/libs/ftp/vendor/autoload.php');
        $ftp = new \FtpClient\FtpClient();
        $ftp->connect($pt->config->ftp_host, false, $pt->config->ftp_port);
        $login = $ftp->login($pt->config->ftp_username, $pt->config->ftp_password);
        if ($login) {
            if (!empty($pt->config->ftp_path)) {
                if ($pt->config->ftp_path != "./") {
                    $ftp->chdir($pt->config->ftp_path);
                }
            }
            $file_path = substr($filename, 0, strrpos( $filename, '/'));
            $file_path_info = explode('/', $file_path);
            $path = '';
            if (!$ftp->isDir($file_path)) {
                foreach ($file_path_info as $key => $value) {
                    if (!empty($path)) {
                        $path .= '/' . $value . '/' ;
                    } else {
                        $path .= $value . '/' ;
                    }
                    if (!$ftp->isDir($path)) {
                        $mkdir = $ftp->mkdir($path);
                    }
                }
            }
            $ftp->chdir($file_path);
            $ftp->pasv(true);
            if ($ftp->putFromPath($filename)) {
                if (empty($config['delete'])) {
                    if (empty($config['amazon'])) {
                        @unlink($filename);
                    }
                }
                $ftp->close();
                return true;
            }
            $ftp->close();
        }
    }
    elseif ($pt->config->spaces == 'on' && !empty($pt->config->spaces_key) && !empty($pt->config->spaces_secret) && !empty($pt->config->space_name) && !empty($pt->config->space_region)) {

        $key = $pt->config->spaces_key;
        $secret = $pt->config->spaces_secret;
        $space_name = $pt->config->space_name;
        $region = $pt->config->space_region;

        $s3 = PT_StorageS3Client('https://' . $region . '.digitaloceanspaces.com', $region, $key, $secret);
        $s3->putObject(array(
            'Bucket' => $pt->config->space_name,
            'Key' => $filename,
            'Body' => fopen($filename, 'r+'),
            'ACL' => 'public-read',
            'CacheControl' => 'max-age=3153600'
        ));
        if (empty($config['delete'])) {
            if ($s3->doesObjectExist($pt->config->space_name, $filename)) {
                if (empty($config['amazon'])) {
                    @unlink($filename);
                }
                return true;
            }
        } else {
            return true;
        }
    } elseif ($pt->config->wasabi_storage == 'on' && !empty($pt->config->wasabi_bucket_name)) {

        $s3 = PT_StorageS3Client('https://s3.' . $pt->config->wasabi_bucket_region . '.wasabisys.com', $pt->config->wasabi_bucket_region, $pt->config->wasabi_access_key, $pt->config->wasabi_secret_key);
        $s3->putObject(array(
            'Bucket' => $pt->config->wasabi_bucket_name,
            'Key' => $filename,
            'Body' => fopen($filename, 'r+'),
            'ACL' => 'public-read',
            'CacheControl' => 'max-age=3153600'
        ));
        if (empty($config['delete'])) {
            if ($s3->doesObjectExist($pt->config->wasabi_bucket_name, $filename)) {
                if (empty($config['wasabi'])) {
                    @unlink($filename);
                }
                return true;
            }
        } else {
            return true;
        }
    } elseif ($pt->config->backblaze_storage == 'on' && !empty($pt->config->backblaze_bucket_id)) {
        $info = BackblazeConnect(array('apiUrl' => 'https://api.backblazeb2.com',
                                       'uri' => '/b2api/v2/b2_authorize_account',
                                ));
        if (!empty($info)) {
            $result = json_decode($info,true);
            if (!empty($result['authorizationToken']) && !empty($result['apiUrl']) && !empty($result['accountId'])) {
                $info = BackblazeConnect(array('apiUrl' => $result['apiUrl'],
                                               'uri' => '/b2api/v2/b2_get_upload_url',
                                               'authorizationToken' => $result['authorizationToken'],
                                        ));
                if (!empty($info)) {
                    $info = json_decode($info,true);
                    if (!empty($info) && !empty($info['uploadUrl'])) {
                        $info = BackblazeConnect(array('apiUrl' => $info['uploadUrl'],
                                                       'uri' => '',
                                                       'file' => $filename,
                                                       'authorizationToken' => $info['authorizationToken'],
                                                        ));

                        if (!empty($info)) {
                            $info = json_decode($info,true);
                            if (!empty($info) && !empty($info['accountId'])) {
                                if (empty($config['delete'])) {
                                    @unlink($filename);
                                }
                                return true;
                            }
                        }
                    }
                }
            }
        }
        return false;
    } elseif ($pt->config->cloud_upload == 'on') {
        require_once 'assets/libs/google-lib/vendor/autoload.php';
        try {
            $storage       = new StorageClient(array(
                'keyFilePath' => $pt->config->cloud_file_path
            ));
            // set which bucket to work in
            $bucket        = $storage->bucket($pt->config->cloud_bucket_name);
            $fileContent   = file_get_contents($filename);
            // upload/replace file
            $storageObject = $bucket->upload($fileContent, array(
                'name' => $filename
            ));
            if (!empty($storageObject)) {
                if (empty($config['delete'])) {
                    if (empty($config['amazon'])) {
                        @unlink($filename);
                    }
                }
                return true;
            }
        }
        catch (Exception $e) {
            // maybe invalid private key ?
            // print $e;
            // exit();
            return false;
        }
    } elseif ($pt->config->yandex_storage == 'on') {
        include_once('assets/libs/s3-lib/vendor/autoload.php');

        $s3 = PT_StorageS3Client('https://storage.yandexcloud.net', $pt->config->yandex_region, $pt->config->yandex_key, $pt->config->yandex_secret);
        $s3->putObject(array(
            'Bucket' => $pt->config->yandex_name,
            'Key' => $filename,
            'Body' => fopen($filename, 'r+'),
            'ACL' => 'public-read',
            'CacheControl' => 'max-age=3153600'
        ));
        if (empty($config['delete'])) {
            if ($s3->doesObjectExist($pt->config->yandex_name, $filename)) {
                if (empty($config['wasabi'])) {
                    @unlink($filename);
                }
                return true;
            }
        } else {
            return true;
        }
    } elseif ($pt->config->azure_blob_storage == 'on' && !empty($pt->config->azure_blob_account) && !empty($pt->config->azure_blob_secret) && !empty($pt->config->azure_blob_bucket_name)) {
        $upload = PT_AzureBlobRequest('PUT', $filename, fopen($filename, 'r'), filesize($filename));
        if ($upload) {
            // Re-uploads replace the blob in place; purge the same key from the
            // CDN so edges don't keep serving the stale copy until TTL expiry.
            if (!empty($pt->config->azure_blob_cdn)) {
                PT_AzureCDNPurge($filename);
            }
            if (empty($config['delete'])) {
                @unlink($filename);
            }
            return true;
        }
    } else {
        include_once('assets/libs/s3-lib/vendor/autoload.php');
        $s3Config = (
            empty($pt->config->amazone_s3_key) ||
            empty($pt->config->amazone_s3_s_key) ||
            empty($pt->config->region) ||
            empty($pt->config->s3_bucket_name)
        );

        if ($s3Config){
            return false;
        }
        $s3 = PT_StorageS3Client('', $pt->config->region, $pt->config->amazone_s3_key, $pt->config->amazone_s3_s_key);
        $s3->putObject(array(
            'Bucket' => $pt->config->s3_bucket_name,
            'Key' => $filename,
            'Body' => fopen($filename, 'r+'),
            'ACL' => 'public-read',
            'CacheControl' => 'max-age=3153600'
        ));
        if (empty($config['delete'])) {
            if ($s3->doesObjectExist($pt->config->s3_bucket_name, $filename)) {
                if (empty($config['amazon'])) {
                    @unlink($filename);
                }
                return true;
            }
        } else {
            return true;
        }
    }
}

function PT_DeleteFromToS3($filename, $config = array()) {
    global $pt;

    if (!$pt->remoteStorage && $pt->config->amazone_s3_2 != 1) {
        return false;
    }
    if ($pt->config->ftp_upload == "on") {
        include_once('assets/libs/ftp/vendor/autoload.php');
        $ftp = new \FtpClient\FtpClient();
        $ftp->connect($pt->config->ftp_host, false, $pt->config->ftp_port);
        $login = $ftp->login($pt->config->ftp_username, $pt->config->ftp_password);

        if ($login) {
            if (!empty($pt->config->ftp_path)) {
                if ($pt->config->ftp_path != "./") {
                    $ftp->chdir($pt->config->ftp_path);
                }
            }
            $file_path = substr($filename, 0, strrpos( $filename, '/'));
            $file_name = substr($filename, strrpos( $filename, '/') + 1);
            $file_path_info = explode('/', $file_path);
            $path = '';
            if (!$ftp->isDir($file_path)) {
                return false;
            }
            $ftp->chdir($file_path);
            $ftp->pasv(true);
            if ($ftp->remove($file_name)) {
                return true;
            }
        }
    }
    elseif ($pt->config->spaces == 'on' && !empty($pt->config->spaces_key) && !empty($pt->config->spaces_secret) && !empty($pt->config->space_name) && !empty($pt->config->space_region)) {
        $key = $pt->config->spaces_key;
        $secret = $pt->config->spaces_secret;
        $space_name = $pt->config->space_name;
        $region = $pt->config->space_region;

        $s3 = PT_StorageS3Client('https://' . $region . '.digitaloceanspaces.com', $region, $key, $secret);
        $s3->deleteObject(array(
            'Bucket' => $pt->config->space_name,
            'Key' => $filename
        ));
        if (!$s3->doesObjectExist($pt->config->space_name, $filename)) {
            return true;
        }
    }
    elseif ($pt->config->wasabi_storage == 'on' && !empty($pt->config->wasabi_bucket_name)) {
        if (empty($pt->config->wasabi_bucket_name) || empty($pt->config->wasabi_access_key) || empty($pt->config->wasabi_secret_key) || empty($pt->config->wasabi_bucket_region)) {
            return false;
        }
        include_once('assets/libs/s3-lib/vendor/autoload.php');
        $s3 = PT_StorageS3Client('https://s3.' . $pt->config->wasabi_bucket_region . '.wasabisys.com', $pt->config->wasabi_bucket_region, $pt->config->wasabi_access_key, $pt->config->wasabi_secret_key);
        $s3->deleteObject(array(
            'Bucket' => $pt->config->wasabi_bucket_name,
            'Key' => $filename
        ));
        if (!$s3->doesObjectExist($pt->config->wasabi_bucket_name, $filename)) {
            return true;
        }

    }
    elseif ($pt->config->backblaze_storage == 'on' && !empty($pt->config->backblaze_bucket_id)) {
        $info = BackblazeConnect(array('apiUrl' => 'https://api.backblazeb2.com',
                                       'uri' => '/b2api/v2/b2_authorize_account',
                                ));
        if (!empty($info)) {
            $result = json_decode($info,true);
            if (!empty($result['authorizationToken']) && !empty($result['apiUrl']) && !empty($result['accountId'])) {
                $info = BackblazeConnect(array('apiUrl' => $result['apiUrl'],
                                               'uri' => '/b2api/v2/b2_list_file_names',
                                               'authorizationToken' => $result['authorizationToken'],
                                        ));
                if (!empty($info)) {
                    $info = json_decode($info,true);
                    if (!empty($info) && !empty($info['files'])) {
                        foreach ($info['files'] as $key => $value) {
                            if ($value['fileName'] == $filename) {
                                $info = BackblazeConnect(array('apiUrl' => $result['apiUrl'],
                                                               'uri' => '/b2api/v2/b2_delete_file_version',
                                                               'authorizationToken' => $result['authorizationToken'],
                                                               'fileId' => $value['fileId'],
                                                               'fileName' => $value['fileName'],
                                                        ));
                                return true;
                            }
                        }
                    }
                }
            }
        }
    }
    elseif ($pt->config->cloud_upload == 'on') {
        require_once 'assets/libs/google-lib/vendor/autoload.php';
        try {
            $storage = new StorageClient(array(
                'keyFilePath' => $pt->config->cloud_file_path
            ));
            // set which bucket to work in
            $bucket  = $storage->bucket($pt->config->cloud_bucket_name);
            $object  = $bucket->object($filename);
            $delete  = $object->delete();
            if ($delete) {
                return true;
            }
        }
        catch (Exception $e) {
            // maybe invalid private key ?
            // print $e;
            // exit();
            return false;
        }
    } 
    elseif ($pt->config->azure_blob_storage == 'on' && !empty($pt->config->azure_blob_account) && !empty($pt->config->azure_blob_secret) && !empty($pt->config->azure_blob_bucket_name)) {
        $deleted = PT_AzureBlobRequest('DELETE', $filename);
        if ($deleted) {
            // The blob is gone, but edges fronted by azure_blob_cdn may keep
            // serving the stale copy until TTL, so purge the key (best-effort).
            if (!empty($pt->config->azure_blob_cdn)) {
                PT_AzureCDNPurge($filename);
            }
            return true;
        }
    }
    else if ($pt->config->s3_upload == "on") {
        include_once('assets/libs/s3-lib/vendor/autoload.php');
        $s3Config = (
            empty($pt->config->amazone_s3_key) ||
            empty($pt->config->amazone_s3_s_key) ||
            empty($pt->config->region) ||
            empty($pt->config->s3_bucket_name)
        );

        if ($s3Config){
            return false;
        }
        $s3 = PT_StorageS3Client('', $pt->config->region, $pt->config->amazone_s3_key, $pt->config->amazone_s3_s_key);

        $s3->deleteObject([
            'Bucket' => $pt->config->s3_bucket_name,
            'Key'    => $filename,
        ]);

        if (!$s3->doesObjectExist($pt->config->s3_bucket_name, $filename)) {
            return true;
        }
    }
    elseif ($pt->config->yandex_storage == "on") {
        if (empty($pt->config->yandex_region) || empty($pt->config->yandex_key) || empty($pt->config->yandex_secret)) {
            return false;
        }
        include_once('assets/libs/s3-lib/vendor/autoload.php');
        $s3 = PT_StorageS3Client('https://storage.yandexcloud.net', $pt->config->yandex_region, $pt->config->yandex_key, $pt->config->yandex_secret);
        $s3->deleteObject(array(
            'Bucket' => $pt->config->yandex_name,
            'Key' => $filename
        ));
        if (!$s3->doesObjectExist($pt->config->yandex_name, $filename)) {
            return true;
        }
    }

    if ($pt->config->amazone_s3_2 == 1 && $pt->config->backblaze_storage == 'off' && $pt->config->live_video == 1 && !empty($pt->config->region_2) && !empty($pt->config->amazone_s3_key_2)) {
        include_once('assets/libs/s3-lib/vendor/autoload.php');
        $s3 = PT_StorageS3Client('https://s3.' . $pt->config->region_2, $pt->config->region_2, $pt->config->amazone_s3_key_2, $pt->config->amazone_s3_s_key_2);
        $s3->deleteObject(array(
            'Bucket' => $pt->config->bucket_name_2,
            'Key' => $filename
        ));
        if (!$s3->doesObjectExist($pt->config->bucket_name_2, $filename)) {
            return true;
        }
    }

    return false;
}

function PT_RegisterNewField($registration_data = array()) {
    global $pt, $mysqli;
    if (empty($registration_data)) {
        return false;
    }

    $fields      = '`' . implode('`, `', array_keys($registration_data)) . '`';
    $data        = '\'' . implode('\', \'', $registration_data) . '\'';
    $table       = T_FIELDS;
    $query       = mysqli_query($mysqli, "INSERT INTO  `$table` ({$fields}) VALUES ({$data})");

    if ($query) {
        $sql_id  = mysqli_insert_id($mysqli);
        $column  = 'fid_' . $sql_id;
        $table   = T_USR_PROF_FIELDS;
        $length  = $registration_data['length'];
        mysqli_query($mysqli, "ALTER TABLE `$table` ADD COLUMN `{$column}` varchar({$length}) NOT NULL DEFAULT ''");
        return true;
    }

    return false;
}

function PT_UpdateUserCustomData($user_id, $update_data, $loggedin = true) {
    global $pt, $sqlConnect;

    if ($loggedin == true) {
        if (IS_LOGGED == false) {
            return false;
        }
    }

    if (empty($user_id) || !is_numeric($user_id)) {
        return false;
    }

    if (empty($update_data)) {
        return false;
    }

    $user_id = PT_Secure($user_id);
    if ($loggedin == true) {
        if (PT_IsAdmin() === false) {
            if ($pt->user->id != $user_id) {
                return false;
            }
        }
    }

    $update = array();
    foreach ($update_data as $field => $data) {
        foreach ($data as $key => $value) {
            $update[] = '`' . $key . '` = \'' . PT_Secure($value, 0) . '\'';
        }
    }

    $impload     = implode(', ', $update);
    $table       = T_USR_PROF_FIELDS;
    $update_sql  = "UPDATE `$table` SET {$impload} WHERE `user_id` = {$user_id}";

    $usr_fields  = mysqli_query($sqlConnect, "SELECT COUNT(`id`) as count FROM `$table` WHERE `user_id` = {$user_id}");
    $usr_fields  = mysqli_fetch_assoc($usr_fields);
    $query       = false;

    if ($usr_fields['count'] == 1) {
        $query   = mysqli_query($sqlConnect, $update_sql);
    }

    else {
        $new_fid = mysqli_query($sqlConnect, "INSERT INTO `$table` (`user_id`) VALUES ({$user_id})");
        if ($new_fid) {
            $query = mysqli_query($sqlConnect, $update_sql);
        }
    }

    return $query;
}

function PT_ReactionEmoji($reaction = 'like'){
    $reactions = array(
        'like' => '&#128077;',
        'love' => '&#10084;&#65039;',
        'haha' => '&#128514;',
        'wow' => '&#128562;',
        'sad' => '&#128546;',
        'angry' => '&#128545;'
    );
    return (!empty($reactions[$reaction])) ? $reactions[$reaction] : '&#128077;';
}

function pt_comm_object_data($comment = null,$pinned = null){
    global $pt,$user,$db;
    if (empty($comment)) {
        return false;
    }

    $pt->is_comment_owner = false;
    $replies              = '';
    $pt->pin              = false;
    $html                 = '';
    $comment_replies      = $db->where('comment_id', $comment->id)->get(T_COMM_REPLIES);
    $is_liked_comment     = '';
    $is_comment_disliked  = '';
    $comment_user_data    = PT_UserData($comment->user_id);
    $pt->is_verified      = ($comment_user_data->verified == 1) ? true : false;
    $pt->video_owner      = false;

    $db->where('id',$comment->video_id);
    $db->where('user_id',$user->id);
    $pt->video_owner = ($db->getValue(T_VIDEOS,'count(*)') > 0);
    foreach ($comment_replies as $reply) {
        $pt->is_reply_owner = false;
        $pt->is_ro_verified = false;
        $reply_user_data    = PT_UserData($reply->user_id);
        $is_liked_reply     = '';
        $is_disliked_reply  = '';
        if (IS_LOGGED == true) {
            $is_reply_owner = $db->where('id', $reply->id)->where('user_id', $user->id)->getValue(T_COMM_REPLIES, 'count(*)');
            if ($is_reply_owner || $pt->video_owner) {
                $pt->is_reply_owner = true;
            }

            //Check is this reply  voted by logged-in user
            $db->where('reply_id', $reply->id);
            $db->where('user_id', $user->id);
            $db->where('type', 1);
            $is_liked_reply    = ($db->getValue(T_COMMENTS_LIKES, 'count(*)') > 0) ? 'active' : '';

            $db->where('reply_id', $reply->id);
            $db->where('user_id', $user->id);
            $db->where('type', 2);
            $is_disliked_reply = ($db->getValue(T_COMMENTS_LIKES, 'count(*)') > 0) ? 'active' : '';
        }

        if ($reply_user_data->verified == 1) {
            $pt->is_ro_verified = true;
        }

        //Get related to reply likes
        $db->where('reply_id', $reply->id);
        $db->where('type', 1);
        $reply_likes    = $db->getValue(T_COMMENTS_LIKES, 'count(*)');

        $db->where('reply_id', $reply->id);
        $db->where('type', 2);
        $reply_dislikes = $db->getValue(T_COMMENTS_LIKES, 'count(*)');



        $reply->text = PT_Markup(PT_Duration($reply->text));
        $reply_reaction      = '';
        $reply_reaction_emoji = '&#128077;';
        if (IS_LOGGED == true) {
            $db->where('reply_id', $reply->id);
            $db->where('user_id', $user->id);
            $db->where('type', 1);
            $reply_reaction = $db->getValue(T_COMMENTS_LIKES, 'reaction');
            if (!empty($reply_reaction)) {
                $reply_reaction_emoji = PT_ReactionEmoji($reply_reaction);
            } else {
                $reply_reaction = '';
            }
        }

        $replies    .= PT_LoadPage('watch/replies', array(
            'ID' => $reply->id,
            'TEXT' => $reply->text,
            'TIME' => PT_Time_Elapsed_String($reply->time),
            'USER_DATA' => $reply_user_data,
            'COMM_ID' => $comment->id,
            'LIKES'  => $reply_likes,
            'DIS_LIKES' => $reply_dislikes,
            'LIKED' => $is_liked_reply,
            'DIS_LIKED' => $is_disliked_reply,
            'REACTION' => $reply_reaction,
            'REACTION_EMOJI' => $reply_reaction_emoji,
        ));
    }

    if (IS_LOGGED == true) {
        $db->where('comment_id', $comment->id);
        $db->where('user_id', $user->id);

        $is_liked_comment = $db->getValue(T_COMMENTS_LIKES, 'count(*)');

        $db->where('comment_id', $comment->id);
        $db->where('user_id', $user->id);
        $db->where('type', 1);
        $is_liked_comment   = ($db->getValue(T_COMMENTS_LIKES, 'count(*)') > 0) ? 'active' : '';

        $db->where('comment_id', $comment->id);
        $db->where('user_id', $user->id);
        $db->where('type', 2);
        $is_comment_disliked = ($db->getValue(T_COMMENTS_LIKES, 'count(*)') > 0) ? 'active' : '';

        $comment_reaction = '';
        $db->where('comment_id', $comment->id);
        $db->where('user_id', $user->id);
        $db->where('type', 1);
        $comment_reaction = $db->getValue(T_COMMENTS_LIKES, 'reaction');

        if ($user->id == $comment->user_id) {
            $pt->is_comment_owner = true;
        }

        $db->where('id',$comment->video_id);
        $db->where('user_id',$user->id);
        $pt->video_owner = ($db->getValue(T_VIDEOS,'count(*)') > 0);
    }



    $comm = ($pinned == true) ? 'includes/pinned-comments' : "comments";
    $html = PT_LoadPage("watch/$comm", array(
        'ID' => $comment->id,
        'TEXT' => PT_Markup(PT_Duration($comment->text)),
        'TIME' => PT_Time_Elapsed_String($comment->time),
        'USER_DATA' => $comment_user_data,
        'LIKES' => $comment->likes,
        'DIS_LIKES' => $comment->dis_likes,
        'LIKED' => $is_liked_comment,
        'DIS_LIKED' => $is_comment_disliked,
        'REACTION' => $comment_reaction,
        'REACTION_EMOJI' => (!empty($comment_reaction)) ? PT_ReactionEmoji($comment_reaction) : '&#128077;',
        'COMM_REPLIES' => $replies,
        'VID_ID' => $comment->video_id
    ));

    return $html;
}

function pt_push_channel_notifiations($video_id = 0,$type = "added_video") {
    global $pt, $db;
    if (IS_LOGGED == false) {
        return false;
    }
    $get_subscribers = $db->where('user_id', $pt->user->id)->where('notify',1)->get(T_SUBSCRIPTIONS);
    $userIds         = array();
    if (empty($get_subscribers)) {
        return false;
    }
    if ($type == "added_video") {
        $video_uid = $db->where('video_id', $video_id)->getValue(T_VIDEOS, 'id');
    }
    else{
        $video = $db->where('id', $video_id)->getOne(T_VIDEOS);
        if (empty($video)) {
            return false;
        }
        $video_uid = $video->id;
        $video_id = $video->video_id;
    }

    if (empty($video_uid)) {
        return false;
    }
    foreach ($get_subscribers as $key => $subscriber) {
        if ($subscriber->notify == 1) {
            $userIds[] = "('{$pt->user->id}', '{$subscriber->subscriber_id}', '$video_uid', '{$type}', 'watch/{$video_id}', '" . time() . "')";
        }
    }
    $query_implode       = implode(',', $userIds);
    $query_row           = $db->rawQuery("INSERT INTO " . T_NOTIFICATIONS . " (`notifier_id`, `recipient_id`, `video_id`, `type`, `url`, `time`) VALUES $query_implode");
    if ($query_row) {
        if ($pt->config->push == 1) {
            PT_NotificationWebPushNotifier();
        }
        return true;
    }
}

function PT_GetMessageData($id = 0) {
    global $pt, $db;
    if (empty($id) || !IS_LOGGED) {
        return false;
    }
    $fetched_data = $db->where('id', PT_Secure($id))->getOne(T_MESSAGES);
    if (!empty($fetched_data)) {
        $fetched_data->text = PT_Markup($fetched_data->text);
        return $fetched_data;
    }
    return false;
}

function PT_GetMessages($id, $data = array(),$limit = 50) {
    global $pt, $db;
    if (IS_LOGGED == false) {
        return false;
    }

    $chat_id = PT_Secure($id);

    if (!empty($data['chat_user'])) {
        $chat_user = $data['chat_user'];
    } else {
        $chat_user = PT_UserData($chat_id);
    }


    $where = "((`from_id` = {$chat_id} AND `to_id` = {$pt->user->id} AND `to_deleted` = '0') OR (`from_id` = {$pt->user->id} AND `to_id` = {$chat_id} AND `from_deleted` = '0'))";

    // count messages
    $db->where($where);
    if (!empty($data['last_id'])) {
        $data['last_id'] = PT_Secure($data['last_id']);
        $db->where('id', $data['last_id'], '>');
    }

    if (!empty($data['first_id'])) {
        $data['first_id'] = PT_Secure($data['first_id']);
        $db->where('id', $data['first_id'], '<');
    }

    $count_user_messages = $db->getValue(T_MESSAGES, "count(*)");
    $count_user_messages = $count_user_messages - $limit;
    if ($count_user_messages < 1) {
        $count_user_messages = 0;
    }

    // get messages
    $db->where($where);
    if (!empty($data['last_id'])) {
        $db->where('id', $data['last_id'], '>');
    if (!empty($data['first_id'])) {
        $db->where('id', $data['first_id'], '<');
    }
    }


    $get_user_messages = $db->orderBy('id', 'ASC')->get(T_MESSAGES, array($count_user_messages, $limit));

    $messages_html = '';

    $return_methods = array('obj', 'html');

    $return_method = 'obj';
    if (!empty($data['return_method'])) {
        if (in_array($data['return_method'], $return_methods)) {
            $return_method = $data['return_method'];
        }
    }

    $update_seen = array();
    
    foreach ($get_user_messages as $key => $message) {
        $message->user_data = PT_UserData($message->from_id);
        if ($return_method == 'html') {
            $message_type = 'incoming';
            if ($message->from_id == $pt->user->id) {
                $message_type = 'outgoing';
            }
            $messages_html .= PT_LoadPage("messages/ajax/$message_type", array(
                'ID' => $message->id,
                'AVATAR' => $message->user_data->avatar,
                'NAME' => $chat_user->name,
                'TIME' => PT_Time_Elapsed_String_chat($message->time),
                'TEXT' => PT_MarkUp($message->text)
            ));
        }
        if ($message->seen == 0 && $message->to_id == $pt->user->id) {
            $update_seen[] = $message->id;
        }
    }

    if (!empty($update_seen)) {
        $update_seen = implode(',', $update_seen);
        $update_seen = $db->where("id IN ($update_seen)")->update(T_MESSAGES, array('seen' => time()));
    }

    return (!empty($messages_html)) ? $messages_html : $get_user_messages;
}


function PT_GetMessagesUserList($data = array(),$limit = 20,$offset=0) {
    global $pt, $db;
    if (IS_LOGGED == false) {
        return false;
    }

    $db->where("user_two NOT IN (".implode(',', $pt->blocked_array).")")->where("user_one = {$pt->user->id}");

    if (isset($data['keyword'])) {
        $keyword = PT_Secure($data['keyword']);
        $db->where("user_two IN (SELECT id FROM users WHERE username LIKE '%$keyword%' OR CONCAT(`first_name`,  ' ', `last_name` ) LIKE '%$keyword%')");
    }
    if (!empty($offset)) {
        $db->where('time',PT_Secure($offset),'<');
    }

    $users = $db->orderBy('time', 'DESC')->get(T_CHATS, $limit);

    $return_methods = array('obj', 'html');

    $return_method = 'obj';
    if (!empty($data['return_method'])) {
        if (in_array($data['return_method'], $return_methods)) {
            $return_method = $data['return_method'];
        }
    }

    $users_html = '';
    $data_array = array();
    foreach ($users as $key => $user) {
        $chat_time = $user->time;
        $user = PT_UserData($user->user_two);
        if (!empty($user)) {
            $get_last_message = $db->where("((from_id = {$pt->user->id} AND to_id = $user->id AND `from_deleted` = '0') OR (from_id = $user->id AND to_id = {$pt->user->id} AND `to_deleted` = '0'))")->orderBy('id', 'DESC')->getOne(T_MESSAGES);
            $get_count_seen = $db->where("to_id = {$pt->user->id} AND from_id = $user->id AND `from_deleted` = '0' AND seen = 0")->orderBy('id', 'DESC')->getValue(T_MESSAGES, 'COUNT(*)');
            if ($return_method == 'html') {
                $users_html .= PT_LoadPage("messages/ajax/user-list", array(
                    'ID' => $user->id,
                    'AVATAR' => $user->avatar,
                    'NAME' => $user->name,
                    'LAST_MESSAGE' => (!empty($get_last_message->text)) ? PT_EditMarkup($get_last_message->text) : '',
                    'COUNT' => (!empty($get_count_seen)) ? $get_count_seen : '',
                    'USERNAME' => $user->username,
                    'TIME' => PT_Time_Elapsed_String_chat($get_last_message->time),
                    'TTIME' => $chat_time,
                ));
            } else {
                $data_array[$key]['user'] = $user;
                $data_array[$key]['get_count_seen'] = $get_count_seen;
                $data_array[$key]['get_last_message'] = $get_last_message;
            }
        }
    }
    $users_obj = (!empty($data_array)) ? ToObject($data_array) : array();
    return (!empty($users_html)) ? $users_html : $users_obj;
}

function is_age($user_id = 0) {
    global $pt, $db;
    if (!IS_LOGGED) {
        return false;
    }

    if ($pt->user->age < 18) {
        return false;
    }
    return true;
}

function getTwitch($url){
    $channelsApi = $url;
    $clientId = 'twb88q5mhne1gsrwvkhtlugvrqniks';
    $ch = curl_init();

    curl_setopt_array($ch, array(
       CURLOPT_HTTPHEADER => array(
          'Client-ID: ' . $clientId,
          'Accept: application/vnd.twitchtv.v4+json'
       ),
       CURLOPT_RETURNTRANSFER => true,
       CURLOPT_URL => $channelsApi
    ));

    $response = curl_exec($ch);


    curl_close($ch);

    return $response;
}

function getTwitchApiUri($type) {
    $apiUrl = "https://api.twitch.tv/kraken";
    $apiCalls = array(
        "streams" => $apiUrl."/streams/",
        "search" => $apiUrl."/search/",
        "channel" => $apiUrl."/channels/",
        "user" => $apiUrl."/user/",
        "teams" => $apiUrl."/teams/",
        "clips" => $apiUrl."/clips/",
        "videos" => $apiUrl."/videos/",
    );
    return $apiCalls[$type];
}

// user active
function secondsToTime($inputSeconds) {
    $secondsInAMinute = 60;
    $secondsInAnHour = 60 * $secondsInAMinute;
    $secondsInADay = 24 * $secondsInAnHour;

    // Extract days
    $days = floor($inputSeconds / $secondsInADay);

    // Extract hours
    $hourSeconds = $inputSeconds % $secondsInADay;
    $hours = floor($hourSeconds / $secondsInAnHour);

    // Extract minutes
    $minuteSeconds = $hourSeconds % $secondsInAnHour;
    $minutes = floor($minuteSeconds / $secondsInAMinute);

    // Extract the remaining seconds
    $remainingSeconds = $minuteSeconds % $secondsInAMinute;
    $seconds = ceil($remainingSeconds);

    // Format and return
    $timeParts = [];
    // $sections = [
    //     'day' => (int)$days,
    //     'hour' => (int)$hours,
    //     'minute' => (int)$minutes,
    //     'second' => (int)$seconds,
    // ];
    $sections = [
        'day' => (int)$days,
        'hour' => (int)$hours,
        'min' => (int)$minutes,
        'sec' => (int)$seconds,
    ];

    foreach ($sections as $name => $value){
        if ($value > 0){
            $timeParts[] = $value. ' '.$name.($value == 1 ? '' : '');
            if (count($timeParts) > 1) {
                break;
            }
        }
    }

    return implode(' / ', $timeParts);
}
// user active

function GetBlockedIds()
{
    global $pt, $db;

    if (!IS_LOGGED || $pt->config->block_system == 'off') {
        return array(0);
    }

    $data = array(0);
    $query = $db->rawQuery("SELECT * FROM ".T_BLOCK." WHERE ( user_id = ".$pt->user->id." AND blocked_id != ".$pt->user->id." ) OR ( user_id != ".$pt->user->id." AND blocked_id = ".$pt->user->id.")");
    if (!empty($query)) {
        foreach ($query as $key => $user) {
            if ($user->user_id != $pt->user->id) {
                $data[] = $user->user_id;
            }
            if ($user->blocked_id != $pt->user->id) {
                $data[] = $user->blocked_id;
            }
        }
    }
    return $data;
}
function GetBlockedUsers($user_id = 0)
{
    global $pt, $db;

    if (!IS_LOGGED || $pt->config->block_system == 'off') {
        return array();
    }
    if (!empty($user_id) && is_numeric($user_id) && $user_id > 0) {
        $user_id = PT_Secure($user_id);
    }
    else{
        $user_id = $pt->user->id;
    }

    $data = array();
    $query = $db->rawQuery("SELECT * FROM ".T_BLOCK." WHERE user_id = ".$user_id);
    if (!empty($query)) {
        foreach ($query as $key => $user) {
            $data[] = PT_UserData($user->blocked_id);
        }
    }
    return $data;
}
function PT_GetUserSessions($user_id = 0)
{
    global $pt, $db;

    if (!IS_LOGGED) {
        return false;
    }
    if (!empty($user_id) && is_numeric($user_id) && $user_id > 0) {
        $user_id = PT_Secure($user_id);
    }
    else{
        $user_id = $pt->user->id;
    }

    $data = array();
    $query = $db->where('user_id',$user_id)->get(T_SESSIONS);
    if (!empty($query)) {
        foreach ($query as $key => $user) {
            $user->browser = 'Unknown';
            $user->time = PT_Time_Elapsed_String($user->time);
            $user->platform = ucfirst($user->platform);
            $user->ip_address = '';
            if ($user->platform == 'web' || $user->platform == 'windows') {
                $user->platform = 'Unknown';
            }
            if ($user->platform == 'Phone') {
                $user->browser = 'Mobile';
            }
            if ($user->platform == 'Windows') {
                $user->browser = 'Desktop Application';
            }
            if (!empty($user->platform_details)) {
                $uns = unserialize($user->platform_details);
                $user->browser = $uns['name'];
                $user->platform = ucfirst($uns['platform']);
                $user->ip_address = $uns['ip_address'];
            }
            $data[] = $user;
        }
    }
    return $data;
}

function PT_RunInBackground($data = array()) {
    if (!empty(ob_get_status())) {
        ob_end_clean();
        header("Content-Encoding: none");
        header("Connection: close");
        ignore_user_abort();
        ob_start();
        if (!empty($data)) {
            header('Content-Type: application/json');
            echo json_encode($data);
        }
        $size = ob_get_length();
        header("Content-Length: $size");
        ob_end_flush();
        flush();
        session_write_close();
        if (is_callable('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}

function PT_IsAdminInvitationExists($code = false) {
    global $sqlConnect, $pt;
    if (!$code) {
        return false;
    }
    $code      = PT_Secure($code);
    $data_rows = mysqli_query($sqlConnect, "SELECT `id` FROM " . T_INVITATIONS . " WHERE `code` = '$code' AND status = '0'");
    return mysqli_num_rows($data_rows) > 0;
}
function PT_SaveLiveRecording($post_id)
{
    global $sqlConnect, $pt, $db;
    $post = $db->where('id', PT_Secure($post_id))->getOne(T_VIDEOS);
    if (empty($post) || empty($post->stream_name)) {
        return false;
    }
    $record_path = './recordings';
    if (!empty($pt->config->mediamtx_recordings_path)) {
        $record_path = rtrim($pt->config->mediamtx_recordings_path, '/\\');
    }
    $source = $record_path . '/' . $post->stream_name . '.mp4';
    if (!file_exists($source)) {
        $source_ts = $record_path . '/' . $post->stream_name . '.ts';
        if (!file_exists($source_ts)) {
            return false;
        }
        $source = $source_ts;
    }
    if (!is_readable($source)) {
        return false;
    }
    $dir = 'upload/videos/' . date('Y') . '/' . intval(date('m'));
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_dir($dir)) {
        return false;
    }
    $final_file = $dir . '/' . $post->video_id . '_' . time() . '.mp4';
    $saved = false;
    if (pathinfo($source, PATHINFO_EXTENSION) == 'mp4' && !empty($pt->config->ffmpeg_binary_file) && file_exists($pt->config->ffmpeg_binary_file) && isfuncEnabled('shell_exec')) {
        @shell_exec($pt->config->ffmpeg_binary_file . ' -y -i ' . escapeshellarg($source) . ' -c copy ' . escapeshellarg($final_file) . ' 2>&1');
        $saved = file_exists($final_file) && filesize($final_file) > 0;
    }
    if (!$saved) {
        $final_file = $dir . '/' . $post->video_id . '_' . time() . '.' . pathinfo($source, PATHINFO_EXTENSION);
        $saved = copy($source, $final_file);
    }
    if ($saved) {
        $db->where('id', $post_id)->update(T_VIDEOS, array('video_location' => $final_file, 'converted' => 1, 'live_ended' => 1));
        return true;
    }
    return false;
}
function PT_LiveStreamToken($path, $action, $ttl = 300)
{
    global $pt;
    $secret = !empty($pt->config->mediamtx_auth_secret) ? $pt->config->mediamtx_auth_secret : '';
    if (empty($secret)) {
        return '';
    }
    $exp = time() + intval($ttl);
    $data = $action . '|' . $path . '|' . $exp;
    return $exp . '.' . hash_hmac('sha256', $data, $secret);
}
function PT_CleanLiveRecordings($record_path, $retention_days = 7)
{
    global $pt;
    if (empty($record_path) || !is_dir($record_path) || intval($retention_days) < 1) {
        return 0;
    }
    $record_path = rtrim($record_path, '/\\');
    $cutoff      = time() - (intval($retention_days) * 86400);
    $deleted     = 0;
    $directory   = new RecursiveDirectoryIterator($record_path, RecursiveDirectoryIterator::SKIP_DOTS);
    $iterator    = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), array('ts', 'mp4', 'm4s'))) {
            if ($file->getMTime() < $cutoff && @unlink($file->getPathname())) {
                $deleted++;
            }
        }
    }
    $sub_dirs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($record_path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    $dirs = array();
    foreach ($sub_dirs as $dir) {
        if ($dir->isDir()) {
            $dirs[] = $dir->getPathname();
        }
    }
    rsort($dirs);
    foreach ($dirs as $d) {
        @rmdir($d);
    }
    return $deleted;
}
function GetVideoTime($first,$second)
{
    $first_date = new DateTime();
    $first_date->setTimestamp($first);
    $second_date = new DateTime();
    $second_date->setTimestamp($second);
    $difference = $first_date->diff($second_date);
    $time = '00:';
    $minuts = floor($difference->h * 60) + $difference->i;
    $current_time = ($minuts*60)+$difference->s;

    if ($minuts > 0) {
        if ($minuts < 10) {
            $time = '0'.$minuts.':';
        }
        else{
            $time = $minuts.':';
        }
    }
    $seconds_time = '00';
    if ($difference->s < 10) {
        $seconds_time = '0'.$difference->s;
    }
    else{
        $seconds_time = $difference->s;
    }
    return array('time' => $time.$seconds_time,
                 'current_time' => $current_time);
}
function CheckPaystackPayment($ref)
{
    global $pt, $db;
    if (empty($ref) || IS_LOGGED == false) {
        return false;
    }
    $ref = PT_Secure($ref);
    $result = array();
    //The parameter after verify/ is the transaction reference to be verified
    $url = 'https://api.paystack.co/transaction/verify/'.$ref;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt(
      $ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer '.$pt->config->paystack_secret_key]
    );
    $request = curl_exec($ch);
    curl_close($ch);

    if ($request) {
        $result = json_decode($request, true);
        if($result){
          if($result['data']){
            if($result['data']['status'] == 'success'){
                return true;
            }else{
              die("Transaction was not successful: Last gateway response was: ".$result['data']['gateway_response']);
            }
          }else{
            die($result['message']);
          }

        }else{
          die("Something went wrong while trying to convert the request variable to json. Uncomment the print_r command to see what is in the result variable.");
        }
      }else{
        die("Something went wrong while executing curl. Uncomment the var_dump line above this line to see what the issue is. Please check your CURL command to make sure everything is ok");
      }
}
function CheckRazorpayPayment($payment_id, $data)
{
    global $pt;
    if (empty($payment_id) || empty($data)) {
        return false;
    }

    $url = 'https://api.razorpay.com/v1/payments/' . $payment_id . '/capture';
    $key_id = $pt->config->razorpay_key_id;
    $key_secret = $pt->config->razorpay_key_secret;
    $params = http_build_query($data);
    //cURL Request
    $ch = curl_init();
    //set the url, number of POST vars, POST data
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERPWD, $key_id . ':' . $key_secret);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $request = curl_exec ($ch);
    curl_close ($ch);
    return json_decode($request);
}
function EarnFromView()
{
    global $pt,$db;
    $request   = (!empty($_SESSION['ua_']) && !empty($_SESSION['vo_']));


    if ($request === true) {
        $ad_id   = PT_Secure($_SESSION['ua_']);
        $pub_id  = PT_Secure($_SESSION['vo_']);
        $ad      = $db->where('id',$ad_id)->getOne(T_USR_ADS);
        if (!empty($ad)) {
            $ad_owner     = $db->where('id',$ad->user_id)->getOne(T_USERS);
            $con_price    = $pt->config->ad_c_price;
            $pub_price    = $pt->config->pub_price;
            $ad_trans     = false;
            $is_owner     = false;
            $ad_tans_data = array(
                'results' => ($ad->results += 1)
            );

            if (IS_LOGGED) {
                $is_owner = ($ad->user_id == $pt->user->id) ? true : false;
            }

            if (!array_key_exists($ad_id, $pt->user_ad_cons['uaid_']) && !$is_owner) {
                $video_owner = $db->where('id',$pub_id)->getOne(T_USERS);
                if ((($pt->config->usr_v_mon == 'on' && $pt->config->user_mon_approve == 'off') || ($pt->config->usr_v_mon == 'on' && $pt->config->user_mon_approve == 'on' && $video_owner->monetization == '1')) && $video_owner->video_mon == 1){

                    if (!empty($video_owner) && ($ad->user_id != $video_owner->id)) {
                        $db->where('id',$pub_id)->update(T_USERS,array(
                            'balance' => (($video_owner->balance += $pub_price))
                        ));
                        $db->insert(T_ADS_TRANS,array('amount' => $pub_price,'type' => 'video', 'ad_id' => $ad_id, 'video_owner' => $pub_id, 'time' => time()));
                    }
                }

                $ad_tans_data['spent']               = ($ad->spent += $con_price);
                $ad_trans                            = true;
                $pt->user_ad_cons['uaid_'][$ad->id]  = $ad->id;
                setcookie('_uads', htmlentities(serialize($pt->user_ad_cons)), time() + (10 * 365 * 24 * 60 * 60),'/');
                $db->insert(T_ADS_TRANS,array('amount' => $con_price ,'type' => 'spent', 'ad_id' => $ad_id, 'video_owner' => $pub_id, 'time' => time()));
            }
            if ($ad->type == 1) {
                $type_ = 'click';
            }
            else{
                $type_ = 'view';
            }
            $db->insert(T_ADS_TRANS,array('type' => $type_, 'ad_id' => $ad_id, 'video_owner' => $pub_id, 'time' => time()));

            $update       = $db->where('id',$ad_id)->update(T_USR_ADS,$ad_tans_data);
            if ($update && $ad_trans && !$is_owner) {
                $ad_value = ($ad_owner->wallet -= $con_price);
                if ($ad_value < 0) {
                    $ad_value = 0;
                }
                $db->where('id',$ad_owner->id)->update(T_USERS,array('wallet' => $ad_value));
                if ($ad->day_limit > 0) {
                    if ($ad->day == date("Y-m-d")) {
                        $db->where('id',$ad->id)->update(T_USR_ADS,array('day_spend' => ($ad->day_spend + $con_price)));
                    }
                    else{
                        $db->where('id',$ad->id)->update(T_USR_ADS,array('day_spend' => $con_price ,
                                                                         'day'       => date("Y-m-d")));
                    }
                }
            }

            $data['status'] = 200;
            unset($_SESSION['ua_']);
        }
    }
}
function RegisterPoint($post_id, $type, $action = '+',$user_id = 0){
    global $pt, $sqlConnect,$db;
    if (!IS_LOGGED) {
        return false;
    }
    if ($pt->config->point_level_system == 0 ){
        return false;
    }
    if ((empty($post_id) or !is_numeric($post_id) or $post_id < 1) && $type != 'admob') {
        return false;
    }
    if (empty($type)) {
        return false;
    }

    if (!empty($user_id) && is_numeric($user_id) && $user_id > 0) {
        $user_id = PT_Secure($user_id);
    }
    else{
        $user_id = PT_Secure( $pt->user->id );
        if (empty($user_id) || !is_numeric($user_id) || $user_id < 1) {
            return fasle;
        }
    }
    if (empty($pt->user->point_day_expire)) {
        $today_end = strtotime(date('M')." ".date('d').", ".date('Y')." 11:59pm");
        $db->where('id',$user_id)->update(T_USERS,array('point_day_expire' => $today_end));
    }
    if ($pt->user->point_day_expire <= time()) {
        $today_end = strtotime(date('M')." ".date('d').", ".date('Y')." 11:59pm");
        $db->where('id',$user_id)->update(T_USERS,array('point_day_expire' => $today_end,
                                                             'daily_points' => 0));
    }
    $points = 0;
    $dollar_to_point_cost = $pt->config->dollar_to_point_cost;
    $post_id = PT_Secure($post_id);

    switch ($type) {
        case "comments":
            $query_comments     = "SELECT `id` FROM `" . T_COMMENTS . "` WHERE `video_id` = ".$post_id." AND `user_id` = ".$user_id;
            $sql_query_comments = mysqli_query($sqlConnect, $query_comments);
            if ($sql_query_comments->num_rows == 1) {
                $points = $pt->config->comments_point;
            }
            break;
        case "likes":
            $points = $pt->config->likes_point;
            break;
        case "dislikes":
            $points = $pt->config->dislikes_point;
            break;
        case "upload":
            $points = $pt->config->upload_point;
            break;
        case "admob":
            $points = $pt->config->point_system_admob_cost;
            break;
        case "watch":
            $have_video = $db->where('id', $post_id)->where('user_id',$pt->user->id)->getValue(T_VIDEOS,'COUNT(*)');
            if ($have_video == 0) {
                $points = $pt->config->watching_point;
            }
            break;
        default:
            $points = 0;
            break;
    }

    if( $points == 0 ){
        return false;
    }

    $wallet = $points / $dollar_to_point_cost;

    $user_data = $db->where('id', $user_id)->getOne(T_USERS);
    $converted_points  = 0;
    $points_amount = 0;
    $wallet_amount = 0;
    $balance_amount = 0;
    $daily_points = 0;

    if ( $action == '+' ) {
        $converted_points  = ($user_data->converted_points + $points);
        $points_amount = ($user_data->points + $points);
        $daily_points = ($user_data->daily_points + $points);
        $wallet_amount = max(($user_data->wallet + $wallet),0);
        $balance_amount = max(($user_data->balance + $wallet),0);
        if ($pt->user->is_pro && $daily_points > $pt->config->pro_day_limit) {
            return false;
        }
        elseif ($pt->user->is_pro == 0 && $daily_points > $pt->config->free_day_limit) {
            return false;
        }
    } else if ($action == '-') {
        $converted_points  = ($user_data->converted_points - $points);
        $points_amount =($user_data->points - $points);
        $daily_points =($user_data->daily_points - $points);
        $wallet_amount = max(($user_data->wallet - $wallet),0);
        $balance_amount = max(($user_data->balance - $wallet),0);
    }



    $query_one = "";
    if ($pt->config->point_allow_withdrawal == 1 ){
        $query_one = "UPDATE " . T_USERS . " SET `points` = '{$points_amount}',`daily_points` = '{$daily_points}', `balance` = '{$balance_amount}' , `converted_points` = '{$converted_points}' WHERE `id` = {$user_id} ";
    }else{
        $query_one = "UPDATE " . T_USERS . " SET `points` = '{$points_amount}',`daily_points` = '{$daily_points}', `wallet` = '{$wallet_amount}' , `converted_points` = '{$converted_points}' WHERE `id` = {$user_id} ";
    }

    $query     = mysqli_query($sqlConnect, $query_one);
    if ($query) {
        return true;
    }
}
function number_shorten($number, $precision = 3, $divisors = null) {

    // Setup default $divisors if not provided
    if (!isset($divisors)) {
        $divisors = array(
            pow(1000, 0) => '', // 1000^0 == 1
            pow(1000, 1) => 'K', // Thousand
            pow(1000, 2) => 'M', // Million
            pow(1000, 3) => 'B', // Billion
            pow(1000, 4) => 'T', // Trillion
            pow(1000, 5) => 'Qa', // Quadrillion
            pow(1000, 6) => 'Qi', // Quintillion
        );
    }

    // Loop through each $divisor and find the
    // lowest amount that matches
    foreach ($divisors as $divisor => $shorthand) {
        if (abs($number) < ($divisor * 1000)) {
            // We found a match!
            break;
        }
    }

    // We found our match, or there were no matches.
    // Either way, use the last defined value for $divisor.
    return number_format($number / $divisor, $precision) . $shorthand;
}
function getPageFromPath($path = '') {
    if (empty($path)) {
        return false;
    }
    $path = explode("&", $path);
    $data = array();
    $data['options'] = array();
    if (!empty($path[0])) {
        $data['page'] = $path[0];
    }
    if (!empty($path[1])) {
        unset($path[0]);
        $data['options'] = $path;
        foreach ($path as $key => $value) {
            preg_match_all('/(.*)=(.*)/m', $value, $matches);
            if (!empty($matches) && !empty($matches[1]) && !empty($matches[1][0]) && !empty($matches[2]) && !empty($matches[2][0])) {
                $_GET[$matches[1][0]] = $matches[2][0];
            }

        }
    }
    return $data;
}
function GetNgeniusToken()
{
    global $pt, $sqlConnect,$db;
    $ch = curl_init(); 
    if ($pt->config->ngenius_mode == 'sandbox') {
        curl_setopt($ch, CURLOPT_URL, "https://api-gateway.sandbox.ngenius-payments.com/identity/auth/access-token"); 
    }
    else{
        curl_setopt($ch, CURLOPT_URL, "https://identity-uat.ngenius-payments.com/auth/realms/ni/protocol/openid-connect/token"); 
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "accept: application/vnd.ni-identity.v1+json",
        "authorization: Basic ".$pt->config->ngenius_api_key,
        "content-type: application/vnd.ni-identity.v1+json"
      )); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);   
    curl_setopt($ch, CURLOPT_POST, 1); 
    curl_setopt($ch, CURLOPT_POSTFIELDS,  "{\"realmName\":\"ni\"}"); 
    $output = json_decode(curl_exec($ch)); 
    return $output;
}
function CreateNgeniusOrder($token,$postData)
{
    global $pt, $sqlConnect,$db;

    $json = json_encode($postData);
    $ch = curl_init();
    if ($pt->config->ngenius_mode == 'sandbox') {
        curl_setopt($ch, CURLOPT_URL, "https://api-gateway.sandbox.ngenius-payments.com/transactions/outlets/".$pt->config->ngenius_outlet_id."/orders");
    }
    else{
        curl_setopt($ch, CURLOPT_URL, "https://api-gateway-uat.ngenius-payments.com/transactions/outlets/".$pt->config->ngenius_outlet_id."/orders");
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "Authorization: Bearer ".$token, 
    "Content-Type: application/vnd.ni-payment.v2+json",
    "Accept: application/vnd.ni-payment.v2+json"));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);

    $output = json_decode(curl_exec($ch));
    curl_close ($ch);
    return $output;
}
function NgeniusCheckOrder($token,$ref)
{
    global $pt, $sqlConnect,$db;
    $ch = curl_init();
    if ($pt->config->ngenius_mode == 'sandbox') {
        curl_setopt($ch, CURLOPT_URL, "https://api-gateway.sandbox.ngenius-payments.com/transactions/outlets/".$pt->config->ngenius_outlet_id."/orders/".$ref);
    }
    else{
        curl_setopt($ch, CURLOPT_URL, "https://api-gateway-uat.ngenius-payments.com/transactions/outlets/".$pt->config->ngenius_outlet_id."/orders/".$ref);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "Authorization: Bearer ".$token));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $output = json_decode(curl_exec($ch));
    curl_close ($ch);
    return $output;
}
function coinpayments_api_call($req = array()) {
    global $pt, $sqlConnect,$db;
    $result = array('status' => 400);

    // Generate the query string
    $post_data = http_build_query($req, '', '&');
    // echo $post_data;
    // echo "<br>";
    // Calculate the HMAC signature on the POST data
    $hmac = hash_hmac('sha512', $post_data, $pt->config->coinpayments_secret);
    // echo $hmac;
    // exit();

    $ch = curl_init('https://www.coinpayments.net/api.php');
    curl_setopt($ch, CURLOPT_FAILONERROR, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('HMAC: '.$hmac));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

    // Execute the call and close cURL handle
    $data = curl_exec($ch);
    // Parse and return data if successful.

    if ($data !== FALSE) {
        $info = json_decode($data, TRUE);
        if (!empty($info) && !empty($info['result'])) {
            $result = array('status' => 200,
                            'data' => $info['result']);
        }
        else{
            $result['message'] = $info['error'];
        }
    } else {
        $result['message'] = 'cURL error: '.curl_error($ch);
    }
    return $result;
}
function generateRandomString($length = 8) {
    $characters = '0123456789abcdefghijklmnopqrs092u3tuvwxyzaskdhfhf9882323ABCDEFGHIJKLMNksadf9044OPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}
function CheckCanLogin() {
    global $pt, $sqlConnect,$db;
    // if (IS_LOGGED) {
    //     return false;
    // }
    $ip = get_ip_address();
    if (empty($ip)) {
        return true;
    }
    if ($pt->config->lock_time < 1) {
        return true;
    }
    if ($pt->config->bad_login_limit < 1) {
        return true;
    }
    $time  = time() - (60 * $pt->config->lock_time);
    $login = $db->where('ip', $ip)->get(T_BAD_LOGIN);
    if (count($login) >= $pt->config->bad_login_limit) {
        $last = end($login);
        if ($last->time >= $time) {
            return false;
        }
    }
    $db->where('time', time() - (60 * $pt->config->lock_time * 2), '<')->delete(T_BAD_LOGIN);
    return true;
}
function AddBadLoginLog() {
    global $pt, $sqlConnect,$db;
    if (IS_LOGGED) {
        return false;
    }
    $ip = get_ip_address();
    if (empty($ip)) {
        return true;
    }
    $time  = time();
    $query = mysqli_query($sqlConnect, "INSERT INTO " . T_BAD_LOGIN . " (`ip`, `time`) VALUES ('{$ip}', '{$time}')");
    if ($query) {
        return true;
    }
}
function GetAvailableLinks($user_id) {
    global $pt, $sqlConnect,$db,$lang;
    if (IS_LOGGED == false || empty($user_id) || !is_numeric($user_id) || $user_id < 1) {
        return false;
    }
    $user_id = PT_Secure($user_id);
    $time    = 0;
    if ($pt->config->expire_user_links == 'hour') {
        $time = time() - (60 * 60);
    }
    if ($pt->config->expire_user_links == 'day') {
        $time = time() - (60 * 60 * 24);
    }
    if ($pt->config->expire_user_links == 'week') {
        $time = time() - (60 * 60 * 24 * 7);
    }
    if ($pt->config->expire_user_links == 'month') {
        $time = time() - (60 * 60 * 24 * date("t"));
    }
    if ($pt->config->expire_user_links == 'year') {
        $time = time() - (60 * 60 * 24 * 365);
    }
    $query_one = " SELECT count(*) AS count FROM " . T_INVITAION_LINKS . " WHERE `user_id` = '{$user_id}' AND `time` > '{$time}' ";
    $query     = mysqli_query($sqlConnect, $query_one);
    if (mysqli_num_rows($query)) {
        $fetched_data = mysqli_fetch_assoc($query);
        if ($pt->config->user_links_limit > 0) {
            return $pt->config->user_links_limit - $fetched_data['count'];
        } else {
            return $lang->unlimited;
        }
    }
    return false;
}
function GetGeneratedLinks($user_id) {
    global $pt, $sqlConnect,$db,$lang;
    if (IS_LOGGED == false || empty($user_id) || !is_numeric($user_id) || $user_id < 1) {
        return false;
    }
    $user_id = PT_Secure($user_id);
    $time    = 0;
    if ($pt->config->expire_user_links == 'hour') {
        $time = time() - (60 * 60);
    }
    if ($pt->config->expire_user_links == 'day') {
        $time = time() - (60 * 60 * 24);
    }
    if ($pt->config->expire_user_links == 'week') {
        $time = time() - (60 * 60 * 24 * 7);
    }
    if ($pt->config->expire_user_links == 'month') {
        $time = time() - (60 * 60 * 24 * date("t"));
    }
    if ($pt->config->expire_user_links == 'year') {
        $time = time() - (60 * 60 * 24 * 365);
    }
    $query_one = " SELECT count(*) AS count FROM " . T_INVITAION_LINKS . " WHERE `user_id` = '{$user_id}' AND `time` > '{$time}' ";
    $query     = mysqli_query($sqlConnect, $query_one);
    if (mysqli_num_rows($query)) {
        $fetched_data = mysqli_fetch_assoc($query);
        return $fetched_data['count'];
    }
    return false;
}
function GetUsedLinks($user_id) {
    global $pt, $sqlConnect,$db,$lang;
    if (IS_LOGGED == false || empty($user_id) || !is_numeric($user_id) || $user_id < 1) {
        return false;
    }
    $user_id = PT_Secure($user_id);
    $time    = 0;
    if ($pt->config->expire_user_links == 'hour') {
        $time = time() - (60 * 60);
    }
    if ($pt->config->expire_user_links == 'day') {
        $time = time() - (60 * 60 * 24);
    }
    if ($pt->config->expire_user_links == 'week') {
        $time = time() - (60 * 60 * 24 * 7);
    }
    if ($pt->config->expire_user_links == 'month') {
        $time = time() - (60 * 60 * 24 * date("t"));
    }
    if ($pt->config->expire_user_links == 'year') {
        $time = time() - (60 * 60 * 24 * 365);
    }
    $query_one = " SELECT count(*) AS count FROM " . T_INVITAION_LINKS . " WHERE `user_id` = '{$user_id}' AND `invited_id` != 0 AND `time` > '{$time}' ";
    $query     = mysqli_query($sqlConnect, $query_one);
    if (mysqli_num_rows($query)) {
        $fetched_data = mysqli_fetch_assoc($query);
        return $fetched_data['count'];
    }
    return false;
}
function GetMyInvitaionCodes($user_id) {
    global $pt, $sqlConnect,$db,$lang;
    if (IS_LOGGED == false || empty($user_id) || !is_numeric($user_id) || $user_id < 1) {
        return false;
    }
    $user_id = PT_Secure($user_id);
    $time    = 0;
    if ($pt->config->expire_user_links == 'hour') {
        $time = time() - (60 * 60);
    }
    if ($pt->config->expire_user_links == 'day') {
        $time = time() - (60 * 60 * 24);
    }
    if ($pt->config->expire_user_links == 'week') {
        $time = time() - (60 * 60 * 24 * 7);
    }
    if ($pt->config->expire_user_links == 'month') {
        $time = time() - (60 * 60 * 24 * date("t"));
    }
    if ($pt->config->expire_user_links == 'year') {
        $time = time() - (60 * 60 * 24 * 365);
    }
    $data      = array();
    $query_one = " SELECT * FROM " . T_INVITAION_LINKS . " WHERE `user_id` = '{$user_id}' AND `time` > '{$time}' ";
    $query     = mysqli_query($sqlConnect, $query_one);
    if (mysqli_num_rows($query)) {
        while ($fetched_data = mysqli_fetch_assoc($query)) {
            $fetched_data['user_name'] = '';
            $fetched_data['user_url']  = '';
            if (!empty($fetched_data['invited_id'])) {
                $user_data                 = PT_UserData($fetched_data['invited_id']);
                if (!empty($user_data)) {
                    $fetched_data['user_name'] = $user_data->name;
                    $fetched_data['user_url']  = $user_data->url;
                }
            }
            $data[] = $fetched_data;
        }
    }
    return $data;
}
function IfCanGenerateLink($user_id) {
    global $pt, $sqlConnect,$db,$lang;
    if (IS_LOGGED == false || empty($user_id) || !is_numeric($user_id) || $user_id < 1) {
        return false;
    }
    $user_id = PT_Secure($user_id);
    $time    = 0;
    if ($pt->config->expire_user_links == 'hour') {
        $time = time() - (60 * 60);
    }
    if ($pt->config->expire_user_links == 'day') {
        $time = time() - (60 * 60 * 24);
    }
    if ($pt->config->expire_user_links == 'week') {
        $time = time() - (60 * 60 * 24 * 7);
    }
    if ($pt->config->expire_user_links == 'month') {
        $time = time() - (60 * 60 * 24 * date("t"));
    }
    if ($pt->config->expire_user_links == 'year') {
        $time = time() - (60 * 60 * 24 * 365);
    }
    $query_one = " SELECT count(*) AS count FROM " . T_INVITAION_LINKS . " WHERE `user_id` = '{$user_id}' AND `time` > '{$time}' ";
    $query     = mysqli_query($sqlConnect, $query_one);
    if (mysqli_num_rows($query)) {
        $fetched_data = mysqli_fetch_assoc($query);
        if ($pt->config->user_links_limit > 0) {
            if ($pt->config->user_links_limit > $fetched_data['count']) {
                return true;
            } else {
                return false;
            }
        }
    }
    return true;
}
function IsUserInvitationExists($code = false) {
    global $pt, $sqlConnect,$db,$lang;
    if (!$code) {
        return false;
    }
    $code      = PT_Secure($code);
    $data_rows = mysqli_query($sqlConnect, "SELECT `id` FROM " . T_INVITAION_LINKS . " WHERE `code` = '$code' AND `invited_id` = 0");
    return mysqli_num_rows($data_rows) > 0;
}
function AddInvitedUser($user_id, $code) {
    global $pt, $sqlConnect,$db,$lang;
    if (empty($user_id) || !is_numeric($user_id) || $user_id < 1 || empty($code)) {
        return false;
    }
    $user_id = PT_Secure($user_id);
    $code    = PT_Secure($code);
    $db->where('code', $code)->update(T_INVITAION_LINKS, array(
        'invited_id' => $user_id
    ));
}
function DeleteUserInvitation($col = '', $val = false) {
    global $pt, $sqlConnect,$db,$lang;
    if (!$val && !$col) {
        return false;
    }
    $val = PT_Secure($val);
    $col = PT_Secure($col);
    return mysqli_query($sqlConnect, "DELETE FROM " . T_INVITAION_LINKS . " WHERE `$col` = '$val'");
}
function TranslateDate($date='')
{
    global $pt, $sqlConnect,$db,$lang;
    $words = array("saturday","sunday","monday","tuesday","wednesday","thursday","friday",
                   "sat","sun","mon","tue","wed","thu","fri",
                   "january","february","march","april","may","june","july","august","september","october","november","december",
                   "jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec");

    $keys = array(
        ($pt->language != 'arabic' ? ucfirst($lang->saturday) : $lang->saturday),
        ($pt->language != 'arabic' ? ucfirst($lang->sunday) : $lang->sunday),
        ($pt->language != 'arabic' ? ucfirst($lang->monday) : $lang->monday),
        ($pt->language != 'arabic' ? ucfirst($lang->tuesday) : $lang->tuesday),
        ($pt->language != 'arabic' ? ucfirst($lang->wednesday) : $lang->wednesday),
        ($pt->language != 'arabic' ? ucfirst($lang->thursday) : $lang->thursday),
        ($pt->language != 'arabic' ? ucfirst($lang->friday) : $lang->friday),
        ($pt->language != 'arabic' ? ucfirst($lang->saturday) : $lang->saturday),
        ($pt->language != 'arabic' ? ucfirst($lang->sunday) : $lang->sunday),
        ($pt->language != 'arabic' ? ucfirst($lang->monday) : $lang->monday),
        ($pt->language != 'arabic' ? ucfirst($lang->tuesday) : $lang->tuesday),
        ($pt->language != 'arabic' ? ucfirst($lang->wednesday) : $lang->wednesday),
        ($pt->language != 'arabic' ? ucfirst($lang->thursday) : $lang->thursday),
        ($pt->language != 'arabic' ? ucfirst($lang->friday) : $lang->friday),
        ($pt->language != 'arabic' ? ucfirst($lang->january) : $lang->january),
        ($pt->language != 'arabic' ? ucfirst($lang->february) : $lang->february),
        ($pt->language != 'arabic' ? ucfirst($lang->march) : $lang->march),
        ($pt->language != 'arabic' ? ucfirst($lang->april) : $lang->april),
        ($pt->language != 'arabic' ? ucfirst($lang->may) : $lang->may),
        ($pt->language != 'arabic' ? ucfirst($lang->june) : $lang->june),
        ($pt->language != 'arabic' ? ucfirst($lang->july) : $lang->july),
        ($pt->language != 'arabic' ? ucfirst($lang->august) : $lang->august),
        ($pt->language != 'arabic' ? ucfirst($lang->september) : $lang->september),
        ($pt->language != 'arabic' ? ucfirst($lang->october) : $lang->october),
        ($pt->language != 'arabic' ? ucfirst($lang->november) : $lang->november),
        ($pt->language != 'arabic' ? ucfirst($lang->december) : $lang->december),
        ($pt->language != 'arabic' ? ucfirst($lang->january) : $lang->january),
        ($pt->language != 'arabic' ? ucfirst($lang->february) : $lang->february),
        ($pt->language != 'arabic' ? ucfirst($lang->march) : $lang->march),
        ($pt->language != 'arabic' ? ucfirst($lang->april) : $lang->april),
        ($pt->language != 'arabic' ? ucfirst($lang->may) : $lang->may),
        ($pt->language != 'arabic' ? ucfirst($lang->june) : $lang->june),
        ($pt->language != 'arabic' ? ucfirst($lang->july) : $lang->july),
        ($pt->language != 'arabic' ? ucfirst($lang->august) : $lang->august),
        ($pt->language != 'arabic' ? ucfirst($lang->september) : $lang->september),
        ($pt->language != 'arabic' ? ucfirst($lang->october) : $lang->october),
        ($pt->language != 'arabic' ? ucfirst($lang->november) : $lang->november),
        ($pt->language != 'arabic' ? ucfirst($lang->december) : $lang->december));
    $text = str_replace($words, $keys, strtolower($date));
    if ($pt->config->date_style == 'd-M-Y' || $pt->config->date_style == 'd-F-Y') {
        $text = str_replace("-", " ", $text);
    }
    
    return $text;
}

function CheckHavePermission($page='')
{
    global $pt, $sqlConnect,$db,$lang;

    if (IS_LOGGED == false || empty($page)) {
        return false;
    }
    if (empty($pt->user->permission)) {
        return false;
    }

    $permission = json_decode($pt->user->permission,true);
    if (!empty($permission) && is_array($permission)) {
        if(isset($permission[$page]) && $permission[$page] == "1") {
            return true;
        }
    }
    return false;
}
function CheckHaveMultiPermission($pages=array())
{
    global $pt, $sqlConnect,$db,$lang;

    if (IS_LOGGED == false || empty($pages)) {
        return false;
    }
    if (empty($pt->user->permission)) {
        return false;
    }

    $permission = json_decode($pt->user->permission,true);
    if (!empty($permission) && is_array($permission)) {
        foreach ($pages as $key => $value) {
            if(isset($permission[$value]) && $permission[$value] == "1") {
                return true;
            }
        }
    }
    return false;
}
function GetIso()
{
    global $pt,$db;
    $iso = array();
    if (!empty($pt->langs)) {
        $esc = array();
        foreach ($pt->langs as $value) {
            $esc[] = "'".$db->escape($value)."'";
        }
        $in   = implode(',',$esc);
        $rows = $db->rawQuery("SELECT lang_name, iso FROM ".T_LANG_ISO." WHERE lang_name IN (".$in.")");
        foreach ($rows as $info) {
            if (!empty($info->lang_name) && !empty($info->iso)) {
                $iso[$info->lang_name] = $info->iso;
            }
        }
    }
    return $iso;
}
function LangsStatus()
{
    global $pt,$db;
    $iso = array();
    if (!empty($pt->langs)) {
        $esc = array();
        foreach ($pt->langs as $value) {
            $esc[] = "'".$db->escape($value)."'";
        }
        $in   = implode(',',$esc);
        $rows = $db->rawQuery("SELECT lang_name, status FROM ".T_LANG_ISO." WHERE lang_name IN (".$in.")");
        foreach ($rows as $info) {
            if (!empty($info->lang_name) && isset($info->status)) {
                $iso[$info->lang_name] = $info->status;
            }
        }
    }
    return $iso;
}
function BackblazeConnect($args=[])
{
    global $pt,$db;

    $session = curl_init($args['apiUrl'] . $args['uri']);
    $content_type = '';

    if ($args['uri'] == '/b2api/v2/b2_list_buckets') {
        $data = array("accountId" => $args['accountId']);
        $post_fields = json_encode($data);
        curl_setopt($session, CURLOPT_POSTFIELDS, $post_fields); 
        curl_setopt($session, CURLOPT_POST, true); // HTTP POST
    }
    else if ($args['uri'] == '/b2api/v2/b2_get_upload_url' || $args['uri'] == '/b2api/v2/b2_list_file_names') {
        $data = array("bucketId" => $pt->config->backblaze_bucket_id);
        $post_fields = json_encode($data);
        curl_setopt($session, CURLOPT_POSTFIELDS, $post_fields); 
        curl_setopt($session, CURLOPT_POST, true); // HTTP POST
    }
    else if ($args['uri'] == '/b2api/v2/b2_delete_file_version') {
        $data = array("fileId" => $args['fileId'], "fileName" => $args['fileName']);
        $post_fields = json_encode($data);
        curl_setopt($session, CURLOPT_POSTFIELDS, $post_fields); 
        curl_setopt($session, CURLOPT_POST, true); // HTTP POST
    }
    elseif (isset($args['file']) && !empty($args['file'])) {
        $handle = fopen($args['file'], 'r');
        $read_file = fread($handle,filesize($args['file']));
        curl_setopt($session, CURLOPT_POSTFIELDS, $read_file); 
    }

    // Add post fields
    
    

    // Add headers
    $headers = array();
    
    if ($args['uri'] == '/b2api/v2/b2_authorize_account') {
        $credentials = base64_encode($pt->config->backblaze_access_key_id . ":" . $pt->config->backblaze_access_key);
        $headers[] = "Accept: application/json";
        $headers[] = "Authorization: Basic " . $credentials;
        curl_setopt($session, CURLOPT_HTTPGET, true);
    }
    else if (isset($args['file']) && !empty($args['file'])) {
        $headers[] = "X-Bz-File-Name: " . $args['file'];
        $headers[] = "Content-Type: " . mime_content_type($args['file']);
        $headers[] = "X-Bz-Content-Sha1: " . sha1_file($args['file']);
        $headers[] = "X-Bz-Info-Author: " . "unknown";
        $headers[] = "X-Bz-Server-Side-Encryption: " . "AES256";
        $headers[] = "Authorization: " . $args['authorizationToken'];
    }
    else{
        $headers[] = "Authorization: " . $args['authorizationToken'];
    }

    curl_setopt($session, CURLOPT_HTTPHEADER, $headers); 

    curl_setopt($session, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($session, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, true);  // Receive server response
    $server_output = curl_exec($session); // Let's do this!
    curl_close ($session); // Clean up
    return $server_output;
}

function GetTiktokVideoDownloadLink($url='')
{
    global $pt,$db,$lang;

    $result = array('status' => 400,
                    'message' => $lang->error_msg);

    $tiktok_api_url = trim((isset($pt->config->tiktok_api_url) && !empty($pt->config->tiktok_api_url)) ? $pt->config->tiktok_api_url : '', '/');
    if (empty($tiktok_api_url)) {
        return $result;
    }

    $api_response = connect_to_url($tiktok_api_url . '/api/tiktok/info?video_url=' . rawurlencode($url), array('hmac' => (isset($pt->config->tiktok_api_token) ? $pt->config->tiktok_api_token : '')));
    if (!empty($api_response)) {
        $json = json_decode($api_response, true);
        if (!empty($json['success']) && !empty($json['video_url'])) {
            $result = array(
                'status' => 200,
                'video_url' => $json['video_url'],
                'cover' => (!empty($json['cover'])) ? $json['cover'] : '',
                'id' => (!empty($json['id'])) ? $json['id'] : '',
                'title' => (!empty($json['title'])) ? $json['title'] : '',
                'desc' => (!empty($json['desc'])) ? $json['desc'] : '',
            );
        }
    }

    return $result;
}

function SaveTiktokVideo($url='')
{
    global $pt,$db,$lang;

    if (!file_exists('upload/photos/' . date('Y'))) {
        @mkdir('upload/photos/' . date('Y'), 0777, true);
    }
    if (!file_exists('upload/photos/' . date('Y') . '/' . date('m'))) {
        @mkdir('upload/photos/' . date('Y') . '/' . date('m'), 0777, true);
    }
    if (!file_exists('upload/timeline/' . date('Y') . '/' . date('m'))) {
        @mkdir('upload/timeline/' . date('Y') . '/' . date('m'), 0777, true);
    }
    if (!file_exists('upload/videos/' . date('Y'))) {
        @mkdir('upload/videos/' . date('Y'), 0777, true);
    }
    if (!file_exists('upload/videos/' . date('Y') . '/' . date('m'))) {
        @mkdir('upload/videos/' . date('Y') . '/' . date('m'), 0777, true);
    }
    if (!file_exists('upload/photos/' . date('Y').'/index.html')) {
        @file_put_contents('upload/photos/' . date('Y').'/index.html','index.html');
    }
    if (!file_exists('upload/photos/' . date('Y') . '/' . date('m').'/index.html')) {
        @file_put_contents('upload/photos/' . date('Y') . '/' . date('m').'/index.html','index.html');
    }
    if (!file_exists('upload/videos/' . date('Y').'/index.html')) {
        @file_put_contents('upload/videos/' . date('Y').'/index.html','index.html');
    }
    if (!file_exists('upload/videos/' . date('Y') . '/' . date('m').'/index.html')) {
        @file_put_contents('upload/videos/' . date('Y') . '/' . date('m').'/index.html','index.html');
    }

    $ch = curl_init();
    $headers = array(
        'Range: bytes=0-',
    );
    $options = array(
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLINFO_HEADER_OUT    => true,
        CURLOPT_USERAGENT => 'okhttp',
        CURLOPT_ENCODING       => "utf-8",
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_COOKIEJAR      => 'cookie.txt',
    CURLOPT_COOKIEFILE     => 'cookie.txt',
        CURLOPT_REFERER        => 'https://www.tiktok.com/',
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_MAXREDIRS      => 10,
    );
    curl_setopt_array( $ch, $options );

    $data = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $filename = 'upload/videos/' . date('Y') . '/' . date('m').'/' .PT_GenerateKey() . ".mp4";
    $d = fopen($filename, "w");
    fwrite($d, $data);
    fclose($d);

    // Validate before persisting: the muxed mp4 must look like an MP4, not an
    // error/JSON payload from the mux API or a truncated response. Otherwise
    // a bogus "video" would be uploaded and linked into the library.
    $size = @filesize($filename);
    $head = @file_get_contents($filename, false, null, 0, 64);
    $is_mp4 = !empty($head) && strpos($head, 'ftyp') !== false && strncmp($head, '{"', 2) !== 0 && strncmp($head, '<', 1) !== 0;
    if ($httpcode < 200 || $httpcode >= 400 || empty($data) || !$is_mp4 || $size < 1024) {
        @unlink($filename);
        return '';
    }

    PT_UploadToS3($filename);

    return $filename;
}

function SaveTiktokImage($url='')
{
    global $pt,$db,$lang;

    if (!file_exists('upload/photos/' . date('Y'))) {
        @mkdir('upload/photos/' . date('Y'), 0777, true);
    }
    if (!file_exists('upload/photos/' . date('Y') . '/' . date('m'))) {
        @mkdir('upload/photos/' . date('Y') . '/' . date('m'), 0777, true);
    }
    if (!file_exists('upload/timeline/' . date('Y') . '/' . date('m'))) {
        @mkdir('upload/timeline/' . date('Y') . '/' . date('m'), 0777, true);
    }
    if (!file_exists('upload/videos/' . date('Y'))) {
        @mkdir('upload/videos/' . date('Y'), 0777, true);
    }
    if (!file_exists('upload/videos/' . date('Y') . '/' . date('m'))) {
        @mkdir('upload/videos/' . date('Y') . '/' . date('m'), 0777, true);
    }
    if (!file_exists('upload/photos/' . date('Y').'/index.html')) {
        @file_put_contents('upload/photos/' . date('Y').'/index.html','index.html');
    }
    if (!file_exists('upload/photos/' . date('Y') . '/' . date('m').'/index.html')) {
        @file_put_contents('upload/photos/' . date('Y') . '/' . date('m').'/index.html','index.html');
    }
    if (!file_exists('upload/videos/' . date('Y').'/index.html')) {
        @file_put_contents('upload/videos/' . date('Y').'/index.html','index.html');
    }
    if (!file_exists('upload/videos/' . date('Y') . '/' . date('m').'/index.html')) {
        @file_put_contents('upload/videos/' . date('Y') . '/' . date('m').'/index.html','index.html');
    }

    $ch = curl_init();
    $headers = array(
        'Range: bytes=0-',
    );
    $options = array(
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLINFO_HEADER_OUT    => true,
        CURLOPT_USERAGENT => 'okhttp',
        CURLOPT_ENCODING       => "utf-8",
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_COOKIEJAR      => 'cookie.txt',
    CURLOPT_COOKIEFILE     => 'cookie.txt',
        CURLOPT_REFERER        => 'https://www.tiktok.com/',
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_MAXREDIRS      => 10,
    );
    curl_setopt_array( $ch, $options );

    $data = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $filename = 'upload/photos/' . date('Y') . '/' . date('m').'/' .PT_GenerateKey() . ".jpg";
    $d = fopen($filename, "w");
    fwrite($d, $data);
    fclose($d);

    PT_UploadToS3($filename);
    
    return $filename;
}
function checkHTTPS() {
    if(!empty($_SERVER['HTTPS'])) {
        if($_SERVER['HTTPS'] !== 'off') {
          return true;
        }
    } else {
      if($_SERVER['SERVER_PORT'] == 443) {
        return true;
      }
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
      if ($_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
         return true;
      }
    }
    return false;
}

function url_origin( $s, $use_forwarded_host = false )
{
    $ssl      = ( ! empty( $s['HTTPS'] ) && $s['HTTPS'] == 'on' );
    $sp       = strtolower( $s['SERVER_PROTOCOL'] );
    $protocol = substr( $sp, 0, strpos( $sp, '/' ) ) . ( ( $ssl ) ? 's' : '' );
    $port     = $s['SERVER_PORT'];
    $port     = ( ( ! $ssl && $port=='80' ) || ( $ssl && $port=='443' ) ) ? '' : ':'.$port;
    $host     = ( $use_forwarded_host && isset( $s['HTTP_X_FORWARDED_HOST'] ) ) ? $s['HTTP_X_FORWARDED_HOST'] : ( isset( $s['HTTP_HOST'] ) ? $s['HTTP_HOST'] : null );
    $host     = isset( $host ) ? $host : $s['SERVER_NAME'] . $port;
    return $host;
}

function full_url( $s, $use_forwarded_host = false )
{
    return url_origin( $s, $use_forwarded_host ) . $s['REQUEST_URI'];
}

function file_upload_max_size() {
  static $max_size = -1;

  if ($max_size < 0) {
    // Start with post_max_size.
    $post_max_size = parse_size(ini_get('post_max_size'));
    if ($post_max_size > 0) {
      $max_size = $post_max_size;
    }

    // If upload_max_size is less, then reduce. Except if upload_max_size is
    // zero, which indicates no limit.
    $upload_max = parse_size(ini_get('upload_max_filesize'));
    if ($upload_max > 0 && $upload_max < $max_size) {
      $max_size = $upload_max;
    }
  }
  return $max_size;
}

function formatBytes($size, $precision = 2)
{
    $base = log($size, 1024);
    $suffixes = array('', 'K', 'M', 'G', 'T');   

    return round(pow(1024, $base - floor($base)), $precision) .$suffixes[floor($base)];
}
function parse_size($size) {
  $unit = preg_replace('/[^bkmgtpezy]/i', '', $size); // Remove the non-unit characters from the size.
  $size = preg_replace('/[^0-9\.]/', '', $size); // Remove the non-numeric characters from the size.
  if ($unit) {
    // Find the position of the unit in the ordered string which is the power of magnitude to multiply a kilobyte by.
    return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
  }
  else {
    return round($size);
  }
}


function getStatus($config = array()) {
    global $pt,$db;

    $errors = [];

    
    if(!ini_get('allow_url_fopen') ) {
        $errors[] = ["type" => "error", "message" => "PHP function <strong>allow_url_fopen</strong> is disabled on your server, it is required to be enabled."];
    }
    if(!function_exists('mime_content_type')) {
        $errors[] = ["type" => "error", "message" => "PHP <strong>FileInfo</strong> extension is disabled on your server, it is required to be enabled."];
    }
    if (!class_exists('DOMDocument')) {
        $errors[] = ["type" => "error", "message" => "PHP <strong>dom & xml</strong> extensions are disabled on your server, they are required to be enabled."];
    }
    if (!is_writable('./upload')) {
        $errors[] = ["type" => "error", "message" => "The folder: <strong>/upload</strong> is not writable, upload folder and all subfolder(s) permission should be set to <strong>777</strong>."];
    }
    if (!is_writable('./sitemaps')) {
        $errors[] = ["type" => "error", "message" => "The folder: <strong>/sitemaps</strong> is not writable, sitemaps folder permission should be set to <strong>777</strong>."];
    }

    if ($pt->remoteStorage) {
        if (!is_writable('./upload/photos/d-avatar.jpg')) {
            $errors[] = ["type" => "error", "message" => "The file: <strong>./upload/photos/d-avatar.jpg</strong> is not writable, the file permission should be set to <strong>777</strong>.<br> Also make sure the file exists."];
        }

        if (!is_writable('./upload/photos/f-avatar.png')) {
            $errors[] = ["type" => "error", "message" => "The file: <strong>./upload/photos/f-avatar.png</strong> is not writable, the file permission should be set to <strong>777</strong>.<br> Also make sure the file exists."];
        }
        
        if (!is_writable('./upload/photos/d-cover.jpg')) {
            $errors[] = ["type" => "error", "message" => "The file: <strong>./upload/photos/d-cover.jpg</strong> is not writable, the file permission should be set to <strong>777</strong>.<br> Also make sure the file exists."];
        }
        
    }

    if ($pt->config->ffmpeg_system == 'on') {
        if (!isfuncEnabled("shell_exec")) {
            $errors[] = ["type" => "error", "message" => "The function: <strong>shell_exec</strong> is not enabled, please contact your hosting provider to enable it, it's required for <strong>FFMPEG</strong>."];
        }
        if ($pt->config->ffmpeg_binary_file == "./assets/libs/ffmpeg/ffmpeg" || $pt->config->ffmpeg_binary_file == "assets/libs/ffmpeg/ffmpeg") {
            if (!is_writable($pt->config->ffmpeg_binary_file)) {
                $errors[] = ["type" => "error", "message" => "The file: <strong>/assets/libs/ffmpeg/ffmpeg</strong> is not writable, file permission should be <strong>777</strong>."];
            }
        }
        
    }
    
    if (!is_writable('./sitemap-main.xml')) {
        $errors[] = ["type" => "error", "message" => "The file: <strong>./sitemap-main.xml</strong> is not writable, the file permission should be set to <strong>777</strong>."];
    }


    if (session_status() == PHP_SESSION_NONE) {
        $errors[] = ["type" => "error", "message" => "PHP Session can't start, please check the session settings on your server, the session path should be writable, contact your server for more Information."];
    }

    if (!empty($config['curl'])) {
        $ch = curl_init ();
        $timeout = 10; 
        $myHITurl = "https://www.google.com";
        curl_setopt ( $ch, CURLOPT_URL, $myHITurl );
        curl_setopt ( $ch, CURLOPT_HEADER, 0 );
        curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt ( $ch, CURLOPT_CONNECTTIMEOUT, $timeout );
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $file_contents = curl_exec ( $ch );
        if (curl_errno ( $ch )) {
            $errors[] = ["type" => "error", "message" => "<strong>cURL</strong> is not functioning, can't connect to the outside world, error found: <strong>" . curl_error ( $ch ) . "</strong>, please contact your hosting provider to fix it."];
        }
        curl_close ( $ch );
    }

    if (!empty($config['htaccess'])) {
        if (!file_exists('./.htaccess')) {
            $errors[] = ["type" => "error", "message" => "The file: <strong>.htaccess</strong> is not uploaded to your server, make sure the file <strong>.htaccess</strong> is uploaded to your server."];
        } else {
            $file_gethtaccess = file_get_contents("./.htaccess");
            if (strpos($file_gethtaccess, "index.php?link1") === false) {
                $errors[] = ["type" => "error", "message" => "The file: <strong>.htaccess</strong> is not updated, please re-upload the original .htaccess file."];
            }
        }
    }


    if (!empty($config['nodejsport']) && $pt->config->server == "nodejs") {
        $parse = parse_url($pt->config->site_url);
        $host = $parse['host'];
        $ports = array($pt->config->server_port);
        foreach ($ports as $port)
        {
            $connection = @fsockopen($host, $port);

            if (!is_resource($connection))
            {
                $errors[] = ["type" => "error", "message" => "<strong>NodeJS</strong>is enabled, but the system can't connect to NodeJS server, <strong> " . $host . ':' . $port . " </strong>is down or port <strong>$port</strong> is blocked."];
            } 
        }
    }


    $dirs = array_filter(glob('upload/*'), 'is_dir');
    foreach ($dirs as $key => $value) {
        if (!is_writable($value)) {
            $errors[] = ["type" => "error", "message" => "The folder: <strong>{$value}</strong> is not writable, folder permission should be set to <strong>777</strong>."];
        }
    }

    if (empty($pt->config->smtp_host) && empty($pt->config->smtp_username)) {
        $errors[] = ["type" => "error", "message" => "<strong>SMTP</strong> is not configured, it's recommended to setup <strong>SMTP</strong>, so the system can send e-mails from the server. <br> <a href=" . PT_LoadAdminLinkSettings('email-settings') . ">Click Here To Setup SMTP</a>"];
    }



    if (!is_writable('./themes/' . $pt->config->theme . '/img')) {
        $errors[] = ["type" => "error", "message" => "The folder: <strong>/themes/{$pt->config->theme}/img</strong> is not writable, the path and all subfolder(s) permission should be set to <strong>777</strong>, including <strong>logo.png</strong>"];
    }
    

    if (file_exists('./install')) {
        $errors[] = ["type" => "error", "message" => "The folder: <strong>./install</strong> is not deleted or renamed, make sure the folder <strong>./install</strong> is deleted."];
    }
    

    if (!empty($pt->config->filesVersion)) {
        if ($pt->config->filesVersion > $pt->config->version) {
            $errors[] = ["type" => "error", "message" => "There is a conflict in database version and files version, your database version is: <strong>v{$pt->config->version}</strong>, but script version is: <strong>v{$pt->config->filesVersion}</strong>. <br> Please run <strong><a href='{$pt->config->site_url}/update.php'>{$pt->config->site_url}/update.php</a></strong> of <strong>v{$pt->config->filesVersion}</strong>. <br><br><a href='https://docs.playtubescript.com/#updates'>Click Here For More Information.</a>"];
        } else if ($pt->config->filesVersion < $pt->config->version) {
            $errors[] = ["type" => "error", "message" => "There is a conflict in database version and files version, your database version is: <strong>v{$pt->config->version}</strong>, but script version is: <strong>v{$pt->config->filesVersion}</strong>. <br>Please upload the files of <strong>v{$pt->config->filesVersion}</strong> using FTP or SFTP, file managers are not recommended."];
        }
    } else {
        $errors[] = ["type" => "error", "message" => "There is a conflict in database version and files version, your database version is: <strong>v{$pt->config->version}</strong>, but script version is: <strong>v{$pt->config->filesVersion}</strong>, <br>Please upload the files of <strong>v{$pt->config->filesVersion}</strong> using FTP or SFTP, file managers are not recommended."];
    }

    if (!empty($pt->config->cronjob_last_run)) {
        $now = strtotime("-15 minutes");
        if ($pt->config->cronjob_last_run < $now) {
            $errors[] = ["type" => "error", "message" => "File <strong>cron-job.php</strong> last run exceeded 15 minutes, make sure it's added to cronjob list. <br> <a href=" . PT_LoadAdminLinkSettings('cronjob_settings') . ">CronJob Settings</a>"];
        }
    }
    

    $getSqlModes = $db->rawQuery("SELECT @@sql_mode as modes;");
      if (!empty($getSqlModes[0]->modes)) {
         $results = @explode(',', strtolower($getSqlModes[0]->modes));
         if (in_array('strict_trans_tables', $results)) {
           $errors[] = ["type" => "error", "message" => "The sql-mode <b>strict_trans_tables</b> is enabled in your mysql server, please contact your host provider to disable it."];
         }
         if (in_array('only_full_group_by', $results)) {
           $errors[] = ["type" => "error", "message" => "The sql-mode <b>only_full_group_by</b> is enabled in your mysql server, this can cause some issues on your website, please contact your host provider to disable it."];
         }
      }

    $getUploadSize = file_upload_max_size();

    if ($getUploadSize < 1000000000) {
        $errors[] = ["type" => "warning", "message" => "Your server max upload size is less than 100MB, Current: <strong>" . formatBytes($getUploadSize). "</strong> Recommended is <strong>1024MB</strong>. You should update both: upload_max_filesize, post_max_size."];
    }

    if (ini_get('max_execution_time') < 100 && ini_get('max_execution_time') > 0) {
        $errors[] = ["type" => "warning", "message" => "Your server max_execution_time is less than 100 seconds, Current: <strong>" . ini_get('max_execution_time'). "</strong> Recommended is <strong>3000</strong>."];
    }

    if ($pt->config->developer_mode == "on") {
        $errors[] = ["type" => "warning", "message" => "<strong>Developer Mode</strong> is enabled in <strong>Settings -> General Configuration</strong>, it's not recommended to enable <strong>Developer Mode</strong> if your website is live, some errors may show."];
    }

    if(!function_exists('exif_read_data')) {
        $errors[] = ["type" => "warning", "message" => "PHP <strong>exif</strong> extension is disabled on your server, it is recommended to be enabled."];
    }

    try {
        $getSqlWait = $db->rawQuery("show variables where Variable_name='wait_timeout';");
        if (!empty($getSqlWait[0]->Value)) {
            if ($getSqlWait[0]->Value < 1000) {
              $errors[] = ["type" => "warning", "message" => "The MySQL variable <b>wait_timeout</b> is {$getSqlWait[0]->Value}, minumum required is <strong>1000</strong>, please contact your host provider to update it."];
            }
        }
    } catch (Exception $e) {
        
    }

    return $errors;
}

function checkIfThereIsError($object) {
    foreach ($object as $key => $value) {
        if ($value['type'] == "error") {
            return true;
        }
    }
    return false;
}

function isfuncEnabled($func) {
    return is_callable($func) && false === stripos(ini_get('disable_functions'), $func);
}
function GetReferrers($user_id = 0) {
    global $pt,$db,$sqlConnect;
    if (IS_LOGGED == false) {
        return false;
    }
    if (empty($user_id)) {
        $user_id = PT_Secure($pt->user->id);
    } else {
        $user_id = PT_Secure($user_id);
    }
    $data          = array();
    $fetched_data = $db->where('referrer',$user_id)->orderBy('id','DESC')->get(T_USERS);
    foreach ($fetched_data as $key => $value) {
        $data[] = PT_UserData($value->id);
    }
    return $data;
}

function http_request_call($method, $url, $header, $data, $json){
    if( $method == 1 ){
        $method_type = 1; // 1 = POST
    }else{
        $method_type = 0; // 0 = GET
    }

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl, CURLOPT_HEADER, 0);

    if( $header !== 0 ){
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    }

    curl_setopt($curl, CURLOPT_POST, $method_type);

    if( $data !== 0 ){
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    }

    $response = curl_exec($curl);

    if( $json == 0 ){
        $json = $response;
    }else{
        $json = json_decode($response, true);
    }

    curl_close($curl);

    return $json;
}
function GenrateCode($user_id, $app_id) {
    global $sqlConnect,$db;
    $app_id  = PT_Secure($app_id);
    $user_id = PT_Secure($user_id);
    if (empty($app_id) || empty($user_id)) {
        return false;
    }
    $token     = PT_GenerateKey(40, 40);
    $have_code = $db->where('app_id',$app_id)->where('user_id',$user_id)->getValue(T_APPS_CODES,'COUNT(*)');
    if ($have_code) {
        $db->where('app_id',$app_id)->where('user_id',$user_id)->delete(T_APPS_CODES);
    }

    $db->insert(T_APPS_CODES,[
        'user_id' => $user_id,
        'app_id' => $app_id,
        'code' => $token,
        'time' => time()
    ]);
    return $token;
}
function getHashTagId($tag = '')
{
    global $sqlConnect,$db,$pt;
    $tagData = $db->where('tag',$tag)->getOne(T_HASHTAGS);

    $tagID = 0;
    if (!empty($tagData)) {
        $tagID = $tagData->id;
    }
    else{
        $id =   $db->insert(T_HASHTAGS,
                [
                    'tag' => $tag,
                    'time' => time()
                ]);
        if (!empty($id)) {
            $tagID = $id;
        }
    }
    return $tagID;
}

function tagRaplaceText($replace , $search , $subject)
{
    global $sqlConnect,$db,$pt;
    $match_search  = '#' . $search;
    $match_replace = '#[' . $replace . ']';
    if (mb_detect_encoding($match_search, 'ASCII', true)) {
        $subject = preg_replace("/$match_search\b/i", $match_replace, $subject);
    } else {
        $subject = str_replace($match_search, $match_replace, $subject);
    }
    return $subject;
}

function addToHashTags($text)
{
    global $sqlConnect,$db,$pt;
    if ($pt->config->hashtag_system == 'off') {
        return $text;
    }
    $hashtag_regex = '/#([^`~!@$%^&*\#()\-+=\\|\/\.,<>?\'\":;{}\[\]* ]+)/i';
    preg_match_all($hashtag_regex, $text, $matches);
    foreach ($matches[1] as $match) {
        $match = strtolower($match);

        $tagID = getHashTagId($match);

        if (!empty($tagID)) {
            $text = tagRaplaceText($tagID,$match,$text);
        }
    }
    return $text;
}
function fetchDataFromURL($url = '') {
    if (empty($url)) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.0; en-US; rv:1.7.12) Gecko/20050915 Firefox/1.0.7");
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    return curl_exec($ch);
}
function addNewRefUser($price,$percent)
{
    global $sqlConnect,$db,$pt;
    if (empty($price)) {
        return false;
    }
    $db->where('id',$pt->user->id)->update(T_USERS,[
                                                    'referrer' => $pt->user->ref_user_id,
                                                    'src' => 'Referrer',
                                                    'ref_user_id' => 0
                                                    ]);
    $ref_amount     = ($percent * $price) / 100;
    $db->where('id',$pt->user->ref_user_id)->update(T_USERS,[
                                                    'balance' => $db->inc($ref_amount)
                                                    ]);
    return true;
}
function getAuthyQR($authy_id='')
{
    global $sqlConnect,$db,$pt;
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'https://api.authy.com/protected/json/users/'.$authy_id.'/secret');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "label=\"".$pt->config->title."(".$pt->user->username.")\"&qr_size=\"300\"");

    $headers = array();
    $headers[] = 'X-Authy-Api-Key: '.$pt->config->authy_token;
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        return false;
    }
    curl_close($ch);
    $result = json_decode($result);
    if (!empty($result) && !empty($result->qr_code)) {
        return $result->qr_code;
    }
    return false;
}
function verifyAuthy($code='',$authy_id='')
{
    global $sqlConnect,$db,$pt;
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'https://api.authy.com/protected/json/verify/'.$code.'/'.$authy_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');


    $headers = array();
    $headers[] = 'X-Authy-Api-Key: '.$pt->config->authy_token;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        return false;
    }
    curl_close($ch);
    $result = json_decode($result);
    if (!empty($result) && !empty($result->success)) {
        return true;
    }
    return false;
}

function importFacebookVideo($url='')
{
    $url = str_replace('m.facebook.com', 'www.facebook.com', $url);
    $url = getFacebookLongUrl($url);
    $webPage = urlGetFacebookContents($url);
    // preg_match_all('/<script type="application\/ld\+json" nonce="\w{3,10}">(.*?)<\/script><link rel="canonical"/', $webPage, $matches);
    // preg_match_all('/"video":{(.*?)},"video_home_www_injection_related_chaining_section"/', $webPage, $matches2);
    preg_match_all('/"playable_url":"(.*?)"/', $webPage, $matches3);
    // preg_match_all('/<script type="application\/ld\+json" nonce=".*?">(.*?)<\/script>/', $webPage, $matches4);
    // preg_match_all('/RelayPrefetchedStreamCache","next",\[\],(.*)],\["VideoPlayerSpinner\.react"]/', $webPage, $matches5);
    if (!empty($matches3[1][0])) {
        preg_match('/"preferred_thumbnail":{"image":{"uri":"(.*?)"/', $webPage, $thumbnail);
        preg_match_all('/"playable_url_quality_hd":"(.*?)"/', $webPage, $hdLink);
        $thumbnail = isset($thumbnail[1]) ? decodeJsonText($thumbnail[1]) : '';
        $video = decodeJsonText($matches3[1][0]);
        return [
            'thumbnail' => $thumbnail,
            'video' => $video
        ];
    }
    return false;
}
function decodeJsonText($text)
{
    $json = '{"text":"' . $text . '"}';
    $json = json_decode($json, 1);
    return $json["text"];
}
function urlGetFacebookContents($url)
{
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'authority: www.facebook.com',
            'cache-control: max-age=0',
            'sec-ch-ua: "Google Chrome";v="89", "Chromium";v="89", ";Not A Brand";v="99"',
            'sec-ch-ua-mobile: ?0',
            'upgrade-insecure-requests: 1',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.114 Safari/537.36',
            'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'sec-fetch-site: none',
            'sec-fetch-mode: navigate',
            'sec-fetch-user: ?1',
            'sec-fetch-dest: document',
            'accept-language: en-GB,en;q=0.9,tr-TR;q=0.8,tr;q=0.7,en-US;q=0.6',
            'cookie: '
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;
}
function getFacebookLongUrl($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'authority: www.facebook.com',
        'cache-control: max-age=0',
        'sec-ch-ua: "Google Chrome";v="89", "Chromium";v="89", ";Not A Brand";v="99"',
        'sec-ch-ua-mobile: ?0',
        'upgrade-insecure-requests: 1',
        'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.114 Safari/537.36',
        'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
        'sec-fetch-site: none',
        'sec-fetch-mode: navigate',
        'sec-fetch-user: ?1',
        'sec-fetch-dest: document',
        'accept-language: en-GB,en;q=0.9,tr-TR;q=0.8,tr;q=0.7,en-US;q=0.6',
        'cookie: '
    ));
    curl_exec($ch);
    $longUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    parse_str(parse_url($longUrl, PHP_URL_QUERY), $query);
    if (!empty($query['next'])) {
        return $query['next'];
    } else {
        return $longUrl;
    }
}
function getInReelUrl($url='')
{
    preg_match('~https?:\/\/www\.instagram\.com.*\/(reel|reels\/videos)\/([A-Za-z0-9-_.]*)~m', $url, $matches);
    return 'https://www.instagram.com/reel/'.$matches[2];
}
function importInstagramVideo($url)
{
    $url = getInReelUrl($url);
    $postShortcode = getInstagramPostShortcode($url);
    $endpointUrl = 'https://www.instagram.com/graphql/query/?query_hash=55a3c4bad29e4e20c20ff4cdfd80f5b4&variables=%7B%22shortcode%22:%22' . $postShortcode . '%22%7D';
    $result = getInstagramContents($endpointUrl);
    if (!empty($result)) {
        $json = json_decode($result, true);
        if (!empty($json) && !empty($json['data']) && !empty($json['data']['shortcode_media']) && $json['data']['shortcode_media']['is_video'] == 1 && $json['data']['shortcode_media']['__typename'] == 'GraphVideo' && !empty($json['data']['shortcode_media']['video_url'])) {
            return [
                'thumbnail' => $json['data']['shortcode_media']['display_url'],
                'video' => $json['data']['shortcode_media']['video_url']
            ];
        }
    }
    return false;
}
function getInstagramContents($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.93 Safari/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Cookie '
    ));
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}
function getInstagramPostShortcode($url)
{
    if (substr($url, -1) != '/') {
        $url .= '/';
    }
    preg_match('/\/(p|tv|reel)\/(.*?)\//', $url, $output);
    return ($output['2'] ?? '');
}
function GetAllProInfo() {
    global $pt, $sqlConnect,$db,$lang_array;
    
    $data = array();
    $packages = $db->arrayBuilder()->get(T_MANAGE_PRO);
    foreach ($packages as $fetched_key => $fetched_data) {
        $fetched_data['formatedFeatures'] = array();
        if (!empty($fetched_data['features'])) {
            $fetched_data['formatedFeatures'] = json_decode($fetched_data['features'],true);
            foreach ($fetched_data['formatedFeatures'] as $key => $value) {
                $fetched_data[$key] = $value;
            }
        }

        if (!empty($fetched_data["image"])) {
            $fetched_data["image"] = PT_GetMedia($fetched_data["image"]);
        }
        if (!empty($fetched_data["night_image"])) {
            $fetched_data["night_image"] = PT_GetMedia($fetched_data["night_image"]);
        }
        $fetched_data['name'] = $fetched_data['type'];

        $fetched_data['name'] = preg_replace_callback("/{LANG_KEY (.*?)}/", function($m) use ($lang_array) {
            return $lang_array[$m[1]];
        }, $fetched_data['name']);

        $fetched_data['ex_time'] = 60 * 60 * 24;
        if (!empty($fetched_data["time"]) && $fetched_data["time"] == 'day') {
            if (!empty($fetched_data["time_count"]) && is_numeric($fetched_data["time_count"]) && $fetched_data["time_count"] > 0) {
                $fetched_data['ex_time']  = $fetched_data['ex_time'] * $fetched_data["time_count"];
            }
        }
        else if (!empty($fetched_data["time"]) && $fetched_data["time"] == 'week') {
            $fetched_data['ex_time'] = $fetched_data['ex_time'] * 7;
            if (!empty($fetched_data["time_count"]) && is_numeric($fetched_data["time_count"]) && $fetched_data["time_count"] > 0) {
                $fetched_data['ex_time']  = $fetched_data['ex_time'] * $fetched_data["time_count"];
            }
        }
        else if (!empty($fetched_data["time"]) && $fetched_data["time"] == 'month') {
            $fetched_data['ex_time'] = $fetched_data['ex_time'] * 30;
            if (!empty($fetched_data["time_count"]) && is_numeric($fetched_data["time_count"]) && $fetched_data["time_count"] > 0) {
                $fetched_data['ex_time']  = $fetched_data['ex_time'] * $fetched_data["time_count"];
            }
        }
        else if (!empty($fetched_data["time"]) && $fetched_data["time"] == 'year') {
            $fetched_data['ex_time'] = $fetched_data['ex_time'] * 365;
            if (!empty($fetched_data["time_count"]) && is_numeric($fetched_data["time_count"]) && $fetched_data["time_count"] > 0) {
                $fetched_data['ex_time']  = $fetched_data['ex_time'] * $fetched_data["time_count"];
            }
        }
        else if (!empty($fetched_data["time"]) && $fetched_data["time"] == 'unlimited') {
            $fetched_data['ex_time'] = 0;
        }

        $fetched_data['html_icon'] = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-star" style="background: linear-gradient( -43deg, #9135fa 27%, #bc37e0 66%, #b723de 82%, #b10edb 100%);"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg>';
        if (empty($_COOKIE['mode'])) {
            if (!empty($fetched_data["image"])) {
                $fetched_data['html_icon'] = '<div><img src="'.$fetched_data["image"].'" class="pro_packages_icon"></div>';
            }
            elseif (!empty($fetched_data["night_image"])) {
                $fetched_data['html_icon'] = '<div><img src="'.$fetched_data["night_image"].'" class="pro_packages_icon"></div>';
            }
            else{
                $fetched_data['html_icon'] = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-star" style="background: linear-gradient( -43deg, #9135fa 27%, #bc37e0 66%, #b723de 82%, #b10edb 100%);"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg>';
            }
        }
        elseif (!empty($_COOKIE['mode']) && $_COOKIE['mode'] == 'day' && !empty($fetched_data["image"])) {
            $fetched_data['html_icon'] = '<div><img src="'.$fetched_data["image"].'" class="pro_packages_icon"></div>';
        }
        elseif (!empty($_COOKIE['mode']) && $_COOKIE['mode'] == 'night' && !empty($fetched_data["night_image"])) {
            $fetched_data['html_icon'] = '<div><img src="'.$fetched_data["night_image"].'" class="pro_packages_icon"></div>';
        }
        else if ($fetched_data['id'] == 1) {
            $fetched_data['html_icon'] = '<span style="color: '.(!empty($fetched_data['color']) ? $fetched_data['color'] : "#4c7737").'"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-star" style="background: linear-gradient( -43deg, #9135fa 27%, #bc37e0 66%, #b723de 82%, #b10edb 100%);"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg></span>';

        }



        $data[$fetched_data["id"]] = $fetched_data;

    }
    return $data;
}
function canUseFeature($user_id,$key)
{
    global $pt, $sqlConnect,$db,$lang_array;
    $user = $db->where('id',PT_Secure($user_id))->getOne(T_USERS);
    $value = $pt->manage_pro_features[$key];
    if (!empty($user)) {
        if ($pt->config->{$key} == 'all') {
            return true;
        }
        if ($user->admin) {
            return true;
        }
        if ($pt->config->{$key} == 'admin' && !$user->admin) {
            return false;
        }
        if ($pt->config->{$key} == 'pro' && !$user->is_pro) {
            return false;
        }
        if ($pt->config->{$key} == 'pro' && $user->is_pro && !empty($pt->pro_packages[$user->pro_type]) && $pt->pro_packages[$user->pro_type][$value] != 1) {
            return false;
        }
        return true;
    }
    return false;
}
function createCashfreeOrder($data = [])
{
    global $pt, $sqlConnect,$db,$lang_array;

    $customer_id = "customer" . uniqid();

    $info = array(
        'order_amount' => $data['amount'],
        'order_currency' => 'INR'
    );
    $info['customer_details'] = array(
        'customer_id' => $customer_id,
        'customer_email' => $data['email'],
        'customer_phone' => $data['phone']
    );
    $info['order_meta'] = array(
        'return_url' => $data['return_url'],
        'notify_url' => $data['notify_url'],
    );


    $ch = curl_init();

    $url = 'https://sandbox.cashfree.com';
    if ($pt->config->cashfree_mode == 'live') {
        $url = 'https://api.cashfree.com';
    }

    curl_setopt($ch, CURLOPT_URL, $url . '/pg/orders');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($info));

    $headers = array();
    $headers[] = 'Accept: application/json';
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'X-Api-Version: 2022-09-01';
    $headers[] = 'X-Client-Id: ' . $pt->config->cashfree_client_key;
    $headers[] = 'X-Client-Secret: ' . $pt->config->cashfree_secret_key;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }
    curl_close($ch);
    $result = json_decode($result,true);
    if (!empty($result['payment_session_id'])) {
        return $result['payment_session_id'];
    }
    elseif (!empty($result['message'])) {
        throw new Exception($result['message']);
    }
    else{
        throw new Exception($lang_array['error_msg']);
    }
}

function payCashfreeOrder($data = [])
{
    global $pt, $sqlConnect,$db,$lang_array;

    $card = array(
        'channel' => 'link',
        'card_number' => $data['card_number'],
        'card_holder_name' => $data['card_holder_name'],
        'card_expiry_mm' => $data['card_expiry_mm'],
        'card_expiry_yy' => $data['card_expiry_yy'],
        'card_cvv' => $data['card_cvv']
    );

    $info = array(
        'payment_session_id' => $data['payment_session_id'],
        'payment_method' => array(
            'card' => $card
        )
    );


    $ch = curl_init();

    $url = 'https://sandbox.cashfree.com';
    if ($pt->config->cashfree_mode == 'live') {
        $url = 'https://api.cashfree.com';
    }

    curl_setopt($ch, CURLOPT_URL, $url . '/pg/orders/sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($info));

    $headers = array();
    $headers[] = 'Accept: application/json';
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'X-Api-Version: 2022-09-01';
    $headers[] = 'X-Client-Id: ' . $pt->config->cashfree_client_key;
    $headers[] = 'X-Client-Secret: ' . $pt->config->cashfree_secret_key;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }
    curl_close($ch);
    $result = json_decode($result,true);
    if (!empty($result['data']) && !empty($result['data']['url'])) {
        return $result['data']['url'];
    }
    elseif (!empty($result['message'])) {
        throw new Exception($result['message']);
    }
    else{
        throw new Exception($lang_array['error_msg']);
    }
}

function getCashfreeOrder($order_id = '')
{
    global $pt, $sqlConnect,$db,$lang_array;

    $ch = curl_init();

    $url = 'https://sandbox.cashfree.com';
    if ($pt->config->cashfree_mode == 'live') {
        $url = 'https://api.cashfree.com';
    }

    curl_setopt($ch, CURLOPT_URL, $url . '/pg/orders/' . $order_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');


    $headers = array();
    $headers[] = 'Accept: application/json';
    $headers[] = 'X-Api-Version: 2022-09-01';
    $headers[] = 'X-Client-Id: ' . $pt->config->cashfree_client_key;
    $headers[] = 'X-Client-Secret: ' . $pt->config->cashfree_secret_key;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }
    curl_close($ch);
    $result = json_decode($result,true);
    if (!empty($result['order_status']) && $result['order_status'] == 'PAID' && !empty($result['order_amount'])) {
        return $result['order_amount'];
    }
    elseif (!empty($result['message'])) {
        throw new Exception($result['message']);
    }
    else{
        throw new Exception($lang_array['error_msg']);
    }
}

function updateDashboardData($type='This Year')
{
    global $pt, $sqlConnect,$db,$lang_array;

    $this_start = strtotime("1 January ".date('Y')." 12:00am");
    $this_end = strtotime("31 December ".date('Y')." 11:59pm");
    $code = 'm';
    $array = array('01' => 0 ,'02' => 0 ,'03' => 0 ,'04' => 0 ,'05' => 0 ,'06' => 0 ,'07' => 0 ,'08' => 0 ,'09' => 0 ,'10' => 0 ,'11' => 0 ,'12' => 0);

    if ($type == 'Today' || $type == 'Yesterday') {
        $array = array('00' => 0 ,'01' => 0 ,'02' => 0 ,'03' => 0 ,'04' => 0 ,'05' => 0 ,'06' => 0 ,'07' => 0 ,'08' => 0 ,'09' => 0 ,'10' => 0 ,'11' => 0 ,'12' => 0 ,'13' => 0 ,'14' => 0 ,'15' => 0 ,'16' => 0 ,'17' => 0 ,'18' => 0 ,'19' => 0 ,'20' => 0 ,'21' => 0 ,'22' => 0 ,'23' => 0);
        $this_start = strtotime(date('M')." ".date('d').", ".date('Y')." 12:00am");
        $this_end = strtotime(date('M')." ".date('d').", ".date('Y')." 11:59pm");
        if ($type == 'Yesterday') {
            $this_start = strtotime(date('M')." ".date('d',strtotime("-1 days")).", ".date('Y')." 12:00am");
            $this_end = strtotime(date('M')." ".date('d',strtotime("-1 days")).", ".date('Y')." 11:59pm");
        }
        $code = 'H';
    }
    elseif ($type == 'This Week') {
        $time = strtotime(date('l').", ".date('M')." ".date('d').", ".date('Y'));

        if (date('l') == 'Saturday') {
            $this_start = strtotime(date('M')." ".date('d').", ".date('Y')." 12:00am");
        }
        else{
            $this_start = strtotime('last saturday, 12:00am', $time);
        }

        if (date('l') == 'Friday') {
            $this_end = strtotime(date('M')." ".date('d').", ".date('Y')." 11:59pm");
        }
        else{
            $this_end = strtotime('next Friday, 11:59pm', $time);
        }
        
        $array = array('Saturday' => 0 , 'Sunday' => 0 , 'Monday' => 0 , 'Tuesday' => 0 , 'Wednesday' => 0 , 'Thursday' => 0 , 'Friday' => 0);
        $code = 'l';
    }
    elseif ($type == 'This Month' || $type == 'Last Month') {
        $this_start = strtotime("1 ".date('M')." ".date('Y')." 12:00am");
        $this_end = strtotime(cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y'))." ".date('M')." ".date('Y')." 11:59pm");
        $array = array_fill(1, cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y')),0);
        $month_days = cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y'));
        if ($type == 'Last Month') {
            $this_start = strtotime("1 ".date('M',strtotime("-1 month"))." ".date('Y')." 12:00am");
            $this_end = strtotime(cal_days_in_month(CAL_GREGORIAN, date('m',strtotime("-1 month")), date('Y'))." ".date('M',strtotime("-1 month"))." ".date('Y')." 11:59pm");
            $array = array_fill(1, cal_days_in_month(CAL_GREGORIAN, date('m',strtotime("-1 month")), date('Y')),0);
            $month_days = cal_days_in_month(CAL_GREGORIAN, date('m',strtotime("-1 month")), date('Y'));
        }
        if (cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y')) == 31) {
            $array = array('01' => 0 ,'02' => 0 ,'03' => 0 ,'04' => 0 ,'05' => 0 ,'06' => 0 ,'07' => 0 ,'08' => 0 ,'09' => 0 ,'10' => 0 ,'11' => 0 ,'12' => 0 ,'13' => 0 ,'14' => 0 ,'15' => 0 ,'16' => 0 ,'17' => 0 ,'18' => 0 ,'19' => 0 ,'20' => 0 ,'21' => 0 ,'22' => 0 ,'23' => 0,'24' => 0 ,'25' => 0 ,'26' => 0 ,'27' => 0 ,'28' => 0 ,'29' => 0 ,'30' => 0 ,'31' => 0);
        }elseif (cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y')) == 30) {
            $array = array('01' => 0 ,'02' => 0 ,'03' => 0 ,'04' => 0 ,'05' => 0 ,'06' => 0 ,'07' => 0 ,'08' => 0 ,'09' => 0 ,'10' => 0 ,'11' => 0 ,'12' => 0 ,'13' => 0 ,'14' => 0 ,'15' => 0 ,'16' => 0 ,'17' => 0 ,'18' => 0 ,'19' => 0 ,'20' => 0 ,'21' => 0 ,'22' => 0 ,'23' => 0,'24' => 0 ,'25' => 0 ,'26' => 0 ,'27' => 0 ,'28' => 0 ,'29' => 0 ,'30' => 0);
        }elseif (cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y')) == 29) {
            $array = array('01' => 0 ,'02' => 0 ,'03' => 0 ,'04' => 0 ,'05' => 0 ,'06' => 0 ,'07' => 0 ,'08' => 0 ,'09' => 0 ,'10' => 0 ,'11' => 0 ,'12' => 0 ,'13' => 0 ,'14' => 0 ,'15' => 0 ,'16' => 0 ,'17' => 0 ,'18' => 0 ,'19' => 0 ,'20' => 0 ,'21' => 0 ,'22' => 0 ,'23' => 0,'24' => 0 ,'25' => 0 ,'26' => 0 ,'27' => 0 ,'28' => 0 ,'29' => 0);
        }elseif (cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y')) == 28) {
            $array = array('01' => 0 ,'02' => 0 ,'03' => 0 ,'04' => 0 ,'05' => 0 ,'06' => 0 ,'07' => 0 ,'08' => 0 ,'09' => 0 ,'10' => 0 ,'11' => 0 ,'12' => 0 ,'13' => 0 ,'14' => 0 ,'15' => 0 ,'16' => 0 ,'17' => 0 ,'18' => 0 ,'19' => 0 ,'20' => 0 ,'21' => 0 ,'22' => 0 ,'23' => 0,'24' => 0 ,'25' => 0 ,'26' => 0 ,'27' => 0 ,'28' => 0);
        }
        $code = 'd';
    }


    $users_array = $array;
    $posts_array = $array;
    $videos_array = $array;
    $comments_array = $array;
    $likes_array = $array;
    $dislikes_array = $array;

    $users = $db->where('time',$this_start,'>=')->where('time',$this_end,'<=')->get(T_USERS);
    $posts = $db->where('time',$this_start,'>=')->where('time',$this_end,'<=')->get(T_POSTS);
    $videos = $db->where('time',$this_start,'>=')->where('time',$this_end,'<=')->get(T_VIDEOS);
    $comments = $db->where('time',$this_start,'>=')->where('time',$this_end,'<=')->get(T_COMMENTS);
    $likes = $db->where('time',$this_start,'>=')->where('time',$this_end,'<=')->where('type',1)->get(T_DIS_LIKES);
    $dislikes = $db->where('time',$this_start,'>=')->where('time',$this_end,'<=')->where('type',2)->get(T_DIS_LIKES);


    foreach ($users as $key => $value) {
        $day = date($code,$value->time);
        if (in_array($day, array_keys($users_array))) {
            $users_array[$day] += 1; 
        }
    }
    foreach ($posts as $key => $value) {
        $day = date($code,$value->time);
        if (in_array($day, array_keys($users_array))) {
            $posts_array[$day] += 1; 
        }
    }
    foreach ($videos as $key => $value) {
        $day = date($code,$value->time);
        if (in_array($day, array_keys($users_array))) {
            $videos_array[$day] += 1; 
        }
    }
    foreach ($comments as $key => $value) {
        $day = date($code,$value->time);
        if (in_array($day, array_keys($users_array))) {
            $comments_array[$day] += 1; 
        }
    }
    foreach ($likes as $key => $value) {
        $day = date($code,$value->time);
        if (in_array($day, array_keys($users_array))) {
            $likes_array[$day] += 1; 
        }
    }
    foreach ($dislikes as $key => $value) {
        $day = date($code,$value->time);
        if (in_array($day, array_keys($users_array))) {
            $dislikes_array[$day] += 1; 
        }
    }

    return [
        'users_array' => implode(', ', $users_array),
        'posts_array' => implode(', ', $posts_array),
        'videos_array' => implode(', ', $videos_array),
        'comments_array' => implode(', ', $comments_array),
        'likes_array' => implode(', ', $likes_array),
        'dislikes_array' => implode(', ', $dislikes_array),
    ];
}

function updateDashboardDB($type='This Year',$data=[])
{
    global $pt, $sqlConnect,$db,$lang_array;

    $exist = $db->where('name',$type)->getValue(T_DASHBOARD_REPORTS,'COUNT(*)');
    if ($exist > 0) {
        $db->where('name',$type)->update(T_DASHBOARD_REPORTS,[
            'value' => json_encode($data)
        ]);
    }
    else{
        $db->insert(T_DASHBOARD_REPORTS,[
            'name' => $type,
            'value' => json_encode($data)
        ]);
    }
}
function createBackupCodes($count = 10)
{
    $backupCodes = array();
    for ($i = 1; $i <= 10; $i++) {
        $backupCodes[] = rand(111111,999999);
    }
    return $backupCodes;
}
function createBackupCodesFile($backupCodes,$fileName)
{
    $fp = fopen('php://output', 'w');
    array_map(function ($code) use ($fp) {
        fputcsv($fp, array($code));
    }, $backupCodes);
    fclose($fp);
}
function getTopChannelsForThisMonth()
{
    global $pt, $sqlConnect,$db,$lang_array;

    $featured = '';
    $featured_list_html = '';
    $featuredIds = [];

    $start = strtotime("1 ".date('M')." ".date('Y')." 12:00am");
    $end = strtotime(cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y'))." ".date('M')." ".date('Y')." 11:59pm");

    $videos = $db->rawQuery('SELECT u.user_id AS user_id , v.video_id, COUNT(*) AS count FROM '.T_VIEWS.' v ,'.T_VIDEOS.' u WHERE v.time >= '.$start.' AND v.time <= '.$end.' AND u.id = v.video_id AND u.user_id NOT IN ('.implode(",", $pt->blocked_array).') GROUP BY u.user_id ORDER BY count DESC LIMIT 5');

    foreach ($videos as $key => $value) {
        $views_count = number_format($value->count);
        $views_ = $value->count;
        $user = PT_UserData($value->user_id);

        if (!empty($user)) {

            $featuredIds[] = $user->id;

            if (strlen($user->name) > 25) {
                $user->name = mb_substr($user->name, 0,20).'..';
            }
            $pt->userData = $user;

            if ($key == 0) {
                $featured = PT_LoadPage('popular_channels/featured', array(
                    'ID' => $user->id,
                    'USER_DATA' => $user,
                    'VIEWS' => $views_count,
                    'VIEWS_COUNT' => $views_,
                    'SUB' => $user->subscribe_count,
                    'featured_list_html' => $featured_list_html,
                    'ACTIVE_TIME' => (!empty($user->active_time) && $user->active_time > 0 ? secondsToTime($user->active_time) : "0 sec"),
                    'SUBSCIBE_BUTTON' => PT_GetSubscribeButton($user->id),
                ));
            }
            else{
                $featured_list_html .= PT_LoadPage('popular_channels/featured_list', array(
                    'ID' => $user->id,
                    'USER_DATA' => $user,
                    'VIEWS' => $views_count,
                    'VIEWS_COUNT' => $views_,
                    'SUB' => $user->subscribe_count,
                    'ACTIVE_TIME' => (!empty($user->active_time) && $user->active_time > 0 ? secondsToTime($user->active_time) : "0 sec")
                ));
            }
        }
    }

    $featured = str_replace('{featured_list_html}', $featured_list_html, $featured);

    return [
        'featured' => $featured,
        'featuredIds' => $featuredIds
    ];
}


function getYotubeRapidAPIData($id='')
{
    global $pt;

    $yt_api_url = trim((isset($pt->config->yt_api_url) && !empty($pt->config->yt_api_url)) ? $pt->config->yt_api_url : '', '/');
    if (empty($yt_api_url)) {
        return '';
    }

    if (empty($id)) {
        return '';
    }

    if (strpos($id, 'youtu') !== false) {
        preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|shorts/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $id, $match);
        if (!empty($match[1])) {
            $id = $match[1];
        }
    }

    $response = connect_to_url($yt_api_url . '/api/video/download?id=' . rawurlencode($id), array('hmac' => (isset($pt->config->yt_api_token) ? $pt->config->yt_api_token : '')));
    if (empty($response)) {
        return '';
    }

    $json = json_decode($response, true);
    if (!empty($json['success']) && !empty($json['downloadUrl'])) {
        return $json['downloadUrl'];
    }
    return '';
}

function downloadYPVideo($url='')
{
    $ch = curl_init();
    $options = array(
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 5.0; SM-G900P Build/LRX21T) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.111 Mobile Safari/537.36',
        CURLOPT_ENCODING       => "utf-8",
        CURLOPT_AUTOREFERER    => false,
        CURLOPT_COOKIEJAR      => 'cookie.txt',
        CURLOPT_COOKIEFILE     => 'cookie.txt',
        CURLOPT_CONNECTTIMEOUT => 0,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_MAXREDIRS      => 10,
    );
    curl_setopt_array( $ch, $options );
    $data = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'status' => $httpcode,
        'data' => $data,
    ];
}

function PT_EnsureImportQueueTable()
{
    global $sqlConnect;

    $sql = "CREATE TABLE IF NOT EXISTS `" . T_IMPORT_QUEUE . "` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `video_id` int(11) NOT NULL DEFAULT '0',
        `source` varchar(20) NOT NULL DEFAULT '',
        `payload` text,
        `status` int(11) NOT NULL DEFAULT '0',
        `attempts` int(11) NOT NULL DEFAULT '0',
        `error` varchar(500) NOT NULL DEFAULT '',
        `time` int(11) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        KEY `video_id` (`video_id`),
        KEY `status` (`status`),
        KEY `source` (`source`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
    @mysqli_query($sqlConnect, $sql);
}

function PT_EnqueueImportJobs($source = 'youtube', $videos = array(), $category_id = 0, $sub_category_id = 0, $user_id = 0)
{
    global $pt, $db;

    PT_EnsureImportQueueTable();

    $queued   = 0;
    $skipped  = 0;

    if (empty($videos) || !is_array($videos)) {
        return array('queued' => 0, 'skipped' => 0);
    }

    foreach ($videos as $key => $data_fro_ajax) {
        if (empty($data_fro_ajax['video_id'])) {
            continue;
        }
        $video_id_  = $data_fro_ajax['video_id'];
        $exists     = false;
        if ($source == 'youtube') {
            $check_for_video = $db->where('youtube', PT_Secure($video_id_))->getOne(T_VIDEOS, array('id', 'video_location'));
            if (!empty($check_for_video) && !empty($check_for_video->id)) {
                // Only skip when a full copy already exists; embed-only rows are
                // re-queued so the worker can download and attach the local copy.
                if (!empty($check_for_video->video_location)) {
                    $exists = true;
                }
            }
        }
        if ($exists) {
            $skipped++;
            continue;
        }
        $payload = array(
            'video_id'          => $video_id_,
            'title'             => (!empty($data_fro_ajax['title'])) ? $data_fro_ajax['title'] : '',
            'description'       => (!empty($data_fro_ajax['description'])) ? $data_fro_ajax['description'] : '',
            'duration'          => (!empty($data_fro_ajax['duration'])) ? $data_fro_ajax['duration'] : '',
            'main_duration'     => (!empty($data_fro_ajax['main_duration'])) ? $data_fro_ajax['main_duration'] : '',
            'thumb'             => (!empty($data_fro_ajax['thumb'])) ? $data_fro_ajax['thumb'] : '',
            'tags'              => (!empty($data_fro_ajax['tags'])) ? $data_fro_ajax['tags'] : '',
            'user_id'           => $user_id,
            'category_id'       => $category_id,
            'sub_category_id'   => $sub_category_id,
        );
        if ($source == 'tiktok') {
            $payload['author']    = (!empty($data_fro_ajax['author'])) ? $data_fro_ajax['author'] : '';
            $payload['video_url'] = (!empty($data_fro_ajax['video_url'])) ? $data_fro_ajax['video_url'] : '';
        }
        $db->insert(T_IMPORT_QUEUE, array(
            'video_id'  => 0,
            'source'    => $source,
            'payload'   => json_encode($payload),
            'status'    => 0,
            'attempts'  => 0,
            'error'     => '',
            'time'      => time()
        ));
        $queued++;
    }

    return array('queued' => $queued, 'skipped' => $skipped);
}

function PT_ProcessImportYouTubeJob($payload = array())
{
    global $pt, $db;

    $video_id_      = (!empty($payload['video_id'])) ? $payload['video_id'] : '';
    $title          = (!empty($payload['title'])) ? $payload['title'] : '';
    $description    = (!empty($payload['description'])) ? $payload['description'] : '';
    $duration       = (!empty($payload['duration'])) ? $payload['duration'] : '';
    $main_duration  = (!empty($payload['main_duration'])) ? $payload['main_duration'] : '';
    $thumb          = (!empty($payload['thumb'])) ? $payload['thumb'] : '';
    $tags           = (!empty($payload['tags'])) ? $payload['tags'] : '';
    $user_id        = (!empty($payload['user_id'])) ? (int) $payload['user_id'] : 0;
    $category_id    = (!empty($payload['category_id'])) ? $payload['category_id'] : 0;
    $sub_category   = (!empty($payload['sub_category_id'])) ? $payload['sub_category_id'] : '';

    if (empty($video_id_)) {
        return array('status' => 400, 'message' => 'Missing video id');
    }
    if (empty($user_id)) {
        $user_id = (!empty($pt->user->id)) ? $pt->user->id : 0;
    }

    $check_for_video = $db->where('youtube', PT_Secure($video_id_))->getOne(T_VIDEOS, array('id', 'video_location'));
    if (!empty($check_for_video) && !empty($check_for_video->id)) {
        // The video already exists. If it's only an embed (no local copy yet),
        // try to download and attach the local copy to the existing row.
        if (empty($check_for_video->video_location)) {
            $existing_video_id = (int) $check_for_video->id;
        } else {
            return array('status' => 200, 'message' => 'Skipped, already exists.');
        }
    }

    $video_id = PT_GenerateKey(15, 15);
    if (strpos($thumb, 'upload/photos/thumbnail') !== false) {
        $thumb = 'upload/photos/thumbnail.jpg';
    }
    $check_for_video = $db->where('video_id', $video_id)->getValue(T_VIDEOS, 'count(*)');
    if ($check_for_video > 0) {
        $video_id = PT_GenerateKey(15, 15);
    }
    $link_regex = '/(http\:\/\/|https\:\/\/|www\.)([^\ ]+)/i';
    $i          = 0;
    preg_match_all($link_regex, PT_Secure($description), $matches);
    foreach ($matches[0] as $match) {
        $match_url           = strip_tags($match);
        $syntax              = '[a]' . urlencode($match_url) . '[/a]';
        $description = str_replace($match, $syntax, $description);
    }

    $data_insert = array(
        'video_id' => $video_id,
        'user_id' => $user_id,
        'title' => PT_Secure($title),
        'description' => PT_Secure($description),
        'tags' => PT_Secure($tags),
        'duration' => $duration,
        'category_id' => $category_id,
        'youtube' => $video_id_,
        'thumbnail' => $thumb,
        'time' => time(),
        'registered' => date('Y') . '/' . intval(date('m'))
    );
    if (!empty($sub_category) && !empty($category_id)) {
        $is_found = $db->where('type', PT_Secure($category_id))->where('lang_key', PT_Secure($sub_category))->getValue(T_LANGS,'COUNT(*)');
        if ($is_found > 0) {
            $data_insert['sub_category'] = PT_Secure($sub_category);
        }
    }
    $update_existing = (!empty($existing_video_id)) ? true : false;
    if ($update_existing) {
        $insert = $existing_video_id;
    } else {
        $insert = $db->insert(T_VIDEOS, $data_insert);
        if (!$insert) {
            return array('status' => 400, 'message' => 'Could not import the video, database error.');
        }
    }

    $YP_url = '';
    try {
        $YP_url = getYotubeRapidAPIData("https://www.youtube.com/watch?v=" . $video_id_);
    } catch (Exception $e) {
        $YP_url = '';
    }
    if (!empty($YP_url)) {
        $videoContent = downloadYPVideo($YP_url);
        if (!empty($videoContent) && $videoContent['status'] == 200) {
            $update = array();
            $video_url = 'upload/videos/' . date('Y') . '/' . date('m').'/' . PT_GenerateKey() . "_video.mp4";
            @file_put_contents($video_url, $videoContent['data']);
            PT_UploadToS3($video_url);
            $update['video_location'] = $video_url;
            $update['converted'] = 1;
            $update['is_short'] = (!empty($main_duration) && checkIfVideoSort($main_duration)) ? 1 : 0;

            $newThumbnail = 'upload/photos/' . date('Y') . '/' . date('m').'/' . PT_GenerateKey() . ".jpg";
            if (filter_var($thumb, FILTER_VALIDATE_URL) !== FALSE && @file_put_contents($newThumbnail, file_get_contents($thumb))) {
                PT_UploadToS3($newThumbnail);
                $update['thumbnail'] = $newThumbnail;
            }
            $db->where('id', $insert)->update(T_VIDEOS, $update);

            if (!empty($update['video_location']) && $update['is_short'] != 1 && $pt->config->ffmpeg_system == 'on' && !empty($pt->config->ffmpeg_binary_file) && file_exists($pt->config->ffmpeg_binary_file)) {
                $video_res = 0;
                try {
                    $getID3   = new getID3;
                    $file     = $getID3->analyze($update['video_location']);
                    if (!empty($file['video']['resolution_x'])) {
                        $video_res = (int) $file['video']['resolution_x'];
                    }
                } catch (Exception $e) {
                    $video_res = 0;
                }
                $check_in_queue = $db->where('video_id', $insert)->getValue(T_QUEUE, 'count(*)');
                if ($check_in_queue == 0) {
                    $db->insert(T_QUEUE, array(
                        'video_id' => $insert,
                        'video_res' => $video_res,
                        'processing' => 0
                    ));
                }
            }
        }
    }

    return array('status' => 200, 'message' => 'Imported.', 'id' => $insert);
}

function PT_ProcessImportTikTokJob($payload = array())
{
    global $pt, $db;

    $video_id_      = (!empty($payload['video_id'])) ? $payload['video_id'] : '';
    $title          = (!empty($payload['title'])) ? $payload['title'] : '';
    $description    = (!empty($payload['description'])) ? $payload['description'] : '';
    $duration       = (!empty($payload['duration'])) ? $payload['duration'] : '';
    $thumb          = (!empty($payload['thumb'])) ? $payload['thumb'] : '';
    $tags           = (!empty($payload['tags'])) ? $payload['tags'] : '';
    $author         = (!empty($payload['author'])) ? $payload['author'] : '';
    $user_id        = (!empty($payload['user_id'])) ? (int) $payload['user_id'] : 0;
    $category_id    = (!empty($payload['category_id'])) ? $payload['category_id'] : 0;
    $sub_category   = (!empty($payload['sub_category_id'])) ? $payload['sub_category_id'] : '';

    if (empty($video_id_) || empty($author)) {
        return array('status' => 400, 'message' => 'Missing video id or author.');
    }
    if (empty($user_id)) {
        $user_id = (!empty($pt->user->id)) ? $pt->user->id : 0;
    }

    $video_id = PT_GenerateKey(15, 15);
    if (strpos($thumb, 'upload/photos/thumbnail') !== false) {
        $thumb = 'upload/photos/thumbnail.jpg';
    }
    $check_for_video = $db->where('video_id', $video_id)->getValue(T_VIDEOS, 'count(*)');
    if ($check_for_video > 0) {
        $video_id = PT_GenerateKey(15, 15);
    }
    $link_regex = '/(http\:\/\/|https\:\/\/|www\.)([^\ ]+)/i';
    $i          = 0;
    preg_match_all($link_regex, PT_Secure($description), $matches);
    foreach ($matches[0] as $match) {
        $match_url           = strip_tags($match);
        $syntax              = '[a]' . urlencode($match_url) . '[/a]';
        $description = str_replace($match, $syntax, $description);
    }

    $video_url = '';
    try {
        $links = GetTiktokVideoDownloadLink('https://www.tiktok.com/@'.$author.'/video/'.$video_id_);
        if (!empty($links) && !empty($links['status']) && $links['status'] == 200) {
            $video_url = SaveTiktokVideo($links['video_url']);
        }
    } catch (Exception $e) {
        $video_url = '';
    }
    if (empty($video_url)) {
        return array('status' => 400, 'message' => 'Could not download the TikTok video.');
    }

    $new_thumbnail = 'upload/photos/' . date('Y') . '/' . date('m').'/' . PT_GenerateKey() . ".jpg";
    if (!empty($thumb) && filter_var($thumb, FILTER_VALIDATE_URL) !== FALSE && @file_put_contents($new_thumbnail, file_get_contents($thumb))) {
        PT_UploadToS3($new_thumbnail);
        $thumb = $new_thumbnail;
    }

    $data_insert = array(
        'video_id' => $video_id,
        'user_id' => $user_id,
        'title' => PT_Secure($title),
        'description' => PT_Secure($description),
        'tags' => PT_Secure($tags),
        'duration' => $duration,
        'category_id' => $category_id,
        'video_location' => $video_url,
        'converted' => 1,
        'is_short' => 1,
        'thumbnail' => $thumb,
        'time' => time(),
        'registered' => date('Y') . '/' . intval(date('m'))
    );
    if (!empty($sub_category) && !empty($category_id)) {
        $is_found = $db->where('type', PT_Secure($category_id))->where('lang_key', PT_Secure($sub_category))->getValue(T_LANGS,'COUNT(*)');
        if ($is_found > 0) {
            $data_insert['sub_category'] = PT_Secure($sub_category);
        }
    }
    $insert = $db->insert(T_VIDEOS, $data_insert);
    if (!$insert) {
        return array('status' => 400, 'message' => 'Could not import the video, database error.');
    }

    return array('status' => 200, 'message' => 'Imported.', 'id' => $insert);
}

function PT_ProcessImportQueue($limit = 5)
{
    global $pt, $db;

    PT_EnsureImportQueueTable();

    $limit = (int) $limit;
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 20) {
        $limit = 20;
    }

    // Reset jobs stuck in processing state (e.g. crashed worker) back to pending.
    $db->where('status', 1)->where('time', time() - (60 * 30), '<')->update(T_IMPORT_QUEUE, array('status' => 0));

    $jobs = $db->where('status', 0)->orderBy('id', 'ASC')->get(T_IMPORT_QUEUE, $limit);
    if (empty($jobs)) {
        return array('processed' => 0);
    }

    $processed = 0;
    foreach ($jobs as $job) {
        // Claim the job atomically so two workers can't process the same row.
        $db->rawQuery("UPDATE " . T_IMPORT_QUEUE . " SET `status` = 1, `time` = " . time() . " WHERE `id` = " . (int) $job->id . " AND `status` = 0");
        if ((int) $db->count == 0) {
            continue;
        }
        $processed++;
        $payload = array();
        if (!empty($job->payload)) {
            $payload = json_decode($job->payload, true);
            if (!is_array($payload)) {
                $payload = array();
            }
        }
        try {
            if ($job->source == 'tiktok') {
                $result = PT_ProcessImportTikTokJob($payload);
            } else {
                $result = PT_ProcessImportYouTubeJob($payload);
            }
            if ($result['status'] != 200) {
                throw new Exception($result['message']);
            }
            $imported_id = (!empty($result['id'])) ? (int) $result['id'] : 0;
            $db->where('id', $job->id)->update(T_IMPORT_QUEUE, array('status' => 2, 'video_id' => $imported_id, 'time' => time()));
        } catch (Exception $e) {
            $attempts   = (int) $job->attempts + 1;
            $new_status = ($attempts >= 3) ? 3 : 0;
            $db->where('id', $job->id)->update(T_IMPORT_QUEUE, array(
                'status' => $new_status,
                'attempts' => $attempts,
                'error' => substr($e->getMessage(), 0, 500),
                'time' => time()
            ));
        }
    }

    return array('processed' => $processed);
}

function PT_ImportQueueStats()
{
    global $pt, $db;

    PT_EnsureImportQueueTable();

    $stats = array(
        'pending'    => 0,
        'processing' => 0,
        'done'       => 0,
        'failed'     => 0
    );
    try {
        $rows = $db->rawQuery("SELECT `status`, COUNT(*) as c FROM " . T_IMPORT_QUEUE . " GROUP BY `status`");
        foreach ($rows as $row) {
            if ($row->status == 0) {
                $stats['pending'] = (int) $row->c;
            } elseif ($row->status == 1) {
                $stats['processing'] = (int) $row->c;
            } elseif ($row->status == 2) {
                $stats['done'] = (int) $row->c;
            } elseif ($row->status == 3) {
                $stats['failed'] = (int) $row->c;
            }
        }
    } catch (Exception $e) {
        $stats = array('pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0);
    }

    $stats['errors'] = array();
    try {
        $errors = $db->where('status', 3)->where('error', '', '!=')->orderBy('id', 'DESC')->get(T_IMPORT_QUEUE, 10, array('source', 'error', 'time'));
        foreach ($errors as $error) {
            $stats['errors'][] = array(
                'source' => $error->source,
                'error'  => $error->error,
                'time'   => $error->time
            );
        }
    } catch (Exception $e) {
        $stats['errors'] = array();
    }

    return $stats;
}

function cleanConfigData()
{
    global $pt;

    foreach ($pt->encryptedKeys as $key => $value) {
        if (in_array($value, array_keys((array) $pt->config))) {
            $pt->config->{$value} = '';
        }
    }
}

function decryptConfigData()
{
    global $pt,$siteEncryptKey;

    foreach ($pt->encryptedKeys as $key => $value) {
        if (in_array($value, array_keys((array) $pt->config)) && strpos($pt->config->{$value},'$Ap1_') !== false) {
            $tx = str_replace('$Ap1_', '', $pt->config->{$value});
            $pt->config->{$value} = openssl_decrypt($tx, "AES-128-ECB", $siteEncryptKey);
        }
    }
}

function convertVideoUsingFFMPEG($data)
{
    global $pt, $sqlConnect,$db,$lang_array;

    $shell     = shell_exec($data['ffmpeg_b']." -y -i ".$data['video_file_full_path']." -vcodec libx264 -preset {$pt->config->convert_speed} -filter:v scale=".$data['scale'].":-2 -crf 26 ".$data['video_output_full_path']." 2>&1");

    $upload_s3 = PT_UploadToS3($data['filepath']);

    $db->where('id', $data['video_id'])->update(T_VIDEOS, array(
        'converted' => 1,
        $data['col'] => 1,
        'video_location' => $data['filepath']
    ));
}

function createDemoVideo($data)
{
    global $pt, $sqlConnect,$db,$lang_array;

    $shell     = shell_exec($data['ffmpeg_b']." ".$data['video_time']." -y -i ".$data['video_file_full_path']." -vcodec libx264 -preset {$pt->config->convert_speed} -filter:v scale=".$data['scale'].":-2 -crf 26 ".$data['demo_video_full_path']." 2>&1");

    $upload_s3 = PT_UploadToS3($data['demo_video']);

    $db->where('id', $data['video_id'])->update(T_VIDEOS, array(
        'demo' => $data['demo_video']
    ));
}

function createDemoSellVideo($data)
{
    global $pt, $sqlConnect,$db,$lang_array;

    $shell     = shell_exec($data['ffmpeg_b']." -i ".$data['video_file_full_path']." -i ".$data['water']." -filter_complex \"[1]geq=r='r(X,Y)':a='0.5*alpha(X,Y)'[a];[0][a]overlay=(W-w)/2:(H-h)/2\" ".$data['demo_video']);

    $upload_s3 = PT_UploadToS3($data['demo_video']);

    $db->where('id', $data['video_id'])->update(T_VIDEOS, array(
        'demo' => $data['demo_video']
    ));
}

function createGifVideo($data)
{
    global $pt, $sqlConnect,$db,$lang_array;

    $shell     = shell_exec($data['ffmpeg_b']." ".$data['gif_video_time']." -y -i ".$data['video_file_full_path']." -vcodec libx264 -preset {$pt->config->convert_speed} -filter:v scale=3840:-2 -crf 26 ".$data['gif_video_full_path']." 2>&1");

    $upload_s3 = PT_UploadToS3($data['gif_video']);

    $db->where('id', $data['video_id'])->update(T_VIDEOS, array(
        'gif' => $data['gif_video']
    ));
}

function turnOffVideoQuality($video_res)
{
    global $pt, $sqlConnect,$db,$lang_array;

    if ($video_res >= 3840 && $pt->config->p4096 == "on") {
        $pt->config->p2048 = "off";
        $pt->config->p1080 = "off";
        $pt->config->p720 = "off";
        $pt->config->p480 = "off";
        $pt->config->p360 = "off";
        $pt->config->p240 = "off";
    }
    else if ($video_res >= 2048 && $pt->config->p2048 == "on") {
        $pt->config->p4096 = "off";
        $pt->config->p1080 = "off";
        $pt->config->p720 = "off";
        $pt->config->p480 = "off";
        $pt->config->p360 = "off";
        $pt->config->p240 = "off";
    }
    else if ($video_res >= 1920 && $pt->config->p1080 == "on") {
        $pt->config->p4096 = "off";
        $pt->config->p2048 = "off";
        $pt->config->p720 = "off";
        $pt->config->p480 = "off";
        $pt->config->p360 = "off";
        $pt->config->p240 = "off";
    }
    else if ($video_res >= 1280 && $pt->config->p720 == "on") {
        $pt->config->p4096 = "off";
        $pt->config->p2048 = "off";
        $pt->config->p1080 = "off";
        $pt->config->p480 = "off";
        $pt->config->p360 = "off";
        $pt->config->p240 = "off";
    }
    else if ($video_res >= 854 && $pt->config->p480 == "on") {
        $pt->config->p4096 = "off";
        $pt->config->p2048 = "off";
        $pt->config->p1080 = "off";
        $pt->config->p720 = "off";
        $pt->config->p360 = "off";
        $pt->config->p240 = "off";
    }
    else if ($video_res >= 640 && $pt->config->p360 == "on") {
        $pt->config->p4096 = "off";
        $pt->config->p2048 = "off";
        $pt->config->p1080 = "off";
        $pt->config->p720 = "off";
        $pt->config->p480 = "off";
        $pt->config->p240 = "off";
    }
}
function checkIfVideoSort($youtube_time) {
    $start = new DateTime('@0'); // Unix epoch
    $start->add(new DateInterval($youtube_time));
    $total_seconds = $start->getTimestamp();
    return ($total_seconds < 62);
}

function PT_ProcessQueueVideo($queue_item)
{
    global $pt, $db;

    // Nothing to do when handed an empty/invalid queue row.
    if (empty($queue_item) || empty($queue_item->id) || empty($queue_item->video_id)) {
        return array('status' => 'skipped', 'message' => 'empty queue item');
    }

    // Atomically claim the row so two workers (or cron + worker) can't encode
    // the same video at once. Rows already claimed stay untouched until the
    // owner deletes them.
    $db->rawQuery("UPDATE " . T_QUEUE . " SET `processing` = 1 WHERE `id` = " . (int) $queue_item->id . " AND `processing` = 0");
    if ((int) $db->count == 0) {
        return array('status' => 'skipped', 'message' => 'already processing');
    }

    $video = $db->where("id", (int) $queue_item->video_id)->getOne(T_VIDEOS);
    if (empty($video)) {
        $db->where("video_id", (int) $queue_item->video_id)->delete(T_QUEUE);
        return array('status' => 'skipped', 'message' => 'video missing');
    }

    $video_id = $video->id;

    // turnOffVideoQuality() mutates $pt->config permanently; snapshot the
    // quality flags so a long-running worker doesn't leak one video's state
    // into the next.
    $quality_flags = array('p4096', 'p2048', 'p1080', 'p720', 'p480', 'p360', 'p240');
    $quality_snapshot = array();
    foreach ($quality_flags as $flag) {
        $quality_snapshot[$flag] = (isset($pt->config->{$flag})) ? $pt->config->{$flag} : 'on';
    }

    try {
        $video_res      = $queue_item->video_res;
        $ffmpeg_b       = $pt->config->ffmpeg_binary_file;
        $filepath       = explode(".", $video->video_location)[0];
        $full_dir       = dirname(__DIR__, 2);
        $video_file_full_path = $full_dir . "/" . $video->video_location;

        $video_time     = '';
        $demo_video     = '';
        $gif_video      = '';
        $gif_time       = 3;
        $gif_video_time = '-t ' . $gif_time . '  -async 1';
        $have_demo      = false;
        if ($pt->config->demo_video == 'on' && !empty($video->sell_video)) {
            $getID3  = new getID3;
            $analyze = $getID3->analyze($video_file_full_path);
            if (!empty($analyze) && empty($analyze['error']) && !empty($analyze['playtime_seconds']) && $analyze['playtime_seconds'] > 0) {
                $video_time = round((10 * round($analyze['playtime_seconds'], 0)) / 100, 0);
                $video_time = '-t ' . $video_time . '  -async 1';
                $have_demo  = true;
            }
        }

        if ($pt->config->gif_system == 'on') {
            $gif_video = substr($filepath, 0, strpos($filepath, '_video') - 10) . sha1(time()) . "_small_video_.gif";
            $shell     = shell_exec("$ffmpeg_b $gif_video_time -y -i $video_file_full_path " . $full_dir . '/' . $gif_video);
            PT_UploadToS3($gif_video);
            $db->where('id', $video->id);
            $db->update(T_VIDEOS, array(
                'gif' => $gif_video
            ));
        }

        if ($video->is_short == 1) {
            turnOffVideoQuality($video_res);
        }

        if ($pt->config->p240 == "on") {
            $video_output_full_path_240 = $full_dir . "/" . $filepath . "_240p_converted.mp4";

            convertVideoUsingFFMPEG(array(
                'ffmpeg_b' => $ffmpeg_b,
                'video_file_full_path' => $video_file_full_path,
                'video_output_full_path' => $video_output_full_path_240,
                'filepath' => $filepath . "_240p_converted.mp4",
                'video_id' => $video->id,
                'scale' => 426,
                'col' => '240p',
            ));

            if ($pt->config->demo_video == 'on' && empty($demo_video) && $have_demo == true) {
                $demo_video = substr($filepath, 0, strpos($filepath, '_video') - 10) . sha1(time()) . "_video_240p_demo.mp4";

                createDemoVideo(array(
                    'ffmpeg_b' => $ffmpeg_b,
                    'video_time' => $video_time,
                    'video_file_full_path' => $video_file_full_path,
                    'demo_video_full_path' => $full_dir . "/" . $demo_video,
                    'demo_video' => $demo_video,
                    'video_id' => $video->id,
                    'scale' => 426,
                ));
            }
        }

        if ($video->is_stock == 1) {
            $water = $full_dir . "/themes/youplay/img/icon.png";
            $demo_video = substr($filepath, 0, strpos($filepath, '_video') - 10) . sha1(time()) . "_video_demo.mp4";

            createDemoSellVideo(array(
                'ffmpeg_b' => $ffmpeg_b,
                'video_file_full_path' => $video_file_full_path,
                'water' => $water,
                'demo_video' => $demo_video,
                'video_id' => $video->id,
            ));
        }

        if ($video_res >= 3840 && $pt->config->p4096 == "on") {
            $video_output_full_path_4096 = $full_dir . "/" . $filepath . "_4096p_converted.mp4";

            convertVideoUsingFFMPEG(array(
                'ffmpeg_b' => $ffmpeg_b,
                'video_file_full_path' => $video_file_full_path,
                'video_output_full_path' => $video_output_full_path_4096,
                'filepath' => $filepath . "_4096p_converted.mp4",
                'video_id' => $video->id,
                'scale' => 3840,
                'col' => '4096p',
            ));

            if ($pt->config->demo_video == 'on' && empty($demo_video) && $have_demo == true) {
                $demo_video = substr($filepath, 0, strpos($filepath, '_video') - 10) . sha1(time()) . "_video_4096p_demo.mp4";

                createDemoVideo(array(
                    'ffmpeg_b' => $ffmpeg_b,
                    'video_time' => $video_time,
                    'video_file_full_path' => $video_file_full_path,
                    'demo_video_full_path' => $full_dir . "/" . $demo_video,
                    'demo_video' => $demo_video,
                    'video_id' => $video->id,
                    'scale' => 3840,
                ));
            }

            if ($pt->config->gif_system == 'on' && empty($gif_video)) {
                $gif_video = substr($filepath, 0, strpos($filepath, '_video') - 10) . sha1(time()) . "_video_4096p_gif.mp4";

                createGifVideo(array(
                    'ffmpeg_b' => $ffmpeg_b,
                    'gif_video_time' => $gif_video_time,
                    'video_file_full_path' => $video_file_full_path,
                    'gif_video_full_path' => $full_dir . "/" . $gif_video,
                    'video_id' => $video->id,
                ));
            }
        }

        if ($video_res >= 2048 && $pt->config->p2048 == "on") {
            $video_output_full_path_2048 = $full_dir . "/" . $filepath . "_2048p_converted.mp4";

            convertVideoUsingFFMPEG(array(
                'ffmpeg_b' => $ffmpeg_b,
                'video_file_full_path' => $video_file_full_path,
                'video_output_full_path' => $video_output_full_path_2048,
                'filepath' => $filepath . "_2048p_converted.mp4",
                'video_id' => $video->id,
                'scale' => 2048,
                'col' => '2048p',
            ));

            if ($pt->config->demo_video == 'on' && empty($demo_video) && $have_demo == true) {
                $demo_video = substr($filepath, 0, strpos($filepath, '_video') - 10) . sha1(time()) . "_video_2048p_demo.mp4";

                createDemoVideo(array(
                    'ffmpeg_b' => $ffmpeg_b,
                    'video_time' => $video_time,
                    'video_file_full_path' => $video_file_full_path,
                    'demo_video_full_path' => $full_dir . "/" . $demo_video,
                    'demo_video' => $demo_video,
                    'video_id' => $video->id,
                    'scale' => 2048,
                ));
            }
        }

        if (($video_res >= 1920 || $video_res == 0) && $pt->config->p1080 == "on") {
            $video_output_full_path_1080 = $full_dir . "/" . $filepath . "_1080p_converted.mp4";

            convertVideoUsingFFMPEG(array(
                'ffmpeg_b' => $ffmpeg_b,
                'video_file_full_path' => $video_file_full_path,
                'video_output_full_path' => $video_output_full_path_1080,
                'filepath' => $filepath . "_1080p_converted.mp4",
                'video_id' => $video->id,
                'scale' => 1920,
                'col' => '1080p',
            ));

            if ($pt->config->demo_video == 'on' && empty($demo_video) && $have_demo == true) {
                $demo_video = substr($filepath, 0, strpos($filepath, '_video') - 10) . sha1(time()) . "_video_1080p_demo.mp4";

                createDemoVideo(array(
                    'ffmpeg_b' => $ffmpeg_b,
                    'video_time' => $video_time,
                    'video_file_full_path' => $video_file_full_path,
                    'demo_video_full_path' => $full_dir . "/" . $demo_video,
                    'demo_video' => $demo_video,
                    'video_id' => $video->id,
                    'scale' => 1920,
                ));
            }
        }

        if (($video_res >= 1280 || $video_res == 0) && $pt->config->p720 == "on") {
            $video_output_full_path_720 = $full_dir . "/" . $filepath . "_720p_converted.mp4";

            convertVideoUsingFFMPEG(array(
                'ffmpeg_b' => $ffmpeg_b,
                'video_file_full_path' => $video_file_full_path,
                'video_output_full_path' => $video_output_full_path_720,
                'filepath' => $filepath . "_720p_converted.mp4",
                'video_id' => $video->id,
                'scale' => 1280,
                'col' => '720p',
            ));

            if ($pt->config->demo_video == 'on' && empty($demo_video) && $have_demo == true) {
                $demo_video = substr($filepath, 0, strpos($filepath, '_video') - 10) . sha1(time()) . "_video_720p_demo.mp4";

                createDemoVideo(array(
                    'ffmpeg_b' => $ffmpeg_b,
                    'video_time' => $video_time,
                    'video_file_full_path' => $video_file_full_path,
                    'demo_video_full_path' => $full_dir . "/" . $demo_video,
                    'demo_video' => $demo_video,
                    'video_id' => $video->id,
                    'scale' => 1280,
                ));
            }
        }

        if (($video_res >= 854 || $video_res == 0) && $pt->config->p480 == "on") {
            $video_output_full_path_480 = $full_dir . "/" . $filepath . "_480p_converted.mp4";

            convertVideoUsingFFMPEG(array(
                'ffmpeg_b' => $ffmpeg_b,
                'video_file_full_path' => $video_file_full_path,
                'video_output_full_path' => $video_output_full_path_480,
                'filepath' => $filepath . "_480p_converted.mp4",
                'video_id' => $video->id,
                'scale' => 854,
                'col' => '480p',
            ));

            if ($pt->config->demo_video == 'on' && empty($demo_video) && $have_demo == true) {
                $demo_video = substr($filepath, 0, strpos($filepath, '_video') - 10) . sha1(time()) . "_video_480p_demo.mp4";

                createDemoVideo(array(
                    'ffmpeg_b' => $ffmpeg_b,
                    'video_time' => $video_time,
                    'video_file_full_path' => $video_file_full_path,
                    'demo_video_full_path' => $full_dir . "/" . $demo_video,
                    'demo_video' => $demo_video,
                    'video_id' => $video->id,
                    'scale' => 854,
                ));
            }
        }

        if (($video_res >= 640 || $video_res == 0) && $pt->config->p360 == "on") {
            $video_output_full_path_360 = $full_dir . "/" . $filepath . "_360p_converted.mp4";

            convertVideoUsingFFMPEG(array(
                'ffmpeg_b' => $ffmpeg_b,
                'video_file_full_path' => $video_file_full_path,
                'video_output_full_path' => $video_output_full_path_360,
                'filepath' => $filepath . "_360p_converted.mp4",
                'video_id' => $video->id,
                'scale' => 640,
                'col' => '360p',
            ));

            if ($pt->config->demo_video == 'on' && empty($demo_video) && $have_demo == true) {
                $demo_video = substr($filepath, 0, strpos($filepath, '_video') - 10) . sha1(time()) . "_video_360p_demo.mp4";

                createDemoVideo(array(
                    'ffmpeg_b' => $ffmpeg_b,
                    'video_time' => $video_time,
                    'video_file_full_path' => $video_file_full_path,
                    'demo_video_full_path' => $full_dir . "/" . $demo_video,
                    'demo_video' => $demo_video,
                    'video_id' => $video->id,
                    'scale' => 640,
                ));
            }
        }

        if (file_exists($video->video_location)) {
            unlink($video->video_location);
        }
        $db->where("video_id", $video->id)->delete(T_QUEUE);
        pt_push_channel_notifiations($video_id);

        return array('status' => 'ok', 'video_id' => (int) $video_id);
    } catch (Exception $e) {
        $db->where("video_id", $video_id)->delete(T_QUEUE);
        if (file_exists($video->video_location)) {
            unlink($video->video_location);
        }
        return array('status' => 'error', 'video_id' => (int) $video_id, 'message' => $e->getMessage());
    } finally {
        // Restore quality flags so one video's state never poisons the next.
        foreach ($quality_snapshot as $flag => $value) {
            $pt->config->{$flag} = $value;
        }
    }
}
