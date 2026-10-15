# Chapter 17 — Resources, logging and monitoring

## What you'll learn

- How cgroups limit memory, CPU and processes, and what happens at each limit
- How Node.js sizes its heap inside a container, and why small containers need one setting
- Telling a container OOM kill (exit code 137) from a JavaScript heap out-of-memory crash
- CPU limits, throttling, and why `os.availableParallelism()` ignores `--cpus`
- PID limits and ulimits
- Where container logs go: logging drivers, rotation, blocking vs. non-blocking delivery
- Structured JSON logs from Node.js with pino, with request IDs
- `docker stats`, `docker events` and health status for day-to-day monitoring
- Exposing Prometheus metrics from Snip, and what a full metrics stack looks like
- Snip with limits on every service, rotated logs, and a Valkey memory cap that protects the click queue

## Why limits matter

Without limits, a container can use all of the host's memory and CPU. One leaking Node.js process can push the whole
machine into swapping or trigger the kernel's out-of-memory killer, which then picks *some* process to kill, maybe
your database. Limits turn "the server fell over" into "one container restarted", and they make behavior predictable:
a container that works with 256 MB in testing will have exactly 256 MB in production.

Limits are enforced by **cgroups** (control groups), a kernel feature that groups processes and meters what the group
uses (chapter 20 covers the internals). Docker creates one cgroup per container. Two versions exist:

```bash
docker info --format '{{.CgroupVersion}} {{.CgroupDriver}}'
```

```text
2 systemd
```

Current Linux distributions use cgroup v2, which this chapter assumes when it shows files under `/sys/fs/cgroup`. On
an older cgroup v1 host, the limits behave the same but the files have other names (`memory/memory.limit_in_bytes`
instead of `memory.max`, for example).

## Memory limits

```bash
docker run -d --name api -m 256m snip:1.8
```

`-m` (`--memory`) is a **hard limit**: when the processes in the container need more and the kernel can't reclaim
enough (by dropping file caches, for example), the kernel's OOM killer kills a process in the container. Related
settings:

| Flag | Compose | Meaning |
|---|---|---|
| `-m 256m` | `deploy.resources.limits.memory: 256M` (or `mem_limit`) | Hard limit |
| `--memory-reservation 128m` | `deploy.resources.reservations.memory` (or `mem_reservation`) | Soft limit: under memory pressure on the host, the kernel reclaims down toward it first |
| `--memory-swap 256m` | `memswap_limit` | Memory **plus** swap. Defaults to twice `-m` when the host has swap; set it equal to `-m` to forbid swapping |
| `--oom-kill-disable` | `oom_kill_disable` | Never use it: the container hangs instead of dying |

Inside the container, the limit is visible in the cgroup filesystem, and Node.js reads it too:

```bash
docker run --rm -m 256m node:24-alpine sh -c 'cat /sys/fs/cgroup/memory.max; node -p "process.constrainedMemory() / 1048576"'
```

```text
268435456
256
```

`free` and `os.totalmem()` still show the *host's* memory; they don't know about cgroups. Anything that sizes itself
from total memory (some JVM versions used to, many caches do) needs to be told the real limit.

### How Node.js sizes its heap

V8 stores JavaScript objects in its heap, whose maximum size (`heap_size_limit`) is fixed when the process starts.
Current Node.js versions pick it from the container's memory limit. Measured with Node.js 24.22 on a 16 GB host:

```bash
for m in 128m 256m 512m 1g 2g 4g; do
  echo -n "$m: "
  docker run --rm -m $m node:24-slim node -p 'Math.round(v8.getHeapStatistics().heap_size_limit / 1048576)'
done
```

| Container limit | Heap limit (MB) |
|---|---|
| 128 MB | 259 |
| 256 MB | 259 |
| 512 MB | 259 |
| 1 GB | 560 |
| 2 GB | 1120 |
| 4 GB | 2240 |
| none (16 GB host) | 4288 |

From 1 GB up, the heap gets about half the limit, which leaves room for everything that isn't heap: `Buffer`s, native
modules, thread stacks, the code itself. Up to 512 MB, though, the heap limit stayed at about 259 MB in these measurements, so a
256 MB container has a heap limit *larger than the container*. A memory leak then doesn't end with a clear
JavaScript error; the kernel kills the process from outside.

The fix is one setting. `--max-old-space-size=<MB>` sets the heap size directly, and the newer
`--max-old-space-size-percentage=<n>` (added during the Node.js 24 line; check `node --help`) sets it as a percentage
of the memory limit, so the same setting works for every container size:

```bash
docker run --rm -m 256m -e NODE_OPTIONS=--max-old-space-size-percentage=60 node:24-slim \
  node -p 'Math.round(v8.getHeapStatistics().heap_size_limit / 1048576)'
```

```text
156
```

`NODE_OPTIONS` works for every `node` process in the container, including ones started by `node --watch`, so it's
the best place for it. 50–75% is a reasonable range: lower for apps that use a lot of `Buffer` memory (file
uploads, streaming), higher for apps whose memory is mostly objects.

