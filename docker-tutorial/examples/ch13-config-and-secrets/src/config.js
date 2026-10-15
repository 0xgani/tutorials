import { readFileSync } from 'node:fs';

// All configuration comes from the environment (twelve-factor). Secrets can also come
// from files: for any secret NAME, NAME_FILE=/run/secrets/... wins over NAME.
// Invalid configuration stops the process at startup with every problem listed at once.
export function loadConfig(env = process.env) {
  const errors = [];

  const port = Number(env.PORT ?? 3000);
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    errors.push(`PORT must be a port number, got "${env.PORT}"`);
  }

  const config = {
    port,
    baseUrl: optionalUrl('BASE_URL') ?? `http://localhost:${port}`,
    databaseUrl: optionalUrl('DATABASE_URL'),
    valkeyUrl: optionalUrl('VALKEY_URL'),
  };

  // The database password is kept out of DATABASE_URL so that it can come from a secret file.
  const dbPassword = secret('DATABASE_PASSWORD');
  if (config.databaseUrl && dbPassword) {
    const url = new URL(config.databaseUrl);
    url.password = encodeURIComponent(dbPassword);
    config.databaseUrl = url.href;
  }

  if (errors.length > 0) {
    throw new Error(`invalid configuration:\n  - ${errors.join('\n  - ')}`);
  }
  return Object.freeze(config);

  function secret(name) {
    const file = env[`${name}_FILE`];
    if (!file) return env[name] || undefined;
    try {
      return readFileSync(file, 'utf8').trim();
    } catch (err) {
      errors.push(`${name}_FILE: cannot read ${file} (${err.code})`);
      return undefined;
    }
  }

  function optionalUrl(name) {
    if (!env[name]) return undefined;
    try {
      return new URL(env[name]).href.replace(/\/$/, '');
    } catch {
      errors.push(`${name} must be a URL, got "${env[name]}"`);
      return undefined;
    }
  }
}

// For entry points: print the problems without a stack trace and exit with 78
// (EX_CONFIG, "configuration error"), which a restart can't fix.
export function loadConfigOrExit() {
  try {
    return loadConfig();
  } catch (err) {
    console.error(err.message);
    process.exit(78);
  }
}

// What the app logs at startup: the configuration minus anything secret.
export function describeConfig(config) {
  const redact = (value) => {
    if (!value) return value;
    const url = new URL(value);
    if (url.password) url.password = '***';
    return url.href;
  };
  return { ...config, databaseUrl: redact(config.databaseUrl), valkeyUrl: redact(config.valkeyUrl) };
}
