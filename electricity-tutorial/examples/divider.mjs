// Voltage divider (chapter 10): Vout = Vin × Rbottom / (Rtop + Rbottom).
//   node divider.mjs <Vin> <Rtop> <Rbottom> [Rload]
//   node divider.mjs 12.6 20k 10k
//   node divider.mjs 5 10k 10k 10k      (with a 10 kΩ load across the bottom resistor)
import { parse, format, usage } from './units.mjs';

const [vin, rTop, rBottom, rLoad] = process.argv.slice(2).map(parse);
if (rBottom === undefined) {
  usage(`
Usage: node divider.mjs <Vin> <Rtop> <Rbottom> [Rload]
Example: node divider.mjs 12.6 20k 10k`);
}

const unloaded = vin * rBottom / (rTop + rBottom);
const rTh = rTop * rBottom / (rTop + rBottom);   // Thévenin resistance seen from the output (chapter 9)
console.log(`Output, no load:      ${format(unloaded, 'V')}`);
console.log(`Divider current:      ${format(vin / (rTop + rBottom), 'A')}`);
console.log(`Output resistance:    ${format(rTh, 'Ω')} (Thévenin)`);

if (rLoad !== undefined) {
  const rParallel = rBottom * rLoad / (rBottom + rLoad);
  const loaded = vin * rParallel / (rTop + rParallel);
  console.log(`Output with the load: ${format(loaded, 'V')} (${((1 - loaded / unloaded) * 100).toFixed(1)} % lower)`);
}
