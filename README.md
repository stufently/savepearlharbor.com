# savepearlharbor.com

WordPress mirror of Habr articles. Production: `ms14.8qw.ru`, docker compose
project in `/opt/services/savepearlharbor.com` (caddy -> nginx -> php-fpm -> mysql).

`server/` is a copy of the server-side code and configs **without secrets**.
The server is the source of truth for what runs; after editing a file on the
server, copy it back here.

| repo | on the server | notes |
|---|---|---|
| `server/docker-compose.yml` | `docker-compose.yml` | passwords come from `.env` (0600, see `server/.env.example`) |
| `server/Caddyfile` | `Caddyfile` | TLS, proxies to nginx:80; JSON access log with duration in `data/caddy/data/access.log` (rolled by Caddy) |
| `server/nginx/nginx.conf` | `nginx.conf` -> `/etc/nginx/conf.d/default.conf` | 410 for crawl traps and per-post comment feeds, rate limits (PHP, wp-login 5r/m), static sitemaps + XSL, gzip, fastcgi_cache on tmpfs, `/parser/` closed, access log `logs/nginx/access.log` with `rt=` `urt=` `cs=` |
| `server/nginx/nginx.d/bots.conf` | `nginx.d/` -> `/etc/nginx/sph/` | blocked bot UAs and scraper networks (403) |
| `server/php/php-fpm-www.conf`, `php-custom.ini`, `php-opcache.ini` | same names | fpm pool / php.ini overrides; `php-opcache.ini` -> `conf.d/zz-opcache.ini` |
| `server/mysql/mysql-tuning.cnf` | `mysql-tuning.cnf` -> `/etc/mysql/conf.d/zz-tuning.cnf` | |
| `server/robots.txt` | `site/robots.txt` | |
| `server/mu-plugins/` | `site/wp-content/mu-plugins/` | `sph-cache.php`: cache TTL hint (X-Accel-Expires), no comment-feed links |
| `server/parser/` | `site/parser/` | Habr importer; `config.inc.php` (root 0600) from `config.inc.php.example` |
| `server/bin/sitemap-gen.py`, `sitemap-worker.php` | `bin/` | static sitemap generator (root cron) |
| `server/logrotate/savepearlharbor-parser`, `savepearlharbor-nginx` | `/etc/logrotate.d/` | parser + sitemap logs, nginx access log |
| `server/maintenance/` | `/root/` | one-off scripts (2026-10-06 post_modified_gmt backfill) |
| `server/cron/root.crontab` | `crontab -l` of root | |

`parser/` in the repo root is the old 2019 parser, kept for history.

## Parser

`site/parser/parser_json.php`, run every 5 min by root cron via
`docker exec savepearlharborcom-php-1 php ...` (as root inside the container,
so `site/parser` is `root:root 0755`, scripts `0644`, `config.inc.php` `0600`).
Logs: `parser-cron.log` (stdout) and `site/parser/parser.log` (PHP errors).
Not reachable over HTTP (`location ^~ /parser/ { return 404; }`).

## Static sitemaps

WordPress core sitemaps (plain permalinks, so they live on `/?sitemap=...`
URLs; the index is `/?sitemap=index`). Rendering a 2000-post page costs ~4 s
of PHP/MySQL and bots fetch ~300 pages a day, so nginx serves them from files:

* `bin/sitemap-gen.py` (host, root cron hourly, `--full` weekly) asks WordPress
  (`sitemap-worker.php`, piped into the php container) to render the pages and
  writes `sitemap-static/gen-<ts>/`, then switches the `sitemap-static/current`
  symlink atomically. Anything invalid -> old set stays, exit 1, line in
  `sitemap-gen.log`.
* Post pages are fixed ID ranges (ascending, <= 2000 posts each, boundaries in
  `current/state.json`), so only pages whose signature
  (`COUNT | MAX(post_modified_gmt) | SUM(ID)`) changed are re-rendered —
  normally just the last one. Public URLs are the same as WordPress' own.
* nginx maps the exact canonical query strings to file names
  (`map $request_uri $sph_sitemap_file`), serves them with `gzip_static` and
  `X-Sitemap-Static: 1`; a missing file falls back to PHP.

```
/opt/services/savepearlharbor.com/bin/sitemap-gen.py          # incremental
/opt/services/savepearlharbor.com/bin/sitemap-gen.py --full   # re-render all pages
```

## Page cache

nginx `fastcgi_cache` (zone `sph`, tmpfs 1.6 GB at `/var/cache/nginx/sph`)
for anonymous GET/HEAD of an allow-list of URL shapes: `/`, `/?p=N`,
`/?paged=N`, `/?cat=N`, site/author feeds. Search, previews, admin, login,
REST and any request with a WP session cookie go to PHP. TTL comes from
`mu-plugins/sph-cache.php` (article 1 day, feeds 30 min, lists 10 min).
`X-Cache-Status` shows HIT/MISS/BYPASS. Purge everything: recreate the nginx
container, or `docker exec savepearlharborcom-nginx-1 sh -c 'rm -rf /var/cache/nginx/sph/*'`.
W3TC object cache is disabled (it wrote ~150 kB/s of cache files to disk).
