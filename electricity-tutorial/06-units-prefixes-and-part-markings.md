# Chapter 6 — Units, prefixes and part markings

## What you'll learn

- The SI prefixes from pico to giga, and how to convert between them without mistakes
- Scientific and engineering notation, and how to calculate with powers of ten
- A complete reference of the electrical units used in this tutorial
- How to read resistor colour codes (4- and 5-band) and the codes printed on SMD resistors
- Why resistors come in odd values like 4.7 kΩ: E-series and tolerance
- How to read capacitor markings like `104` and `10µF 25V`
- How to read a datasheet: absolute maximum ratings, recommended conditions, and typical vs. guaranteed values

## Why a whole chapter on units?

In electronics, values span from picofarads (10⁻¹²) to megohms (10⁶). The most common beginner mistakes are not
conceptual; they are off by a factor of 1000: using 20 instead of 0.020 for 20 mA and getting a 0.15 Ω resistor
instead of 150 Ω, or reading `M` as milli. One wrong prefix can turn a calculation into a fire. This chapter makes
the units automatic so the rest of the tutorial (and Arduino datasheets) read easily.

## SI prefixes

A prefix multiplies a unit by a power of ten. Electronics uses steps of 1000:

| Prefix | Symbol | Multiplier | Power of ten | Examples |
|---|---|---|---|---|
| giga | G | 1 000 000 000 | 10⁹ | 1 GΩ insulation, 16 GHz |
| mega | M | 1 000 000 | 10⁶ | 1 MΩ resistor, 16 MHz crystal |
| kilo | k | 1 000 | 10³ | 4.7 kΩ, 1 kW, 1 kHz |
| — | — | 1 | 10⁰ | 5 V, 1 A, 330 Ω |
| milli | m | 0.001 | 10⁻³ | 20 mA, 500 mW, 10 ms |
| micro | µ (often `u`) | 0.000 001 | 10⁻⁶ | 100 µF, 50 µA, 4 µs |
| nano | n | 0.000 000 001 | 10⁻⁹ | 100 nF, 62.5 ns |
| pico | p | 0.000 000 000 001 | 10⁻¹² | 22 pF |

Rules that prevent errors:

- **Case matters.** `m` is milli (10⁻³); `M` is mega (10⁶) — a factor of a billion apart. `k` is lowercase; `K` is
  sometimes used informally but strictly means kelvin.
- **`µ` is often written `u`** when the Greek letter isn't available: `100uF` is 100 µF. Older schematics and some
  capacitors use `mF` or `MFD` to mean microfarads (an old convention). Context tells you: a 1000 F capacitor
  doesn't fit on a board.
- **The prefix attaches to the unit**, with no space: `4.7 kΩ`, `20 mA`. The number gets a space before the unit.

### Converting between prefixes

Every step in the table is a factor of 1000, which means moving the decimal point three places:

```text
Going to a SMALLER unit (A → mA → µA): multiply by 1000, the number gets bigger.
Going to a LARGER unit (µA → mA → A):  divide by 1000, the number gets smaller.

0.02 A    = 20 mA       = 20 000 µA
4700 Ω    = 4.7 kΩ      = 0.0047 MΩ
0.1 µF    = 100 nF      = 100 000 pF
```

A sanity check: a smaller unit always needs a bigger number for the same quantity, just as 1 metre = 100 centimetres.

## Scientific and engineering notation

Writing `0.000 000 1` is error-prone, so scientists write numbers as a value times a power of ten:

```text
0.000 000 1   = 1 × 10⁻⁷
6 240 000 000 000 000 000 = 6.24 × 10¹⁸
```

**Engineering notation** restricts the power to multiples of three, so it maps straight onto a prefix:
`4.7 × 10³ Ω = 4.7 kΩ`, `100 × 10⁻⁹ F = 100 nF`. On calculators and in code the power is written with an `e`:
`4.7e3`, `100e-9`, `1.6e-19`.

### Calculating with powers of ten

Multiply the plain numbers, **add** the powers; divide the plain numbers, **subtract** the powers:

