# Chapter 12 — Batteries and power supplies

## What you'll learn

- EMF and internal resistance: why a battery's voltage sags under load
- The battery chemistries you'll meet, and their voltages, capacities and quirks
- What capacity really means, and C-rates
- Cells in series and in parallel
- Wall adapters: regulated vs. unregulated, polarity symbols, and choosing one
- USB as a power source, and what USB-C changes
- Linear vs. switching regulators, dropout, and regulator heat
- How Arduino boards take power, and which input to use
- How to estimate battery life, and a power plan for the Plant Station

## A real source: EMF and internal resistance

An ideal voltage source holds its voltage no matter how much current you draw. Real sources don't. A battery behaves
like an ideal source (its **EMF**, electromotive force) in series with a small **internal resistance** `r`: exactly
the Thévenin equivalent from chapter 9.

```text
          ┌──────────── battery ────────────┐
          │                                 │
          │   (EMF) ──[ r ]──────────────── ┼──● + terminal
          │                                 │    V_terminal = EMF − I × r
          │   ──────────────────────────────┼──● − terminal
          └─────────────────────────────────┘
```

The voltage you can measure at the terminals is:

```text
V_terminal = EMF − I × r
```

With no load (`I = 0`) you measure the full EMF. Draw current and the terminal voltage drops by `I × r`, while the
battery itself heats by `I² × r`.

**Worked example.** A 9 V battery (EMF 9.4 V when fresh, internal resistance about 1.5 Ω) powers a board drawing
200 mA:

```text
V_terminal = 9.4 − 0.2 × 1.5 = 9.1 V
```

Now a motor briefly draws 2 A:

```text
V_terminal = 9.4 − 2 × 1.5 = 6.4 V
```

The voltage collapses while the motor starts, which can reset a microcontroller on the same battery. The maximum
current a battery can supply into a dead short is `EMF / r`; for this 9 V battery about 6 A, for a lithium-ion cell
with 0.05 Ω internal resistance about 80 A — enough to melt wire (chapter 20).

Internal resistance rises as a battery empties, gets cold or ages. That's why a "dead" battery may still read close
to its normal voltage on a meter (almost no current) but collapse the moment you connect a load. **To judge a
battery, measure its voltage under load.**

## Battery chemistries

A **cell** is one electrochemical unit; its chemistry fixes its voltage. A **battery** is, strictly, several cells
together (a 9 V battery is six 1.5 V cells), though people use the word for single cells too.

| Chemistry | Nominal per cell | Full → empty | Rechargeable | Typical use | Notes |
|---|---|---|---|---|---|
| Alkaline (AA, AAA, 9 V) | 1.5 V | 1.6 → ~1.0 V | No | Remotes, toys | Capacity drops sharply at high current; can leak when flat |
| NiMH (AA, AAA) | 1.2 V | 1.4 → 1.0 V | Yes | Replacing alkalines | Flat voltage curve; low internal resistance |
| Lithium coin cell (CR2032) | 3.0 V | 3.0 → ~2.0 V | No | RTC backup, tiny sensors | ~225 mAh, high internal resistance; only for µA–few mA loads |
| Lithium-ion (18650, pouch) | 3.6–3.7 V | 4.2 → ~3.0 V | Yes | Phones, laptops, power banks | High energy; **needs protection** (chapter 20) |
| Lithium polymer (LiPo) | 3.7 V | 4.2 → ~3.0 V | Yes | Drones, small gadgets | Li-ion in a soft pouch; same rules, easier to puncture |
| LiFePO₄ (lithium iron phosphate) | 3.2 V | 3.65 → ~2.5 V | Yes | Solar storage, e-bikes | Safer, longer life, lower energy density |
| Lead-acid (car, UPS, sealed SLA) | 2.0 V (12 V battery = 6 cells) | 12.7 → ~11.8 V resting (12 V battery) | Yes | Cars, alarms, UPS | Heavy, cheap; damaged by deep discharge |

