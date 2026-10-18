# Chapter 21 — Capstone project: the Plant Station

## What you'll learn

- How to turn a list of requirements into a complete electrical design
- How every component value in a real project follows from the earlier chapters
- How to draw a project's schematic in sections joined by net labels
- How to write a power budget and size the supply, wires and fuse from it
- How to compare a mains-powered and a battery-powered version
- How to bring up a new circuit safely, one section at a time, with expected measurements
- How to think about failure modes before they happen
- Where to go next: from this design to your first Arduino build

## The brief

Chapter 1 sketched the Plant Station on a napkin. Here is the brief, written properly:

| # | Requirement |
|---|---|
| R1 | Measure soil moisture in one pot, every few minutes |
| R2 | When the soil is dry, run a 12 V DC pump (0.4 A running) for about 15 s; at most a few times a day |
| R3 | Show status on one LED: steady when fine, blinking while watering, off when unpowered |
| R4 | A push button waters the plant manually |
| R5 | Run from a mains adapter indoors; optionally from a battery on a balcony |
| R6 | Work with an Arduino Uno R3 or R4 |
| R7 | Survive common mistakes: a reversed adapter, a jammed pump, a shorted wire, static from handling |
| R8 | Be safe near water |

The electrical design follows. (The program that runs it is for your Arduino studies; the pin plan from chapter 19 is
all it needs.)

## Block diagram

```text
                      ┌──────────────── 12 V power path (high current) ─────────────────┐
                      │                                                                  ▼
  ┌───────────┐   ┌───┴──────────────┐                                         ┌──────────────────┐
  │ 12 V 2 A  ├──►│ input protection │                                         │ pump driver      │
  │ adapter   │   │ fuse, Schottky,  │                                         │ MOSFET, flyback, │──► pump
  └───────────┘   │ bulk capacitor   │                                         │ noise capacitor  │
                  └───┬──────────────┘                                         └────────▲─────────┘
                      │ +12V_P                                                          │ gate (D9)
                      ▼                                                                 │
                ┌───────────────────────────── Arduino Uno ───────────────────────────┐ │
                │ VIN → on-board 5 V regulator                                         ├─┘
                │ D2 ◄── button        D5 ──► status LED                               │
                │ D7 ──► probe power   A0 ◄── moisture divider                         │
                └──────────────────────────────────────────────────────────────────────┘
                 All grounds meet at one star point at the input connector.
```

## The schematic

The schematic is drawn in three sections. Net labels connect them (chapter 7): every `+12V_P` is the same node, every
`+5V` is the board's 5 V rail, and every `GND` is one node, wired as a star from the input connector.

### Section 1: power input and protection

```text
   J1 (adapter in)        F1                  D1
   + ●───────────────[ 1.5 A slow ]──────────►|────────●─────────────── +12V_P
                                             1N5822    │
                                                   C1 ═╪═ 470 µF 25 V (+ to the top)
                                                       │
   − ●─────────────────────────────────────────────────●── STAR POINT ── GND
```

`+12V_P` ("protected 12 V") feeds the board's `VIN` pin and the pump section.

### Section 2: pump driver

```text
   +12V_P ─────────────────────┬─────────────┬──────────────┐
                               │             │              │
                          J2 [ pump M1 ]  C3 100 nF     D2 ─┴─ 1N5819
                               │             │              ▲  (cathode to +12V_P)
                               ●─────────────┴──────────────┘
                               │
                             D │
                            ┌──┴──┐
   D9 ──[ R4 220 Ω ]──●─────┤G    │ Q1 IRLZ44N
                      │     └──┬──┘
                      │      S │
                 [ R5 100 kΩ ] │
                      │        │
   GND ───────────────┴────────┴──── own wire to the STAR POINT
```

### Section 3: board inputs and outputs

```text
   button (D2):                      status LED (D5):

   D2 ───┐                           D5 ──[ R1 820 Ω ]──►|── GND
         │                                          LED1 (green)
     SW1 / (push)
         │
   GND ──┘     internal pull-up enabled in software


   moisture sensor (D7 powers it, A0 reads it):

   D7 ──────────┐
                │
          J3 [ probe ]     two prongs in the soil
                │
   A0 ──────────●──────────┬────────────┐
                           │            │
                    R2 [ 22 kΩ 1 % ]  C2 ═╪═ 100 nF
                           │            │
   GND ────────────────────┴────────────┘
```

