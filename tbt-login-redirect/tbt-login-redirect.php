<?php
/**
 * Plugin Name:       TBT Login Redirect
 * Plugin URI:        https://thebluetree.pl/
 * Description:        Sends logged-out visitors who hit a restricted lesson to the login screen instead of a 404, then returns them to the exact lesson they requested after they log in. Leaves Addify's role-based redirects and WooCommerce Memberships restriction settings untouched.
 * Version:           1.0.0
 * Author:            The Blue Tree
 * Requires at least: 5.4
 * Requires PHP:      7.2
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tbt-login-redirect
 *
 * @package TBT_Login_Redirect
 *
 * -----------------------------------------------------------------------------
 * WHY THIS PLUGIN EXISTS (see README.md for the full write-up)
 * -----------------------------------------------------------------------------
 * A logged-out user who follows a direct link to a membership lesson currently
 * gets a 404 instead of a login prompt, and after logging in manually lands on
 * the generic /vip/ page (Addify's role-based rule) rather than the lesson they
 * asked for. Legacy access code and Addify cannot be modified, so this
 * standalone plugin hooks WordPress with standard filters/actions to:
 *
 *   1. Intercept a logged-out request for a restricted lesson BEFORE the 404
 *      fires (template_redirect at an early priority).
 *   2. Redirect that visitor to the login page with the requested URL captured.
 *   3. After a successful login, send them to the captured lesson URL instead
 *      of Addify's default /vip/ destination -- but ONLY for this case, so
 *      normal logins keep Addify's behaviour.
 *
 * Every value that depends on the live-site diagnosis (which post types count
 * as "lessons", which login page to use, how to detect a lesson request) is
 * exposed as a filter so it can be confirmed and adjusted without editing code.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TBT_LRP_VERSION' ) ) {
	define( 'TBT_LRP_VERSION', '1.0.0' );
}

/**
 * Name of the short-lived cookie used to carry the captured lesson URL through
 * the login POST. Needed because a WooCommerce My Account login (or a custom
 * login form) does not necessarily forward our redirect_to query argument.
 */
if ( ! defined( 'TBT_LRP_COOKIE' ) ) {
	define( 'TBT_LRP_COOKIE', 'tbt_lrp_redirect' );
}

/**
 * Query flag we add to the login URL so we can recognise (and, if ever needed,
 * debug) requests that originated from this plugin.
 */
if ( ! defined( 'TBT_LRP_FLAG' ) ) {
	define( 'TBT_LRP_FLAG', 'tbt_lrp' );
}

/* -------------------------------------------------------------------------- */
/* Configuration helpers (Phase 1 values -- override via filters)             */
/* -------------------------------------------------------------------------- */

/**
 * Post types that should be treated as "lessons".
 *
 * IMPORTANT (Phase 1): confirm the real lesson post type on the live site and
 * override this list if needed, e.g.:
 *
 *     add_filter( 'tbt_lrp_lesson_post_types', function () {
 *         return array( 'your_real_lesson_cpt' );
 *     } );
 *
 * Defaults cover the common LMS plugins plus a plain "lesson" type. If the list
 * does not match the site, the plugin simply does nothing (fails safe).
 *
 * @return string[]
 */
function tbt_lrp_get_lesson_post_types() {
	$defaults = array(
		'lesson',        // Generic / Tutor LMS.
		'lessons',       // Occasional variant.
		'sfwd-lessons',  // LearnDash.
		'sfwd-courses',  // LearnDash course.
		'course',        // LifterLMS / generic.
		'courses',       // Variant.
	);

	$types = apply_filters( 'tbt_lrp_lesson_post_types', $defaults );

	return is_array( $types ) ? array_filter( array_map( 'strval', $types ) ) : array();
}

/**
 * Optional list of regex patterns matched against the request path. Empty by
 * default. Use this only if lessons are not distinguishable by post type, e.g.:
 *
 *     add_filter( 'tbt_lrp_lesson_url_patterns', function () {
 *         return array( '#^/lekcje/#', '#^/lesson-#' );
 *     } );
 *
 * @return string[]
 */