### Two ways to run out of memory

They look completely different, so learn to tell them apart. First, memory outside the heap, which `Buffer`s use:

```bash
docker run --name oom1 -m 256m node:24-alpine node -e '
  const a = [];
  setInterval(() => { a.push(Buffer.alloc(32 << 20, 1)); console.log("rss", process.memoryUsage().rss >> 20, "MB") }, 200)'
docker inspect oom1 --format 'exit={{.State.ExitCode}} oom={{.State.OOMKilled}}'
```

```text
rss 210 MB
rss 242 MB
rss 274 MB
exit=137 oom=true
```

No error message at all: the process is just gone. Exit code **137** is 128 + 9, killed by `SIGKILL`, and
`OOMKilled` is `true`. Docker records it as an `oom` event too (below). Without the heap setting, filling the
*heap* in a 256 MB container ends the same way, because the heap limit (259 MB) is never reached.

Second, the heap limit, with the setting in place:

```bash
docker run --name oom3 -m 256m node:24-alpine node --max-old-space-size=160 -e '
  const a = []; setInterval(() => { for (let i = 0; i < 200000; i++) a.push({ i, s: "x" + i }) }, 50)'
docker inspect oom3 --format 'exit={{.State.ExitCode}} oom={{.State.OOMKilled}}'
```

```text
<--- Last few GCs --->
[1:0x7f37830fe000]     1577 ms: Mark-Compact (reduce) 159.5 (160.9) -> 159.3 (161.4) MB, ...
FATAL ERROR: Ineffective mark-compacts near heap limit Allocation failed - JavaScript heap out of memory
----- Native stack trace -----
exit=139 oom=false
```

Now there's a clear message in the log, the GC history shows that the heap was full, and `OOMKilled` is `false`.
Node.js aborts the process on a heap OOM, which normally means exit code 134 (`SIGABRT`). As PID 1 (no `--init`),
the signal can't kill the process the usual way and it ended with 139 instead in this test; with `--init` it was 134.
Either way, the log says "JavaScript heap out of memory".

| Symptom | Cause | Where to look |
|---|---|---|
| Exit 137, `OOMKilled=true`, no message | The container's memory limit | Memory outside the heap (Buffers, native code), or a heap limit larger than the container |
| Exit 134 or 139, "JavaScript heap out of memory" | V8's heap limit | A leak in JavaScript objects, or a heap set too small for the workload |
| Exit 137, `OOMKilled=false` | A `SIGKILL` from someone else | `docker kill`, or a stop timeout that ran out (chapter 18) |

> **Watch out:** with a restart policy, the restarted container has `OOMKilled=false` again. A climbing
> `RestartCount` in `docker inspect`, and the `oom` events in `docker events` (below), are the reliable records.

### Changing limits on a running container

`docker update` changes most limits live:

```bash
docker update --memory 384m --memory-swap 384m snip-api-1
```

The cgroup changes immediately, but a Node.js process that's already running keeps the heap limit it chose at
startup, and Compose doesn't know about the change: the next `docker compose up` that recreates the container goes
back to what `compose.yaml` says. Use `docker update` for an emergency, then fix the Compose file.

## CPU limits

| Flag | Compose | Meaning |
|---|---|---|
| `--cpus 1.5` | `deploy.resources.limits.cpus: "1.5"` (or `cpus`) | At most 1.5 CPUs' worth of time per scheduling period |
| `--cpu-shares 512` | `cpu_shares` | A relative weight (default 1024), only matters when CPUs are contended |
| `--cpuset-cpus 0,1` | `cpuset` | Run only on these CPU cores |

`--cpus` is a quota: in every 100 ms period, the container's processes may use `cpus × 100 ms` of CPU time in total.
A busy loop with `--cpus 0.5`:

```bash
docker run -d --name burn --cpus 0.5 node:24-alpine node -e 'for (;;) {}'
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.PIDs}}' burn
docker exec burn cat /sys/fs/cgroup/cpu.max /sys/fs/cgroup/cpu.stat
docker rm -f burn
```

```text
NAME   CPU %     MEM USAGE / LIMIT     MEM %   PIDS
burn   50.47%    10.58MiB / 15.72GiB   0.07%   7

50000 100000
…
nr_periods 53
nr_throttled 52
…
```

`cpu.max` is the quota and the period in microseconds. Once the quota is used up, the processes are **throttled**:
paused until the next period. For a web server that's latency: a request that needs 60 ms of CPU in a container with
`--cpus 0.5` can't finish in less than about 100 ms. A rising `nr_throttled` is the signal that the limit is too tight.

CPU limits don't change what the process sees as the number of CPUs:

```bash
docker run --rm --cpus 1 node:24-alpine node -p 'os.availableParallelism()'          # 4, the host's count
docker run --rm --cpuset-cpus 0,1 node:24-alpine node -p 'os.availableParallelism()'  # 2
```