And the board itself: `VIN` ← `+12V_P`, `GND` ← its own wire to the star point.

## Every value, and where it came from

| Ref | Part | Value | Derivation | Chapter |
|---|---|---|---|---|
| J1 | Input connector | 5.5 × 2.1 mm jack or 2-way screw terminal | Rated ≥ 2 A; keyed jack prevents most polarity mistakes | 11, 20 |
| F1 | Fuse | 1.5 A slow-blow | > 0.45 A normal × 1.25…2, tolerates 1.2 A start, < 22 AWG's 2–3 A | 20 |
| D1 | Reverse-polarity diode | 1N5822 Schottky, 3 A, 40 V | ≥ 1.2 A surge with margin; drop ≈ 0.35 V, loss ≈ 0.15 W | 17 |
| C1 | Bulk capacitor | 470 µF, 25 V electrolytic | 2× voltage margin; covers the pump's start surge and damps hot-plug ringing | 15, 20 |
| — | Board regulator | Uno's own, from `VIN` | R3: `(11.6 − 5) × 0.054 ≈ 0.36 W` (linear, within limits); R4: switching | 12 |
| Q1 | Pump switch | IRLZ44N, logic-level N-MOSFET | `R_DS(on)` 0.022 Ω at 5 V: 3.5 mW running, 32 mW at start | 18 |
| R4 | Gate resistor | 220 Ω | Limits the gate-charging spike to < 23 mA | 18 |
| R5 | Gate pull-down | 100 kΩ | Pump stays off while the board boots | 18, 19 |
| D2 | Flyback diode | 1N5819 Schottky, 1 A | Clamps the pump's kickback; carries the 0.4 A running current at turn-off | 16 |
| C3 | Motor noise capacitor | 100 nF ceramic, 50 V | Absorbs brush noise at the pump | 15, 16 |
| J2 | Pump connector | 2-way screw terminal | Pump surge too high for breadboard contacts | 11 |
| M1 | Pump | 12 V DC, 0.4 A, 10 Ω winding | Stall/start current `12 / 10 = 1.2 A` | 16 |
| LED1, R1 | Status LED | Green, `V_F` ≈ 2.0 V; 820 Ω | `(5 − 2.0) / 820 ≈ 3.7 mA`, safe on an R4's 8 mA pins | 4, 17 |
| SW1 | Button | Momentary, normally open, to GND | Internal pull-up (20–50 kΩ), active low, software debounce | 19 |
| R2 | Divider resistor | 22 kΩ, 1 % | `√(2 kΩ × 200 kΩ) ≈ 20 kΩ` → 22 kΩ | 10 |
| C2 | ADC filter | 100 nF ceramic | `τ ≤ 22 kΩ × 100 nF ≈ 2 ms`; read after 10 ms | 10, 15 |
| J3 | Probe connector | 2-way | Probe powered from D7 only while reading, to stop corrosion | 10 |

## Wiring

From chapter 11, with the pump 1.5 m from the box:

| Run | Current | Wire | Check |
|---|---|---|---|
| Adapter to J1 | 1.25 A peak | Adapter's own cable | Rated for 2 A |
| J1 → F1 → D1 → `+12V_P`, star point | 1.25 A peak | 22 AWG stranded, short | Well within 2–3 A |
| `+12V_P` → board `VIN`, board GND → star | 54 mA | 22 AWG | Trivial |
| J2 → pump (1.5 m), pump return → Q1 | 0.4 A, 1.2 A peaks | 22 AWG stranded, two-core | 3 m loop ≈ 0.16 Ω: drop ≈ 0.19 V (1.6 %) at start-up |
| Q1 source → star point | Same as the pump | 22 AWG, its own wire | Keeps pump current off the board's ground (no ground shift) |
| Probe (1.5 m) | ≤ 0.21 mA | 26–28 AWG two-core | Any wire works; keep away from the pump wires |
| LED, button | ≤ 4 mA | Breadboard jumpers | Trivial |

