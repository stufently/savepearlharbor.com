#!/usr/bin/env python3
"""Static snapshot of the WordPress core sitemaps of savepearlharbor.com.

nginx serves /?sitemap=... straight from <root>/current/*.xml (see nginx.conf,
"[4] static sitemaps") and falls back to PHP only when a file is missing.

Post sitemap pages are FIXED ID RANGES of <= 2000 posts (ascending ID), not
WordPress' OFFSET pages: a new post only ever touches the last page, so a run
re-renders just the pages whose signature changed. Signature of a range =
COUNT(*) | MAX(post_modified_gmt) | SUM(ID) over published posts in it, so a
new post, an edited one (post_modified) and a deleted/unpublished one are all
caught. Page/category/user sitemaps and the index are cheap and rendered on
every run. The XML itself comes from WordPress (sitemap-worker.php: core
provider + core renderer), so <loc>/<lastmod> are exactly what WP emits.

Every run builds a new generation <root>/gen-<ts>/ (unchanged files are hard
links to the current one), validates it and switches <root>/current with one
atomic rename of a symlink. Any failure keeps the old set and exits non-zero.

  sitemap-gen.py           incremental (hourly cron, under flock)
  sitemap-gen.py --full    re-render every page, keep the page boundaries
"""
import argparse
import datetime
import fcntl
import gzip
import json
import os
import re
import shutil
import subprocess
import sys
import time
import xml.etree.ElementTree as ET

NS = "{http://www.sitemaps.org/schemas/sitemap/0.9}"
PREFIX = "https://savepearlharbor.com/?"
PAGE_SIZE = 2000          # wp_sitemaps_get_max_urls(); a page is filled up to this
URL_LIMIT = 50000         # sitemaps.org hard limit per file
KEEP_GENERATIONS = 2
WORKER = os.path.join(os.path.dirname(os.path.abspath(__file__)), "sitemap-worker.php")

# query string -> file name. MUST stay in sync with map $sph_sitemap_file in
# nginx.conf (same regexes, same names).
RULES = [
    (re.compile(r"^sitemap=index$"), "index", None),
    (re.compile(r"^sitemap=posts&sitemap-subtype=(post|page)&paged=([1-9][0-9]{0,3})$"),
     "posts-{0}-{1}", ("posts", 0, 1)),
    (re.compile(r"^sitemap=taxonomies&sitemap-subtype=(category|post_tag)&paged=([1-9][0-9]{0,3})$"),
     "taxonomies-{0}-{1}", ("taxonomies", 0, 1)),
    (re.compile(r"^sitemap=users&paged=([1-9][0-9]{0,3})$"), "users-{0}", ("users", None, 0)),
]


def log(msg):
    ts = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    print(f"{ts} {msg}", flush=True)


def job_for_query(q):
    """'sitemap=taxonomies&sitemap-subtype=category&paged=1' -> render job"""
    for rx, fmt, spec in RULES:
        m = rx.match(q)
        if m and spec:
            g = m.groups()
            provider, sub_i, page_i = spec
            return {"name": fmt.format(*g), "kind": "wp", "provider": provider,
                    "subtype": g[sub_i] if sub_i is not None else "", "page": int(g[page_i])}
    return None


class Worker:
    def __init__(self, container, user):
        self.container, self.user = container, user
        self.calls = 0
        self.src = open(WORKER, "rb").read()

    def __call__(self, cmd, arg):
        self.calls += 1
        r = subprocess.run(
            ["docker", "exec", "-i", "-u", self.user, self.container,
             "php", "-d", "memory_limit=1024M", "--", cmd, json.dumps(arg)],
            input=self.src, capture_output=True, timeout=600)
        if r.returncode != 0:
            raise RuntimeError(f"worker {cmd}: rc={r.returncode} {r.stderr.decode()[-500:]}")
        try:
            return json.loads(r.stdout)
        except json.JSONDecodeError as e:
            raise RuntimeError(f"worker {cmd}: not JSON ({e}): {r.stdout[:200]!r}") from None


