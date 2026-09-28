/**
 * PlayTube AJAX response contracts.
 *
 * ---------------------------------------------------------------------------
 * How the surface is wired
 * ---------------------------------------------------------------------------
 * Templates never call ajax.php directly. They call
 * PT_Ajax_Requests_File() . 'aj/<type>[/<first>[/<second>]]', and .htaccess
 * rewrites those to:
 *
 *   aj/<type>          -> ajax.php?type=<type>
 *   aj/<type>/<first>  -> ajax.php?type=<type>&first=<first>
 *   aj/<type>/<f>/<s>  -> ajax.php?type=<type>&first=<f>&second=<s>
 *
 * ajax.php then requires ./ajax/<type>.php, which branches on $first/$second,
 * and finally json_encode()s $data. Two responses are produced by ajax.php
 * itself and therefore carry no handler fields:
 *
 *   - the session/hash guard, when hash is absent or PT_CheckMainSession fails
 *   - the unknown-type case, when ./ajax/<type>.php does not exist
 *
 * ---------------------------------------------------------------------------
 * What this file is and is not
 * ---------------------------------------------------------------------------
 * Every response is a JSON object carrying an integer `status`. This file types
 * the envelope vocabulary and enumerates the routes the templates actually
 * call. It does NOT yet give each route its own response interface - doing that
 * is per-handler work against ajax/<type>.php and is tracked separately.
 *
 * The route list is checked bidirectionally by tools/check-ajax-routes.mjs, so
 * a template calling an unlisted endpoint (or a stale entry surviving a
 * rename) fails `npm run typecheck` rather than failing silently at runtime.
 */

/**
 * Integer `status` values actually used in response envelopes across
 * ajax/*.php. Note that 1 and 2 also appear in admin.php/ap.php, but those are
 * writes to the T_WITHDRAWAL_REQUESTS.status column, not response envelopes, so
 * they are deliberately excluded.
 *
 *   200 ok                 300 toggled off / removed
 *   201 created            304 present, but nothing changed
 *   203 ok, non-standard   400 bad request / validation / not logged in
 *                          401 unauthorized
 *                          402 payment required / upload rejected
 *                          404 not found
 *                          500 server error
 */
export type AjaxStatus = 200 | 201 | 203 | 300 | 304 | 400 | 401 | 402 | 404 | 500;

/** Every handler response extends this. */
export interface AjaxBase {
  status: AjaxStatus;
}

/** The session/hash rejection emitted by ajax.php before any handler runs. */
export interface AjaxBadRequest {
  status: 400;
  message: 'bad-request';
}

/** The unknown-endpoint rejection emitted by ajax.php. Note: no `status`. */
export interface AjaxTypeNotFound {
  error: 404;
  error_message: 'type not found';
}

/**
 * `{ status: 400, error: 'Not logged in' }` - the most common handler response
 * in the codebase (21 occurrences). Handlers also reuse `error` for validation
 * failures, where the value is a lang string or an array of errors, so treat it
 * as a string unless you have checked the specific handler.
 */
export interface AjaxNotLoggedIn extends AjaxBase {
  status: 400;
  error: string;
}

/** `{ status: 4xx, message: <lang string> }` - used for 400, 402 and 404. */
export interface AjaxMessage extends AjaxBase {
  message: string;
}

/**
 * `{ status: 200, html: <pre-rendered fragment> }`.
 *
 * The fragment is server-rendered markup, injected with .html(). Any value read
 * off the response other than `status` is a string, not a model.
 */
export interface AjaxHtml extends AjaxBase {
  html: string;
}

/**
 * `{ status: 200, html, token }` - paginated HTML responses. `token` is the
 * cursor the caller echoes back to request the next page.
 */
export interface AjaxPagedHtml extends AjaxBase {
  html: string;
  token: string;
}

/** `{ status: 200, url }` - caller redirects, e.g. to a login page. */
export interface AjaxRedirect extends AjaxBase {
  url: string;
}

