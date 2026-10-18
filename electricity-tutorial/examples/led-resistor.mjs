// The series resistor for an LED (chapters 4 and 17).
//   node led-resistor.mjs <supply voltage> <LED forward voltage> <LED current>
//   node led-resistor.mjs 5 2.0 10mA
import { parse, format, usage, nextStandardUp } from './units.mjs';

const [supply, forward, current] = process.argv.slice(2).map(parse);
if (current === undefined) {
  usage(`
Usage: node led-resistor.mjs <supply V> <LED forward V> <LED current>
Example: node led-resistor.mjs 5 2.0 10mA`);
}
if (forward >= supply) {
  console.log('The supply voltage must be higher than the LED forward voltage.');
  process.exit(1);
}

const exact = (supply - forward) / current;   // Ohm's law on the voltage left over for the resistor
const chosen = nextStandardUp(exact);           // round UP to a standard value, so the current only goes down
const actual = (supply - forward) / chosen;
const power = actual ** 2 * chosen;

console.log(`Voltage across the resistor: ${format(supply - forward, 'V')}`);
console.log(`Exact resistor:              ${format(exact, 'Ω')}`);
console.log(`Nearest E12 value above it:  ${format(chosen, 'Ω')}`);
console.log(`LED current with it:         ${format(actual, 'A')}`);
console.log(`Resistor power:              ${format(power, 'W')} (use a resistor rated at least ${format(power * 2, 'W')})`);
