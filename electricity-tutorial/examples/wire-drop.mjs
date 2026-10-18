// Voltage drop and heat in a copper wire run (chapter 11).
//   node wire-drop.mjs <current> <one-way length in m> <size> [supply voltage]
//   <size> is an AWG gauge ("22awg") or a cross-section in mm² ("0.5mm2").
//   node wire-drop.mjs 3 2 22awg 5
import { parse, format, usage } from './units.mjs';

const RHO_COPPER = 1.72e-8;      // Ω·m at 20 °C (annealed copper, chapter 4)

const awgDiameterMm = (n) => 0.127 * 92 ** ((36 - n) / 39);
const awgAreaMm2 = (n) => Math.PI * awgDiameterMm(n) ** 2 / 4;

function areaFromSize(size) {
  const awg = size.match(/^(\d+)\s*awg$/i);
  if (awg) return awgAreaMm2(Number(awg[1]));
  const mm2 = size.match(/^([\d.]+)\s*mm2?$/i);
  if (mm2) return Number(mm2[1]);
  throw new Error(`Give the size as e.g. 22awg or 0.5mm2, not "${size}"`);
}

const [currentText, lengthText, sizeText, supplyText] = process.argv.slice(2);
if (!sizeText) {
  usage(`
Usage: node wire-drop.mjs <current> <one-way length in m> <size: 22awg | 0.5mm2> [supply voltage]
Example: node wire-drop.mjs 3 2 22awg 5`);
}

const current = parse(currentText);
const oneWay = Number(lengthText);
const areaMm2 = areaFromSize(sizeText);
const loop = 2 * oneWay;                                   // out AND back: the current flows through both wires
const resistance = RHO_COPPER * loop / (areaMm2 * 1e-6);   // R = ρ × L / A
const drop = current * resistance;
const loss = current ** 2 * resistance;

console.log(`Cross-section:          ${areaMm2.toPrecision(3)} mm²`);
console.log(`Resistance (${loop} m loop): ${format(resistance, 'Ω')}`);
console.log(`Voltage drop:           ${format(drop, 'V')}`);
console.log(`Power lost in the wire: ${format(loss, 'W')} (${format(loss / loop, 'W')} per metre)`);

if (supplyText) {
  const supply = parse(supplyText);
  const percent = (drop / supply) * 100;
  console.log(`Voltage at the load:    ${format(supply - drop, 'V')} (${percent.toFixed(1)} % lost)`);
  for (const target of [3, 5]) {
    const needed = RHO_COPPER * loop * current / (supply * target / 100) * 1e6;   // mm² for that drop
    let gauge = 40;
    while (gauge > 0 && awgAreaMm2(gauge) < needed) gauge--;
    console.log(`For ≤ ${target} % drop you need ≥ ${needed.toPrecision(2)} mm², i.e. ${gauge} AWG or thicker`);
  }
}