function tbt_lrp_get_lesson_url_patterns() {
	$patterns = apply_filters( 'tbt_lrp_lesson_url_patterns', array() );

	return is_array( $patterns ) ? $patterns : array();
}

/**
 * Base login URL to send logged-out visitors to.
 *
 * IMPORTANT (Phase 1): confirm the real login entry point. By default this
 * prefers the WooCommerce "My Account" page (where subscribers normally log in)
 * and falls back to native wp-login.php. Override with:
 *
 *     add_filter( 'tbt_lrp_login_url', function ( $url, $redirect_to ) {
 *         return home_url( '/logowanie/' ); // custom login page
 *     }, 10, 2 );
 *
 * @param string $redirect_to Already-sanitised same-site URL to return to.
 * @return string Absolute login URL (without the redirect argument attached).
 */
function tbt_lrp_get_login_base_url( $redirect_to ) {
	$login_base = '';

	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$login_base = wc_get_page_permalink( 'myaccount' );
	}

	if ( empty( $login_base ) ) {
		$login_base = wp_login_url();
	}

	return apply_filters( 'tbt_lrp_login_url', $login_base, $redirect_to );
}

/* -------------------------------------------------------------------------- */
/* URL / request helpers                                                      */
/* -------------------------------------------------------------------------- */

/**
 * Return the raw path (with query string) of the current request.
 *
 * @return string
 */
function tbt_lrp_current_request_uri() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	return is_string( $uri ) ? $uri : '/';
}

/**
 * Turn any URL/path into a safe, same-site absolute URL.
 *
 * Everything except the path and query string is discarded and rebuilt against
 * home_url(), which makes an open-redirect impossible, and the result is passed
 * through wp_validate_redirect() as a final guard. Returns '' if invalid.
 *
 * @param string $url Candidate URL or path.
 * @return string Safe absolute URL, or empty string.
 */
function tbt_lrp_sanitize_redirect( $url ) {
	$url = wp_unslash( (string) $url );

	if ( '' === $url ) {
		return '';
	}

	$parts = wp_parse_url( $url );

	if ( false === $parts ) {
		return '';
	}

	$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
	$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

	// Force a leading slash so protocol-relative ("//evil.com") input cannot
	// survive the reconstruction below.
	if ( '' === $path || '/' !== $path[0] ) {
		$path = '/' . ltrim( $path, '/' );
	}

	$absolute = home_url( $path . $query );

	// Final same-site validation; empty fallback signals "reject".
	return wp_validate_redirect( $absolute, '' );
}

/**
 * Resolve the post ID the current request targets, working even if legacy code
 * has already turned the main query into a 404.
 *
 * @return int Post ID, or 0 if none.
 */
function tbt_lrp_resolve_requested_post_id() {
	// 1. Trust the resolved query object when WordPress produced one.
	$queried = get_queried_object();
	if ( $queried instanceof WP_Post ) {
		return (int) $queried->ID;
	}

	// 2. Fall back to resolving the raw request path -- this bypasses any
	//    query manipulation the legacy code performed in pre_get_posts.
	$path = wp_parse_url( tbt_lrp_current_request_uri(), PHP_URL_PATH );
	if ( empty( $path ) ) {
		return 0;
	}

	$post_id = url_to_postid( $path );
	if ( $post_id ) {
		return (int) $post_id;
	}

	// 3. Last resort: match the final slug against each known lesson post type,
	//    covering CPTs that are not publicly queryable for logged-out users.
	$segments = array_filter( explode( '/', trim( $path, '/' ) ) );
	$slug     = $segments ? (string) end( $segments ) : '';

	if ( '' !== $slug ) {
		foreach ( tbt_lrp_get_lesson_post_types() as $post_type ) {
			$found = get_page_by_path( $slug, OBJECT, $post_type );
			if ( $found instanceof WP_Post ) {
				return (int) $found->ID;
			}
		}
	}

	return 0;
}

/**
 * Decide whether the current request is for a lesson that a logged-out visitor
 * should be asked to log in for.
 *
 * @return bool
 */
