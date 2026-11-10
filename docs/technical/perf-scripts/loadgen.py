#!/usr/bin/env python3
"""Tiny closed-loop HTTP load generator (oha/wrk were not installed).
Usage: loadgen.py SESSIONS.json PAGE CONCURRENCY SECONDS
SESSIONS.json: [{"cookie": "PHPSESSID=...; lowRes=0", "token": "<ajax token>"}, ...]  (one per concurrent worker, PHP file sessions serialise per session)
"""
import http.client, json, sys, threading, time, statistics

PAGES = {
    "dorf1": ("GET", "/game/dorf1.php", None),
    "dorf2": ("GET", "/game/dorf2.php", None),
    "build_barracks": ("GET", "/game/build.php?id=19", None),
    "map_zoom1": ("POST", "/game/ajax.php?cmd=mapPositionData", "data[zoomLevel]=1&data[x]=0&data[y]=0"),
    "map_zoom3": ("POST", "/game/ajax.php?cmd=mapPositionData", "data[zoomLevel]=3&data[x]=0&data[y]=0"),
    "rally_send": ("GET", "/game/build.php?id=39&tt=2", None),
    "rally_sim": ("GET", "/game/build.php?id=39&tt=3", None),
    "reports": ("GET", "/game/berichte.php", None),
    "statistics": ("GET", "/game/statistiken.php", None),
}

def worker(sess, page, deadline, out, errs):
    method, path, body = PAGES[page]
    conn = http.client.HTTPConnection("127.0.0.1", 8080, timeout=60)
    hdr = {"Cookie": sess["cookie"]}
    if method == "POST":
        hdr["Content-Type"] = "application/x-www-form-urlencoded"
        hdr["X-CSRF-Token"] = sess["token"]
    while time.time() < deadline:
        t = time.perf_counter()
        try:
            conn.request(method, path, body=body, headers=hdr)
            r = conn.getresponse(); data = r.read()
            dt = time.perf_counter() - t
            if r.status != 200 or b"Fatal error" in data or b"outerLoginBox" in data:
                errs.append(r.status)
            else:
                out.append(dt)
        except Exception as e:
            errs.append(str(e)); conn.close(); conn = http.client.HTTPConnection("127.0.0.1", 8080, timeout=60)

def pct(v, p):
    v = sorted(v); return v[min(len(v) - 1, int(len(v) * p))]

if __name__ == "__main__":
    sessions = json.load(open(sys.argv[1])); page = sys.argv[2]; c = int(sys.argv[3]); secs = float(sys.argv[4])
    out, errs, th = [], [], []
    start = time.time(); deadline = start + secs
    for i in range(c):
        t = threading.Thread(target=worker, args=(sessions[i % len(sessions)], page, deadline, out, errs)); t.start(); th.append(t)
    for t in th: t.join()
    el = time.time() - start
    print(json.dumps({"page": page, "c": c, "n": len(out), "err": len(errs), "rps": round(len(out) / el, 1),
        "p50_ms": round(pct(out, .5) * 1000, 1), "p95_ms": round(pct(out, .95) * 1000, 1), "p99_ms": round(pct(out, .99) * 1000, 1),
        "mean_ms": round(statistics.mean(out) * 1000, 1)}))