`os.availableParallelism()` counts the CPUs the process may *run on* (`--cpuset-cpus` changes that), not how much
time it gets. That matters for anything that starts one worker per CPU: the `cluster` module, worker-thread pools,
`UV_THREADPOOL_SIZE` heuristics, some bundlers. In a container with `--cpus 1` on a 64-core host, starting 64 workers
just divides one CPU 64 ways, with 64 times the memory. Make the worker count a configuration setting.

For a single Node.js process, which runs JavaScript on one thread, a limit of 1 CPU is usually right; more helps only
with garbage collection threads, the libuv thread pool (crypto, compression, file I/O) and worker threads.

## PIDs and ulimits

**PID limits** cap the number of processes *and threads* in the container. They stop fork bombs and runaway process
spawning from exhausting the host's process table. Node.js itself already uses several threads:

```bash
docker run --rm --pids-limit 20 node:24-alpine node -e '
  const { spawn } = require("child_process"); let started = 0, failed = 0;
  for (let i = 0; i < 30; i++) {
    const child = spawn("sleep", ["5"]);
    child.on("spawn", () => started++);
    child.on("error", (err) => { if (!failed++) console.log(err.message) });
  }
  setTimeout(() => { console.log("started", started, "failed", failed); process.exit(0) }, 500)'
```

```text
spawn sleep EAGAIN
started 13 failed 17
```

Thirteen children plus Node.js's 7 threads make 20. Set it with `--pids-limit` or `deploy.resources.limits.pids` in
Compose; 100 is plenty for a typical Node.js service.

**ulimits** are per-process limits inherited from the daemon's configuration. The one that matters most is `nofile`,
the maximum number of open files, which includes sockets: a server with 10,000 open connections needs a `nofile` limit
above 10,000.

```bash
docker run --rm node:24-alpine sh -c 'ulimit -n; ulimit -Hn'                                 # the defaults
docker run --rm --ulimit nofile=1024:2048 node:24-alpine sh -c 'ulimit -n; ulimit -Hn'       # 1024, 2048
```

The defaults depend on how the Docker daemon was started (its `LimitNOFILE` setting, or `default-ulimits` in
`daemon.json`), so check them on your servers instead of assuming. In Compose:

```yaml
    ulimits:
      nofile:
        soft: 65536
        hard: 65536
```

## Logs

### Where logs go

A container's logs are whatever its main process writes to **stdout and stderr**. The Docker daemon reads both
streams and hands each line to the container's **logging driver**. That's why chapter 2 said to log to stdout instead
of to files: the platform collects stdout, and files inside the container disappear with it.

The default driver, `json-file`, writes every line as a JSON object to a file on the host:

```bash
docker inspect snip-api-1 --format '{{.LogPath}}'
sudo head -c 200 /var/lib/docker/containers/<id>/<id>-json.log
```

```text
/var/lib/docker/containers/5728…/5728…-json.log
{"log":"{\"level\":30,\"time\":1792060454669,\"msg\":\"tick\",\"i\":2677}\n","stream":"stdout","time":"2026-10-15T10:34:14.669524932Z"}
```

`docker logs` reads these files:

```bash
docker logs --tail 50 snip-api-1            # the last 50 lines
docker logs -f --since 10m snip-api-1       # the last 10 minutes, then follow
docker logs -t snip-api-1                   # with Docker's timestamp on each line
docker compose logs -f api worker           # several services, interleaved
docker compose logs --no-log-prefix api     # without the "api-1  | " prefix, for piping
```

### Rotation: the classic disk-full outage

`json-file` **does not rotate by default**. A chatty container on a server that runs for months fills the disk, and
then everything on the server fails at once. Set rotation per container:

```bash
docker run -d --log-opt max-size=10m --log-opt max-file=3 …
```

```text
5728…-json.log       136 bytes    (current)
5728…-json.log.1   10053 bytes
5728…-json.log.2   10060 bytes    (oldest; deleted at the next rotation)
```

…or once for every new container, in `/etc/docker/daemon.json` (then restart the daemon). Docker's documentation
recommends the `local` driver for this: it rotates by default (5 files of 20 MB), compresses rotated files, and uses a
more compact format. `docker logs` works the same with it:

```json
{
  "log-driver": "local",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
```

> **Watch out:** daemon defaults apply only to containers created *after* the change. Existing containers keep
> their logging configuration until they're recreated (`docker compose up -d --force-recreate`).

The main reason to keep `json-file` is that some log collectors read those files directly. Whichever you choose,
check that rotation is configured: `docker inspect <c> --format '{{.HostConfig.LogConfig}}'`.

### Other drivers

| Driver | Sends logs to |
|---|---|
| `json-file`, `local` | Files on the host (above) |
| `journald`, `syslog` | The host's journal or a syslog server |
| `fluentd`, `gelf` | Fluentd / Fluent Bit, Graylog or Logstash |
| `awslogs`, `gcplogs`, `splunk` | Cloud and commercial services |
| `none` | Nowhere |

