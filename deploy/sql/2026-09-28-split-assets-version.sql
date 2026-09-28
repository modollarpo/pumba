-- Separate the asset cache-buster from the schema version.
--
-- Why
-- ---
-- The `config` table's `version` row was doing two unrelated jobs:
--
--   1. Schema/upgrade tracking. assets/includes/app_start.php:586 hardcodes
--      $pt->config->filesVersion = '3.1.2' (the version of the files on disk),
--      and getStatus() in assets/includes/functions_one.php:3794-3802 errors
--      when filesVersion != version.
--
--   2. Asset cache-busting. Every stylesheet and script is referenced as
--      `?version={{CONFIG version}}` in the theme layouts.
--
-- Bumping `version` to bust a CSS cache therefore makes the site report that
-- the database is running newer code than the files on disk. Assets and schema
-- versions move at completely different rates, so they get separate rows now.
--
-- After this migration, to restyle CSS bump `assets_version` only:
--     UPDATE config SET value = '3.1.5' WHERE name = 'assets_version';
-- ...and leave `version` alone.
--
-- Prerequisite: deploy the code change first. It adds a fallback in
-- PT_GetConfig() so a missing assets_version row degrades to `version` rather
-- than emitting an empty `?version=`. This migration is then safe to run at any
-- time, in either order.
--
-- Safe to run more than once.

-- 1. Restore `version` to the version of the files actually on disk.
--    Only touch this if it has drifted; if you are mid-upgrade to a real
--    release, set it to that release's version instead.
UPDATE `config` SET `value` = '3.1.2' WHERE `name` = 'version';

-- 2. De-duplicate `version`.
--    The shipped dump contains three `version` rows (ids 138, 167 and 171).
--    PT_GetConfig() builds its array as `$data[$config->name] = $config->value`
--    in row order, so with duplicates present the value that wins is whichever
--    row the query returns last. That makes the upgrade check ambiguous.
--    Keep the lowest id and drop the rest.
DELETE `version_1` FROM `config` AS `version_1`
  INNER JOIN `config` AS `version_2`
    ON `version_1`.`name` = 'version'
   AND `version_2`.`name` = 'version'
   AND `version_2`.`id` < `version_1`.`id`;

-- 3. Add the asset cache-buster row, if it is not already there.
INSERT INTO `config` (`name`, `value`)
SELECT 'assets_version', '3.1.2'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `name` FROM `config`) AS `c`
                    WHERE `c`.`name` = 'assets_version');

-- 4. Confirm. Expect exactly one `version` row and one `assets_version` row,
--    both '3.1.2'.
SELECT `name`, `value`, COUNT(*) AS rows_found
  FROM `config`
 WHERE `name` IN ('version', 'assets_version')
 GROUP BY `name`, `value`;
