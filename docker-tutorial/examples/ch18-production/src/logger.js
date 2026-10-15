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
