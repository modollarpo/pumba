-- Add the LANG key used by the "Keep Spinning" homepage rail.
--
-- Why
-- ---
-- The new rail on the homepage (sources/home/content.php +
-- themes/playtag/layout/home/content.html) is built from the logged-in user's
-- watch-later list and its title references {{LANG keep_spinning}}. PlayTube
-- renders front-end strings from the `langs` table, so without this row the
-- token is left literally in the markup. English is seeded here; other
-- languages fall back to the per-language value if/when translated.
--
-- Safe to run more than once (guarded by NOT EXISTS).

INSERT INTO `langs` (`lang_key`, `type`, `english`)
SELECT 'keep_spinning', '', 'Keep Spinning'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `lang_key` FROM `langs`) AS `l`
                   WHERE `l`.`lang_key` = 'keep_spinning');

-- Confirm. Expect one row.
SELECT `lang_key`, `english` FROM `langs`
 WHERE `lang_key` = 'keep_spinning';