Two points that catch beginners:

- **The 9 V battery is a poor power source for Arduino projects.** It stores only about 5 Wh (chapter 5), has high
  internal resistance, and a board's linear regulator wastes almost half of it as heat. It lasts hours, not days. Use
  AA packs, a Li-ion cell with a converter, or a wall adapter.
- **Lithium cells must never be over-charged, over-discharged or short-circuited.** They need a dedicated charger and
  usually a protection circuit (chapter 20).

## Capacity and C-rate

Capacity is the charge a battery delivers before reaching its cut-off voltage, in mAh or Ah (chapter 5). The
printed figure is measured under kind conditions: room temperature and a low current (often the current that empties
it in 20 hours). In real use you get less:

- **High currents** reduce usable capacity, especially for alkaline cells: an AA alkaline gives about 2500 mAh at
  25 mA but perhaps half that at 1 A.
- **Cold** reduces it.
- **Your circuit's minimum voltage** may cut off earlier than the battery's: a board that needs 3.0 V can't use the
  bottom of a Li-ion cell.
- **Age** reduces it.

Plan on **70–80 %** of the rated capacity.

The **C-rate** expresses current relative to capacity: 1C is the current that would empty the battery in one hour. For
a 2000 mAh cell, 1C = 2 A, 0.5C = 1 A, 2C = 4 A. Datasheets give maximum discharge and charge rates in C: a typical
Li-ion cell charges at 0.5–1C and discharges at 1–3C continuously; power-tool cells and LiPo packs are rated for 10C
or more.

## Cells in series and in parallel

```text
   series: voltages add, capacity stays           parallel: capacity adds, voltage stays

   + ─[1.5V]─[1.5V]─[1.5V]─[1.5V]─ −               + ─┬─[3.7V 2Ah]─┬─ −
       = 6 V, 2500 mAh (same charge flows             └─[3.7V 2Ah]─┘
         through every cell)                         = 3.7 V, 4 Ah
```

- **In series**, the same current flows through every cell (chapter 8), so the capacity in mAh is that of one cell,
  while the voltage and the stored energy add up. Four AA cells give 6 V (alkaline) or 4.8 V (NiMH). Internal
  resistances add too.
- **In parallel**, the voltage is that of one cell and the capacities add. **Only connect cells in parallel when they
  are the same chemistry and at the same voltage.** Connecting a full Li-ion cell to an empty one makes a huge current
  flow from one to the other (`ΔV / (r1 + r2)`: 1 V across 0.1 Ω is 10 A).
- Use **matched cells**: same model, same age, same state of charge. In a series pack, the weakest cell empties first
  and is then driven backwards by the others, which ruins it (and for lithium, is dangerous). Lithium series packs use
  a battery management system (BMS) that monitors each cell.

## Wall adapters

A wall adapter ("wall wart", "power brick") converts mains AC to low-voltage DC. Its label tells you what you need:

```text
   INPUT:  100–240V ~ 50/60Hz 0.5A
   OUTPUT: 12V ⎓ 2.0A   24W
           ⊖—◖●—⊕        ← polarity: centre pin positive (the common one)
```

- **Output voltage** must match what your device expects.
- **Output current** is the most it can deliver; it must be **at least** what your device draws, plus margin for
  surges. A higher rating is fine (chapter 3: a supply delivers what's drawn, not what it's rated for).
- **`⎓`** means DC; **`~`** means AC. A few old adapters (for some routers and halogen lamps) output AC: check.
- **Polarity**: the symbol shows which terminal is positive. Centre-positive is standard for Arduino and most
  electronics, but not universal. A reversed adapter can destroy a device that lacks reverse-polarity protection
  (chapter 17).

