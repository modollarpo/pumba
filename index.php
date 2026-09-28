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


require_once('./assets/init.php');

if (!empty($auto_redirect)) {
    $checkHTTPS = checkHTTPS();
    $isURLSSL = strpos($site_url, 'https');

    if ($isURLSSL !== false) {
        if (empty($checkHTTPS)) {
            header("Location: https://" . full_url($_SERVER));
            exit();
        }
    } else if ($checkHTTPS) {
        header("Location: http://" . full_url($_SERVER));
        exit();
    }

    if (strpos($site_url, 'www') !== false) {
        if (!preg_match('/www/', $_SERVER['HTTP_HOST'])) {
            $protocol = ($isURLSSL !== false) ? "https://" : "http://";
            header("Location: $protocol" . full_url($_SERVER));
            exit();
        }
    }

    if (preg_match('/www/', $_SERVER['HTTP_HOST'])) {
        if (strpos($site_url, 'www') === false) {
            $protocol = ($isURLSSL !== false) ? "https://" : "http://";
            header("Location: $protocol" . str_replace("www.", "", full_url($_SERVER)));
            exit();
        }
    }
}

$page = 'home';
if (isset($_GET['link1'])) {
    $page = $_GET['link1'];
}
if ($page == 'finish') {
    $page = 'confirm';
}
if ($pt->config->pop_up_18 == 'on' && (!empty($_COOKIE['pop_up_18']) && $_COOKIE['pop_up_18'] == 'no' && $page != 'age_block' && !IS_LOGGED)) {
    header('Location: ' .PT_Link('age_block'));
    exit();
}
$pt->is_ajax_load = false;
$maintenance_mode = false;
if ( $pt->config->maintenance_mode == 'on' ) {
    if ( IS_LOGGED === false ) {
        $maintenance_mode = true;
        if (!empty($_COOKIE['maintenance_mode']) && $_COOKIE['maintenance_mode'] == 'no') {
            $maintenance_mode = false;
        }
        if(isset($_GET['access']) && $_GET['access'] == 'admin'){
            if (empty($_COOKIE['maintenance_mode'])) {
                setcookie("maintenance_mode", 'no', time() + (10 * 365 * 24 * 60 * 60), '/');
            }
            $maintenance_mode = false;
        }
    } else {
        if ($pt->user->admin == "0") {
            $maintenance_mode = true;
        }
    }

    if( $maintenance_mode === true ){
        $file_location = "./sources/maintenance/content.php";
        if (file_exists($file_location)) {
            require_once $file_location;
        }
    }
}

if (!empty($_GET['ref']) && !empty($_GET['ref_type']) && in_array($_GET['ref_type'], array_keys((array)$pt->config->affiliate_type)) && $pt->config->affiliate_type->{$_GET['ref_type']} == 1 && !IS_LOGGED && $pt->config->affiliate_system == 1) {
    $_GET['ref'] = PT_Secure($_GET['ref']);
    $user  = $db->where('username', $_GET['ref'])->getOne(T_USERS);
    if (!empty($user)) {
        setcookie("ref", $user->username, time() + (10 * 365 * 24 * 60 * 60), "/");
        setcookie("ref_type", PT_Secure($_GET['ref_type']), time() + (10 * 365 * 24 * 60 * 60), "/");
    }
}

if (IS_LOGGED == true) {
    if ($user->last_active < (time() - 60)) {
        $update = $db->where('id', $user->id)->update('users', array(
            'last_active' => time()
        ));
    }
}

if (IS_LOGGED) {
    // if ($pt->config->require_subcription == 'on' && !$pt->user->is_pro && !PT_IsAdmin() && $page == 'watch') {
    //     $page = 'go_pro';
    // }
}
else{
    if ($pt->config->require_subcription == 'on' && $page == 'shorts') {
        $page = 'home';
    }
}