function tbt_lrp_is_lesson_request() {
	$post_id   = tbt_lrp_resolve_requested_post_id();
	$is_lesson = false;

	if ( $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( $post_type && in_array( $post_type, tbt_lrp_get_lesson_post_types(), true ) ) {
			$is_lesson = true;
		}
	}

	// URL-pattern fallback for sites where lessons are not identified by CPT.
	if ( ! $is_lesson ) {
		$path = (string) wp_parse_url( tbt_lrp_current_request_uri(), PHP_URL_PATH );
		foreach ( tbt_lrp_get_lesson_url_patterns() as $pattern ) {
			if ( @preg_match( $pattern, $path ) === 1 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$is_lesson = true;
				break;
			}
		}
	}

	if ( ! $is_lesson ) {
		return false;
	}

	// Only force a login when the content is actually restricted. A lesson that
	// WooCommerce Memberships considers publicly viewable must stay public.
	if ( $post_id && function_exists( 'wc_memberships_is_post_content_restricted' ) ) {
		if ( ! wc_memberships_is_post_content_restricted( $post_id ) ) {
			return false;
		}
	}

	/**
	 * Final say on whether this request should be intercepted.
	 *
	 * @param bool $is_lesson Whether the request looks like a restricted lesson.
	 * @param int  $post_id   Resolved post ID (0 if unknown).
	 */
	return (bool) apply_filters( 'tbt_lrp_is_lesson_request', $is_lesson, $post_id );
}

/**
 * Requests we must never touch (admin, AJAX, cron, REST, feeds, robots, the
 * login and account pages themselves, and anything already flagged by us).
 *
 * @return bool
 */
