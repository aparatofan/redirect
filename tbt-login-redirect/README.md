# TBT Login Redirect

A small, standalone WordPress plugin for **thebluetree.pl**. It makes a
logged-out visitor who follows a direct link to a restricted lesson see the
**login screen instead of a 404**, and after they log in it drops them **on the
exact lesson they originally requested** rather than the generic `/vip/` page.

It changes no legacy code, no Addify settings, and no WooCommerce Memberships
settings. Rollback is just deactivating the plugin.

---

## What it does

1. **Intercepts early.** On `template_redirect` (priority `1`, before the legacy
   404 fires) it checks: *is the visitor logged out, and is this a restricted
   lesson request?*
2. **Captures + redirects to login.** It stores the requested URL and redirects
   the visitor to the login page, passing the URL as `redirect_to` (and also in
   a short-lived `tbt_lrp_redirect` cookie, so the URL survives a WooCommerce /
   custom login POST that would not otherwise forward it).
3. **Returns them after login.** Via `login_redirect` (native login) and
   `woocommerce_login_redirect` (My Account login) it sends the user to the
   captured lesson — **only** when a captured URL exists. For every other login
   it returns the value it was given, so **Addify's role-based redirects keep
   working unchanged**.
4. **Stays safe.** The return URL is stripped to path + query, rebuilt against
   `home_url()`, and passed through `wp_validate_redirect()`, so it can never
   become an open redirect.

## Hooks used

| Hook | Type | Priority | Purpose |
| --- | --- | --- | --- |
| `template_redirect` | action | `1` (early) | Detect a logged-out restricted-lesson request and redirect to login before the 404. |
| `login_redirect` | filter | `100` | After native login, return to the captured lesson if there is one; otherwise defer to the default (Addify). |
| `woocommerce_login_redirect` | filter | `100` | Same, for logins through the WooCommerce My Account page. |

Priority `100` on the two login filters is deliberate: it runs **after** Addify,
so this plugin only overrides Addify's `/vip/` destination for the specific
"return to the requested lesson" case and leaves Addify's decision untouched for
normal logins.

## Edge cases handled

- **Logged-in but no membership access** → not intercepted (the interceptor
  bails for logged-in users), so WooCommerce Memberships shows its normal
  restricted/upgrade message and there is **no redirect loop**.
- **Genuinely nonexistent / non-lesson URLs** → not a lesson, so they fall
  through to the normal 404.
- **Logged-in user with the correct tier** → not intercepted; works as normal.
- **Repeat attempts / login failure** → the login and My Account pages, plus any
  request already carrying our `tbt_lrp` flag, are excluded, and only logged-out
  users are ever redirected, which closes the loop.
- **Public (unrestricted) lessons** → if WooCommerce Memberships reports the
  content is not restricted, the visitor is **not** forced to log in.

---

## Phase 1 — diagnosis status and the assumptions this build makes

> **Read this before deploying.** Phase 1 of the spec asks for a diagnosis on the
> live/staging site (post type of a lesson, where the 404 originates, the exact
> login URL, the not-a-member behaviour). That diagnosis needs access to the
> running WordPress install and **was not performed as part of writing this
> code.** So the plugin was built to be *correct regardless of the answers* by
> making each Phase-1 value configurable and failing safe (doing nothing) when a
> default does not match. Confirm the four items below on staging, adjust the
> filters if needed, then run the test plan.

### 1. Which post type is a "lesson"?

Default detection matches these post types:
`lesson`, `lessons`, `sfwd-lessons` (LearnDash), `sfwd-courses`, `course`,
`courses`. If the real lesson post type is different, set it:

```php
add_filter( 'tbt_lrp_lesson_post_types', function () {
    return array( 'your_real_lesson_cpt' );
} );
```

To confirm the real type, open a lesson in `wp-admin` and read the `post_type`
in the URL, or run `get_post_type( $id )`.

If lessons are **not** identified by post type (e.g. they are plain pages under a
path), use a URL pattern instead:

```php
add_filter( 'tbt_lrp_lesson_url_patterns', function () {
    return array( '#^/lekcje/#' ); // regex matched against the request path
} );
```

If neither matches, the plugin does nothing — it will not break the site, but it
also will not fix the 404 until the detection is set correctly.

### 2. Where does the 404 come from?

The interceptor resolves the requested post **from the raw request path**
(`url_to_postid()`, with a `get_page_by_path()` fallback across the lesson post
types) rather than trusting the main query. That means it still detects the
lesson even if the legacy code forces a 404 in `pre_get_posts`, and running at
`template_redirect` priority `1` means it fires before a legacy
`template_redirect` handler at the default priority. If diagnosis shows the 404
is produced somewhere that runs *earlier* than priority `1` on
`template_redirect`, lower this plugin's priority or move it to `pre_get_posts` —
the detection helpers are reusable as-is.

If the 404 turns out to be **WooCommerce Memberships "Hide completely"**
restriction mode rather than a logged-out-visibility issue, this plugin still
works: it keys off "logged out + restricted lesson", which is exactly that case.

### 3. What is the login URL?

Default: the WooCommerce **My Account** page permalink
(`wc_get_page_permalink( 'myaccount' )`), falling back to `wp_login.php`. If the
site uses a custom login page, point the plugin at it:

```php
add_filter( 'tbt_lrp_login_url', function ( $url, $redirect_to ) {
    return home_url( '/logowanie/' );
}, 10, 2 );
```

Because the captured URL is carried in both the `redirect_to` argument **and** a
cookie, the post-login return works whether login happens via `wp-login.php`,
the My Account form, or a custom login form that ends in `wp_signon()`.

### 4. Not-a-member behaviour

Confirm what a **logged-in** user without the required tier sees on a restricted
lesson. This plugin never touches logged-in users, so that path is unchanged and
cannot loop. Verify it still shows the existing restricted/upgrade message.

---

## Configuration reference (filters)

| Filter | Default | Use |
| --- | --- | --- |
| `tbt_lrp_lesson_post_types` | common LMS types | Set the real lesson post type(s). |
| `tbt_lrp_lesson_url_patterns` | `[]` | Detect lessons by URL regex instead of / in addition to post type. |
| `tbt_lrp_is_lesson_request` | computed bool | Final override on whether a request is intercepted. |
| `tbt_lrp_login_url` | My Account or wp-login | Point at a custom login page. |
| `tbt_lrp_full_login_url` | built URL | Adjust the complete login URL (e.g. extra args). |

## Installation

1. Copy the `tbt-login-redirect/` folder into `wp-content/plugins/`.
2. Activate **TBT Login Redirect** in *Plugins*.
3. Confirm the four Phase 1 items above on **staging** and add any filter
   overrides (a small mu-plugin or the theme's `functions.php` is fine).
4. Run the test plan.

## Test plan (run on staging first)

1. Logged-out visitor clicks a lesson link → sees the **login screen**, not a 404.
2. Successful login → lands on the **exact lesson** originally requested.
3. Logged-out visitor opens a genuinely nonexistent URL → **normal 404**.
4. Logged-in visitor without the required tier opens a restricted lesson → sees
   the existing **restricted/upgrade** message, **no loop**.
5. Logged-in visitor with the correct tier opens a lesson → works normally.
6. Log in from the homepage (not via a lesson link) → **Addify's** role-based
   redirect (e.g. Subscriber → `/vip/`, Administrator → dashboard, etc.) still
   applies.

## Rollback

Deactivate the plugin. It stores no data and changes no settings, so there is
nothing else to undo.