if (!empty($_GET['v'])) {
    $video_short_id = PT_Secure($_GET['v']);
    $get_video = PT_GetVideoByID($video_short_id, 0, 0, 1, 1);
    if (!empty($get_video)) {
        header("Location: $get_video->url");
        exit();
    }
}
if (file_exists("./sources/$page/content.php")) {
    if (IS_LOGGED && !empty($pt->user) && $pt->user->is_pro && !empty($pt->pro_packages[$pt->user->pro_type]) && !empty($pt->pro_packages[$pt->user->pro_type]['max_upload'])) {
        $pt->config->max_upload_all_users = $pt->pro_packages[$pt->user->pro_type]['max_upload'];
        $pt->config->max_upload = $pt->pro_packages[$pt->user->pro_type]['max_upload'];
    }
    if (!empty($_GET)) {
        foreach ($_GET as $key => $value) {
            if (!is_array($value)) {
                $value = preg_replace('/on[^<>=]+=[^<>]*/m', '', $value);
                $_GET[$key] = strip_tags($value);
            }
            else{
                foreach ($value as $keyv => $valuev) {
                    $valuev = preg_replace('/on[^<>=]+=[^<>]*/m', '', $valuev);
                    $value[$keyv] = strip_tags($valuev);
                }
                $_GET[$key] = $value;
            }
        }
    }
    if (!empty($_POST)) {
        foreach ($_POST as $key => $value) {
            if (!is_array($value)) {
                $value = preg_replace('/on[^<>=]+=[^<>]*/m', '', $value);
                $_POST[$key] = strip_tags($value);
            }
            else{
                foreach ($value as $keyv => $valuev) {
                    $valuev = preg_replace('/on[^<>=]+=[^<>]*/m', '', $valuev);
                    $value[$keyv] = strip_tags($valuev);
                }
                $_POST[$key] = $value;
            }
        }
    }
    if ($pt->config->review_embed_videos == 'on') {
        $pt->config->approve_videos = 'on';
    }
    include("./sources/$page/content.php");
}
else{
    header('Location: ' . PT_Link('404'));
    exit;
}

if (empty($pt->content)) {
    include("./sources/404/content.php");
}

$side_header = 'not-logged';

if (IS_LOGGED == true) {
    $side_header = 'loggedin';
}

$announcement_html = '';
$footer            = '';