def check_xml(name, body, root_tag, item_tag, expect=None):
    if not body.strip():
        raise RuntimeError(f"{name}: empty")
    try:
        root = ET.fromstring(body)
    except ET.ParseError as e:
        raise RuntimeError(f"{name}: invalid XML ({e})") from None
    if root.tag != NS + root_tag:
        raise RuntimeError(f"{name}: root {root.tag}, expected {root_tag}")
    locs = [el.findtext(NS + "loc") for el in root.iter(NS + item_tag)]
    if any(not loc or not loc.startswith(PREFIX[:-1]) for loc in locs):
        raise RuntimeError(f"{name}: empty/foreign <loc>")
    if len(locs) > URL_LIMIT:
        raise RuntimeError(f"{name}: {len(locs)} entries > {URL_LIMIT}")
    if expect is not None and len(locs) != expect:
        raise RuntimeError(f"{name}: {len(locs)} entries, signature says {expect}")
    return locs


def allocate(ranges, counts, tail):
    """Append new post IDs (ascending) to the ranges: fill the last page up to
    PAGE_SIZE, then open new pages. Ranges stay contiguous (lo = prev hi + 1)."""
    ranges = [list(r) for r in ranges]
    counts = list(counts)
    for pid in tail:
        if not ranges or counts[-1] >= PAGE_SIZE:
            lo = ranges[-1][1] + 1 if ranges else 1
            ranges.append([lo, pid])
            counts.append(1)
        else:
            ranges[-1][1] = pid
            counts[-1] += 1
    return ranges


