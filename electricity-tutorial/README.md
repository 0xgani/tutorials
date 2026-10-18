# The Electricity Tutorial — from electrons to your first Arduino circuit

A long-form, text-only tutorial that teaches how electricity actually works, so that when you pick up an Arduino you
understand *why* a circuit works, not just which wire goes where. It starts with atoms and electrons, builds up
charge, current, voltage, resistance, power and energy with their units (coulombs, amperes, volts, ohms, watts,
joules, watt-hours, milliamp-hours), and then uses them: Ohm's law, series and parallel circuits, Kirchhoff's laws,
voltage dividers, wire thickness and voltage drop, batteries and power supplies, AC and mains safety, multimeters,
capacitors, inductors, motors and relays, diodes and LEDs, transistors as switches, digital logic levels and PWM, and
how to protect circuits (and yourself). It closes with a capstone that designs, on paper and with numbers, the complete
electrical side of a real Arduino project.

Everything is explained from first principles, with the *why* before the *how*: what is physically going on inside a
wire, why a thin wire gets hot, why a resistor is needed in front of an LED, why a motor needs a diode across it, why
an unconnected input pin reads random values.

## Who it's for

- You want to learn Arduino (or any microcontroller) and you don't want to copy circuits blindly.
- You may have forgotten (or never had) school physics. No prior electronics knowledge is assumed.
- You are comfortable with basic algebra: rearranging `V = I × R` into `I = V / R` is about as hard as it gets.
  Chapter 6 reviews powers of ten and unit prefixes, which is where most beginner mistakes come from.
- You know JavaScript and Node.js. That is *not* needed to understand the material, but the tutorial ships small
  optional Node.js calculator scripts (see below) that you can read, run and modify.

By the end you should be able to read a schematic, pick a resistor, size a wire, estimate battery life, choose a
power supply, drive a motor safely from a microcontroller pin, measure what a circuit is really doing with a
multimeter, and spot the mistakes that destroy boards.

## Prerequisites

| You need | Why | Notes |
|---|---|---|
| Curiosity and a calculator | Every chapter has worked numbers | Your phone's calculator is fine |
| Optional: a cheap digital multimeter | Chapter 14 and the "try it" sections | Any auto-ranging meter with a CAT rating is fine (chapter 14) |
| Optional: a starter kit | Trying things on a breadboard | A breadboard, jumper wires, assorted resistors, LEDs, a few capacitors, a push button, a 9 V or 4×AA battery holder |
| Optional: Node.js 18 or newer | Running the calculators in [`examples/`](examples/) | `node --version` |

You do **not** need an Arduino yet. Nothing in this tutorial requires mains electricity, and you should not work on
mains wiring as a learning exercise (chapter 13 explains why).

## How to use this tutorial

- **Read in order.** Each chapter builds on the earlier ones, and the running example grows as you go.
- **Do the arithmetic yourself.** Every chapter has worked examples. Cover the answer, work it out, then compare.
  Fluency with the units is the skill, and it only comes from practice.
- **Do the exercises.** Every chapter ends with 3–6 exercises from easy to hard. Solutions are hidden in collapsible
  "Solution" sections.
- **Build what you can.** If you have a kit, the chapters suggest small experiments that use only batteries of 9 V or
  less. They are optional; the text stands on its own.
- **Treat the numbers as engineering numbers.** Real parts have tolerances, wires have temperatures, batteries sag.
  The tutorial rounds sensibly and tells you when a value is approximate.

### The running example: the Plant Station

Throughout the tutorial you design the electrical side of the **Plant Station**, a small self-watering plant monitor
that you could later build with an Arduino. It has a status LED, a push button, a soil-moisture sensor, a small 12 V
water pump, and a power supply. You meet it in chapter 1 as a sketch on a napkin. Chapter 4 calculates the LED's
resistor, chapter 5 its power budget, chapter 10 turns the moisture sensor into a voltage the board can read,
chapter 11 sizes the wires to the pump, chapter 12 picks the power supply and estimates battery life, chapter 15 adds
decoupling capacitors, chapter 16 tames the pump motor's voltage spikes, chapter 17 adds reverse-polarity protection,
chapter 18 switches the pump with a MOSFET, chapter 19 wires the button with a pull-up resistor and dims the LED with
PWM, and chapter 20 adds a fuse. The capstone (chapter 21) puts the whole design together with a full schematic,
parts list, power budget and test plan.

### The calculators

[`examples/`](examples/) contains small, dependency-free Node.js scripts that do the arithmetic from the chapters:
Ohm's law, LED resistors, power and battery life, voltage dividers, wire voltage drop, RC time constants and the
capstone's power budget. Each one is introduced in the chapter that teaches the idea, and each chapter also shows the
same calculation by hand, so you never *need* them. Run any of them with `node examples/<name>.mjs` and no arguments
to see its usage.

## Markers

One marker appears throughout. It always uses the same wording, so you can search for it.

> **Just a taste — you don't need to learn or fully understand this now.** This marks something that is only shown so
> you know it exists. A later chapter (named in the note) teaches it properly, or it is advanced enough that knowing
> it exists is all you need for now.

Everything without a marker is meant to be learned.

## Table of contents

### Part I — What electricity is

1. [What electricity is](01-what-electricity-is.md) — atoms, protons and electrons, electric charge, conductors,
   insulators and semiconductors, static electricity vs. current, the water analogy and where it breaks, and the
   Plant Station.
2. [How electrons move](02-how-electrons-move.md) — free electrons, the electric field, drift velocity (slower than a
   snail), why the lamp still lights instantly, conventional current vs. electron flow, collisions and heat, and
   where the energy really travels.