$og_meta = '';
$og_is_specific = false;
$pt->is_video_page = false;
$pt->seo_thumb = '';
$pt->seo_url = '';
$og_image_default = $pt->config->theme_url . '/img/logo.png';
if (file_exists('./themes/' . $pt->config->theme . '/img/logo.png')) {
    $og_image_default = $pt->config->site_url . '/themes/' . $pt->config->theme . '/img/logo.png';
}
if (in_array($pt->page, array('watch', 'shorts')) && !empty($get_video)) {
    $pt->is_video_page = true;
    $og_is_specific = true;
    $pt->seo_url = $get_video->url;
    $pt->seo_thumb = PT_GetMedia(str_replace('mqdefault', 'maxresdefault', $get_video->thumbnail));
    $og_type = (!empty($get_video->is_movie) && $get_video->is_movie == 1) ? 'video.movie' : 'video.other';
    $og_tw = 'summary_large_image';
    $og_meta = PT_LoadPage('watch/og-meta', array(
        'OG_TITLE' => htmlspecialchars(FilterStripTags($pt->title)),
        'OG_TYPE' => $og_type,
        'OG_URL' => htmlspecialchars($pt->seo_url),
        'OG_IMAGE' => htmlspecialchars($pt->seo_thumb),
        'OG_DESC' => htmlspecialchars(PT_CleanSeoText($pt->description, 300)),
        'TW_CARD' => $og_tw
    ));
}
if ($pt->page == 'read') {
    $pt->article = $article;
    $og_is_specific = true;
    $pt->seo_url = PT_Link('articles/read/' . PT_URLSlug($article->title, $article->id));
    $pt->seo_thumb = PT_GetMedia($article->image);
    $og_meta = PT_LoadPage('watch/og-meta', array(
        'OG_TITLE' => htmlspecialchars(FilterStripTags($pt->title)),
        'OG_TYPE' => 'article',
        'OG_URL' => htmlspecialchars($pt->seo_url),
        'OG_IMAGE' => htmlspecialchars($pt->seo_thumb),
        'OG_DESC' => htmlspecialchars(PT_CleanSeoText($pt->description, 300)),
        'TW_CARD' => 'summary_large_image'
    ));
}
if ($pt->page == 'home') {
    $og_meta .= '<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "' . addslashes($pt->config->name) . '",
    "url": "' . $pt->config->site_url . '",
    "potentialAction": {
        "@type": "SearchAction",
        "target": {
            "@type": "EntryPoint",
            "urlTemplate": "' . $pt->config->site_url . '/search?keyword={search_term_string}"
        },
        "query-input": "required name=search_term_string"
    }
}
</script>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": "' . addslashes($pt->config->name) . '",
    "url": "' . $pt->config->site_url . '",
    "logo": "' . $og_image_default . '"
}
</script>';
}
$pt->lang_attr = 'en';
if (!empty($pt->language) && !empty($pt->iso) && in_array($pt->language, array_keys($pt->iso)) && !empty($pt->iso[$pt->language])) {
    $pt->lang_attr = $pt->iso[$pt->language];
}
$og_url_base = $pt->config->site_url;
if (!empty($pt->page_url_)) {
    $og_url_base = $pt->page_url_;
}
foreach ($pt->langs as $key => $value) {
    if ($pt->langs_status[$value] == 1) {
        $iso = '';
        if (!empty($pt->iso[$value])) {
            $iso = $pt->iso[$value];
        }
        $lang_url = $og_url_base . '?lang=' . $value;
        if ($iso == $pt->lang_attr) {
            $lang_url = $og_url_base;
        }
        $og_meta .= '<link rel="alternate" href="' . $lang_url . '" hreflang="' . $iso . '" />';
    }
}
if ($pt->page != 'login') {
    $langs__footer = $langs;
    $langs_html    = '';
    $langs_modal_html    = '';
    foreach ($langs__footer as $key => $language) {
        if ($pt->langs_status[$language] == 1) {
            $lang_explode = explode('.', $language);
            $language     = $lang_explode[0];
            $language_    = ucfirst($language);
            $langs_html .= '<li><a href="?lang=' . $language . '" rel="nofollow">' . $language_ . '</a></li>';
            $langs_modal_html .=  '<li class="language_select"><a href="?lang=' . $language . '" rel="nofollow" class="' . $language . '">' . $language_ . '</a></li>';
        }
    }
    $pt->langs = $langs_modal_html;
    $footer = PT_LoadPage('footer/content', array(
        'DATE' => date('Y'),
        'LANGS' => $langs_html
    ));
}


/* Get active Announcements */

if ($pt->page != 'timeline') {

    $announcement          = pt_get_announcments();
    if(!empty($announcement)) {
        $announcement_html =  PT_LoadPage("announcements/content",array(
            'ANN_ID'       => $announcement->id,
            'ANN_TEXT'     => PT_Decode($announcement->text),
        ));
    }
}
/* Get active Announcements */
if (!empty($user->id)) {

$pt->subscribers_ = $db->rawQuery("SELECT * FROM ".T_SUBSCRIPTIONS." WHERE subscriber_id = '".$user->id."' AND user_id NOT IN (".implode(',', $pt->blocked_array).") ORDER BY id DESC LIMIT 6");

}

if (!empty($pt->config->seo)) {
    $seo = json_decode($pt->config->seo,true);
    if (in_array($pt->page, array_keys($seo)) && !empty($seo[$page])) {
        $pt->title       = str_replace('{SITE_TITLE}', $pt->config->title, $seo[$page]['title']);
        $pt->title = preg_replace_callback("/{LANG_KEY (.*?)}/", function($m) use ($lang_array) {
            return (isset($lang_array[$m[1]])) ? $lang_array[$m[1]] : '';
        }, $pt->title);
        $pt->description = str_replace('{SITE_DESC}', $pt->config->description, $seo[$page]['meta_description']);
        $pt->keyword     = str_replace('{SITE_KEYWORDS}', $pt->config->keyword, $seo[$page]['meta_keywords']);
    }
}

