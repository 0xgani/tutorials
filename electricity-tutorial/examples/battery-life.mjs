// Battery life from capacity and average current (chapters 12 and 21).
//   node battery-life.mjs <capacity> <load> [load …]
//   A load is a current, optionally with the fraction of time it is on: "50mA" or "50mA@0.02" (on 2 % of the time).
//   node battery-life.mjs 2000mAh 15uA 25mA@0.002 400mA@0.0003
import { parse, format, usage } from './units.mjs';

const USABLE = 0.8;   // plan on ~80 % of the rated capacity: cut-off voltage, temperature, ageing, high currents

const [capacityText, ...loads] = process.argv.slice(2);
if (loads.length === 0) {
  usage(`
Usage: node battery-life.mjs <capacity, e.g. 2000mAh> <load[@fraction]> [more loads …]
Example: node battery-life.mjs 2000mAh 15uA 25mA@0.002 400mA@0.0003`);
}

const capacityAh = parse(capacityText);   // "2000mAh" -> 2 (amp-hours)
let average = 0;
for (const load of loads) {
  const [currentText, fractionText = '1'] = load.split('@');
  const share = parse(currentText) * Number(fractionText);
  average += share;
  console.log(`${load.padEnd(20)} contributes ${format(share, 'A')} on average`);
}

const hours = (capacityAh * USABLE) / average;
console.log(`Average current:  ${format(average, 'A')}`);
console.log(`Battery life:     ${hours.toFixed(0)} h ≈ ${(hours / 24).toFixed(1)} days (using ${USABLE * 100} % of the capacity)`);