```text
(2 × 10³) × (5 × 10⁻³) = (2 × 5) × 10^(3 + (−3)) = 10 × 10⁰ = 10
(3 × 10⁰) / (10 × 10⁻³) = (3 / 10) × 10^(0 − (−3)) = 0.3 × 10³ = 300
```

The second line is `3 V / 10 mA = 300 Ω`. Many unit pairs combine neatly; learn these shortcuts:

| Calculation | Shortcut |
|---|---|
| V / mA | = kΩ (5 V / 1 mA = 5 kΩ) |
| V / kΩ | = mA (5 V / 1 kΩ = 5 mA) |
| mA × kΩ | = V |
| kΩ × µF | = ms (time constant, chapter 15) |
| V × mA | = mW |
| mAh × V | = mWh (divide by 1000 for Wh) |

**The safest method** when unsure: convert every value to the base unit (volts, amps, ohms, farads, seconds) in
scientific notation, calculate, and only convert the answer back to a prefix at the end.

## The electrical units in this tutorial

| Quantity | Symbol in formulas | Unit | Unit symbol | Defined as |
|---|---|---|---|---|
| Charge | Q | coulomb | C | 6.24 × 10¹⁸ electrons |
| Current | I | ampere | A | C/s |
| Voltage | V (or U) | volt | V | J/C |
| Resistance | R | ohm | Ω | V/A |
| Conductance | G | siemens | S | 1/Ω |
| Energy | E (or W) | joule | J | W·s |
| Power | P | watt | W | J/s = V·A |
| Capacitance | C | farad | F | C/V (chapter 15) |
| Inductance | L | henry | H | V·s/A (chapter 16) |
| Frequency | f | hertz | Hz | cycles per second (chapter 13) |
| Time | t | second | s | |
| Battery capacity | — | amp-hour | Ah, mAh | 3600 C |
| Energy (practical) | — | watt-hour | Wh, kWh | 3600 J |

Notice how they're all built from each other. That's not a coincidence: it's what lets you check a formula by its
units. If you calculate a resistance and the units come out as V·A, you've multiplied where you should have divided.

## Resistor colour codes

Through-hole resistors are too small to print numbers on, so their value is encoded in coloured bands.

| Colour | Digit | Multiplier | Tolerance |
|---|---|---|---|
| Black | 0 | × 1 | |
| Brown | 1 | × 10 | ±1 % |
| Red | 2 | × 100 | ±2 % |
| Orange | 3 | × 1 k | |
| Yellow | 4 | × 10 k | |
| Green | 5 | × 100 k | ±0.5 % |
| Blue | 6 | × 1 M | ±0.25 % |
| Violet | 7 | × 10 M | ±0.1 % |
| Grey | 8 | | |
| White | 9 | | |
| Gold | | × 0.1 | ±5 % |
| Silver | | × 0.01 | ±10 % |

A classic mnemonic for the digit order: **B**ad **B**eer **R**ots **O**ur **Y**oung **G**uts **B**ut **V**odka
**G**oes **W**ell.

### 4-band resistors (±5 %, usually carbon film)

```text
    ┌─────────────────────────────┐
 ───┤ ▌    ▌    ▌         ▌       ├───
    └─────────────────────────────┘
      1st  2nd  multiplier tolerance (gap before it)
     digit digit
```

Read from the end with the bands bunched together; the tolerance band (gold or silver) is separated by a gap.

| Bands | Reading | Value |
|---|---|---|
| orange, orange, brown, gold | 3, 3, × 10, ±5 % | 330 Ω |
| red, red, brown, gold | 2, 2, × 10 | 220 Ω |
| brown, black, red, gold | 1, 0, × 100 | 1 kΩ |
| yellow, violet, red, gold | 4, 7, × 100 | 4.7 kΩ |
| brown, black, orange, gold | 1, 0, × 1 k | 10 kΩ |
| brown, black, yellow, gold | 1, 0, × 10 k | 100 kΩ |
| brown, black, gold, gold | 1, 0, × 0.1 | 1 Ω |

