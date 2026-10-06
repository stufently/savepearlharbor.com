#!/bin/bash
# Backfill wp_posts.post_modified_gmt for imported posts (zero) from
# post_date_gmt, in 5000-ID chunks, pausing between chunks and backing off
# while sda write latency is high. post_modified itself is not touched.
set -u
LO=100000; HI=500000; STEP=5000
wstat() { awk '$3=="sda"{print $8, $11}' /proc/diskstats; }   # writes_completed, ms_writing
q() { docker exec -i savepearlharborcom-mysql-1 sh -c 'mysql -uhabr -p"$MYSQL_PASSWORD" habr -N -B' 2> >(grep -v "Using a password" >&2); }
total=0
for ((a=LO; a<HI; a+=STEP)); do
  b=$((a+STEP-1))
  read w0 t0 < <(wstat)
  n=$(printf "%s\n" "SET SESSION sql_mode='';" \
     "UPDATE wp_posts SET post_modified_gmt = post_date_gmt WHERE ID BETWEEN $a AND $b AND post_type='post' AND post_modified_gmt='0000-00-00 00:00:00' AND post_date_gmt<>'0000-00-00 00:00:00';" \
     "SELECT ROW_COUNT();" | q) || { echo "FAIL at $a-$b"; exit 1; }
  total=$((total+n))
  sleep 1.5
  read w1 t1 < <(wstat)
  dw=$((w1-w0)); aw=0; [ $dw -gt 0 ] && aw=$(( (t1-t0)/dw ))
  echo "$(date -u +%T) ids $a-$b updated=$n total=$total w_await~${aw}ms"
  if [ $aw -gt 15 ]; then echo "  backing off 30s"; sleep 30; fi
done
echo "DONE total=$total"
