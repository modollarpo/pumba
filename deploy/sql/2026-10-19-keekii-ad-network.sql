-- Wire the PlayTube pre-roll slot to the KIKI ad network VAST endpoint.
--
-- Why
-- ---
-- sources/watch/content.php:635-671 picks one row from `video_ads`
-- (`WHERE active = 1`, `ORDER BY RAND()`) when config `user_ads` is on, the
-- viewer is not Pro, and no `last_ads_seen` cookie newer than 600s. If that row
-- has a non-empty `vast_xml_link`, the value is handed to the player verbatim
-- as the ad tag:
--
--   playtag content.html:1692-1698   vastAdTagUrl: '{{VAST_URL}}'
--   playtag content.html:1363,1637   vastTag / adTagUrl: "{{VAST_URL}}"
--   playtag content.html:2062        PT_LoadPage('watch/ima') -> ima.html:47
--                                     adsRequest.adTagUrl = '<?php echo($pt->vast_url) ?>'
--
-- PT_LoadPage() substitutes {{...}} with str_replace (functions_general.php:28-38),
-- so the query string survives unescaped and the params below reach the ad server
-- as written.
--
-- The tag URL resolves the placement on the KIKI side:
-- property=play -> publisher `pub_play`, slot=preroll -> `slot_play_preroll`
-- (format video, floor 3.5). Rotation of individual creatives is the ad
-- server's job, so ONE row here is the whole integration -- do not add a row
-- per creative; a `video_ads` row with an empty `vast_xml_link` would make
-- PlayTube fall through to the local `ad_media` / `ad_image` ad paths instead.
--
-- skip_seconds feeds `AD_SKIP` / `AD_SKIP_NUM` in the template, which drives the
-- player's skip countdown and (mediaelement path) `vastTimeout: {{AD_SKIP_NUM}} * 1000`
-- and `var counter = {{AD_SKIP_NUM}}`. It must therefore be > 0 -- leaving it 0
-- emits `vastTimeout: 0` and a 0 counter. 5 matches the VAST skipoffset the
-- network emits for its 15s creatives.
--
-- `ad_link`, `ad_media` and `ad_image` stay empty on purpose: with
-- `vast_xml_link` set, the click-through comes from the VAST XML
-- (network-authoritative `/api/ads/click`), and a non-empty `ad_media` would
-- also enable PlayTube's own stats redirect (`PT_Link('redirect/{id}?type=video')`)
-- as a second click path.
--
-- Safe to run more than once (guarded by NOT EXISTS). The `config` UPDATE only
-- ever writes 'on', which is also the value shipped in playtube.sql.
--
-- Order: run after the base install (imports playtube.sql, which now ships the
-- same row). This file then no-ops on the row and only confirms it.

-- 1. The pre-roll branch is behind config `user_ads`; the ad network is useless
--    without it.
UPDATE `config` SET `value` = 'on' WHERE `name` = 'user_ads';

-- 2. One active VAST row. `id` is auto-assigned so this cannot collide with an
--    existing ad already entered through Admin -> Manage Video Ads.
INSERT INTO `video_ads`
  (`name`, `ad_link`, `ad_media`, `ad_image`, `skip_seconds`, `vast_type`, `vast_xml_link`,
   `views`, `clicks`, `active`, `user_id`)
SELECT 'KIKI network pre-roll', '', '', '', 5, 'vast',
       'https://keekii.net/api/ads/vast?property=play&slot=preroll',
       0, 0, 1, 0
  FROM DUAL
 WHERE NOT EXISTS (
   SELECT 1 FROM (SELECT `vast_xml_link` FROM `video_ads`) AS `v`
    WHERE `v`.`vast_xml_link` = 'https://keekii.net/api/ads/vast?property=play&slot=preroll'
 );

-- Confirm. Expect exactly one row: active = 1, vast_type = 'vast',
-- skip_seconds = 5, ad_media/ad_image/ad_link all empty.
SELECT `id`, `name`, `vast_type`, `skip_seconds`, `active`, `views`, `clicks`,
       `ad_media`, `ad_image`, `vast_xml_link`
  FROM `video_ads`
 WHERE `vast_xml_link` LIKE 'https://keekii.net/api/ads/vast%';

-- Also confirm the ad system is on (the pre-roll branch needs it).
SELECT `name`, `value` FROM `config` WHERE `name` = 'user_ads';
