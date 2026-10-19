-- Add the LANG keys used by the lifted VidBox features in the playtag theme.
--
-- Why
-- ---
-- PlayTube pulls front-end strings from the `langs` table (not from
-- assets/langs/*.php, which only holds category/country lists). The new
-- playtag UI references two keys that do not exist yet:
--
--   * my_library -> title of the header "My Library" dropdown
--                   (themes/playtag/layout/header/content.html)
--   * more_info  -> secondary CTA on the homepage hero
--                   (themes/playtag/layout/home/content.html)
--
-- Until these rows exist, {{LANG my_library}} / {{LANG more_info}} are left
-- literally in the markup. English is seeded here; other languages fall back
-- to the per-language value if/when translated.
--
-- Safe to run more than once (guarded by NOT EXISTS).

INSERT INTO `langs` (`lang_key`, `type`, `english`)
SELECT 'my_library', '', 'My Library'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `lang_key` FROM `langs`) AS `l`
                   WHERE `l`.`lang_key` = 'my_library');

INSERT INTO `langs` (`lang_key`, `type`, `english`)
SELECT 'more_info', '', 'More Info'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `lang_key` FROM `langs`) AS `l`
                   WHERE `l`.`lang_key` = 'more_info');

-- Confirm. Expect one row per key.
SELECT `lang_key`, `english` FROM `langs`
 WHERE `lang_key` IN ('my_library', 'more_info');
