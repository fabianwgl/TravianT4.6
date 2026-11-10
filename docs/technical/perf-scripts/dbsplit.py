#!/usr/bin/env python3
"""PHP-vs-DB split: per page, run a sequential (c=1) load with the MariaDB slow log (long_query_time=0, FILE output) on,
then aggregate the queries issued by the app container. Usage: dbsplit.py SESSIONS.json APP_IP SECONDS pages..."""
import json, subprocess, sys, re, collections
SP = "/private/tmp/claude-501/-Users-fwgl-dev/a0c24474-5633-486e-8cca-3448e3ff5591/scratchpad"
LOG = "/var/lib/mysql/a15be18e0b3c-slow.log"
def sql(q):
    return subprocess.check_output([f"{SP}/sql.sh", "-N", "-B", "-e", q]).decode()
def dc(cmd):
    return subprocess.check_output(["docker", "compose", "exec", "-T", "database", "sh", "-c", cmd]).decode(errors="replace")
sessions, app_ip, secs, pages = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4:]
sql("SET GLOBAL log_output='FILE'; SET GLOBAL long_query_time=0; SET GLOBAL slow_query_log=1")
try:
    for p in pages:
        dc(f": > {LOG}")
        out = json.loads(subprocess.check_output(["python3", "docs/technical/perf-scripts/loadgen.py", sessions, p, "1", secs]).decode())
        n = out["n"]
        txt = dc(f"cat {LOG}")
        q = 0; qt = 0.0; rex = 0; agg = collections.defaultdict(lambda: [0, 0.0, 0])
        for blk in re.split(r"\n(?=# User@Host:)", txt):
            if f"[{app_ip}]" not in blk: continue
            m = re.search(r"# Query_time: ([\d.]+)\s+Lock_time: ([\d.]+)\s+Rows_sent: (\d+)\s+Rows_examined: (\d+)", blk)
            if not m: continue
            stmt = blk.split("SET timestamp=", 1)[-1].split(";\n", 1)[-1].strip()
            key = re.sub(r"\d+", "N", stmt)[:110]
            t = float(m.group(1)); r = int(m.group(4))
            q += 1; qt += t; rex += r
            a = agg[key]; a[0] += 1; a[1] += t; a[2] += r
        top = sorted(agg.items(), key=lambda kv: -kv[1][1])[:4]
        print(json.dumps({"page": p, "requests": n, "mean_ms": out["mean_ms"], "queries_per_req": round(q / n, 1), "db_ms_per_req": round(qt / n * 1000, 2),
            "db_share_pct": round(qt / n * 1000 / out["mean_ms"] * 100, 1), "rows_examined_per_req": round(rex / n),
            "top": [f"{c/n:.1f}/req {t/n*1000:.2f}ms/req rows={r//n}/req {k}" for k, (c, t, r) in top]}))
finally:
    sql("SET GLOBAL slow_query_log=0; SET GLOBAL long_query_time=10")