/** `{ status: 200, videos: [...] }` - the main video-list response. */
export interface AjaxVideoList extends AjaxBase {
  status: 200;
  videos: unknown[];
}

/** `{ status: 200, posts: [...] }` - activity/posts feed. */
export interface AjaxPostList extends AjaxBase {
  status: 200;
  posts: unknown[];
}

/**
 * `{ status: 200, users: <html> }`.
 *
 * Despite the name this is pre-rendered markup, not a user array.
 */
export interface AjaxUserListHtml extends AjaxBase {
  status: 200;
  users: string;
}

/**
 * `{ status: 200, comments: ... }`.
 *
 * Deliberately widened to `unknown`: handlers return either an array of comment
 * rows or an HTML string under this same key, depending on the branch. Narrow it
 * per handler before relying on it.
 */
export interface AjaxComments extends AjaxBase {
  status: 200;
  comments: unknown;
}

/** `{ status: 200, queued, skipped }` - bulk import/enqueue result. */
export interface AjaxQueued extends AjaxBase {
  status: 200;
  queued: number;
  skipped: number;
}

/** `{ status: 200, count }` - e.g. the updated view counter from aj/views. */
export interface AjaxCount extends AjaxBase {
  count: number;
}

/** `{ status: 200, file_path, file_name }` - completed upload. */
export interface AjaxUploadedFile extends AjaxBase {
  status: 200;
  file_path: string;
  file_name: string;
}

/** `{ status: 500, errors }` - handler threw. */
export interface AjaxServerError extends AjaxBase {
  status: 500;
  errors: unknown;
}

/** Reaction kinds accepted by ajax/like-system.php. */
export type ReactionType = 'like' | 'love' | 'haha' | 'wow' | 'sad' | 'angry';

/**
 * The response union, including the two responses ajax.php itself produces.
 * Discriminate on `status`; the two ajax.php-level shapes are the only ones
 * without a `status` key and must be checked first.
 */
export type AjaxResponse =
  | AjaxBadRequest
  | AjaxTypeNotFound
  | AjaxNotLoggedIn
  | AjaxMessage
  | AjaxHtml
  | AjaxPagedHtml
  | AjaxRedirect
  | AjaxVideoList
  | AjaxPostList
  | AjaxUserListHtml
  | AjaxComments
  | AjaxQueued
  | AjaxCount
  | AjaxUploadedFile
  | AjaxServerError
  | AjaxBase;

/**
 * Every `aj/...` path literal that appears in the three themes, as written in
 * the templates.
 *
 * Entries ending in `/` are prefixes with a runtime-appended segment:
 *   aj/like-system/            . 'like' | 'love' | 'haha' | 'wow' | 'sad' | 'angry'
 *   aj/comment-like-system/    . 'like' | 'dislike'
 *   aj/reply-like-system/      . 'like' | 'dislike'
 *   aj/load-more/              . the feed name
 *
 * The trailing slash is kept because that is the literal the checker matches
 * against the templates.
 */
