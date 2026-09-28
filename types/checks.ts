/**
 * Compile-time assertions over types/api.d.ts.
 *
 * This file emits nothing and runs nothing - it exists so `tsc --noEmit` has
 * real work to do. The `@ts-expect-error` lines are the load-bearing part:
 * they fail the build if a type stops constraining what it should.
 */
import type {
  AjaxStatus,
  AjaxResponse,
  AjaxBadRequest,
  AjaxTypeNotFound,
  AjaxNotLoggedIn,
  AjaxMessage,
  AjaxPagedHtml,
  AjaxVideoList,
  AjaxCount,
  KnownAjaxRoute,
  ReactionType,
} from './api.js';

/* ------------------------------------------------------------------ *
 * 1. Status vocabulary
 * ------------------------------------------------------------------ */

/** Every declared status is assignable from the union. */
const s200: AjaxStatus = 200;
const s304: AjaxStatus = 304;
const s402: AjaxStatus = 402;
void [s200, s304, s402];

// 1 and 2 are withdrawal-request DB values, not response statuses.
// @ts-expect-error 1 is not a response status
const sOne: AjaxStatus = 1;
// @ts-expect-error 302 is never emitted
const s302: AjaxStatus = 302;

/* ------------------------------------------------------------------ *
 * 2. Envelope shapes
 * ------------------------------------------------------------------ */

/** The two responses ajax.php emits before a handler runs. */
const guard: AjaxBadRequest = { status: 400, message: 'bad-request' };
const missing: AjaxTypeNotFound = { error: 404, error_message: 'type not found' };
void [guard, missing];

// The 404 shape has no `status`; conflating it with a normal envelope would
// hide the fact that a caller can receive a body it never expected.
// @ts-expect-error 'type not found' is the only error_message ajax.php emits
const badMissing: AjaxTypeNotFound = { error: 404, error_message: 'something else' };

/** Handlers reuse `error` for both auth and validation failures. */
const notLoggedIn: AjaxNotLoggedIn = { status: 400, error: 'Not logged in' };
const validation: AjaxNotLoggedIn = { status: 400, error: 'File type not supported' };
void [notLoggedIn, validation];

// @ts-expect-error not-logged-in is a 400, never a 200
const wrongStatus: AjaxNotLoggedIn = { status: 200, error: 'Not logged in' };

/** Paginated HTML: `token` is required, not optional. */
const page: AjaxPagedHtml = { status: 200, html: '<div/>', token: 'c3RhcnQ9' };
void page;

// @ts-expect-error a paged response must carry the cursor
const pageNoToken: AjaxPagedHtml = { status: 200, html: '<div/>' };

/** aj/views returns the refreshed counter. */
const views: AjaxCount = { status: 200, count: 4096 };
void views;

// @ts-expect-error count is numeric, not a string
const viewsString: AjaxCount = { status: 200, count: '4096' };

/** aj/load-more-style video lists carry an array. */
const videos: AjaxVideoList = { status: 200, videos: [] };
void videos;

// @ts-expect-error videos is an array, not a bare string
const videosScalar: AjaxVideoList = { status: 200, videos: '<div/>' };

/** 402 upload rejections ride on the plain message envelope. */
const tooBig: AjaxMessage = { status: 402, message: 'File is too big: 512MB' };
void tooBig;

/* ------------------------------------------------------------------ *
 * 3. Union discrimination
 * ------------------------------------------------------------------ */

/**
 * `AjaxResponse` is a discriminated union, so a caller can narrow on `status`
 * without casts. This is the whole point of the file: a future change that
 * breaks discrimination should fail here rather than at 200 call sites.
 */
function describe(r: AjaxResponse): string {
  if (!('status' in r)) return r.error_message;
  switch (r.status) {
    case 200:
      return 'ok';
    case 400:
      return 'rejected';
    case 402:
      return 'payment required';
    case 500:
      return 'server error';
    default:
      return 'other';
  }
}
void describe;

/* ------------------------------------------------------------------ *
 * 4. Route vocabulary
 * ------------------------------------------------------------------ */

const like: KnownAjaxRoute = 'aj/like-system/';
const wallet: KnownAjaxRoute = 'aj/wallet/stripe_session';
void [like, wallet];

// @ts-expect-error not an endpoint any theme calls
const bogus: KnownAjaxRoute = 'aj/does-not-exist';

// @ts-expect-error templates call PT_Ajax_Requests_File() . 'aj/...'; the
// bare filename is not a route
const notARoute: KnownAjaxRoute = 'ajax.php';

const reaction: ReactionType = 'love';
// @ts-expect-error not in ajax/like-system.php's $reactions whitelist
const badReaction: ReactionType = 'thumbsup';
void [reaction, badReaction];
