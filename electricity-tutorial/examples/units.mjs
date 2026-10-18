// Shared helpers for the calculators: parse "4.7k", "20mA", "330R", "2k2" and print values with SI prefixes.

const PREFIXES = { p: 1e-12, n: 1e-9, u: 1e-6, 'µ': 1e-6, m: 1e-3, '': 1, k: 1e3, K: 1e3, M: 1e6, G: 1e9 };

// Accepts plain numbers ("0.02"), a prefix ("20m", "4.7k"), a prefix plus unit ("20mA", "4.7kΩ", "100uF"),
// and the resistor notation where the prefix is the decimal point ("4k7" = 4.7k, "2R2" = 2.2).
export function parse(text) {
  const s = String(text).trim().replace(/(ohms?|Ω|Wh|Ah|[AVWFHs])$/i, '');
  const rkm = s.match(/^(\d+)([pnuµmkKMGR])(\d+)$/);
  if (rkm) {
    const [, whole, p, frac] = rkm;
    return Number(`${whole}.${frac}`) * (p === 'R' ? 1 : PREFIXES[p]);
  }
  const m = s.match(/^([-+]?\d*\.?\d+(?:e[-+]?\d+)?)\s*([pnuµmkKMGR]?)$/);
  if (!m) throw new Error(`Can't read the value "${text}"`);
  const [, num, p] = m;
  return Number(num) * (p === 'R' ? 1 : PREFIXES[p]);
}

// format(0.0091, 'A') -> "9.09 mA"; format(330, 'Ω') -> "330 Ω"
export function format(value, unit, digits = 3) {
  if (value === 0 || !Number.isFinite(value)) return `${value} ${unit}`;
  const steps = [[1e9, 'G'], [1e6, 'M'], [1e3, 'k'], [1, ''], [1e-3, 'm'], [1e-6, 'µ'], [1e-9, 'n'], [1e-12, 'p']];
  const abs = Math.abs(value);
  const [scale, prefix] = steps.find(([f]) => abs * (1 + 1e-9) >= f) ?? steps[steps.length - 1];
  return `${Number((value / scale).toPrecision(digits))} ${prefix}${unit}`;
}

// The E12 and E24 series of standard resistor values (one decade; chapter 6).
export const E12 = [1.0, 1.2, 1.5, 1.8, 2.2, 2.7, 3.3, 3.9, 4.7, 5.6, 6.8, 8.2];
export const E24 = [1.0, 1.1, 1.2, 1.3, 1.5, 1.6, 1.8, 2.0, 2.2, 2.4, 2.7, 3.0,
  3.3, 3.6, 3.9, 4.3, 4.7, 5.1, 5.6, 6.2, 6.8, 7.5, 8.2, 9.1];

// The smallest standard value that is >= value (safe for current-limiting resistors).
export function nextStandardUp(value, series = E12) {
  for (let decade = 10 ** Math.floor(Math.log10(value)); ; decade *= 10) {
    for (const base of series) {
      const candidate = Number((base * decade).toPrecision(3));
      if (candidate >= value * 0.9999) return candidate;
    }
  }
}

export function usage(text) {
  console.log(text.trim());
  process.exit(1);
}