export type KnownAjaxRoute =
  | 'aj/add-comment'
  | 'aj/add-reply/activity'
  | 'aj/add-reply/article'
  | 'aj/add-reply/video'
  | 'aj/ads/create'
  | 'aj/ads/delete'
  | 'aj/ads/edit'
  | 'aj/ads/rad-transaction'
  | 'aj/ads/toggle-stat'
  | 'aj/ads/transfer_balance'
  | 'aj/articles/delete-article'
  | 'aj/articles/new-article'
  | 'aj/articles/update-article'
  | 'aj/cards/create'
  | 'aj/cards/delete'
  | 'aj/cards/search'
  | 'aj/check-video-status'
  | 'aj/comment-like-system/'
  | 'aj/comments/get_comment'
  | 'aj/comments/open_modal'
  | 'aj/comments/update_comment'
  | 'aj/contact_us'
  | 'aj/delete-activity'
  | 'aj/delete-comment'
  | 'aj/delete-history'
  | 'aj/delete-reply'
  | 'aj/delete-saved'
  | 'aj/delete-video'
  | 'aj/developers/accept'
  | 'aj/developers/create'
  | 'aj/developers/edit'
  | 'aj/edit-video'
  | 'aj/fetch-video'
  | 'aj/ffmpeg-submit'
  | 'aj/get_notifications'
  | 'aj/google_login'
  | 'aj/invitation_links/create'
  | 'aj/like-system/'
  | 'aj/lists'
  | 'aj/live/check_comments'
  | 'aj/live/create'
  | 'aj/live/create_thumb'
  | 'aj/live/delete'
  | 'aj/live/donate'
  | 'aj/live/live_chating'
  | 'aj/load-more/'
  | 'aj/load-more/activity'
  | 'aj/load-more/articles'
  | 'aj/load-more-comments'
  | 'aj/load-related-videos'
  | 'aj/main/copy_report'
  | 'aj/main/hide-announcement'
  | 'aj/main/monetization'
  | 'aj/main/no_18'
  | 'aj/main/share'
  | 'aj/main/yes_18'
  | 'aj/messages/delete_chat'
  | 'aj/messages/fetch'
  | 'aj/messages/load_user_chat'
  | 'aj/messages/mark_read'
  | 'aj/messages/new'
  | 'aj/messages/search'
  | 'aj/pin-comment'
  | 'aj/playlist_subscribe'
  | 'aj/post/create'
  | 'aj/post/edit'
  | 'aj/provider'
  | 'aj/reply-like-system/'
  | 'aj/report/video/cancel'
  | 'aj/report/video/report'
  | 'aj/save-video'
  | 'aj/search'
  | 'aj/search_users'
  | 'aj/set-cookies'
  | 'aj/shorts/load'
  | 'aj/sort-comments'
  | 'aj/submit-imported-video'
  | 'aj/submit-video'
  | 'aj/subscribe'
  | 'aj/upload-thumbnail'
  | 'aj/upload-video'
  | 'aj/upload-video-ffmpeg'
  | 'aj/user/authy_register'
  | 'aj/user/avatar'
  | 'aj/user/backup_codes'
  | 'aj/user/block'
  | 'aj/user/change_price'
  | 'aj/user/change-pass'
  | 'aj/user/delete'
  | 'aj/user/download_user_info'
  | 'aj/user/general'
  | 'aj/user/get_more_subscribers_'
  | 'aj/user/info'
  | 'aj/user/notify'
  | 'aj/user/privacy'
  | 'aj/user/profile'
  | 'aj/user/re_cover'
  | 'aj/user/remove_session'
  | 'aj/user/request_code'
  | 'aj/user/request-withdrawal'
  | 'aj/user/save_ads'
  | 'aj/user/two_factor'
  | 'aj/user/update_info'
  | 'aj/user/verify_code'
  | 'aj/user/verify_email'
  | 'aj/user/video-monetization'
  | 'aj/verification/request'
  | 'aj/views'
  | 'aj/wallet/alipay_pay'
  | 'aj/wallet/authorize'
  | 'aj/wallet/bank_replenish'
  | 'aj/wallet/braintree'
  | 'aj/wallet/checkout_replenish'
  | 'aj/wallet/create_yoomoney'
  | 'aj/wallet/fluttewave'
  | 'aj/wallet/get_aamarpay'
  | 'aj/wallet/get_braintree'
  | 'aj/wallet/get_coinbase'
  | 'aj/wallet/get_coinpayments'
  | 'aj/wallet/get_fortumo'
  | 'aj/wallet/get_modal'
  | 'aj/wallet/get_ngenius'
  | 'aj/wallet/move_to_wallet'
  | 'aj/wallet/payfast'
  | 'aj/wallet/qiwi'
  | 'aj/wallet/replenish'
  | 'aj/wallet/securionpay_handle'
  | 'aj/wallet/securionpay_token'
  | 'aj/wallet/set'
  | 'aj/wallet/stripe_session'
  | 'aj/wallet/wallet_pay'
  | 'aj/wallet/wallet_update';