With a remote driver, Docker also keeps a small local cache ("dual logging", on by default), so `docker logs` still
works. With `none` it doesn't:

```text
$ docker logs lg3
Error response from daemon: configured logging driver does not support reading
```

The other common approach skips remote drivers entirely: keep `local` or `json-file` on every host and run a log
collector (Fluent Bit, Vector, Grafana Alloy, the Datadog or Elastic agent…) that reads the files or the Docker API and
ships the lines. If the collector is down, the logs wait on disk instead of being lost.

### Blocking vs. non-blocking delivery

By default, delivery is **blocking**: if the driver can't keep up (a slow syslog server, a full disk), the
container's writes to stdout block, and so does a Node.js process that writes logs synchronously. The alternative
buffers lines in memory and drops them when the buffer is full:

```bash
docker run --log-opt mode=non-blocking --log-opt max-buffer-size=4m …
```

Dropping log lines is usually better than freezing the app; with the local file drivers, blocking is rarely a
problem in practice.

## Structured logs from Node.js

A log line like `recorded 3 clicks for 2 links` is easy to read and hard to query. A JSON object per line can be
filtered and aggregated by any log system without regular expressions: "every request with status 500 in the last
hour, grouped by route". **pino** is the most widely used structured logger for Node.js, and it's fast because it
does as little as possible in the process: it writes JSON to stdout, and formatting or shipping happens elsewhere.

```bash
npm install pino@10
```

`src/logger.js`:

```js
import pino from 'pino';

// Structured logs: one JSON object per line on stdout. Docker's logging driver stores the
// lines; anything that collects logs can parse them without regular expressions.
//   LOG_LEVEL   trace | debug | info (default) | warn | error | fatal | silent
// The default fields include pid and hostname, and a container's hostname is its ID,
// so every line says which container wrote it.
export const logger = pino({
  level: process.env.LOG_LEVEL ?? 'info',
  timestamp: pino.stdTimeFunctions.isoTime,
  formatters: {
    level: (label) => ({ level: label }),   // "level":"info" instead of "level":30
  },
});
```

Then pass objects first and the message second, so each fact gets its own field:

```js
logger.info({ clicks: reply[1].length, links: counts.size }, 'recorded clicks');
logger.error({ err }, 'database connection error');   // pino serializes the error with its stack
```

A child logger adds fields to every line it writes, which is how the worker labels its lines:

```js
const logger = root.child({ component: 'worker' });
```

### One line per request, with a request ID

The API logs each request once, when the response is finished, with a **request ID**. If a proxy in front (chapter
18) already sends an `X-Request-Id` header, Snip uses it, so the same ID appears in the proxy's log and in Snip's;
otherwise it generates one. The ID goes back to the client too, so a user reporting an error can quote it:

```js
app.use((req, res, next) => {
  const start = process.hrtime.bigint();
  req.id = req.get('x-request-id')?.slice(0, 64) ?? randomUUID();   // a proxy may already set one
  res.set('x-request-id', req.id);
  res.on('finish', () => {
    const seconds = Number(process.hrtime.bigint() - start) / 1e9;
    const route = req.route?.path ?? 'unmatched';
    observeRequest({ method: req.method, route, status: res.statusCode, seconds });
    if (route === '/healthz' || route === '/metrics') return;   // probes would drown everything else
    logger.info({
      reqId: req.id, method: req.method, url: req.originalUrl, route,
      status: res.statusCode, ms: Math.round(seconds * 1e4) / 10,
    }, 'request');
  });
  next();
});
```

And an error handler at the end logs failures with the same ID, without sending internals to the client:

```js
app.use((err, req, res, next) => {
  if (res.headersSent) return next(err);
  const status = err.status ?? err.statusCode ?? 500;
  if (status >= 500) logger.error({ err, reqId: req.id }, 'request failed');
  res.status(status).json({ error: status >= 500 ? 'internal error' : err.message });
});
```

The result, with the database stopped for the last request:

```text
{"level":"info","time":"2026-10-15T10:38:33.097Z","pid":1,"hostname":"2fad2e527469","reqId":"4265be3f-…","method":"GET","url":"/Fa58oCE","route":"/:code","status":302,"ms":1.2,"msg":"request"}
{"level":"info","time":"2026-10-15T10:38:33.125Z","pid":1,"hostname":"2fad2e527469","reqId":"abc-123","method":"GET","url":"/Fa58oCE","route":"/:code","status":302,"ms":2.6,"msg":"request"}
{"level":"error","time":"2026-10-15T10:38:45.010Z","pid":1,"hostname":"2fad2e527469","err":{"type":"Error","message":"getaddrinfo ENOTFOUND db","stack":"Error: getaddrinfo ENOTFOUND db\n    at …","code":"ENOTFOUND",…},"reqId":"5115758d-…","msg":"request failed"}
```