3. [Charge, current and voltage](03-charge-current-and-voltage.md) — the coulomb, the ampere, the volt, potential
   and potential difference, ground as a reference, DC and AC at a glance, and typical values from microamps to
   lightning.
4. [Resistance and Ohm's law](04-resistance-and-ohms-law.md) — the ohm (Ω), what resistance is physically,
   resistivity, Ohm's law in all three forms, resistors, non-ohmic devices, and the Plant Station's LED resistor.
5. [Power and energy](05-power-and-energy.md) — the watt, the joule, `P = V × I`, `I²R` heating, power ratings,
   watt-hours and milliamp-hours, efficiency, and the cost of running things.
6. [Units, prefixes and part markings](06-units-prefixes-and-part-markings.md) — SI prefixes, scientific notation,
   converting without mistakes, the resistor colour code, E-series values and tolerance, capacitor and SMD codes, and
   how to read a datasheet.

### Part II — Circuits

7. [Circuits, schematics and breadboards](07-circuits-schematics-and-breadboards.md) — what makes a circuit, open and
   short circuits, schematic symbols, reading a schematic, nets and ground symbols, and how a breadboard is wired
   inside.
8. [Series and parallel circuits](08-series-and-parallel-circuits.md) — series resistance, parallel resistance,
   combinations, how current and voltage split, and why your house is wired in parallel.
9. [Kirchhoff's laws and circuit analysis](09-kirchhoffs-laws-and-circuit-analysis.md) — the current law, the
   voltage law, node voltages, solving circuits step by step, superposition, and Thévenin equivalents.
10. [Voltage dividers and sensors](10-voltage-dividers-and-sensors.md) — the divider formula, potentiometers,
    loading, resistive sensors (LDRs, thermistors, moisture probes), and the Plant Station's moisture sensor.
11. [Wires and wire thickness](11-wires-and-wire-thickness.md) — why thickness matters, cross-section and resistance,
    AWG and mm², voltage drop, heating and current capacity, solid vs. stranded, length, temperature, connectors and
    breadboard jumpers, and sizing the Plant Station's pump wires.
12. [Batteries and power supplies](12-batteries-and-power-supplies.md) — EMF and internal resistance, battery
    chemistries, capacity and C-rate, cells in series and parallel, wall adapters, USB power, linear and switching
    regulators, and estimating battery life.
13. [AC, mains and electrical safety](13-ac-mains-and-electrical-safety.md) — alternating current, frequency, peak
    and RMS, why the grid uses AC, transformers, how mains is wired, what current does to the human body, and the
    rules that keep you safe.
14. [Measuring with a multimeter](14-measuring-with-a-multimeter.md) — measuring voltage, current, resistance and
    continuity, why the current mode is dangerous, the meter's own resistance, CAT ratings, and a debugging method.

### Part III — Components

15. [Capacitors and RC circuits](15-capacitors-and-rc-circuits.md) — the farad, how a capacitor stores charge,
    charging curves and the RC time constant, energy, types and voltage ratings, polarity, decoupling, and filtering.
16. [Magnetism, inductors, relays and motors](16-magnetism-inductors-relays-and-motors.md) — electromagnetism, the
    henry, why inductors resist change, inductive kickback and flyback diodes, relays, DC motors, stall current, and
    the Plant Station's pump.
17. [Diodes and LEDs](17-diodes-and-leds.md) — semiconductors and the PN junction, forward voltage, rectifier,
    Schottky and Zener diodes, LEDs and their colours, why LEDs need current limiting, and reverse-polarity
    protection.
18. [Transistors as switches](18-transistors-as-switches.md) — why a microcontroller pin can't drive a motor, BJTs
    and base resistors, MOSFETs and logic-level gates, low-side switching, power dissipation, and switching the Plant
    Station's pump.

### Part IV — Toward Arduino

19. [Digital signals, logic levels and PWM](19-digital-signals-logic-levels-and-pwm.md) — HIGH and LOW as voltages,
    5 V and 3.3 V logic, input thresholds, floating inputs, pull-up and pull-down resistors, buttons and bounce, pin
    current limits, PWM, analog-to-digital conversion, and level shifting.
20. [Protecting circuits and good practice](20-protecting-circuits-and-good-practice.md) — fuses and polyfuses,
    reverse polarity, overvoltage and transients, ESD, common ground, power and ground wiring, lithium battery
    safety, heat, and a pre-power-on checklist.
21. [Capstone project: the Plant Station](21-capstone-project.md) — the complete electrical design: requirements,
    schematic, every component value derived, wire sizes, power budget, battery option, protection, a parts list, a
    bring-up and test plan with a multimeter, and what to learn next.

## Repository layout

```text
electricity-tutorial/
  README.md              # this file
  01-...md … 21-...md    # the chapters
  examples/              # optional Node.js calculators used in the chapters
```

## Conventions

- Units follow the SI conventions: a space between number and unit (`5 V`, `220 Ω`, `20 mA`), the unit symbol is
  never pluralised (`5 V`, not `5 Vs`), and prefixes are case-sensitive (`m` is milli, `M` is mega).
- `×` means multiply and `/` means divide. Formulas appear in `code` so they stand out, for example `P = V × I`.
- Worked examples show every step with units, because carrying units through a calculation is the best way to catch
  a mistake.
- Code blocks are tagged with their language: `text` for formulas, diagrams and output, `bash` for commands, `js` for
  the calculators.
- Schematic diagrams are drawn in ASCII. Chapter 7 explains the symbols.
