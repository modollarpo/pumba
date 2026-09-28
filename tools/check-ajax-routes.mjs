#!/usr/bin/env node
/**
 * Bidirectional drift check between the templates and types/api.d.ts.
 *
 *   1. A template calls an `aj/...` endpoint that is not in KnownAjaxRoute
 *      -> FAIL. Someone added an endpoint without typing it.
 *   2. KnownAjaxRoute lists an endpoint no template calls
 *      -> FAIL. Stale entry left behind by a rename or deletion.
 *
 * Exits non-zero on either, so it can gate `npm run typecheck`.
 *
 * Reads api.d.ts as text rather than importing it: that keeps the script
 * dependency-free and lets it run before any TypeScript toolchain exists.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const THEMES = ['default', 'playtag', 'youplay'];
const ROUTE_RE = /aj\/[a-zA-Z0-9_/-]+/g;

/**
 * First-party surfaces that actually call the ajax router.
 *
 * Only these are scanned. Every subdirectory under js/ is vendored - DataTables
 * ships base64 font blobs (js/lib/jquery-datatable/.../vfs_fonts.js) that the
 * route regex happily matches, and enumerating vendor dirs to exclude them is
 * brittle. The layout templates plus the top-level js files are where every real
 * call site lives.
 */
function firstPartyFiles(theme) {
  const files = [];

  const layoutDir = join(ROOT, 'themes', theme, 'layout');
  (function walk(dir) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (entry.name.endsWith('.html')) files.push(full);
    }
  })(layoutDir);

  const jsDir = join(ROOT, 'themes', theme, 'js');
  for (const entry of readdirSync(jsDir, { withFileTypes: true })) {
    if (entry.isFile() && entry.name.endsWith('.js')) files.push(join(jsDir, entry.name));
  }

  return files;
}

/** Collect the `aj/...` literals declared in the KnownAjaxRoute union. */
function declaredRoutes() {
  const src = readFileSync(join(ROOT, 'types', 'api.d.ts'), 'utf8');
  const start = src.indexOf('export type KnownAjaxRoute =');
  if (start === -1) throw new Error('KnownAjaxRoute not found in types/api.d.ts');

  const end = src.indexOf(';', start);
  const body = src.slice(start, end);
  const routes = new Set();

  for (const m of body.matchAll(/'([^']+)'/g)) routes.add(m[1]);
  return routes;
}

/** Collect every `aj/...` literal present in the first-party surfaces. */
function usedRoutes() {
  const byRoute = new Map();
  let scanned = 0;

  for (const theme of THEMES) {
    let files;
    try {
      files = firstPartyFiles(theme);
    } catch {
      console.error(`error: themes/${theme} not found`);
      process.exit(2);
    }

    // A rename that breaks the expected layout would silently disable the
    // check, so refuse to pass on an empty surface.
    if (files.length === 0) {
      console.error(`error: no first-party files found for theme "${theme}"`);
      console.error('  expected themes/<theme>/layout/**/*.html and themes/<theme>/js/*.js');
      process.exit(2);
    }
    scanned += files.length;

    for (const file of files) {
      const src = readFileSync(file, 'utf8');
      for (const m of src.matchAll(ROUTE_RE)) {
        const route = m[0];
        if (!byRoute.has(route)) byRoute.set(route, []);
        byRoute.get(route).push(relative(ROOT, file).split(sep).join('/'));
      }
    }
  }

  return { byRoute, scanned };
}

const declared = declaredRoutes();
const { byRoute: used, scanned } = usedRoutes();

const undeclared = [...used.keys()].filter((r) => !declared.has(r)).sort();
const stale = [...declared].filter((r) => !used.has(r)).sort();

console.log(
  `ajax routes: ${used.size} used in ${scanned} first-party files ` +
    `across ${THEMES.length} themes, ${declared.size} declared in types/api.d.ts`
);

if (undeclared.length === 0 && stale.length === 0) {
  console.log('ok: template routes and KnownAjaxRoute are in sync');
  process.exit(0);
}

let failed = false;

if (undeclared.length > 0) {
  failed = true;
  console.error(`\nerror: ${undeclared.length} route(s) called by templates but not typed:`);
  for (const route of undeclared) {
    const sites = [...new Set(used.get(route))];
    console.error(`  ${route}`);
    for (const site of sites.slice(0, 3)) console.error(`      ${site}`);
    if (sites.length > 3) console.error(`      ...and ${sites.length - 3} more`);
  }
  console.error('\n  add each to the KnownAjaxRoute union in types/api.d.ts');
}

if (stale.length > 0) {
  failed = true;
  console.error(`\nerror: ${stale.length} route(s) typed but no longer called by any theme:`);
  for (const route of stale) console.error(`  ${route}`);
  console.error('\n  remove them from the KnownAjaxRoute union in types/api.d.ts');
}

process.exit(failed ? 1 : 0);