Because each line is JSON, a quick question needs only standard tools:

```bash
docker compose logs --no-log-prefix api | grep '"status":5' | wc -l
```

On a development machine, raw JSON is tiring to read. pino's companion tool formats it for humans:

> **Just a taste — you don't need to learn or fully understand this now.** Optional; only for reading logs locally.

```bash
docker compose logs -f --no-log-prefix api | npx pino-pretty
```

### Log at startup what you'll need when it crashes

Snip's first log line records the limits it runs under, so an OOM kill a week later can be explained from the log
alone:

```js
const limit = process.constrainedMemory();
logger.info({
  config: describeConfig(config),
  node: process.version,
  memoryLimitMB: limit > 0 && limit < os.totalmem() ? mb(limit) : null,
  heapLimitMB: mb(v8.getHeapStatistics().heap_size_limit),
}, 'starting');
```

```text
{"level":"info",…,"config":{"port":3000,"baseUrl":"http://localhost:3000","databaseUrl":"postgres://snip:***@db:5432/snip","valkeyUrl":"redis://valkey:6379"},"node":"v24.22.0","memoryLimitMB":256,"heapLimitMB":156,"msg":"starting"}
```

(Without a limit, `process.constrainedMemory()` returns 0 or a huge number, depending on the cgroup version; hence
the comparison with `os.totalmem()`.) The database password is still redacted, as in chapter 13. Never log secrets,
full request bodies or authorization headers; pino's `redact` option can remove known paths automatically.

## Watching containers

### `docker stats`

A live view of every running container's resource usage against its limits:

```bash
docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.PIDs}}'
```

```text
NAME            CPU %     MEM USAGE / LIMIT     MEM %     PIDS
snip-worker-1   0.03%     45.37MiB / 128MiB     35.44%    11
snip-api-1      0.48%     44.73MiB / 256MiB     17.47%    11
snip-db-1       0.05%     22.8MiB / 1GiB        2.23%     9
snip-valkey-1   0.31%     3.922MiB / 256MiB     1.53%     9
```

The CPU column is a percentage of *one* CPU, so a container using two full cores shows 200%. Memory usage excludes
reclaimable file cache. `docker compose stats` does the same for one project.

### `docker events`

The daemon publishes an event for every state change. They're the best record of what happened and when:

```bash
docker events --filter type=container \
  --format '{{.Action}} {{.Actor.Attributes.name}} {{index .Actor.Attributes "exitCode"}}'
```

```text
create ev1
start ev1
oom ev1
die ev1 137
create ev2
start ev2
health_status: unhealthy ev2
kill ev2
die ev2 137
```

Useful filters: `--filter event=oom`, `--filter event=die`, `--filter container=snip-api-1`,
`--filter label=com.docker.compose.project=snip`. `--since 1h --until 0s` prints past events and exits instead of
following, which makes it a quick post-mortem: "what died in the last hour, and with which exit code?"

### Health status

The healthchecks from chapter 11 show up in `docker ps`, and in detail in `docker inspect`:

```bash
docker inspect snip-api-1 --format '{{.State.Health.Status}} {{.State.Health.FailingStreak}}'
docker inspect snip-api-1 --format '{{json .State.Health.Log}}'   # the last 5 checks with their output
```

Plain Docker doesn't act on an unhealthy container, it only records it; `restart` policies react to the process
*exiting*, not to failed healthchecks. Compose uses health for `depends_on`, Swarm and orchestrators replace unhealthy
containers (chapter 21), and on a single server you can alert on `health_status: unhealthy` events.

## Metrics

Logs answer "what happened to this request?"; **metrics** answer "how is the system doing over time?" (request rate,
error rate, latency percentiles, memory). The most common format is Prometheus's: a plain-text page that a Prometheus
server **scrapes** every few seconds.

Snip serves one at `/metrics`. The module below is written by hand to show the format; a real app would use the
`prom-client` package, which does the same and much more:

```js
const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5]; // seconds
const series = new Map(); // 'GET|/:code|302' -> { labels, count, sum, buckets[] }

// Called once per finished request. `route` is the route pattern (/:code), never the raw
// URL: every distinct label value creates a new time series.
export function observeRequest({ method, route, status, seconds }) {
  const key = `${method}|${route}|${status}`;
  let s = series.get(key);
  if (!s) {
    s = { labels: `method="${method}",route="${route}",status="${status}"`, count: 0, sum: 0,
          buckets: BUCKETS.map(() => 0) };
    series.set(key, s);
  }
  s.count += 1;
  s.sum += seconds;
  BUCKETS.forEach((le, i) => { if (seconds <= le) s.buckets[i] += 1; });
}
```

`renderMetrics()` prints those counters, plus gauges for memory, the heap limit and event loop delay
(`examples/ch17-observability/src/metrics.js` has the whole file):

```bash
curl -s localhost:3000/metrics | grep -E '^http_requests_total|^nodejs|^process'
```