The pump's high-current loop (`+12V_P` → pump → Q1 → star point) is the only path carrying amps. It never passes
through the breadboard or the board.

## Power budget

```bash
node examples/power-budget.mjs
```

```text
Load                 Rail   On-current  Power when on  Energy per day
Arduino Uno board     5 V   50 mA       250 mW         6 Wh
Status LED (820 Ω)    5 V   3.7 mA      18.5 mW        444 mWh
Moisture probe        5 V   210 µA      1.05 mW        840 nWh
Pump                 12 V   400 mA      4.8 W          80 mWh

Peak current from the 12 V adapter (all loads on, pump starting): 1.25 A
Average current from the adapter: 54 mA
Energy used by the loads per day: 6.52 Wh; drawn from the adapter: 15.5 Wh
Choose an adapter rated at least 2 A (1.5 × the peak, rounded up)
```

What it tells you:

- **The adapter**: 1.25 A peak → a **12 V, 2 A regulated, centre-positive** adapter (chapter 12).
- **Energy**: the loads use 6.5 Wh a day, but 15.5 Wh is drawn from the adapter, because an Uno R3's linear regulator
  burns more than half of the electronics' energy dropping 11.6 V to 5 V. With an Uno R4 (switching regulator; set
  `LINEAR = false` in the script) it's about 7.2 Wh. Either way, a few kWh per year: negligible cost, but it's the
  difference that matters on batteries.
