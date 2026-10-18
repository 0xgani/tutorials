// Ohm's law and power (chapters 4 and 5). Give any two of V, I, R, P; get the other two.
//   node ohm.mjs V=5 R=220
//   node ohm.mjs I=20mA R=330
//   node ohm.mjs P=0.25 R=1k
import { parse, format, usage } from './units.mjs';

const given = Object.fromEntries(
  process.argv.slice(2).map((arg) => {
    const [key, value] = arg.split('=');
    return [key.toUpperCase(), parse(value)];
  }),
);
const keys = Object.keys(given).sort().join('');
let { V, I, R, P } = given;

switch (keys) {
  case 'IV': R = V / I; P = V * I; break;
  case 'RV': I = V / R; P = V * I; break;
  case 'IR': V = I * R; P = V * I; break;
  case 'PV': I = P / V; R = V / I; break;
  case 'IP': V = P / I; R = V / I; break;
  case 'PR': I = Math.sqrt(P / R); V = I * R; break;
  default:
    usage(`
Usage: node ohm.mjs <two of V=… I=… R=… P=…>
Examples: node ohm.mjs V=5 R=220     node ohm.mjs I=20mA R=330     node ohm.mjs P=0.25 R=1k`);
}

console.log(`V = ${format(V, 'V')}`);
console.log(`I = ${format(I, 'A')}`);
console.log(`R = ${format(R, 'Ω')}`);
console.log(`P = ${format(P, 'W')}`);
