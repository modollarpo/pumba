-- De-duplicate the `config` table, which has no unique index on `name`.
--
-- Why
-- ---
-- `PT_GetConfig()` (assets/includes/functions_one.php:91) builds its config
-- array as:
--
--     foreach ($configs as $key => $config) {
--         $data[$config->name] = $config->value;
--     }
--
-- So when two rows share a `name`, the one that wins is whichever the query
-- happens to return LAST. On a full InnoDB table scan that is the highest
-- `id`, but it is not guaranteed -- there is no ORDER BY -- so the effective
-- value of a duplicated setting is arbitrary and can differ between hosts or
-- after an optimiser change. That is a latent bug regardless of the values
-- involved.
--
-- The shipped dump (playtube.sql) duplicates 10 of 437 config names. Seven are
-- identical copies and harmless, but three disagree, and in all three the
-- lower id is empty while the higher id holds the real value:
--
--     ftp_endpoint   id 95  = ''                     id 103 = 'storage.wowonder.com'
--     ftp_host       id 90  = ''                     id 98  = 'localhost'
--     ftp_port       id 91  = ''                     id 99  = '21'
--
-- So the higher id is the value the site is actually serving today.
--
-- This migration therefore keeps the HIGHEST id and drops the rest. That
-- preserves current behaviour exactly -- the value in force after the
-- migration is the value in force before it. It removes the ambiguity
-- without changing what the site does.
--
-- If you specifically WANT the other value, do not run this blindly: pick the
-- surviving row's value deliberately first, e.g.
--
--     UPDATE config SET value = '<intended>' WHERE name = 'ftp_host';
--
-- ...then run this, and confirm with the query at the end.
--
-- Safe to run more than once.
--
-- Order: run 2026-09-28-split-assets-version.sql first. It also de-duplicates
-- `version`, and this file's final check reports on `version` too.

-- Keep the highest id for every duplicated name; drop the lower-id shadows.
-- Mirrors PT_GetConfig()'s last-write-wins resolution, so the surviving value
-- is the one that was already in effect.
DELETE `stale` FROM `config` AS `stale`
  INNER JOIN `config` AS `winner`
    ON `stale`.`name` = `winner`.`name`
   AND `winner`.`id` > `stale`.`id`;

-- Confirm. Every name here should now return rows_found = 1, with
-- ftp_endpoint/ftp_host/ftp_port holding the non-empty values listed above.
SELECT `name`, `value`, COUNT(*) AS `rows_found`
  FROM `config`
 WHERE `name` IN (
       'version', 'assets_version',
       'ftp_endpoint', 'ftp_host', 'ftp_port',
       'ftp_path', 'ftp_password', 'ftp_upload', 'ftp_username',
       'backblaze_region', 'watermark'
     )
 GROUP BY `name`, `value`
 ORDER BY `name`;