function tbt_lrp_is_excluded_request() {
	if ( is_admin() || is_feed() || is_robots() ) {
		return true;
	}

	if ( function_exists( 'is_favicon' ) && is_favicon() ) {
		return true;
	}

	if ( wp_doing_ajax() || wp_doing_cron() ) {
		return true;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}

	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return true;
	}

	// Already came from us -- do not loop.
	if ( isset( $_GET[ TBT_LRP_FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return true;
	}

	// The WooCommerce My Account page (login lives here) must never be
	// intercepted, or logging in becomes impossible.
	if ( function_exists( 'wc_get_page_id' ) ) {
		$account_id = wc_get_page_id( 'myaccount' );
		if ( $account_id > 0 && is_page( $account_id ) ) {
			return true;
		}
	}

	return false;
}

/* -------------------------------------------------------------------------- */
/* Cookie helpers (carry the captured URL through the login POST)             */
/* -------------------------------------------------------------------------- */

/**
 * Store the captured lesson URL in a short-lived, same-site cookie.
 *
 * @param string $url Safe absolute URL.
 */
function tbt_lrp_set_cookie( $url ) {
	if ( '' === $url || headers_sent() ) {
		return;
	}

	$expire = time() + ( 15 * MINUTE_IN_SECONDS );
	$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
	$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
	$secure = is_ssl();

	if ( PHP_VERSION_ID >= 70300 ) {
		setcookie(
			TBT_LRP_COOKIE,
			$url,
			array(
				'expires'  => $expire,
				'path'     => $path,
				'domain'   => $domain,
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	} else {
		setcookie( TBT_LRP_COOKIE, $url, $expire, $path, $domain, $secure, true );
	}

	// Make it readable within this same request if ever needed.
	$_COOKIE[ TBT_LRP_COOKIE ] = $url;
}

/**
 * Clear the captured-URL cookie.
 */
function tbt_lrp_clear_cookie() {
	if ( ! isset( $_COOKIE[ TBT_LRP_COOKIE ] ) ) {
		return;
	}

	unset( $_COOKIE[ TBT_LRP_COOKIE ] );

	if ( headers_sent() ) {
		return;
	}

	$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
	$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

	setcookie( TBT_LRP_COOKIE, '', time() - HOUR_IN_SECONDS, $path, $domain );
}

/* -------------------------------------------------------------------------- */
/* Core: intercept the logged-out lesson request                              */
/* -------------------------------------------------------------------------- */

/**
 * Runs early on template_redirect. If a logged-out visitor is requesting a
 * restricted lesson, capture the URL and send them to the login page before the
 * legacy 404 can fire.
 */
function tbt_lrp_maybe_redirect_to_login() {
	// Logged-in users are handled by WooCommerce Memberships as before -- never
	// intercept them, which also prevents a post-login redirect loop.
	if ( is_user_logged_in() ) {
		return;
	}

	if ( tbt_lrp_is_excluded_request() ) {
		return;
	}

	if ( ! tbt_lrp_is_lesson_request() ) {
		// Genuinely nonexistent pages and non-lesson URLs fall through to the
		// normal 404 behaviour untouched.
		return;
	}

	$redirect_to = tbt_lrp_sanitize_redirect( tbt_lrp_current_request_uri() );

	// If we could not build a safe return URL, do nothing rather than risk a
	// bad redirect -- the visitor just sees the existing behaviour.
	if ( '' === $redirect_to ) {
		return;
	}

	tbt_lrp_set_cookie( $redirect_to );

	$login_base = tbt_lrp_get_login_base_url( $redirect_to );
	$login_url  = add_query_arg(
		array(
			'redirect_to'  => rawurlencode( $redirect_to ),
			TBT_LRP_FLAG   => 1,
		),
		$login_base
	);

	/**
	 * Filter the final login URL (including the redirect argument) before use.
	 *
	 * @param string $login_url   Full login URL.
	 * @param string $redirect_to Safe URL the user will return to.
	 * @param string $login_base  Base login URL without arguments.
	 */
	$login_url = apply_filters( 'tbt_lrp_full_login_url', $login_url, $redirect_to, $login_base );

	wp_safe_redirect( $login_url );
	exit;
}
add_action( 'template_redirect', 'tbt_lrp_maybe_redirect_to_login', 1 );

/* -------------------------------------------------------------------------- */
/* Core: after login, return the user to the captured lesson                  */
/* -------------------------------------------------------------------------- */

/**
 * Pick the URL to send the user to after login: the captured lesson URL if we
 * have one, otherwise empty (so the default / Addify destination is kept).
 *
 * @param string $requested Requested redirect_to value from the login request.
 * @return string Safe absolute URL, or '' to defer to the default.
 */
function tbt_lrp_pick_redirect_target( $requested ) {
	$candidate = '';

	if ( ! empty( $requested ) ) {
		$candidate = (string) $requested;
	}

	if ( '' === $candidate && ! empty( $_COOKIE[ TBT_LRP_COOKIE ] ) ) {
		$candidate = wp_unslash( $_COOKIE[ TBT_LRP_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	// We are done with the cookie whether or not it was usable.
	tbt_lrp_clear_cookie();

	if ( '' === $candidate ) {
		return '';
	}

	return tbt_lrp_sanitize_redirect( $candidate );
}

/**
 * Native wp-login.php path: override the post-login destination only when we
 * have a captured lesson URL. Priority 100 so we run after Addify and can take
 * precedence for our specific case while leaving its value intact otherwise.
 *
 * @param string           $redirect_to           Default post-login URL.
 * @param string           $requested_redirect_to Requested redirect (from redirect_to).
 * @param WP_User|WP_Error $user                  Logged-in user or error.
 * @return string
 */
function tbt_lrp_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
	if ( ! ( $user instanceof WP_User ) ) {
		return $redirect_to;
	}

	$target = tbt_lrp_pick_redirect_target( $requested_redirect_to );

	return '' !== $target ? $target : $redirect_to;
}
add_filter( 'login_redirect', 'tbt_lrp_login_redirect', 100, 3 );

/**
 * WooCommerce My Account path: same idea via the WooCommerce filter, reading the
 * captured URL from the request or the cookie.
 *
 * @param string  $redirect Default WooCommerce redirect.
 * @param WP_User $user     Logged-in user.
 * @return string
 */
function tbt_lrp_wc_login_redirect( $redirect, $user ) {
	$requested = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security

	$target = tbt_lrp_pick_redirect_target( $requested );

	return '' !== $target ? $target : $redirect;
}
add_filter( 'woocommerce_login_redirect', 'tbt_lrp_wc_login_redirect', 100, 2 );
