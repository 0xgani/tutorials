# Chapter 20 — Protecting circuits and good practice

## What you'll learn

- What fuses protect (wires, not parts), and how to choose one
- Resettable polyfuses, and the one on every Arduino's USB input
- Reverse-polarity, over-voltage and transient protection
- Electrostatic discharge (ESD): what it does to chips and how to avoid it
- Grounding: common ground, shared return paths, ground shift and star grounding
- Wiring habits that keep noise down
- Lithium battery safety
- Heat, water and the environment
- A checklist to run before you power anything up for the first time

## Why protection matters

Most of this tutorial has been about making circuits work. This chapter is about what happens when something goes
wrong: a wire chafes through, an adapter is plugged in backwards, a pump jams, a static spark hits a pin, or a battery
is shorted. Good designs assume these will happen and limit the damage. The parts that do it cost very little
compared with a board, a battery or a fire.

## Fuses

A **fuse** is a deliberately weak link: a short wire or strip that melts and opens the circuit when the current
exceeds its rating for long enough.

```text
   + supply ──[ fuse ]──── rest of the circuit
              placed as close to the source as possible, in the positive lead
```

The most important idea: **a fuse protects the wiring (and the source), not the components.** A short circuit
downstream can draw tens or hundreds of amps from a battery or a strong supply (chapters 4 and 12). Without a fuse,
the wire becomes the weakest link and overheats — the fire risk. A fuse placed at the source opens before that
happens. It usually doesn't save a component that has already failed short; it stops the failure from spreading.

### Choosing a fuse

1. **Current rating**: above the normal maximum current (typically 1.25–2× it), and **below what the smallest wire
   it protects can safely carry** (chapter 11).
2. **Speed**: **fast-acting** (F) fuses blow quickly; **slow-blow** or time-delay (T) fuses tolerate brief surges.
   Use slow-blow for motors, pumps and anything with large input capacitors, whose start-up surges would blow a fast
   fuse.
3. **Voltage rating**: at least the circuit's voltage (any common fuse covers 12 V or 24 V DC).
4. **Form factor**: 5 × 20 mm glass in a holder, automotive blade fuses (convenient for 12 V systems), or small SMD
   fuses on boards.

**Fuses don't blow instantly at their rating.** A fuse typically carries its rated current indefinitely, blows in
seconds to minutes at about twice it, and only blows in milliseconds at many times it. Its datasheet has a
**time–current curve**. That's why a fuse is the right protection for wiring (which also heats slowly) and the wrong
protection for a delicate chip.

**Worked example: the Plant Station.** On the 12 V input: normal maximum current about 0.45 A (pump plus electronics),
pump start surge 1.2 A for a fraction of a second, smallest power wire 22 AWG (comfortable to 2–3 A). Choose a
**1.5 A or 2 A slow-blow** fuse: it ignores the start surge, carries normal loads with margin, and blows if the pump
jams (1.2 A continuous is close to the limit, so it won't blow quickly — the pump's own thermal behaviour matters
too) or if anything shorts (tens of amps: instantly). It's below the wire's limit.

**Batteries need fuses most.** A small Li-ion or LiPo pack can deliver tens of amps; a car battery, hundreds. Put the
fuse right at the battery's positive terminal. Many lithium cells have protection circuits built in (below), but a
fuse is still good practice on any pack powering wiring.

### Polyfuses (resettable fuses)

A **polyfuse** or **PTC resettable fuse** is a polymer component whose resistance jumps dramatically when it heats
up past a threshold. In an overload it heats, its resistance rises, and the current drops to a trickle; when the fault
is removed and it cools, it resets.

| Spec | Meaning |
|---|---|
| Hold current `I_hold` | Carries this indefinitely without tripping |
| Trip current `I_trip` | Trips (eventually) at this, typically about 2× `I_hold` |
| Time to trip | Seconds at moderate overloads, much faster at a dead short |

The Arduino Uno has a **500 mA polyfuse** on its USB input. If you short the 5 V pin while running from USB, it trips
and protects your computer's USB port; unplug, fix the fault, wait a minute, and it resets. Polyfuses are slow and
have a little resistance, so they're used where convenience matters more than precision.

## Reverse polarity, over-voltage and transients