def run(a):
    t0 = time.monotonic()
    w = Worker(a.container, a.user)
    current = os.path.join(a.root, "current")
    cur_dir = os.path.realpath(current) if os.path.islink(current) else None
    state = {}
    if cur_dir and os.path.exists(os.path.join(cur_dir, "state.json")):
        state = json.load(open(os.path.join(cur_dir, "state.json")))
    old_pages = state.get("pages", [])          # [{"lo","hi","sig"}], index = page-1
    full = a.full or not old_pages

    # 1. signatures; new posts extend the ranges, then re-sign the result
    s = w("sigs", [[p["lo"], p["hi"]] for p in old_pages])
    ranges = allocate([[p["lo"], p["hi"]] for p in old_pages],
                      [r["count"] for r in s["ranges"]], s["tail"])
    s2 = w("sigs", ranges)
    sigs = s2["ranges"]
    if old_pages and len(ranges) < len(old_pages):
        raise RuntimeError("page count went down")  # cannot happen: ranges are append-only
    new_posts = len(s["tail"])

    # 2. which post pages to render
    todo = []
    for i, (rng, sg) in enumerate(zip(ranges, sigs)):
        old = old_pages[i] if i < len(old_pages) else None
        if full or not old or [old["lo"], old["hi"]] != rng or old["sig"] != sg["sig"]:
            todo.append(i)
    # a file missing from the current generation is re-rendered too
    for i in range(len(ranges)):
        if i not in todo and not (cur_dir and os.path.exists(
                os.path.join(cur_dir, f"posts-post-{i + 1}.xml"))):
            todo.append(i)
    todo.sort()

    files = {}                                   # name -> bytes (newly rendered)
    for n, i in enumerate(todo):
        if n:
            time.sleep(a.pause)
        name = f"posts-post-{i + 1}"
        r = w("render", [{"name": name, "kind": "post", "lo": ranges[i][0], "hi": ranges[i][1]}])[name]
        body = r["xml"].encode()
        check_xml(name, body, "urlset", "url", expect=sigs[i]["count"])
        files[name] = body

    # 3. index (+ the list of non-post sitemaps), then those sitemaps
    idx = w("render", [{"name": "index", "kind": "index",
                        "posts": [{"page": i + 1, "lastmod": sg["lastmod"]} for i, sg in enumerate(sigs)]}])["index"]
    body = idx["xml"].encode()
    locs = check_xml("index", body, "sitemapindex", "sitemap")
    post_locs = [l for l in locs if "sitemap-subtype=post&" in l]
    if len(post_locs) != len(ranges):
        raise RuntimeError(f"index: {len(post_locs)} post pages, expected {len(ranges)}")
    files["index"] = body
    jobs = []
    for loc in idx["others"]:
        j = job_for_query(loc[len(PREFIX):]) if loc.startswith(PREFIX) else None
        if not j:
            raise RuntimeError(f"index: no static name for {loc}")
        jobs.append(j)
    if jobs:
        time.sleep(a.pause)
        out = w("render", jobs)
        for j in jobs:
            b = out[j["name"]]["xml"].encode()
            n = len(check_xml(j["name"], b, "urlset", "url", expect=out[j["name"]]["count"]))
            # no independent count for these: refuse to replace a non-empty
            # sitemap with an empty one (a silently failed query looks like that)
            prev = os.path.join(cur_dir, j["name"] + ".xml") if cur_dir else None
            if n == 0 and prev and os.path.exists(prev) and b"<url>" in open(prev, "rb").read():
                raise RuntimeError(f"{j['name']}: empty now, non-empty before")
            files[j["name"]] = b

    wanted = [f"posts-post-{i + 1}" for i in range(len(ranges))] + [j["name"] for j in jobs] + ["index"]
    changed = [n for n in wanted if n in files and not (
        cur_dir and os.path.exists(os.path.join(cur_dir, n + ".xml"))
        and open(os.path.join(cur_dir, n + ".xml"), "rb").read() == files[n])]
    new_state = {"pages": [{"lo": r[0], "hi": r[1], "sig": sg["sig"]} for r, sg in zip(ranges, sigs)],
                 "files": wanted}
    stale = set(os.listdir(cur_dir)) if cur_dir else set()
    stale = {f[:-4] for f in stale if f.endswith(".xml")} - set(wanted)
    if cur_dir and not changed and not stale and not full and new_state == state:
        log(f"OK incremental: no changes, rendered={len(todo)} queries={w.calls} "
            f"pages={len(ranges)} in {time.monotonic() - t0:.1f}s")
        return 0

    # 4. new generation: rendered files + hard links for the rest, then switch
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    gen = os.path.join(a.root, f"gen-{stamp}")
    tmp = gen + ".tmp"
    os.makedirs(tmp, mode=0o755)
    try:
        for n in wanted:
            if n in files:
                with open(os.path.join(tmp, n + ".xml"), "wb") as f:
                    f.write(files[n])
                with open(os.path.join(tmp, n + ".xml.gz"), "wb") as f:   # for gzip_static
                    f.write(gzip.compress(files[n], compresslevel=9, mtime=0))
            else:
                for ext in (".xml", ".xml.gz"):
                    os.link(os.path.join(cur_dir, n + ext), os.path.join(tmp, n + ext))
        with open(os.path.join(tmp, "state.json"), "w") as f:
            json.dump(new_state, f)
        for n in os.listdir(tmp):
            os.chmod(os.path.join(tmp, n), 0o644)
        os.chmod(tmp, 0o755)
        os.rename(tmp, gen)
        link_tmp = os.path.join(a.root, ".current.tmp")
        if os.path.lexists(link_tmp):
            os.unlink(link_tmp)
        os.symlink(os.path.basename(gen), link_tmp)   # relative: also resolves inside the container
        os.replace(link_tmp, current)                 # the atomic switch
    except BaseException:
        shutil.rmtree(tmp, ignore_errors=True)
        raise
    gens = sorted(d for d in os.listdir(a.root) if d.startswith("gen-") and not d.endswith(".tmp"))
    for d in gens[:-KEEP_GENERATIONS]:
        shutil.rmtree(os.path.join(a.root, d), ignore_errors=True)
    size = sum(os.path.getsize(os.path.join(gen, f)) for f in os.listdir(gen))
    log(f"OK {'full' if full else 'incremental'}: {os.path.basename(gen)} pages={len(ranges)} "
        f"new_posts={new_posts} rendered_post_pages={len(todo)} changed_files={len(changed)} "
        f"removed={len(stale)} queries={w.calls} urls={sum(sg['count'] for sg in sigs)} "
        f"size={size}B in {time.monotonic() - t0:.1f}s")
    return 0


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--root", default="/opt/services/savepearlharbor.com/sitemap-static")
    ap.add_argument("--container", default="savepearlharborcom-php-1")
    ap.add_argument("--user", default="33:33", help="www-data, the uid WordPress runs as")
    ap.add_argument("--pause", type=float, default=1.0, help="seconds between page renders")
    ap.add_argument("--full", action="store_true")
    a = ap.parse_args()
    os.makedirs(a.root, mode=0o755, exist_ok=True)
    lock = open(os.path.join(a.root, ".lock"), "w")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        log("another run holds the lock, skipping")
        return 75
    try:
        return run(a)
    except Exception as e:
        log(f"FAIL ({'full' if a.full else 'incremental'}): {e}; current set kept")
        return 1


if __name__ == "__main__":
    sys.exit(main())
