// Configuration comes from the environment (twelve-factor style). The npm scripts load .env first.
export function requireEnv(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`${name} is not set; copy .env.example to .env (or .env.test.example to .env.test)`);
  }
  return value;
}

export const config = {
  port: Number(process.env.PORT ?? 3000),
  databaseUrl: requireEnv('DATABASE_URL'),
  poolSize: Number(process.env.DATABASE_POOL_SIZE ?? 10),
};
