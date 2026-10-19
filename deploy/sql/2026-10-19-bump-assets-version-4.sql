-- Bust the asset cache for the corrected small-phone library menu.
--
-- Why
-- ---
-- 2026-10-19-bump-assets-version-3.sql published the first responsive pass at
-- `assets_version` 3.1.7, whose dropdown positioning still let the "My
-- Library" menu leave the screen at ~320px. This follow-up publishes the
-- corrected rules under a new version so any client that fetched the
-- stylesheet during that window re-fetches it.
--
-- Do NOT bump `version` (schema version) here — see the notes in
-- 2026-10-19-bump-assets-version.sql and deploy/OPERATIONS.md.
--
-- Safe to run more than once.

UPDATE `config` SET `value` = '3.1.8' WHERE `name` = 'assets_version';

SELECT `name`, `value`, COUNT(*) AS rows_found
  FROM `config`
 WHERE `name` = 'assets_version'
 GROUP BY `name`, `value`;
