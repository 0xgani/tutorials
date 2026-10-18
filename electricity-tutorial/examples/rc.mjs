// RC circuits (chapter 15): time constant, charging curve and low-pass cut-off frequency.
//   node rc.mjs <R> <C> [supply voltage]
//   node rc.mjs 10k 100uF 5
import { parse, format, usage } from './units.mjs';

const [r, c, supply] = process.argv.slice(2).map(parse);
if (c === undefined) {
  usage(`
Usage: node rc.mjs <R> <C> [supply voltage]
Example: node rc.mjs 10k 100uF 5`);
}

const tau = r * c;                     // τ = R × C, in seconds when R is in ohms and C in farads
console.log(`Time constant τ = R × C:  ${format(tau, 's')}`);
console.log(`Low-pass cut-off 1/(2πRC): ${format(1 / (2 * Math.PI * tau), 'Hz')}`);
console.log('');
console.log('time      charged to');
for (const n of [0.5, 1, 2, 3, 4, 5]) {
  const fraction = 1 - Math.exp(-n);   // V(t) = Vs × (1 − e^(−t/τ))
  const volts = supply === undefined ? '' : `  = ${format(supply * fraction, 'V')}`;
  console.log(`${(n + 'τ').padEnd(5)} ${format(n * tau, 's').padEnd(9)} ${(fraction * 100).toFixed(1).padStart(5)} %${volts}`);
}
