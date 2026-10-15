import { monitorEventLoopDelay } from 'node:perf_hooks';
import v8 from 'node:v8';

// A minimal Prometheus exporter, written by hand to show the format. A real app
// would use the prom-client package, which does the same and much more.

const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5]; // seconds
const series = new Map(); // 'GET|/:code|302' -> { labels, count, sum, buckets[] }
const loopDelay = monitorEventLoopDelay({ resolution: 20 });
loopDelay.enable();

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

export function renderMetrics() {
  const lines = [
    '# HELP http_requests_total HTTP requests by method, route and status.',
    '# TYPE http_requests_total counter',
  ];
  for (const s of series.values()) lines.push(`http_requests_total{${s.labels}} ${s.count}`);

  lines.push('# HELP http_request_duration_seconds HTTP request duration.',
             '# TYPE http_request_duration_seconds histogram');
  for (const s of series.values()) {
    BUCKETS.forEach((le, i) => {
      lines.push(`http_request_duration_seconds_bucket{${s.labels},le="${le}"} ${s.buckets[i]}`);
    });
    lines.push(`http_request_duration_seconds_bucket{${s.labels},le="+Inf"} ${s.count}`,
               `http_request_duration_seconds_sum{${s.labels}} ${s.sum}`,
               `http_request_duration_seconds_count{${s.labels}} ${s.count}`);
  }

  const memory = process.memoryUsage();
  const gauges = {
    process_resident_memory_bytes: memory.rss,
    nodejs_heap_used_bytes: memory.heapUsed,
    nodejs_heap_size_limit_bytes: v8.getHeapStatistics().heap_size_limit,
    nodejs_external_memory_bytes: memory.external,
    nodejs_eventloop_delay_p99_seconds: loopDelay.percentile(99) / 1e9,
    process_uptime_seconds: process.uptime(),
  };
  for (const [name, value] of Object.entries(gauges)) {
    lines.push(`# TYPE ${name} gauge`, `${name} ${value}`);
  }
  loopDelay.reset(); // the next scrape reports the delay since this one
  return `${lines.join('\n')}\n`;
}
