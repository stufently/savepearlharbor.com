<?php
/*
 * WordPress side of sitemap-gen.py. NOT stored under the docroot: the host
 * script pipes it into the php container on stdin:
 *   docker exec -i -u 33:33 savepearlharborcom-php-1 php -- <cmd> <json> < sitemap-worker.php
 * and reads one JSON document from stdout.
 *
 *   sigs   <ranges>  ranges = [[lo,hi],...] contiguous ID ranges of the post
 *                    sitemap pages. Prints a signature per range and the IDs
 *                    above the last hi (new posts). With [] prints all IDs.
 *   render <jobs>    jobs = [{"name":..,"kind":"post","lo":..,"hi":..}
 *                            |{"name":..,"kind":"wp","provider":..,"subtype":..,"page":..}
 *                            |{"name":"index","kind":"index","posts":[{"page":N,"lastmod":..}]}
 *                            |{"name":..,"kind":"xsl","type":"sitemap"|"index"}]
 *                    Prints {name: {"xml": ..., "count": N}}.
 *
 * XML is produced by WordPress itself (core posts provider + core renderer),
 * so loc/lastmod are byte-identical to what /?sitemap=... returns. The only
 * change is the page boundary: a post page is a fixed ID range instead of an
 * OFFSET, so a new post never shifts the older pages.
 */
$_SERVER['HTTP_HOST'] = 'savepearlharbor.com';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';  // wp-config turns this into HTTPS=on
$_SERVER['REQUEST_URI'] = '/';
define('WP_USE_THEMES', false);
require '/var/www/html/wp-load.php';

// do not fill the (file) object cache with 2000 full posts per page
wp_suspend_cache_addition(true);

$cmd = $argv[1] ?? '';
$arg = json_decode($argv[2] ?? 'null', true);
global $wpdb;
$posts = $wpdb->posts;
$base = "FROM $posts WHERE post_type='post' AND post_status='publish'";

function fail($msg) { fwrite(STDERR, "sitemap-worker: $msg\n"); exit(2); }

if ($cmd === 'sigs') {
    if (!is_array($arg)) fail('sigs: bad ranges');
    $out = ['ranges' => [], 'tail' => []];
    foreach ($arg as [$lo, $hi]) {
        $r = $wpdb->get_row($wpdb->prepare(
            // h = order-independent hash of (ID, post_modified_gmt) pairs: catches
            // an edit even when it does not raise MAX(post_modified_gmt)
            "SELECT COUNT(*) c, MAX(post_modified_gmt) m, COALESCE(SUM(ID),0) s,
                    COALESCE(SUM(CRC32(CONCAT(ID,'|',post_modified_gmt))),0) h,
                    MAX(GREATEST(post_modified_gmt, post_date_gmt)) lm
             $base AND ID BETWEEN %d AND %d", $lo, $hi), ARRAY_A);
        if ($wpdb->last_error || !$r) fail('sigs: ' . $wpdb->last_error);
        $out['ranges'][] = [
            'sig' => "{$r['c']}|{$r['m']}|{$r['s']}|{$r['h']}",
            'count' => (int) $r['c'],
            // same format as WP's own <lastmod> (DATE_W3C in the site timezone)
            'lastmod' => $r['lm'] ? wp_date(DATE_W3C, strtotime($r['lm'] . ' UTC')) : null,
        ];
    }
    $hi = $arg ? (int) end($arg)[1] : 0;
    $out['tail'] = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT ID $base AND ID > %d ORDER BY ID", $hi)));
    if ($wpdb->last_error) fail($wpdb->last_error);
    echo json_encode($out);
    exit(0);
}

if ($cmd !== 'render' || !is_array($arg)) fail("usage: sigs|render <json>");

$server   = wp_sitemaps_get_server();
$renderer = $server->renderer;
$range    = null;
add_filter('wp_sitemaps_posts_query_args', function ($args) use (&$range) {
    if ($range) $args['posts_per_page'] = 50000;  // range can outgrow 2000 (old draft published)
    return $args;
});
add_filter('posts_where', function ($where) use (&$range, $posts) {
    if ($range) $where .= sprintf(" AND $posts.ID BETWEEN %d AND %d", $range[0], $range[1]);
    return $where;
});

$out = [];
foreach ($arg as $job) {
    $name = $job['name'];
    // wpdb clears last_error on every query, so a failed query followed by a
    // good one is invisible there; wpdb::print_error() appends every error to
    // the global $EZSQL_ERROR (regardless of show_errors) -- count those.
    $errs_before = count($GLOBALS['EZSQL_ERROR'] ?? []);
    if ($job['kind'] === 'post') {
        $range = [(int) $job['lo'], (int) $job['hi']];
        $list = $server->registry->get_provider('posts')->get_url_list(1, 'post');
        $range = null;
        $out[$name] = ['xml' => $renderer->get_sitemap_xml($list), 'count' => count($list)];
    } elseif ($job['kind'] === 'wp') {
        $p = $server->registry->get_provider($job['provider']);
        if (!$p) fail("no provider {$job['provider']}");
        $list = $p->get_url_list((int) $job['page'], (string) ($job['subtype'] ?? ''));
        $out[$name] = ['xml' => $renderer->get_sitemap_xml($list), 'count' => count($list)];
    } elseif ($job['kind'] === 'index') {
        // WP's own index minus its OFFSET post pages, plus our ID-range pages
        $posts_p = $server->registry->get_provider('posts');
        $entries = [];
        foreach ($job['posts'] as $pg) {
            $e = ['loc' => $posts_p->get_sitemap_url('post', (int) $pg['page'])];
            if (!empty($pg['lastmod'])) $e['lastmod'] = $pg['lastmod'];
            $entries[] = $e;
        }
        $others = [];
        foreach ($server->index->get_sitemap_list() as $e) {
            if (strpos($e['loc'], 'sitemap-subtype=post&') === false) $others[] = $e;
        }
        $out[$name] = ['xml' => $renderer->get_sitemap_index_xml(array_merge($entries, $others)),
                       'count' => count($entries) + count($others), 'others' => array_column($others, 'loc')];
    } elseif ($job['kind'] === 'xsl') {
        // the XSL that every sitemap references (?sitemap-stylesheet=sitemap|index)
        $st = new WP_Sitemaps_Stylesheet();
        $xsl = $job['type'] === 'index' ? $st->get_sitemap_index_stylesheet()
                                        : $st->get_sitemap_stylesheet();
        $out[$name] = ['xml' => $xsl, 'count' => 0];
    } else {
        fail("bad job kind");
    }
    // a failed query yields an empty list, which would otherwise look like a
    // valid (empty) sitemap and replace the good one
    $errs = array_slice($GLOBALS['EZSQL_ERROR'] ?? [], $errs_before);
    if ($errs || $wpdb->last_error) {
        fail("render $name: " . ($errs ? end($errs)['error_str'] : $wpdb->last_error));
    }
}
echo json_encode($out);