/* Generic OG/Twitter tags are emitted here rather than alongside the video and
   article tags above, because the custom SEO block above is what produces the
   final page title/description. Emitting them earlier made og:title disagree with
   <title> (e.g. "Articles | SITE" vs "Articles - SITE"). The guard tests
   $og_is_specific rather than empty($og_meta), because the JSON-LD and hreflang
   blocks below already append to $og_meta on every page. */
if (!$og_is_specific) {
    $og_meta = '<meta property="og:site_name" content="' . htmlspecialchars($pt->config->name) . '" />
<meta property="og:locale" content="en_US" />
<meta property="og:type" content="website" />
<meta property="og:url" content="' . (!empty($pt->page_url_) ? htmlspecialchars($pt->page_url_) : htmlspecialchars($pt->config->site_url)) . '" />
<meta property="og:title" content="' . htmlspecialchars($pt->title) . '" />
<meta property="og:image" content="' . htmlspecialchars($og_image_default) . '" />
<meta property="og:description" content="' . htmlspecialchars(PT_CleanSeoText($pt->description, 160)) . '" />
<meta name="twitter:card" content="summary" />
<meta name="twitter:title" content="' . htmlspecialchars($pt->title) . '" />
<meta name="twitter:description" content="' . htmlspecialchars(PT_CleanSeoText($pt->description, 200)) . '" />
<meta name="twitter:image" content="' . htmlspecialchars($og_image_default) . '" />';
}

$getHeaderAds = PT_GetAd('header');
$getFooterAds = PT_GetAd('footer');
if (in_array($pt->page, ['register', 'login', 'shorts', 'latest', 'top', 'trending', 'stock', 'upload-video', 'import-video'])) {
  $getHeaderAds = "";
}
if (in_array($pt->page, ['register', 'login', 'shorts'])) {
  $getFooterAds = "";
}
if (IS_LOGGED === true && $user->is_pro == 1 && $pt->config->go_pro == 'on') {
    $getHeaderAds = "";
    $getFooterAds = "";
}
$final_content = PT_LoadPage('container', array(
    'CONTAINER_TITLE' => $pt->title,
    'CONTAINER_TITLE_DE' => htmlspecialchars($pt->title),
    'CONTAINER_DESC_DE' => htmlspecialchars(PT_CleanSeoText($pt->description, 160)),
    'CONTAINER_DESC' => $pt->description,
    'CONTAINER_KEYWORDS' => $pt->keyword,
    'CONTAINER_CONTENT' => $pt->content,
    'ANNOUNCEMENT'     => $announcement_html,
    'IS_LOGGED' => (IS_LOGGED == true) ? 'data-logged="true"' : '',
    'MAIN_URL' => $pt->actual_link,

    'HEADER_LAYOUT' => PT_LoadPage('header/content', array(
        'SIDE_HEADER' => PT_LoadPage("header/$side_header"),
        'SEARCH_KEYWORD' => (!empty($_GET['keyword'])) ? PT_Secure($_GET['keyword']) : ''
    )),
    'FOOTER_LAYOUT' => $footer,
    'OG_METATAGS' => $og_meta,
    'EXTRA_JS' => PT_LoadPage('extra-js/content'),
    'MODE' => (($pt->mode == 'night') ? 'checked' : ''),

    'RIGHT_AD' => (IS_LOGGED === true && $user->is_pro == 1 && $pt->config->go_pro == 'on') ? '' : PT_GetAd('right_side'),
    'LEFT_AD' => (IS_LOGGED === true && $user->is_pro == 1 && $pt->config->go_pro == 'on') ? '' : PT_GetAd('left_side'),
    'FOOTER_AD' => $getFooterAds,
    'HEADER_AD' => $getHeaderAds,
    'LANG_ATTR' => $pt->lang_attr,
));


echo $final_content;
$db->disconnect();
unset($pt);
?>