**Reverse polarity** (chapter 17): a series Schottky diode, or a P-channel MOSFET for lower loss, blocks a backwards
supply. Use a keyed connector (one that only fits one way) where you can; that prevents the mistake entirely.

**Over-voltage**: plugging a 19 V laptop charger into a 12 V device, or a 12 V supply into a 5 V pin. A protection
circuit can't make up for the wrong supply, but it can survive it:

- Use connectors that don't fit the wrong supply, label inputs clearly, and keep mismatched adapters apart.
- A **TVS diode** (transient-voltage suppressor) across the input clamps brief spikes and, together with a fuse,
  blows the fuse instead of the circuit when a sustained over-voltage is applied.
- Regulators with a wide input range (like the Uno R4's 6–24 V) tolerate more mistakes.

**Transients** are brief spikes:

- **Inductive kickback** from motors, relays and solenoids: flyback diodes (chapter 16).
- **Hot-plugging spikes**: plugging an adapter into a board whose input has only small ceramic capacitors can make
  the input voltage *ring*, briefly overshooting to nearly twice the supply voltage (the cable's inductance and the
  capacitor form a resonant circuit). An electrolytic bulk capacitor across the input damps this, which is one reason
  the Plant Station's 470 µF capacitor sits at its input.
- **Long cables** pick up spikes from nearby equipment and lightning. Series resistors and TVS diodes on inputs that
  leave the box protect pins.

## Electrostatic discharge (ESD)

Walking across a carpet can charge you to several thousand volts (chapter 1). You feel a discharge only above about
3000 V; smaller ones happen unnoticed. The transistors in a chip are tiny, with insulating layers a few nanometres
thick: a discharge of a few hundred volts into a pin can puncture them. The damage can be immediate or latent (a chip
that works but fails weeks later).

Microcontrollers have ESD protection diodes on their pins, rated for typical handling (often 2000 V on the "human body
model"), which is why Arduino boards survive ordinary use. Good habits:

- **Touch something grounded** (a metal desk lamp's frame, a grounded computer case) before handling boards and chips,
  especially in dry weather.
- **Hold boards by their edges**, not by the pins.
- **Store chips and modules in anti-static bags** (the grey or pink ones they come in) or on conductive foam.
- For frequent work, an **anti-static wrist strap and mat** connected to ground (through the built-in 1 MΩ resistor,
  which limits current for safety) are cheap.
- Add protection to inputs that connect to the outside world (buttons on a long cable, sensor connectors): a series
  resistor, and optionally a TVS or ESD protection diode at the connector.

## Grounding

Chapter 3 introduced the first rule: **everything that exchanges signals must share a ground.** Now the second:
**ground wires carry current, and current in a wire causes a voltage drop** (chapter 11). So "ground" isn't the same
voltage everywhere.

### Ground shift

Picture the Plant Station wired carelessly, with the pump's return current sharing one ground wire with the sensor:

```text
   12 V supply −  ●═══════[ shared ground wire, 0.05 Ω ]═══════●── board GND, sensor GND
                                                               │
                                                       pump return (1.2 A at start)

   ground shift = 1.2 A × 0.05 Ω = 60 mV
```

During the pump's start surge, the board's "0 V" rises 60 mV above the supply's. The analog reading of the moisture
sensor jumps by `60 mV / 4.9 mV ≈ 12` steps (chapter 19). With bigger motors and thinner wires, the shift can be large
enough to reset the board or corrupt digital signals.

### Star grounding

The fix is to keep high-current return paths separate from signal grounds, joining them at **one point**, usually
the supply's negative terminal (or the input connector):

```text
                             ┌──── pump return (high current, own wire)
                             │
   supply − ●── star point ──┼──── 5 V regulator / board GND (low current)
                             │
                             └──── sensor GND (tiny current, follows the board's GND)
```

The pump's current then flows only in its own wire, and the board and sensor share a quiet ground. The same idea
applies on a breadboard: run motor and LED-strip grounds back to the supply separately, not through the breadboard
rail that the microcontroller uses.

> **Just a taste — you don't need to learn or fully understand this now.** A **ground loop** happens when two pieces
> of equipment are connected by both a signal cable and their separate mains earths, creating a loop in which
> currents induced by nearby mains wiring flow. It's the source of the classic hum in audio systems. Battery and
> adapter-powered Arduino projects rarely suffer from it.

## Wiring habits that reduce noise

- **Keep each current's out and return wires together**: use two-core cable or twist the pair. A small loop area
  means less magnetic field to radiate and less to pick up (chapters 2 and 16).
- **Keep high-current and signal wiring apart**, and cross at right angles when they must cross.
- **Decouple every chip** (100 nF) and add bulk capacitance near surge loads (chapter 15).
- **Short wires for fast or sensitive signals**; long sensor runs at slow rates, with filtering.
- **Power big loads from their own supply path**, sharing ground at one point (chapter 18).

## Lithium battery safety

Lithium-ion and LiPo cells store a lot of energy in a flammable electrolyte. Abused, they can enter **thermal
runaway**: internal heat causes reactions that make more heat, venting flammable gas and fire that is very hard to
extinguish.

| Danger | Cause | Prevention |
|---|---|---|
| Over-charge | Charging above 4.2 V per cell (3.65 V for LiFePO₄) | A dedicated lithium charger IC or module (e.g. TP4056-based for single cells), never a plain power supply |
| Over-discharge | Draining below about 2.5–3.0 V per cell | A protection circuit (most "protected" cells and modules include one); low-voltage cut-off in your design |
| Short circuit | Exposed terminals, metal in a bag, a pinched wire | Fuses, insulated terminals, protection circuits |
| Physical damage | Puncture, crushing, bending a pouch | Mount securely; never use a damaged or swollen cell |
| Heat | Hot environments, charging while hot | Keep below about 45 °C when charging, 60 °C in use |

Practical rules:

- Buy cells from reputable sources; fake high-capacity cells are common.
- Charge on a non-flammable surface and don't leave fast-charging packs unattended. A LiPo charging bag is cheap.
- A **swollen** pouch cell is damaged: stop using it and dispose of it properly (battery recycling points).
- Series packs need a **BMS** (battery management system) that balances and protects each cell (chapter 12).

## Heat, water and the environment

- **Feel for heat** (carefully, with the back of a finger, on low-voltage circuits only) or use a cheap IR thermometer
  after the first few minutes of running. Regulators, MOSFETs, resistors and connections should be cool or warm, not
  hot. Anything too hot to keep a finger on (above about 60 °C) needs a redesign.
- **Ventilate enclosures** that contain regulators or power supplies.
- **Water and electronics.** The Plant Station mixes them on purpose. Keep the electronics **above** the water
  reservoir and away from where water might drip, use a **drip loop** in cables (a downward loop so water drips off
  before reaching the box), seal cable entries, and keep mains-powered adapters well away from the water (plug them
  into a socket protected by an RCD, chapter 13).
- **Condensation, dust and vibration** shorten lifetimes; for anything permanent, move from a breadboard to a soldered
  board, with strain relief on cables.

## Before you power up: a checklist

Run through this every time you power a new or modified circuit for the first time:

1. **Power off and visually inspect.** Compare the wiring with the schematic, node by node (chapter 7).
2. **Check polarity** of every polarised part: electrolytic capacitors, diodes, LEDs, the battery or adapter, and
   any chip's orientation (pin 1 marker).
3. **Check transistor pinouts** against the datasheet.
4. **Continuity test between the supply rails** (chapter 14). A beep between `+` and GND is a short; find it now.
5. **Check the supply voltage** with a meter before connecting it.
6. **Use current limiting for the first power-up**: a bench supply with its current limit set a little above the
   expected draw, or a fuse. Watch the current: much more than expected means something is wrong. (No bench supply?
   A low-value resistor of a few ohms in series, or a fuse, plus a quick hand on the parts, is the next best thing.)
7. **Power on, then measure** the supply at the circuit and a few key node voltages against what you expect.
8. **Feel for heat** after a minute.
9. **Only then** connect the expensive parts (the board, the battery, the pump), one at a time.

It takes two minutes and saves boards.

## Key takeaways

- Fuses protect wiring and sources from overload and short circuits. Rate them above the normal current, below the
  wire's capacity; use slow-blow for motors and capacitive loads; put them at the source, especially on batteries.
- Polyfuses reset themselves; the Arduino's USB input has a 500 mA one.
- Protect against reverse polarity (diode, MOSFET, keyed connectors), transients (flyback diodes, bulk capacitors,
  TVS diodes) and over-voltage (labels, connectors, wide-range regulators).
- ESD damages chips: ground yourself, hold boards by the edges, store parts in anti-static bags, protect exposed inputs.
- Share one ground between communicating parts, but give high-current returns their own wire to a single star point
  to avoid ground shift.
- Keep current loops small, separate power and signal wiring, decouple chips.
- Lithium cells need proper chargers, protection circuits and care; never use a damaged or swollen one.
- Check for heat; keep water away from electronics and mains adapters; run the pre-power-up checklist.

## Exercises

1. **Pick a fuse.** A 24 V system feeds a motor drawing 3 A running and 9 A for half a second at start-up, wired with
   18 AWG (comfortable to 5–7 A). Choose a fuse rating and type.

<details>
<summary>Solution</summary>

A **5 A slow-blow** fuse: above 3 A normal (about 1.7×), tolerates the brief 9 A start (slow-blow), and below the
wire's comfortable 5–7 A. A fast-acting 5 A fuse might blow at each start; a 10 A fuse would let the wire overheat in
a sustained overload.

</details>

2. **What does it protect?** A 2 A fuse protects a circuit containing a 100 mA microcontroller board. The board's
   regulator fails short-circuit. Does the fuse save the board? What does it save?

<details>
<summary>Solution</summary>

No, the board is already damaged by the time the fuse sees anything (and the fault current through a shorted regulator
might even be under 2 A). What the fuse saves is the wiring and the power source from a sustained short, preventing
overheating and fire. Protecting the board itself needs other measures (correct voltages, reverse-polarity
protection, current-limited first power-up).

</details>

3. **Ground shift.** A 5 A LED strip and an Arduino share 1 m of 22 AWG ground wire (about 0.053 Ω) back to the
   supply. How much does the Arduino's ground move relative to the supply's when the strip turns on? What effect could
   that have, and how do you fix it?

<details>
<summary>Solution</summary>

`5 A × 0.053 Ω ≈ 0.27 V`. The board's 0 V rises by about a quarter of a volt whenever the strip is on (and the shift
also cuts the board's supply voltage by the same amount): analog readings jump by about 55 steps on a 10-bit, 5 V ADC,
and digital signals to other devices referenced to the supply's ground lose 0.27 V of margin. Fix: run the strip's
ground on its own thick wire back to the supply's negative terminal (star point) and connect the Arduino's ground to
that point with its own wire.

</details>

4. **Polyfuse.** You short an Arduino Uno's 5V pin to GND while it's powered from USB. The board goes dark. You
   remove the short and it stays dark for half a minute, then works again. Explain.

<details>
<summary>Solution</summary>

The short made the USB input's 500 mA polyfuse heat up and trip, cutting the current to a trickle and protecting the
computer's USB port. After the short was removed, the polyfuse needed time to cool down and return to its low
resistance, after which the board powered up normally.

</details>

5. **Checklist in practice.** You've built the Plant Station's MOSFET pump circuit on a board. List, in order, the
   checks you'd do before connecting the 12 V adapter and the pump, and the measurements you'd take right after
   powering it with the pump still disconnected.

<details>
<summary>Solution</summary>

Before power: compare wiring with the schematic; check the flyback diode's direction (cathode to +12 V), the
electrolytic capacitor's polarity, the reverse-polarity Schottky's direction, and the MOSFET's pinout (G-D-S for an
IRLZ44N, left to right facing the printed side); fit the fuse; continuity test between +12 V and GND (no beep) and
between +5 V and GND (no beep); measure the adapter's voltage (about 12 V) and confirm its polarity.

After power, pump disconnected: measure +12 V at the board (about 11.6 V after the Schottky), +5 V after the buck
converter, the gate voltage (0 V with the pin LOW, about 5 V when HIGH), and the drain voltage with nothing connected.
Check nothing is warm. Then connect the pump, run it, measure the 12 V rail during start-up and check that the MOSFET,
diode and wires stay cool.

</details>

---
← Previous: [Digital signals, logic levels and PWM](19-digital-signals-logic-levels-and-pwm.md) | Next: [Capstone project: the Plant Station](21-capstone-project.md) →
