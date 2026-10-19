-- Bust the asset cache for the playtag/youplay restyle.
--
-- Why
-- ---
-- This release restyles both front-end themes: body/secondary text moves from
-- Roboto to Inter, and headings move to Outfit (--title-font). The Google Fonts
-- <link> for Outfit was added to themes/{playtag,youplay}/layout/container.html,
-- and themes/{playtag,youplay}/css/style.css and custom.style.css changed.
--
-- Assets are referenced as `?version={{CONFIG assets_version}}`, so returning
-- visitors keep the stylesheet keyed on the old value and never see the new
-- fonts. Bump `assets_version` so every client re-fetches.
--
-- Do NOT bump `version` for this. That row doubles as the schema version:
-- assets/includes/app_start.php hardcodes $pt->config->filesVersion to the
-- version of the files on disk, and getStatus() treats a mismatch between
-- `version` and filesVersion as the database running newer code than the files
-- — and responds by calling error_reporting(0), silencing PHP errors site-wide.
-- See deploy/OPERATIONS.md and deploy/sql/2026-09-28-split-assets-version.sql.
--
-- Expects the split migration to have run first, so the `assets_version` row
-- already exists ('3.1.4' on the affected install). The guarded INSERT below
-- covers an install where it does not, so this is safe either way.
--
-- Safe to run more than once.

-- 1. Move the copy served to browsers. This is what busts the CSS cache.
UPDATE `config` SET `value` = '3.1.5' WHERE `name` = 'assets_version';

-- 2. Create the row if the split migration has not run yet.
--    `config` has no unique index on `name`, so guard with NOT EXISTS
--    (INSERT IGNORE would not help) to avoid adding a second `assets_version`.
INSERT INTO `config` (`name`, `value`)
SELECT 'assets_version', '3.1.5'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `name` FROM `config`) AS `c`
                   WHERE `c`.`name` = 'assets_version');

-- 3. Confirm. Expect a single `assets_version` row reporting '3.1.5'.
SELECT `name`, `value`, COUNT(*) AS rows_found
  FROM `config`
 WHERE `name` = 'assets_version'
 GROUP BY `name`, `value`;