### 5-band resistors (±1 % or better, usually metal film)

Three digits instead of two, then the multiplier and tolerance:

| Bands | Reading | Value |
|---|---|---|
| orange, orange, black, black, brown | 3, 3, 0, × 1, ±1 % | 330 Ω |
| brown, black, black, red, brown | 1, 0, 0, × 100, ±1 % | 10 kΩ |
| yellow, violet, black, brown, brown | 4, 7, 0, × 10, ±1 % | 4.7 kΩ |

Blue metal-film bodies make some colours hard to tell apart (red vs. orange, brown vs. red). When in doubt, **measure
it** with a multimeter (chapter 14). Professionals do; it's faster than squinting.

### Printed codes on SMD resistors

Surface-mount resistors have codes printed on top:

| Code | Rule | Value |
|---|---|---|
| `331` | Three digits: two digits then number of zeros | 33 × 10¹ = 330 Ω |
| `472` | | 47 × 10² = 4.7 kΩ |
| `103` | | 10 × 10³ = 10 kΩ |
| `4701` | Four digits (±1 %): three digits then zeros | 470 × 10¹ = 4.7 kΩ |
| `4R7` | `R` marks the decimal point | 4.7 Ω |
| `000` or `0` | Zero-ohm link (a jumper) | 0 Ω |
| `01C` | EIA-96 code (two digits look up a table, letter is multiplier) | 10.0 kΩ |

### The "RKM" notation on schematics

Schematics often replace the decimal point with the prefix letter, because a decimal point can vanish when a drawing
is photocopied: `4k7` = 4.7 kΩ, `2R2` = 2.2 Ω, `1M5` = 1.5 MΩ, `330R` = 330 Ω. The same trick appears for
capacitors: `4n7` = 4.7 nF, `2u2` = 2.2 µF.

## Standard values: E-series and tolerance

You can't buy a 300 Ω resistor in most kits; you get 270 Ω or 330 Ω. Why such strange numbers?

Resistors are made with a **tolerance**: a ±5 % 330 Ω resistor may be anywhere from 313.5 Ω to 346.5 Ω. It would be
pointless to make values closer together than the tolerance. So manufacturers use **E-series**: sets of values per
decade spaced so that each value's tolerance range roughly touches the next.

```text
E6  (±20 %): 1.0  1.5  2.2  3.3  4.7  6.8
E12 (±10 %): 1.0  1.2  1.5  1.8  2.2  2.7  3.3  3.9  4.7  5.6  6.8  8.2
E24 (±5 %):  1.0  1.1  1.2  1.3  1.5  1.6  1.8  2.0  2.2  2.4  2.7  3.0
             3.3  3.6  3.9  4.3  4.7  5.1  5.6  6.2  6.8  7.5  8.2  9.1
```

Multiply by any power of ten: E12 includes 10 Ω, 12 Ω, … 82 Ω, 100 Ω, 120 Ω, …, 8.2 kΩ, 10 kΩ, and so on. The values
are spaced evenly on a logarithmic scale (each is about 21 % above the previous in E12), so the *relative* gap is
the same everywhere. E48, E96 and E192 exist for 2 %, 1 % and better parts.

A typical starter kit contains a subset of E12: 10, 22, 47, 100, 220, 330, 470, 680 Ω, 1, 2.2, 4.7, 10, 22, 47, 100 kΩ,
1 MΩ. That covers almost everything in this tutorial.

### Designing with tolerance

When choosing a value, think about which direction is safe:

