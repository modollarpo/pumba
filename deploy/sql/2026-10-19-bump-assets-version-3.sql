-- Bust the asset cache after the responsive fixes for the lifted UI.
--
-- Why
-- ---
-- This release adds responsive rules to
-- themes/playtag/css/custom.style.css (compact "My Library" button and
-- on-screen dropdown on small phones, thumbnail strip only on desktop
-- widths) which is served as
-- `custom.style.css?version={{CONFIG assets_version}}`. Clients that already
-- fetched the stylesheet at 3.1.6 have it cached under the same URL, so the
-- new rules would not be picked up. Bump `assets_version` so every client
-- re-fetches.
--
-- Do NOT bump `version` (schema version) here — see the notes in
-- 2026-10-19-bump-assets-version.sql and deploy/OPERATIONS.md.
--
-- Safe to run more than once.

UPDATE `config` SET `value` = '3.1.7' WHERE `name` = 'assets_version';

SELECT `name`, `value`, COUNT(*) AS rows_found
  FROM `config`
 WHERE `name` = 'assets_version'
 GROUP BY `name`, `value`;