- **The pump is irrelevant to energy** (1 % of it) but sets the peak current. The always-on board dominates
  (chapter 5's lesson).

## The battery variant

For the balcony, chapter 12's Option B: a **3S Li-ion pack** (11.1 V nominal, 12.6 V full, 2500 mAh) with a BMS, a fuse
at the pack, and the same input protection. Two versions:

| | Uno, always awake | Low-power board, sleeping between readings |
|---|---|---|
| Electronics average | 54 mA (linear regulator) | about 1 mA from the pack (2 mA at 5 V through a buck) |
| Pump average (60 s/day) | 0.28 mA | 0.28 mA |
| Battery life | `0.8 × 2500 / 54.3` ≈ 37 h | `0.8 × 2500 / 1.28` ≈ 65 days |

```bash
node examples/battery-life.mjs 2500mAh 54mA 400mA@0.000694
node examples/battery-life.mjs 2500mAh 1.0mA 400mA@0.000694
```

A standard Uno lasts a day and a half; a board designed for sleep lasts two months. That 40-fold difference is the
practical meaning of "sleep current dominates battery life". For a battery version, choose a low-power board, power
the electronics through a buck converter, and turn the LED into a brief blink every few seconds.

Add **low-battery protection**: a divider (chapter 10) from the pack to an analog pin (for 12.6 V maximum,
20 kΩ / 10 kΩ gives 4.2 V; for 3.3 V boards use 33 kΩ / 10 kΩ), so the program stops pumping below about 10.5 V
(3.5 V per cell) — the pump's surge at a low state of charge is what would otherwise trip the BMS.

## Failure modes

Think through what can go wrong before it does. For each fault, what happens, and what limits the damage:

| Fault | What happens | Protection |
|---|---|---|
| Adapter plugged in reversed (screw terminal input) | D1 blocks; nothing powers up | D1 (chapter 17) |
| Wrong adapter: 19 V laptop charger | Uno R3 regulator overheats and shuts down; R4 accepts up to 24 V; pump over-driven | Label the input; keyed jack; R4 board; a TVS diode if you want more |
| Pump jammed | 1.2 A continuously; pump heats | F1 eventually; software timeout (stop the pump after 30 s whatever the sensor says) |
| Pump wires shorted together | Tens of amps from the adapter | F1 blows; adapters also limit their own current |
| Pump wire shorted to `+12V_P` side | Pump runs continuously, can't be switched off | Software can't help; F1 doesn't see an overload. Use two-core cable, strain relief |
| MOSFET fails short (rare with the flyback diode in place) | Pump runs continuously, overflows the pot | Software can detect "watered but soil still dry" over several cycles; a float switch in the saucer as a hardware cut-off is a good extension (exercise 3) |
| Flyback diode missing or reversed | Missing: MOSFET damaged over time. Reversed: a short across the pump, F1 blows at first switch-on | Check its direction in the pre-power checklist |
| Gate pull-down missing | Pump twitches on during upload or reset | R5 |
| Probe corroded | Readings drift towards "dry"; plant over-watered | Pulsed probe power; calibrate; replace the probe periodically |
| Board reset when the pump starts | 12 V dips or ground shifts | C1, separate pump ground wire (chapter 20) |
| Static discharge on the button or probe wires | Damaged pin | Optional 1 kΩ series resistors on D2 and A0 lines that leave the box |
| Water in the electronics | Shorts, corrosion | Box above the reservoir, drip loops, sealed glands; adapter on an RCD-protected socket |

The software-related protections (timeouts, sanity checks) belong to your Arduino code, but they are listed here
because good electrical design and good software protect each other.

## Bill of materials

| Qty | Part | Notes |
|---|---|---|
| 1 | Arduino Uno R3 or R4 | |
| 1 | 12 V 2 A regulated adapter, centre positive, 5.5 × 2.1 mm plug | Certified, from a reputable seller (chapter 13) |
| 1 | Panel or PCB barrel jack, or a 2-way screw terminal | J1 |
| 1 | 5 × 20 mm fuse holder + 1.5 A slow-blow (T1.5A) fuses | F1, plus spares |
| 1 | 1N5822 Schottky diode | D1 |
| 1 | 1N5819 Schottky diode | D2 |
| 1 | 470 µF 25 V electrolytic capacitor | C1 |
| 2 | 100 nF ceramic capacitors (50 V) | C2, C3 |
| 1 | IRLZ44N MOSFET | Q1 |
| 1 | 220 Ω resistor | R4 |
| 1 | 100 kΩ resistor | R5 |
| 1 | 820 Ω resistor | R1 |
| 1 | 22 kΩ 1 % resistor | R2 |
| 1 | Green 5 mm LED | LED1 |
| 1 | Momentary push button | SW1 |
| 1 | 12 V DC submersible pump, ≈ 0.4 A, with tubing | M1 |
| 2 | Soil probe prongs (stainless steel or a ready-made probe) | J3 |
| 2–3 | 2-way screw terminals | J2, J3 |
| — | 22 AWG stranded two-core cable, 26–28 AWG two-core cable, 22 AWG solid jumpers, breadboard | |
| 1 | Enclosure with cable glands | Above the reservoir |

## Bring-up and test plan

Build and test one section at a time, with the next section disconnected (chapter 20's checklist applies at every
stage). Expected values are typical; within about 10 % is fine unless noted.

**Stage 1 — power input, nothing else connected.**

| Test | Expected |
|---|---|
| Continuity `+12V_P` to GND, power off | No beep (C1 may beep briefly as it charges from the meter: a short beep is fine, a steady one is a short) |
| Adapter's open-circuit voltage | 12.0–12.3 V |
| `+12V_P` to GND, powered | About 11.7–12.0 V (adapter minus D1's small no-load drop) |
| Reverse the input briefly (screw terminal version) | `+12V_P` reads 0 V; nothing warm |

**Stage 2 — board on `VIN`.**

| Test | Expected |
|---|---|
| Board's 5V pin to GND | 4.9–5.1 V |
| Current into `VIN` (meter in series, chapter 14) | 30–60 mA, depending on the board |
| Regulator temperature after 5 minutes (Uno R3) | Warm, not hot |

**Stage 3 — LED and button.** Load a simple test program (blink D5; report D2's state over USB).

| Test | Expected |
|---|---|
| Voltage across R1 with the LED on | About 3.0 V → `3.0 / 820 ≈ 3.7 mA` |
| D2 with the button released / pressed | About 5 V / about 0 V |

**Stage 4 — moisture sensor.**

| Test | Expected |
|---|---|
| D7 HIGH, probe in dry soil: A0 | Well under 1 V (reading under about 200) |
| Probe in a glass of tap water | 3.5–4.6 V (reading 700–940; depends on the water) |
| D7 LOW | A0 ≈ 0 V |

Calibrate the "dry" threshold in your real pot: water it, wait a day, note the reading, then let it dry until the
plant needs water and note that reading. Water at a reading in between.

**Stage 5 — pump driver, with a dummy load first.** Replace the pump with a 1 kΩ resistor in series with an LED
(about 10 mA from 12 V) to test switching safely.

| Test | Expected |
|---|---|
| D9 LOW: Q1 drain to GND | ≈ 11.7 V (dummy LED off) |
| D9 HIGH: gate to GND | ≈ 5 V |
| D9 HIGH: drain to GND | A few mV (dummy LED on) |
| Reset the board while watching the dummy LED | Stays off (pull-down works) |

**Stage 6 — the real pump.**

| Test | Expected |
|---|---|
| Running current (meter in series, 10 A input) | About 0.4 A |
| `+12V_P` while the pump starts | Brief dip of well under 1 V; the board must not reset |
| Voltage across Q1 (drain to source) while running | ≈ `0.4 × 0.022` ≈ 9 mV (plus wiring) |
| Q1, D2, wires and connectors after a 30 s run | Cool |
| Pump terminals while running | ≈ 11.5 V (about 0.5 % lost in the wires) |

If every stage passes, the electrical side is finished and your program can rely on it.

## What to learn next

You now have the foundations that most Arduino tutorials skip. Next steps:

1. **Arduino itself.** Install the Arduino IDE, learn its C++ dialect (`setup()`, `loop()`, `digitalWrite`,
   `analogRead`, `analogWrite`, `millis()` for timing without blocking), and build the Plant Station stage by stage,
   following the bring-up plan above. The official documentation at <https://docs.arduino.cc/> has board pinouts and
   power specifications for every board.
2. **A circuit simulator.** Draw this tutorial's circuits in Falstad's online simulator or in LTspice, and watch the
   currents and voltages you calculated (chapter 9).
3. **Soldering.** Move a finished breadboard circuit to perfboard or stripboard. It's the step from a prototype to
   something that lasts.
4. **Sensors and communication buses.** I²C, SPI and UART: how modules talk to the board, and why I²C needs
   pull-ups (chapter 19).
5. **Further reading.** *Make: Electronics* (Charles Platt) for hands-on experiments; *Practical Electronics for
   Inventors* (Scherz and Monk) as a reference; *The Art of Electronics* (Horowitz and Hill) when you want the full
   depth of analog design.

## Key takeaways

- Start from written requirements; let each requirement drive a section of the design.
- Every value in a sound design has a derivation you can point to: Ohm's law for resistors, power budgets for supplies,
  `I × R` and ampacity for wires, surge currents for fuses and capacitors.
- Draw schematics in sections joined by net labels; keep the high-current loop short, separate and off the
  breadboard; star-ground it.
- The power budget sizes the adapter from the peak and the battery from the average; regulators change both.
- Think through failure modes and add cheap protection: fuse, reverse-polarity diode, flyback diode, pull-down,
  bulk capacitor, software timeouts.
- Bring up one section at a time, with expected measurements, a dummy load before the real one, and the
  pre-power-on checklist.

## Exercises

1. **A second plant.** Add a second pot with its own probe and pump. Which parts do you duplicate, which pins do you
   use, and does the adapter or fuse need to change if the two pumps can run at the same time?

<details>
<summary>Solution</summary>

Duplicate the probe circuit (R2, C2, J3) on a second analog pin (A1) with its own probe-power pin (or share D7 if both
probes are read together), and the pump driver (Q1, R4, R5, D2, C3, J2) on a second PWM-capable pin (D10). Peak
current with both pumps starting at once: `2 × 1.2 + 0.05 ≈ 2.45 A`, above a 2 A adapter and the 1.5 A fuse, and the
wiring to the star point carries it too. Either have the software never start both pumps at once (stagger them by a
second, so the peak is `1.2 + 0.4 + 0.05 ≈ 1.65 A`, then a 2 A fuse and adapter suffice), or use a 3 A adapter and a
2.5 A slow-blow fuse with 20 AWG for the shared runs. Staggering is the better design.

</details>

2. **Switch to a 3.3 V board.** You replace the Uno with a 3.3 V board (for example an ESP32 development board) that
   accepts 5 V on its `5V`/`VIN` pin and has 3.3 V I/O with 12-bit ADC inputs that must stay below 3.3 V. List every
   change to the design.

<details>
<summary>Solution</summary>

- **Power**: the board can't take 12 V. Add a 12 V → 5 V buck converter module from `+12V_P` to the board's 5 V
  input (set and measure its output *before* connecting the board).
- **LED**: from 3.3 V, `(3.3 − 2.0) / 3.7 mA ≈ 350 Ω` → 390 Ω (≈ 3.3 mA). Check the pin's drive limit.
- **Moisture divider**: powered from a 3.3 V pin, so it stays within the ADC's range automatically; the same 22 kΩ
  works. Recalibrate the readings (12-bit: 0–4095).
- **MOSFET**: the gate now sees 3.3 V. The IRLZ44N's `R_DS(on)` is specified at 4–5 V, not 3.3 V: switch to a part
  specified at 2.5 V, such as the AO3400 (on a small adapter board), or add a gate driver.
- **Button**: unchanged (internal pull-up to 3.3 V).
- **Battery monitor divider** (if used): rescale for 3.3 V, e.g. 33 kΩ / 10 kΩ for a 12.6 V pack.

</details>

3. **Overflow cut-off.** Add a float switch in the pot's saucer that closes when water collects there. Design it two
   ways: (a) as an input the software reads, and (b) as a hardware interlock that turns the pump off even if the
   software fails.

<details>
<summary>Solution</summary>

(a) Float switch from a spare pin (say D3) to GND, with `INPUT_PULLUP` (and a 1 kΩ series resistor, because the wires
leave the box). The program refuses to pump while D3 reads LOW.

(b) Use a **normally-closed** float switch **in series with the pump**, between J2 and the pump, rated for at
least the pump's 1.2 A start surge. When water collects in the saucer the switch opens and the pump stops, whatever
the MOSFET or the software is doing. (Pulling the MOSFET's gate to ground with the switch also works, but then the
switch fights pin D9 through R4: 5 V across 220 Ω is about 23 mA, too much for an Uno R4 pin, so R4 would have to rise
to 1 kΩ or more. The series switch is simpler and doesn't depend on the MOSFET working.)

</details>

4. **Solar.** You want the battery version to run indefinitely from a small solar panel. Using the low-power figures
   (1.28 mA average from an 11.1 V pack), how much energy per day must the panel replace? If the panel gets the
   equivalent of 3 hours of full sun a day and the charger is 80 % efficient, what panel power do you need?

<details>
<summary>Solution</summary>

```text
energy per day = 11.1 V × 1.28 mA × 24 h ≈ 0.34 Wh
panel energy needed = 0.34 / 0.8 ≈ 0.43 Wh
panel power = 0.43 Wh / 3 h ≈ 0.14 W
```

A 1 W panel gives plenty of margin for cloudy days. (With the always-awake Uno, it would be about 15 Wh a day and a
6–10 W panel, plus a much larger battery for the night.)

</details>

5. **Explain it to a friend.** Your friend's version of the Plant Station uses a 9 V battery, an IRF520 module with no
   diode, and wires the pump's ground through the breadboard's GND rail. The board resets whenever the pump starts and
   the battery lasts an afternoon. Using what you learned, explain each problem and its fix in a few sentences.

<details>
<summary>Solution</summary>

- **9 V battery**: only about 5 Wh, high internal resistance (1–2 Ω), so the pump's 1.2 A start surge drops its voltage
  by about 2 V, enough to brown out the board (chapter 12). A 12 V pump also needs 12 V. Use a 12 V adapter or a 3S
  Li-ion pack with a BMS.
- **IRF520**: not logic-level; at 5 V gate drive it's only partly on, so it drops volts and heats up (chapter 18). Use
  an IRLZ44N.
- **No flyback diode**: every switch-off sends a voltage spike into the MOSFET and the wiring, which damages the
  MOSFET over time and can reset the board (chapter 16). Add a 1N5819 across the pump.
- **Pump ground through the breadboard rail**: the surge current shifts the board's ground and overloads the
  breadboard contacts (chapters 7, 11, 20). Give the pump loop its own wires to a star point at the supply.
- Also add bulk capacitance (470 µF) across the 12 V input (chapter 15).

</details>

---
← Previous: [Protecting circuits and good practice](20-protecting-circuits-and-good-practice.md) | Next: [README](README.md) →
