// The Plant Station's power budget (chapter 21). Edit the LOADS table to try your own design.
//   node power-budget.mjs
import { format } from './units.mjs';

const SUPPLY_VOLTS = 12;         // the wall adapter
// How the board makes its 5 V from VIN (chapter 12): the Uno R3's linear regulator draws the same current it
// delivers; the Uno R4's switching regulator trades voltage for current. Set LINEAR = false to see the difference.
const LINEAR = true;
const SWITCHER_EFFICIENCY = 0.9;
const SECONDS_PER_DAY = 86_400;

// rail: which supply the load hangs on; current: while on; duty: fraction of the day it is on;
// surge: the brief peak current (motors start at their stall current, chapter 16).
const LOADS = [
  { name: 'Arduino Uno board',  rail: 5,  current: 0.050,   duty: 1 },
  { name: 'Status LED (820 Ω)', rail: 5,  current: 0.0037,  duty: 1 },
  { name: 'Moisture probe',     rail: 5,  current: 0.00021, duty: 0.010 / 300 },  // 10 ms every 5 minutes
  { name: 'Pump',               rail: 12, current: 0.4,     duty: 60 / SECONDS_PER_DAY, surge: 1.2 },
];

// Current drawn from the 12 V adapter by a load: direct for 12 V loads, through the regulator for 5 V loads.
function fromSupply(load, amps) {
  if (load.rail === SUPPLY_VOLTS || LINEAR) return amps;
  return (load.rail * amps) / (SWITCHER_EFFICIENCY * SUPPLY_VOLTS);
}

let peak = 0;
let average = 0;
let energyWh = 0;
console.log('Load                 Rail   On-current  Power when on  Energy per day');
for (const load of LOADS) {
  const power = load.rail * load.current;
  const wh = power * load.duty * 24;
  peak += fromSupply(load, load.surge ?? load.current);
  average += fromSupply(load, load.current) * load.duty;
  energyWh += wh;
  console.log(
    `${load.name.padEnd(20)} ${String(load.rail).padStart(2)} V   ${format(load.current, 'A').padEnd(10)}  ` +
      `${format(power, 'W').padEnd(13)}  ${format(wh, 'Wh')}`,
  );
}

const supplyWh = average * SUPPLY_VOLTS * 24;
console.log('');
console.log(`Peak current from the ${SUPPLY_VOLTS} V adapter (all loads on, pump starting): ${format(peak, 'A')}`);
console.log(`Average current from the adapter: ${format(average, 'A')}`);
console.log(`Energy used by the loads per day: ${format(energyWh, 'Wh')}; drawn from the adapter: ${format(supplyWh, 'Wh')}`);
console.log(`Choose an adapter rated at least ${format(Math.ceil(peak * 1.5 * 2) / 2, 'A')} (1.5 × the peak, rounded up)`);