- **Current-limiting resistors** (LEDs): round **up**, so the current comes out lower than designed.
- **Dividers** (chapter 10): the *ratio* matters; two 1 % resistors give a ratio within about 2 %.
- **Worst case**: when a limit really matters (a pin's maximum current), calculate with the lowest resistance the
  tolerance allows. For 330 Ω ±5 %: 313.5 Ω.

Tolerance applies to everything: LED forward voltages vary by ±0.2 V or more between parts, a "5 V" USB supply can be
4.75–5.25 V, capacitors are often ±10 % or ±20 %. Good designs work across the whole range.

## Capacitor markings

Capacitors (chapter 15) have their own conventions:

- **Electrolytic capacitors** (cylinders) are big enough to print plainly: `100µF 25V`, with a stripe marking the
  negative lead. Both numbers matter: capacitance *and* maximum voltage.
- **Ceramic capacitors** (small discs or SMD chips) use a three-digit code **in picofarads**, like SMD resistors:

| Code | Picofarads | Usual name |
|---|---|---|
| `220` or `22` | 22 pF | 22 pF (crystal load capacitors) |
| `101` | 100 pF | 100 pF |
| `103` | 10 000 pF | 10 nF |
| `104` | 100 000 pF | 100 nF = 0.1 µF (the standard decoupling capacitor) |
| `105` | 1 000 000 pF | 1 µF |

A letter after the code is the tolerance (`J` ±5 %, `K` ±10 %, `M` ±20 %, `Z` +80/−20 %), so `104K` is
100 nF ±10 %. Many SMD ceramic capacitors have no marking at all; you rely on the reel label or a meter.

## Reading a datasheet

Every component has a **datasheet** from its manufacturer: the authoritative source for what it can do. Search for
the part number (printed on it, or in the seller's listing) plus "datasheet". Datasheets look intimidating, but for
basic work you need three tables.

### 1. Absolute maximum ratings

Limits that must **never** be exceeded, even briefly, or the part may be damaged permanently. For example, from a
typical 5 mm green LED datasheet:

```text
Absolute Maximum Ratings (Ta = 25 °C)
  Continuous forward current       IF     30 mA
  Peak forward current (1/10 duty) IFP   100 mA
  Reverse voltage                  VR      5 V
  Power dissipation                PD    100 mW
  Operating temperature                  −40 … +85 °C
```

These are not design targets. A part at its absolute maximum is at the edge of destruction, and its lifetime and
performance are not guaranteed there. The Arduino Uno R3's microcontroller (ATmega328P) has an absolute maximum of
40 mA per pin; the design target is 20 mA or less.

### 2. Recommended operating conditions

The range in which the manufacturer guarantees the part works as specified: supply voltage, temperature, input
levels. Design within these.

### 3. Electrical characteristics

What the part actually does, with **min**, **typ** (typical) and **max** columns:

```text
Electrical/Optical Characteristics (Ta = 25 °C)
  Parameter          Symbol  Min   Typ   Max   Unit   Test condition
  Forward voltage    VF      1.8   2.0   2.4   V      IF = 10 mA
  Luminous intensity IV      80    150   —     mcd    IF = 10 mA
  Dominant wavelength λd     565   570   575   nm     IF = 10 mA
```

- **Typ** is what most parts do. It's not guaranteed; use it for estimates.
- **Min/max** are guaranteed. Use them for worst-case design: this LED's forward voltage could be anywhere from 1.8 V
  to 2.4 V at 10 mA.
- **Test conditions** matter: the 2.0 V is at 10 mA and 25 °C; at 20 mA it's higher.

Read the units column carefully, and watch for footnotes. The habit of checking a datasheet before using a part
separates people who build things that work from people who keep buying replacement parts.

## Key takeaways

- Prefixes step by factors of 1000: p, n, µ, m, (none), k, M, G. Case matters (`m` ≠ `M`). `u` means µ.
- To a smaller unit, the number gets bigger. When unsure, convert to base units in scientific notation, calculate,
  then convert back.
- Useful shortcuts: V / mA = kΩ, V / kΩ = mA, mA × kΩ = V.
- Resistor colour code: digits, then multiplier, then tolerance. SMD and capacitor codes are "digits then zeros";
  capacitor codes are in picofarads (`104` = 100 nF). `4k7` means 4.7 kΩ.
- Resistors come in E-series values matched to their tolerance. Round current-limiting resistors up.
- Datasheets: never exceed absolute maximum ratings; design within recommended conditions; use min/max (not typ) for
  worst cases.

## Exercises

1. **Conversions.** Convert: (a) 0.045 A to mA, (b) 2 200 Ω to kΩ, (c) 470 nF to µF, (d) 0.33 MΩ to kΩ,
   (e) 250 µA to mA, (f) 1.5 kW to W.

<details>
<summary>Solution</summary>

(a) 45 mA. (b) 2.2 kΩ. (c) 0.47 µF. (d) 330 kΩ. (e) 0.25 mA. (f) 1 500 W.

</details>

2. **Prefix arithmetic.** Without converting to base units, use the shortcuts to find: (a) the current through
   4.7 kΩ with 3.3 V across it, (b) the resistance that passes 2 mA at 12 V, (c) the voltage across 680 Ω (0.68 kΩ)
   carrying 15 mA, (d) the power in a component with 5 V across it and 40 mA through it.

<details>
<summary>Solution</summary>

```text
(a) 3.3 V / 4.7 kΩ ≈ 0.70 mA
(b) 12 V / 2 mA    = 6 kΩ
(c) 15 mA × 0.68 kΩ = 10.2 V
(d) 5 V × 40 mA    = 200 mW
```

</details>

3. **Colour codes.** Decode: (a) brown, black, brown, gold; (b) red, violet, orange, gold; (c) green, blue, black,
   black, brown. Then give the bands for a 4-band 470 Ω ±5 % resistor and a 4-band 2.2 MΩ ±5 % resistor.

<details>
<summary>Solution</summary>

(a) 1, 0, × 10 → 100 Ω ±5 %. (b) 2, 7, × 1 k → 27 kΩ ±5 %. (c) 5, 6, 0, × 1, ±1 % → 560 Ω ±1 %.

470 Ω = 4, 7, × 10 → yellow, violet, brown, gold.
2.2 MΩ = 2, 2, × 100 k → red, red, green, gold.

</details>

4. **Codes on parts.** What are these: an SMD resistor marked `222`, an SMD resistor marked `1002`, a ceramic
   capacitor marked `473`, a ceramic capacitor marked `221J`, a schematic label `6k8`?

<details>
<summary>Solution</summary>

- `222`: 22 × 10² = 2.2 kΩ.
- `1002`: 100 × 10² = 10 kΩ (±1 % part).
- `473`: 47 × 10³ pF = 47 000 pF = 47 nF.
- `221J`: 22 × 10¹ pF = 220 pF, ±5 %.
- `6k8`: 6.8 kΩ.

</details>

5. **Worst case.** An LED is driven from 5 V through a 220 Ω ±5 % resistor. The datasheet gives the LED's forward
   voltage as min 1.8 V, typ 2.0 V, max 2.4 V. What's the highest current the LED could see? Is it within a 20 mA
   limit?

<details>
<summary>Solution</summary>

The highest current comes from the lowest resistance and the lowest forward voltage (most voltage left for the
resistor):

```text
R_min = 220 × 0.95 = 209 Ω
I_max = (5 − 1.8) / 209 = 3.2 / 209 ≈ 15.3 mA
```

Within 20 mA. (If the supply could be 5.25 V, the worst case would be `3.45 / 209 ≈ 16.5 mA`, still fine.) The typical
case is `(5 − 2.0) / 220 ≈ 13.6 mA`.

</details>

6. **Spot the error.** A beginner calculates an LED resistor as `(5 − 2) / 20 = 0.15 Ω` and buys a 0.15 Ω resistor.
   What went wrong, what's the right value, and what would have happened with their resistor?

<details>
<summary>Solution</summary>

They used 20 (mA) as if it were amps. In base units the current is 0.020 A: `3 V / 0.020 A = 150 Ω`. With 0.15 Ω the
resistor would barely limit anything; the LED would draw a very large current and burn out (and the pin or supply
could be damaged). The units shortcut would also have caught it: V / mA = kΩ, so `3 / 20 = 0.15 kΩ = 150 Ω`.

</details>

---
← Previous: [Power and energy](05-power-and-energy.md) | Next: [Circuits, schematics and breadboards](07-circuits-schematics-and-breadboards.md) →