**Regulated vs. unregulated.** Modern switch-mode adapters are **regulated**: they hold their output at the labelled
voltage from no load to full load. Old, heavy transformer-based adapters are often **unregulated**: a "12 V"
unregulated adapter may output 16–18 V with no load, falling to 12 V only at its rated current. That can surprise a
circuit that only draws a little current. Measure it.

## USB as a power source

USB is the most convenient 5 V source, and the classic Arduino's default.

| USB source | Available current at 5 V |
|---|---|
| USB 2.0 port | 500 mA |
| USB 3.x port | 900 mA |
| USB-C, without negotiation | 1.5 A or 3 A, advertised by the port |
| USB Power Delivery (PD) | Negotiates 5, 9, 15, 20 V (and up to 48 V with EPR), up to 3 A or 5 A |
| Phone chargers (various proprietary "fast charge" schemes) | Higher voltages or currents after negotiation |

Your device must not draw more than the port offers; computers usually shut down an overloaded port. A USB-C port
only supplies power to a device that identifies itself (with resistors on its CC pins); a USB-C board designed
without them may receive no power from a USB-C charger.

USB-PD is how laptops get 20 V over the same connector. Small "PD trigger" boards can request 12 V or 20 V from a PD
charger, which makes a USB-C charger a convenient bench supply for projects like the Plant Station.

## Regulators: making the voltage you need

Chips and microcontrollers need a steady, specific voltage: 5 V or 3.3 V, typically within ±5–10 %. A **voltage
regulator** takes a higher (or varying) input and produces a fixed output. There are two very different kinds.

### Linear regulators

A linear regulator (the classic 7805, the AMS1117, the LM1117) is essentially a transistor acting as an automatically
adjusted resistor: it drops whatever voltage is needed to hold the output steady.

```text
   Vin = 12 V ──[ linear regulator ]── Vout = 5 V ──► load, 200 mA
                    drops 7 V
                    current in = current out = 200 mA

   power in    = 12 V × 0.2 A = 2.4 W
   power out   = 5 V × 0.2 A  = 1.0 W
   regulator   = (12 − 5) × 0.2 = 1.4 W of heat        efficiency 42 %
```

The heat is always `(Vin − Vout) × I`. Its consequences:

- **Efficiency is at best Vout / Vin.** From 12 V to 5 V you lose 58 % of the energy, whatever the regulator.
- **Heat limits the current.** A small regulator without a heatsink can shed perhaps 0.5–1 W before it overheats and
  shuts down. At 1.4 W the regulator above gets very hot.
- **It needs headroom.** The input must be above the output by at least the **dropout voltage**: about 1–2 V for
  old types, 0.1–0.3 V for **LDO** (low-dropout) regulators. A 5 V regulator with 1.1 V dropout needs at least 6.1 V
  in.

Linear regulators are still everywhere because they're cheap, tiny, quiet (no electrical noise) and need only two
capacitors. They're ideal when the voltage difference or the current is small: 5 V to 3.3 V at 50 mA wastes only
0.085 W.

### Switching regulators

A **switching regulator** (converter) switches its input on and off hundreds of thousands of times a second and uses
an inductor and capacitors (chapters 15 and 16) to smooth the result. Instead of burning the excess voltage, it
converts it, trading voltage for current:

- A **buck** converter steps down: 12 V → 5 V.
- A **boost** converter steps up: 3.7 V → 5 V (every power bank has one).
- A **buck-boost** does both, useful for batteries whose voltage crosses the output voltage as they discharge.

Efficiency is typically **85–95 %**, so input power ≈ output power / efficiency:

```text
   12 V ──[ buck, 90 % ]── 5 V at 200 mA (1.0 W out)

   power in   = 1.0 W / 0.9 ≈ 1.11 W
   current in = 1.11 W / 12 V ≈ 93 mA         (less than the 200 mA out!)
   heat       = 0.11 W
```

A switching converter draws **less** current from its input than it delivers, when stepping down. That's the trade
of voltage for current, and it's why your laptop charger's cable can be thin.