```text
http_requests_total{method="GET",route="/healthz",status="200"} 1
http_requests_total{method="POST",route="/api/links",status="201"} 1
http_requests_total{method="GET",route="/:code",status="302"} 21
http_requests_total{method="GET",route="/:code",status="404"} 1
process_resident_memory_bytes 95645696
nodejs_heap_used_bytes 27353936
nodejs_heap_size_limit_bytes 163577856
nodejs_external_memory_bytes 3149932
nodejs_eventloop_delay_p99_seconds 0.021823487
process_uptime_seconds 12.508583461
```

Two rules from this small example matter in every real one:

- **Label values must come from a small, fixed set.** The route *pattern* `/:code` is fine; the URL `/Fa58oCE` would
  create a new series for every short link and eventually exhaust Prometheus's memory. The same goes for user IDs,
  request IDs and raw error messages: those belong in logs.
- **`/metrics` is internal.** It reveals traffic and internals. In chapter 18 the reverse proxy doesn't forward it, so
  only something inside the Docker network (Prometheus) can reach it.

A complete metrics setup adds a Prometheus server that scrapes Snip, **cAdvisor** for per-container CPU, memory,
network and disk metrics, and Grafana for dashboards and alerts. As Compose services it looks roughly like this:

> **Just a taste — you don't need to learn or fully understand this now.** Running Prometheus and Grafana is a topic of
> its own; this shows how they would fit next to Snip.

```yaml
  prometheus:
    image: prom/prometheus
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml:ro   # scrape_configs: targets api:3000, cadvisor:8080
      - promdata:/prometheus
  cadvisor:
    image: gcr.io/cadvisor/cadvisor
    volumes:
      - /:/rootfs:ro
      - /var/run:/var/run:ro
      - /sys:/sys:ro
      - /var/lib/docker/:/var/lib/docker:ro
  grafana:
    image: grafana/grafana
    ports:
      - "127.0.0.1:3001:3000"
```

The Docker daemon can expose its own metrics too (`"metrics-addr": "127.0.0.1:9323"` in `daemon.json`), and hosted
services (Datadog, Grafana Cloud, New Relic and others) replace all three with an agent container.

What to alert on, at minimum: the container restarted or was OOM-killed, the healthcheck is failing, the error rate
(5xx responses) is above normal, latency p99 is above your target, memory is close to the limit, and disk space on the
host (where volumes, images and logs live) is running out.

## Snip with limits and rotated logs

`examples/ch17-observability/` is chapter 16's hardened Snip with pino logging in every process, `/metrics`, and these
changes to `compose.yaml`:

```yaml
# Every service logs to rotated files: at most 3 × 10 MB each, then the oldest is deleted.
x-logging: &logging
  driver: json-file
  options:
    max-size: 10m
    max-file: "3"

x-snip: &snip
  build: .
  image: snip:1.9
  restart: unless-stopped
  logging: *logging
  environment: &snip-env
    DATABASE_URL: postgres://snip@db:5432/snip
    DATABASE_PASSWORD_FILE: /run/secrets/db_password
    VALKEY_URL: redis://valkey:6379
    # Below 512 MB of memory limit, Node.js picks a heap limit larger than the container's:
    # a leak would be OOM-killed instead of failing with a clear error. 60% leaves room for
    # buffers, native memory and the code itself.
    NODE_OPTIONS: --max-old-space-size-percentage=60
  # … secrets and chapter 16's hardening …
  deploy:
    resources:
      limits:
        cpus: "1"
        memory: 256M
        pids: 100

services:
  worker:
    <<: *snip
    command: ["src/worker.js"]
    deploy:                  # replaces the anchor's deploy: the worker needs less
      resources:
        limits:
          cpus: "0.5"
          memory: 128M
          pids: 50
    # …

  db:
    image: postgres:18-alpine
    logging: *logging
    deploy:
      resources:
        limits:
          cpus: "2"
          memory: 1G
    # /dev/shm is 64 MB by default; PostgreSQL uses it for parallel queries.
    shm_size: 256m
    # …

  valkey:
    image: valkey/valkey:9-alpine
    logging: *logging
    deploy:
      resources:
        limits:
          cpus: "0.5"
          memory: 256M
    # Valkey's own limit is well below the container's: rewriting the append-only file forks
    # a child process whose memory counts against the same limit. volatile-lru evicts only
    # keys with an expiry (the url:* cache), never the clicks queue.
    command: ["valkey-server", "--appendonly", "yes",
              "--maxmemory", "96mb", "--maxmemory-policy", "volatile-lru"]
    # …
```

`deploy.resources` works with plain `docker compose up`; it isn't only for Swarm. Check that the limits arrived:

```bash
docker inspect snip-api-1 --format '{{.HostConfig.Memory}} {{.HostConfig.NanoCpus}} {{.HostConfig.PidsLimit}} {{.HostConfig.LogConfig}}'
```

