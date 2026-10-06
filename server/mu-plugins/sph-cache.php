<?php
/**
 * Plugin Name: SPH nginx cache hints + feed links
 * Description: TTL hint for the nginx fastcgi_cache (X-Accel-Expires, nginx
 *              strips it before the client) and no per-post comment-feed links.
 *
 * nginx (nginx.conf, [6]) decides WHAT may be cached: anonymous GET/HEAD of
 * the home page, /?p=N, archive pages and site-wide feeds. This file only
 * says for HOW LONG. No header -> nginx default (fastcgi_cache_valid, 10 min).
 */

// The <link rel="alternate"> to "?feed=rss2&p=N" on every article is what
// made crawlers fetch the per-post comment feeds (~3.5 s each, 37% of PHP
// time on 2026-10-06; comments are closed site-wide). nginx answers those
// URLs 410 now; this stops advertising them.
remove_action('wp_head', 'feed_links_extra', 3);

// Same rule as nginx [7], for spellings nginx cannot see (percent-encoded
// parameter names such as %70=N): WordPress has decoded them by now. Runs
// before WP::send_headers(), i.e. before the expensive get_lastpostmodified().
add_action('parse_request', function ($wp) {
    $q = $wp->query_vars;
    if (empty($q['feed'])) {
        return;
    }
    $singular = false;
    foreach (['p', 'page_id', 'attachment_id', 'attachment', 'name', 'pagename',
              'subpost', 'subpost_id'] as $k) {
        $singular = $singular || !empty($q[$k]);
    }
    // feed=comments-rss2 etc. is turned into withcomments=1 by WP_Query
    $comments = !empty($q['withcomments']) || strpos((string) $q['feed'], 'comments-') === 0;
    if (($comments && $singular) || (!empty($q['withcomments']) && !$singular)
        || ($singular && empty($q['withoutcomments']))) {
        status_header(410);
        nocache_headers();
        exit;
    }
}, 0);

add_action('template_redirect', function () {
    if (is_user_logged_in() || is_search() || is_preview() || is_404()) {
        return;
    }
    if (is_feed()) {
        $ttl = 1800;          // site/author feeds: 30 min
    } elseif (is_singular()) {
        $ttl = 86400;         // articles: 1 day (comments closed, edits rare)
    } else {
        $ttl = 600;           // home, pagination, categories: 10 min
    }
    header('X-Accel-Expires: ' . $ttl);
}, 0);