Their downsides: more parts, a little electrical noise (ripple) on the output, and a small quiescent current even at
no load (from microamps to several milliamps; check the datasheet for battery projects). Ready-made modules cost very
little and are what most hobby projects use.

| | Linear | Switching |
|---|---|---|
| Efficiency | Vout / Vin at best | 85–95 % typical |
| Heat | `(Vin − Vout) × I` | Small |
| Input current (step-down) | = output current | Less than output current |
| Noise | Very low | Some ripple at the switching frequency |
| Can step up? | No | Yes (boost) |
| Cost and size | Tiny and cheap | A small module |
| Best for | Small drops, small currents, noise-sensitive circuits | Big drops, larger currents, batteries |

## How Arduino boards take power

Taking the classic **Arduino Uno R3** as the reference (other boards differ; read their documentation):

| Input | Accepts | Path | Notes |
|---|---|---|---|
| USB | 5 V from a USB port | Through a polyfuse (chapter 20) to the 5 V rail | Simplest; limited by the port |
| Barrel jack | 7–12 V recommended | Reverse-polarity diode, then a linear 5 V regulator | Above 12 V the regulator overheats; below 7 V the output sags |
| `VIN` pin | Same as the barrel jack | Same regulator | Or an output: the jack's voltage, after the diode |
| `5V` pin | Regulated 5 V only | Straight onto the 5 V rail, **bypassing all protection** | Wrong voltage here destroys the board |
| `3.3V` pin | Output only | Small regulator from 5 V, about 50 mA | For 3.3 V sensors |

The **Arduino Uno R4** boards accept 6–24 V on the jack and `VIN`, through a switching regulator, so a 12 V input no
longer cooks a linear regulator.

The Uno R3's linear regulator explains a common surprise: power it from 12 V and drive a few hundred milliamps of
servos or LEDs from its 5 V pin, and the regulator dissipates `(12 − 5) × 0.3 = 2.1 W`, overheats and shuts down. The
rules that follow:

- Power **loads that draw more than about 100–200 mA** (motors, servos, strips) from their own supply, not from the
  board's 5 V pin. Connect the grounds (chapter 3).
- On an Uno R3, feed the jack with **7–9 V** when possible, not 12 V.
- Never put more than 5 V (or 3.3 V on 3.3 V boards) on the `5V`/`3.3V` pins.

## Estimating battery life

```text
battery life (h) ≈ usable capacity (mAh) / average current (mA)
usable capacity  ≈ 0.7–0.8 × rated capacity
```

Two important details:

1. **Use the current drawn from the battery**, at the battery's voltage. If a regulator sits between them, convert:
   for a linear regulator the battery current equals the load current (plus a little); for a switching converter,
   `I_battery = P_load / (efficiency × V_battery)`.
2. **Use the average current**, weighted by the time each load is on. A device that draws 25 mA for 0.2 % of the time
   and 15 µA otherwise averages `25 mA × 0.002 + 0.015 mA ≈ 0.065 mA`.

**Worked example.** A 3.3 V sensor board averages 1 mA from a 2000 mAh battery through an LDO:

```bash
node examples/battery-life.mjs 2000mAh 1mA
```

```text
1mA                  contributes 1 mA on average
Average current:  1 mA
Battery life:     1600 h ≈ 66.7 days (using 80 % of the capacity)
```

Just over two months. Cut the average to 0.1 mA with sleep modes and it lasts nearly two years (at which point the
battery's own self-discharge starts to matter). This is the reasoning behind every "runs a year on two AA cells"
product: **sleep current dominates battery life**. A standard Arduino Uno board can't get there: its USB chip, power
LED and regulator draw tens of milliamps even when the microcontroller sleeps. Low-power projects use bare
microcontrollers or boards designed for it.

## The Plant Station's power plan

From chapter 5's budget: the electronics need 5 V at around 60 mA, and the pump needs 12 V at 0.4 A with surges of
about 1.2 A (chapter 11).

**Option A — mains adapter (the main design).**

```text
   12 V ⎓ 2 A adapter ──┬────────────────────────────► pump (via switch, chapter 18)
   (centre positive)    │
                        └──[ buck 12 V → 5 V ]───────► board 5 V pin, LED, sensor
                              module, ≥ 0.5 A
```

- One **12 V, 2 A regulated adapter** covers the pump's surge (1.2 A) plus the electronics (about
  `0.3 W / (0.9 × 12 V) ≈ 30 mA` from the 12 V side) with margin.