```text
268435456 1000000000 100 map[Config:map[max-file:3 max-size:10m] Type:json-file]
```

### The Valkey limits, found the hard way

Valkey is a cache *and* the click queue here, so both its memory policy and its limits needed care.

**The policy.** With `maxmemory`, Valkey evicts keys when it's full. `allkeys-lru` could evict the `clicks` list, and
the clicks in it would be lost. `volatile-lru` evicts only keys that have an expiry, and only the `url:*` cache entries
have one. Filling Valkey with 400,000 cache entries of 1 KB confirmed it: 340,327 keys were evicted, memory stayed at
96 MB, and a test list survived.

**The headroom.** The first version used `--maxmemory 128mb` in a 192 MB container. The same test got Valkey
OOM-killed, twice:

```text
oom
die 137
start
health_status: healthy
```

Valkey's memory use exceeds `maxmemory` for short periods (a large command runs before eviction catches up; the peak
was 138 MB), and with `--appendonly yes` it regularly rewrites its log in a *forked child process*. The child shares
memory with the parent copy-on-write, but every page the parent changes during the rewrite is copied, and all of it
counts against the container's limit. The fix was a lower `maxmemory` (96 MB) and a higher container limit (256 MB).
The same applies to PostgreSQL (`shared_buffers` plus per-connection memory) and to anything that forks: the
container limit has to cover the application's *peak*, not its configured size.

Notice, too, that nothing in Valkey's log mentioned the kills: the restart policy brought it back, the healthcheck
went green, and only `docker events` (or a climbing `RestartCount`) told the story.

## Key takeaways

- Set a memory limit on every container. At the limit, the kernel kills a process: exit code 137 and
  `OOMKilled=true`, with nothing in the app's log.
- Node.js sizes its heap from the container's limit, but never below about 259 MB. In containers under 512 MB, set
  `NODE_OPTIONS=--max-old-space-size-percentage=60` (or `--max-old-space-size`), so a leak ends with a clear
  "JavaScript heap out of memory" instead of a silent kill.
- `--cpus` is a time quota: too low a limit shows up as throttling and latency, not as errors. It doesn't change
  `os.availableParallelism()`, so make worker counts configurable.
- Limit PIDs (`pids: 100`), and raise `nofile` for servers with many connections.
- Containers log to stdout and stderr; the logging driver stores the lines. Configure rotation (or use the `local`
  driver) on every host, or logs will eventually fill the disk.
- Log JSON with pino: one line per request with a request ID, errors with their stack, the limits at startup, and
  never secrets.
- `docker stats` shows usage against limits; `docker events` is the record of OOM kills, deaths and health changes,
  which survives restarts.
- Expose Prometheus metrics with low-cardinality labels, keep `/metrics` internal, and alert on restarts, OOM kills,
  failing healthchecks, error rate, latency and disk space.
- A container limit must cover the peak: forks, buffers and short bursts above a configured size.

## Exercises

1. **Your heap limits.** On your machine, measure the heap limit Node.js chooses for containers of 128 MB, 384 MB,
   768 MB and 3 GB, with and without `--max-old-space-size-percentage=60`. Which containers would be OOM-killed
   before V8 reported a heap error?

<details>
<summary>Solution</summary>

```bash
for m in 128m 384m 768m 3g; do
  default=$(docker run --rm -m $m node:24-slim node -p 'Math.round(v8.getHeapStatistics().heap_size_limit / 1048576)')
  pct=$(docker run --rm -m $m -e NODE_OPTIONS=--max-old-space-size-percentage=60 node:24-slim \
    node -p 'Math.round(v8.getHeapStatistics().heap_size_limit / 1048576)')
  echo "$m: default $default MB, with 60% $pct MB"
done
```

By default, 128 MB and 384 MB get about 259 MB of heap: the 128 MB container's heap limit is twice its memory limit,
and the 384 MB container has only about 125 MB left for everything else, so both would be killed by the kernel before
V8 complains. 768 MB and 3 GB get roughly half their limit. With 60%, every heap limit is below the container's
limit (plus a few MB for the young generation), with 40% left for the rest.

</details>

