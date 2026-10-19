-- Bust the asset cache again after the VidBox-style UI lift.
--
-- Why
-- ---
-- The 2026-10-19-bump-assets-version.sql release moved `assets_version` to
-- 3.1.5 and shipped the font restyle. This follow-up adds new rules to
-- themes/playtag/css/custom.style.css (hero CTA, rail arrows, My Library
-- dropdown) which is served as `custom.style.css?version={{CONFIG
-- assets_version}}`. Clients that already loaded the site at 3.1.5 have that
-- stylesheet cached under the same URL, so the new rules would not be picked
-- up. Bump `assets_version` so every client re-fetches.
--
-- Do NOT bump `version` (schema version) here — see the notes in
-- 2026-10-19-bump-assets-version.sql and deploy/OPERATIONS.md.
--
-- Safe to run more than once.

UPDATE `config` SET `value` = '3.1.6' WHERE `name` = 'assets_version';

SELECT `name`, `value`, COUNT(*) AS rows_found
  FROM `config`
 WHERE `name` = 'assets_version'
 GROUP BY `name`, `value`;