- A **buck converter module** makes 5 V. A linear regulator from 12 V would waste `(12 − 5) × 0.06 ≈ 0.4 W`
  continuously: workable, but it's 58 % of the electronics' energy for nothing, and it would get warm in a closed box.
  (Alternatively, feed 12 V to the Uno R3's jack and let its regulator handle the 60 mA: 0.42 W is within its limit.
  The buck is the cleaner design, and it's what the capstone uses.)
- The 12 V line goes directly to the pump circuit, so the pump's surges don't pass through the 5 V regulator.

**Option B — battery.** Suppose the Plant Station goes on a balcony with no socket. The pump needs 12 V, so a pack
of three Li-ion cells in series (3S: 11.1 V nominal, 12.6 V full) suits it, with a 3S BMS. Say 2500 mAh. If the
electronics sleep most of the time and average 2 mA at 5 V (through a 90 % buck), and the pump runs 60 s a day at
0.4 A:

```text
electronics from the battery: 5 V × 2 mA / (0.9 × 11.1 V) ≈ 1.0 mA
pump average: 0.4 A × 60 s / 86 400 s ≈ 0.28 mA
total average ≈ 1.28 mA
life ≈ 0.8 × 2500 mAh / 1.28 mA ≈ 1560 h ≈ 65 days
```

```bash
node examples/battery-life.mjs 2500mAh 1.0mA 400mA@0.000694
```

```text
1.0mA                contributes 1 mA on average
400mA@0.000694       contributes 278 µA on average
Average current:  1.28 mA
Battery life:     1565 h ≈ 65.2 days (using 80 % of the capacity)
```

About two months per charge. Still, the board's sleep current dominates; with a well-designed low-power board, it
would be the pump. Chapter 21 compares the options.

## Key takeaways

- A real source is an EMF in series with an internal resistance: `V_terminal = EMF − I × r`. Voltage sags under load,
  more as the battery empties or gets cold. Judge a battery under load.
- Chemistry sets cell voltage: alkaline 1.5 V, NiMH 1.2 V, Li-ion 3.7 V (4.2 V full), LiFePO₄ 3.2 V, lead-acid 2 V
  per cell. The 9 V battery is a poor choice for Arduino projects.
- Plan on 70–80 % of rated capacity. C-rate = current relative to capacity.
- Series: voltage adds, mAh stays. Parallel: mAh adds, voltage stays; only parallel identical cells at the same
  voltage.
- Adapters: match the voltage, have at least the current needed, check polarity and AC/DC, beware unregulated ones.
- Linear regulators burn `(Vin − Vout) × I` as heat and need dropout headroom. Switching converters are 85–95 %
  efficient and draw less input current than output when stepping down.
- Power big loads from their own supply, not from the board's regulator. Never put an unregulated voltage on a `5V`
  pin.
- Battery life ≈ usable capacity / average battery-side current. Sleep current usually dominates.

## Exercises

1. **Internal resistance.** A battery reads 6.3 V with no load and 5.9 V while supplying 0.8 A. What is its internal
   resistance? What would its terminal voltage be at 2 A?

<details>
<summary>Solution</summary>

```text
r = (EMF − V_terminal) / I = (6.3 − 5.9) / 0.8 = 0.5 Ω
At 2 A: V = 6.3 − 2 × 0.5 = 5.3 V
```

</details>

2. **Packs.** What voltage and capacity do you get from (a) four 2000 mAh NiMH cells in series, (b) two 3000 mAh
   Li-ion cells in parallel, (c) a "2S2P" pack of four 3000 mAh Li-ion cells (two parallel pairs in series)? How much
   energy does each store?

<details>
<summary>Solution</summary>

```text
(a) 4 × 1.2 V = 4.8 V, 2000 mAh        → 9.6 Wh
(b) 3.7 V, 6000 mAh                    → 22.2 Wh
(c) 2 × 3.7 V = 7.4 V, 2 × 3000 = 6000 mAh → 44.4 Wh
```

</details>

3. **Linear regulator heat.** An AMS1117-3.3 (dropout about 1.1 V) powers a 3.3 V module drawing 250 mA. How much
   heat does it dissipate from (a) a 5 V input, (b) a 12 V input? Which is reasonable for a small regulator with no
   heatsink? What's the lowest input voltage it can work from?

<details>
<summary>Solution</summary>

```text
(a) (5 − 3.3) × 0.25 = 0.43 W   reasonable (warm)
(b) (12 − 3.3) × 0.25 = 2.2 W   far too much: it will overheat and shut down
lowest input ≈ 3.3 + 1.1 = 4.4 V
```

Use a buck converter for 12 V → 3.3 V.

</details>

4. **Buck input current.** A buck converter with 88 % efficiency delivers 5 V at 2 A from a 24 V supply. What current
   does it draw from the 24 V supply? How much heat does it make?

<details>
<summary>Solution</summary>

```text
P_out = 5 × 2 = 10 W
P_in  = 10 / 0.88 ≈ 11.4 W
I_in  = 11.4 / 24 ≈ 0.47 A
heat  = 11.4 − 10 = 1.4 W
```

</details>

5. **Battery life.** A weather sensor wakes every 10 minutes, runs for 2 seconds at 40 mA (radio on), and sleeps at
   20 µA. It runs from two AA alkaline cells (2500 mAh) through a converter that we'll treat as drawing the same current
   from the battery. Estimate the battery life.

<details>
<summary>Solution</summary>

```text
on fraction  = 2 s / 600 s = 0.00333
average      = 40 mA × 0.00333 + 0.020 mA ≈ 0.133 + 0.020 = 0.153 mA
life         ≈ 0.8 × 2500 / 0.153 ≈ 13 000 h ≈ 545 days, about 1.5 years
```

```bash
node examples/battery-life.mjs 2500mAh 20uA 40mA@0.00333
```

Note that the radio dominates this time (0.133 of 0.153 mA): shortening the radio's on-time would help most.

</details>

6. **Choose a supply.** A project has an Arduino Uno R3 (50 mA), a 5 V LED strip drawing up to 2 A, and a servo that
   draws up to 1 A when moving. Someone proposes powering everything from a 12 V adapter into the Uno's barrel jack and
   connecting the strip and the servo to the Uno's 5V pin. Explain what goes wrong, and propose a better supply design.

<details>
<summary>Solution</summary>

All 3 A would flow through the Uno R3's small linear regulator, which would have to dissipate about
`(12 − 5) × 3 = 21 W`: impossible. It would overheat and shut down immediately (and the board's tracks aren't sized
for 3 A either).

Better: a **5 V, 4 A** (or larger) regulated adapter, or a 12 V adapter with a 5 V buck converter rated for at least
4 A. Wire the strip and servo directly to that 5 V supply with suitably thick wires (chapter 11), power the Uno from
the same 5 V into its 5V pin (only if the supply is a good regulated 5 V) or from USB, and connect all grounds
together. Add a large capacitor across the strip's input and near the servo for surges (chapter 15).

</details>

---
← Previous: [Wires and wire thickness](11-wires-and-wire-thickness.md) | Next: [AC, mains and electrical safety](13-ac-mains-and-electrical-safety.md) →