2. **OOM detective.** Run this container, which leaks memory and restarts on failure. After a minute, determine from
   Docker alone (not the app's output) what happened to it, and why `docker inspect`'s `OOMKilled` is misleading.

   ```bash
   docker run -d --name leaky --restart on-failure -m 64m node:24-alpine node -e '
     const a = []; setInterval(() => a.push(Buffer.alloc(8 << 20, 1)), 500)'
   ```

<details>
<summary>Solution</summary>

```bash
docker inspect leaky --format 'status={{.State.Status}} restarts={{.RestartCount}} oom={{.State.OOMKilled}} exit={{.State.ExitCode}}'
docker events --since 2m --until 0s --filter container=leaky --format '{{.Action}} {{index .Actor.Attributes "exitCode"}}'
docker rm -f leaky
```

`RestartCount` keeps climbing, and the events show the cycle `oom`, `die 137`, `start`, over and over. `OOMKilled`
describes only the *current* run of the container, so whenever you look while it's running, it's `false`. (`on-failure`
backs off: Docker waits longer between restarts each time, so the cycle slows down.) In a real system, alert on
`oom` and `die` events and on restart counts rather than reading `OOMKilled`.

</details>

3. **Throttled.** Start a Node.js HTTP server that does about 20 ms of CPU work per request, first with `--cpus 2` and
   then with `--cpus 0.2`. Send 50 requests to each, and compare the total time and the container's `nr_throttled`.

<details>
<summary>Solution</summary>

```bash
cat > busy.js <<'EOF'
require('http').createServer((req, res) => {
  const end = Date.now() + 20;
  while (Date.now() < end) {}          // 20 ms of CPU
  res.end('done\n');
}).listen(3000);
EOF

for c in 2 0.2; do
  docker run -d --name busy --cpus $c -p 127.0.0.1:3050:3000 -v "$PWD/busy.js:/busy.js:ro" node:24-alpine node /busy.js
  sleep 1
  start=$(date +%s%N)
  for i in $(seq 50); do curl -s -o /dev/null localhost:3050; done
  echo "cpus=$c: $(( ($(date +%s%N) - start) / 1000000 )) ms"
  docker exec busy grep throttled /sys/fs/cgroup/cpu.stat
  docker rm -f busy >/dev/null
done
```

With 2 CPUs, 50 sequential requests take about 50 × 20 ms = 1 s plus overhead, and `nr_throttled` stays near 0. With
0.2 CPUs the container may use 20 ms per 100 ms period, so each request needs about a full period: roughly 5 seconds
in total, and `nr_throttled` climbs with every request. Nothing failed, and nothing in the app's log would explain the
slowness: throttling only shows in latency and in the cgroup's statistics (or cAdvisor's metrics). On a cgroup v1
host, the file is `/sys/fs/cgroup/cpu/cpu.stat`.

</details>

4. **Who's filling the disk?** A server's disk is full. Write the commands to find out whether container logs are the
   cause, which container, and fix it for the future without losing the running containers' current logs.

<details>
<summary>Solution</summary>

```bash
docker system df                                                  # images, containers, volumes, build cache
sudo du -sh /var/lib/docker/containers/*/*-json.log* | sort -h | tail
docker ps -a --format '{{.ID}} {{.Names}}'                         # match the IDs in the paths to names
docker inspect <name> --format '{{.HostConfig.LogConfig}}'        # no max-size: no rotation
```

To free space now, the safe options are to truncate the biggest file in place (`sudo truncate -s 0 <file>`; the
daemon keeps appending to the same file, and `docker logs` starts again from there) after saving what you
need, or to recreate the container. Then set rotation as
the default in `/etc/docker/daemon.json` (`"log-driver": "local"`, or `json-file` with `max-size` and `max-file`),
restart the daemon, and set `logging:` explicitly in your Compose files too, since daemon defaults apply only to new
containers. Recreate the containers (`docker compose up -d --force-recreate`) when convenient. Never delete the log
file itself while the container runs: the daemon keeps writing to the deleted file, and the space isn't freed.

</details>

5. **A cache metric.** Add a counter `snip_cache_requests_total` with a label `result` (`hit` or `miss`) to Snip,
   counted in `withValkey`'s `resolve`. Verify it with `curl localhost:3000/metrics` after five redirects to one new
   link. Then explain why `result` is an acceptable label and the short code isn't.

<details>
<summary>Solution</summary>

In `src/metrics.js`:

```js
const cache = { hit: 0, miss: 0 };
export function observeCache(result) { cache[result] += 1; }
```

…and in `renderMetrics()`, before the gauges:

```js
  lines.push('# HELP snip_cache_requests_total Redirect lookups by cache result.',
             '# TYPE snip_cache_requests_total counter');
  for (const [result, n] of Object.entries(cache)) {
    lines.push(`snip_cache_requests_total{result="${result}"} ${n}`);
  }
```

In `src/cached-store.js`:

```js
import { observeCache } from './metrics.js';
// …
    async resolve(code) {
      const key = `url:${code}`;
      const cached = await valkey.get(key);
      observeCache(cached ? 'hit' : 'miss');
      if (cached) return cached;
      // …
```

```bash
curl -s localhost:3000/metrics | grep snip_cache
```

```text
snip_cache_requests_total{result="hit"} 4
snip_cache_requests_total{result="miss"} 1
```

`result` has two possible values, so it creates two series forever. A label with the short code would create one
series per link, growing without bound, and every series costs memory in Prometheus. The hit ratio,
`rate(snip_cache_requests_total{result="hit"}[5m]) / rate(snip_cache_requests_total[5m])` in PromQL, is what you'd
graph and alert on; per-link questions belong in logs or in the database.

</details>

---
← Previous: [Container security](16-container-security.md) | Next: [Running in production](18-running-in-production.md) →
