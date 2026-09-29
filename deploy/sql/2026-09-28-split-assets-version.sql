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
--
-- Order matters: the cache-buster value must be read BEFORE `version` is
-- overwritten below.

-- 1. Add the asset cache-buster row, if it is not already there, seeded from
--    the value `version` currently holds.
--
--    `version` is at 3.1.4 on the affected install precisely because someone
--    bumped it to force a CSS refresh, so browsers and any CDN in front of the
--    site are holding CSS keyed on ?version=3.1.4. Seeding the schema version
--    (3.1.2) here would hand out a key the client has *already* cached from
--    before the restyle, silently serving the stale stylesheet this change
--    exists to invalidate.
--
--    `ORDER BY id DESC LIMIT 1` deliberately mirrors PT_GetConfig(), which
--    resolves duplicates last-write-wins in row order, so this picks the same
--    value the site is actually serving assets with today.
--
--    Read into assets_version before the UPDATE in step 2 rather than using a
--    `SET @var` session variable: some migration runners execute statements
--    outside a single persistent session, where @cache_buster would come back
--    NULL.
INSERT INTO `config` (`name`, `value`)
SELECT 'assets_version', (
         SELECT `value` FROM (
           SELECT `value` FROM `config` WHERE `name` = 'version'
             ORDER BY `id` DESC LIMIT 1
         ) AS `v`
       )
  FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM (SELECT `name` FROM `config`) AS `c`
                    WHERE `c`.`name` = 'assets_version');

-- 2. Restore `version` to the version of the files actually on disk.
--    Only touch this if it has drifted; if you are mid-upgrade to a real
--    release, set it to that release's version instead.
UPDATE `config` SET `value` = '3.1.2' WHERE `name` = 'version';

-- 3. De-duplicate `version`.
--    The shipped dump contains three `version` rows (ids 138, 167 and 171),
--    all with value '3.1.2'. PT_GetConfig() builds its array as
--    `$data[$config->name] = $config->value` in row order, so duplicates make
--    the effective value depend on the order rows happen to be returned in.
--    Keep the highest id and drop the rest, which is the row PT_GetConfig()'s
--    last-write-wins resolution picks today, so this changes nothing in effect.
--    2026-09-28-dedupe-config-names.sql extends this to every other
--    duplicated name; this step is kept here so the split is self-contained
--    and safe to run on its own.
DELETE `stale` FROM `config` AS `stale`
  INNER JOIN `config` AS `winner`
    ON `stale`.`name` = 'version'
   AND `winner`.`name` = 'version'
   AND `winner`.`id` > `stale`.`id`;

-- 4. Confirm. Expect exactly one `version` row ('3.1.2') and one
--    `assets_version` row (the pre-migration cache-buster, '3.1.4' on the
--    affected install).
SELECT `name`, `value`, COUNT(*) AS rows_found
  FROM `config`
 WHERE `name` IN ('version', 'assets_version')
 GROUP BY `name`, `value`;
