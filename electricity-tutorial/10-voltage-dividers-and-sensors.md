# Chapter 10 — Voltage dividers and sensors

## What you'll learn

- The voltage divider formula, and where it comes from
- How to design a divider: picking a ratio, then picking the size of the resistors
- Why a divider can't be used as a power supply (loading)
- Potentiometers, as dividers and as variable resistors
- How resistive sensors (light, temperature, moisture) become voltages a board can read
- How to choose the fixed resistor for the best sensitivity
- A first look at analog-to-digital conversion
- The Plant Station's soil-moisture sensor and battery monitor

## The divider

Two resistors in series across a voltage, with an output taken from the middle:

```text
   Vin ──┐
         │
      [ R1 ]   "top"
         │
         ●──── Vout
         │
      [ R2 ]   "bottom"
         │
   GND ──┘
```

The same current flows through both (series), so the voltage splits in proportion to resistance (chapter 8). Vout is
the voltage across R2:

```text
I    = Vin / (R1 + R2)
Vout = I × R2

Vout = Vin × R2 / (R1 + R2)
```

The output is a **fixed fraction** of the input, set by the ratio `R2 / (R1 + R2)`. Some examples with Vin = 5 V:

| R1 | R2 | Fraction | Vout |
|---|---|---|---|
| 10 kΩ | 10 kΩ | 1/2 | 2.5 V |
| 20 kΩ | 10 kΩ | 1/3 | 1.67 V |
| 10 kΩ | 20 kΩ | 2/3 | 3.33 V |
| 1 kΩ | 2 kΩ | 2/3 | 3.33 V |
| 1 kΩ | 99 kΩ | 99/100 | 4.95 V |

Notice that the 10 k/20 k and 1 k/2 k dividers give the same output: **only the ratio sets Vout**. The absolute size
of the resistors decides two other things, covered next.

The divider is the most common circuit in electronics after "a resistor and an LED". You'll use it to:

- **Scale a voltage down** so a microcontroller can measure it: a 12 V battery's voltage, for example.
- **Turn a resistance into a voltage**: every resistive sensor works this way.
- **Read a knob**: a potentiometer is a divider with a movable middle.
- **Set a reference voltage** for a comparator or a regulator's feedback pin (you'll see dividers on every adjustable
  regulator, chapter 12).

## Designing a divider: ratio, then size

**Step 1: the ratio.** Rearranged for the ratio:

```text
R2 / (R1 + R2) = Vout / Vin
```

**Step 2: the size.** Once the ratio is fixed, you can scale both resistors up or down together. Two forces pull in
opposite directions:

- **Bigger resistors waste less current.** A divider is always conducting from Vin to ground. With 1 kΩ + 2 kΩ
  across 12 V it draws 4 mA all the time; with 100 kΩ + 200 kΩ, only 40 µA. In a battery-powered device that's the
  difference between a drain that matters and one that doesn't.
- **Smaller resistors resist loading better.** Chapter 9 showed that a divider's output behaves like a Thévenin
  source with `R_th = R1 ∥ R2`. Whatever you connect to the output must have a resistance much larger than `R_th`, or
  the output sags.

For feeding a microcontroller's analog input, total resistances of **10 kΩ to 100 kΩ** are the sweet spot. Many
microcontrollers' datasheets recommend that the source driving the analog input has an output resistance of no
more than about 10 kΩ (the ATmega328P on the Uno R3 asks for that), because the converter briefly charges an internal
capacitor at each reading (chapter 15 explains why that needs a low source resistance). With higher values, a
capacitor of about 100 nF from the output to ground provides that charge (chapter 15).

## Why a divider is not a power supply

Beginners often try to "make 5 V from 12 V" with a divider to power something. Chapter 9's loading example shows why
it fails. Take a 12 V divider designed to give 5 V (R1 = 7 kΩ, R2 = 5 kΩ). Its Thévenin equivalent:

```text
V_th = 12 × 5 / 12 = 5 V
R_th = 7 kΩ ∥ 5 kΩ = 35 / 12 kΩ ≈ 2.9 kΩ
```

Connect an Arduino that draws 50 mA (equivalent to about 100 Ω at 5 V). With 2.9 kΩ in series, the output collapses
to well under a volt. Make the resistors 100 times smaller to cope, and the divider burns `12 V × 12 V / 120 Ω = 1.2 W`
all the time, more than the board, and its output still changes every time the board's current changes.

> **Dividers are for signals, not for power.** To power something from a higher voltage, use a regulator
> (chapter 12).

```bash
node examples/divider.mjs 5 10k 10k 10k
```

```text
Output, no load:      2.5 V
Divider current:      250 µA
Output resistance:    5 kΩ (Thévenin)
Output with the load: 1.67 V (33.3 % lower)
```

## Potentiometers

A **potentiometer** ("pot") is a resistor with a third terminal, the **wiper**, that slides along it. The two ends
have the full resistance between them; the wiper splits it into two parts that always add up to the total.

```text
    end 1 ──┐
            │
         [  R  ]◄── wiper (turn the knob → moves along)
            │
    end 3 ──┘

    as a divider:                         as a variable resistor (rheostat):
    Vin ── end 1                          use the wiper and one end only;
    GND ── end 3                          the resistance varies from ~0 to R
    wiper = anywhere from 0 V to Vin
```

- **As a divider** (the usual way with a microcontroller): connect the ends to 5 V and ground, read the wiper. It
  gives 0 V to 5 V as you turn it, and the reading doesn't depend on the exact pot value. A 10 kΩ pot is the standard
  choice.
- **As a variable resistor**: only two terminals used. Useful for adjusting a current, but be careful: turned to one
  end it's nearly 0 Ω, so never use a pot alone as an LED's current limiter. Put a fixed resistor in series to set the
  minimum.

Pots have a **taper**: *linear* (B) for controls and sensors, *logarithmic* (A) for audio volume. For Arduino work, use
linear. **Trimmer** pots are small, screwdriver-adjusted pots used for calibration.

## Resistive sensors

Many sensors are simply resistors whose resistance changes with something physical:

| Sensor | Changes with | Typical range |
|---|---|---|
| LDR (light-dependent resistor, photoresistor) | Light | ~1 MΩ in the dark, ~1–10 kΩ in daylight |
| NTC thermistor | Temperature (falls as it warms) | 10 kΩ at 25 °C is the standard type |
| PTC thermistor | Temperature (rises as it warms) | Used as resettable fuses (chapter 20) |
| Force-sensitive resistor (FSR) | Pressure | >1 MΩ untouched, down to a few hundred Ω pressed |
| Flex sensor | Bending | ~25 kΩ flat, ~100 kΩ bent |
| Resistive soil probe | Water content of soil | ~1 kΩ (soaked) to >1 MΩ (bone dry) |

A microcontroller can't measure resistance directly; it measures **voltage**. So the sensor goes into a divider with
a fixed resistor, and the output voltage tracks the resistance.

```text
   +5V ──[ sensor ]──●── Vout ──► analog input
                     │
                  [ R_fixed ]
                     │
                    GND

   Vout = 5 V × R_fixed / (R_sensor + R_fixed)
```

With the sensor on top, Vout **rises** as the sensor's resistance **falls**. Swap the two and it falls instead. Either
works; pick whichever makes your code read naturally (for example "higher number = brighter").

### Choosing the fixed resistor

If the fixed resistor is far from the sensor's range, the output barely moves: a 100 Ω fixed resistor with an LDR
ranging 10 kΩ–1 MΩ keeps Vout near 0 V throughout. The best general choice puts the divider at its midpoint (half
of Vin) in the middle of the range you care about. For a sensor that varies by a large factor, that's the
**geometric mean** of its extremes:

```text
R_fixed ≈ √(R_min × R_max)
```

For an LDR from 10 kΩ (bright) to 1 MΩ (dark): `√(10 k × 1 M) = 100 kΩ`. For a thermistor used near room
temperature, use a fixed resistor equal to its resistance at the temperature you care most about: 10 kΩ for a
10 kΩ-at-25 °C NTC.

### Sensors are non-linear

An NTC thermistor's resistance follows an exponential curve, not a straight line. For a common 10 kΩ, B = 3950 type:

| Temperature | Resistance |
|---|---|
| 0 °C | about 33.6 kΩ |
| 25 °C | 10.0 kΩ |
| 50 °C | about 3.6 kΩ |
| 100 °C | about 0.7 kΩ |

And the divider adds its own non-linearity (the formula has the resistance in the denominator). So converting a
reading back into a temperature needs a formula from the datasheet (or a lookup table) in your program. The
electrical part, the divider, stays simple.

## Measuring a voltage that's too high

Microcontroller inputs must stay between 0 V and their supply voltage (5 V or 3.3 V, chapter 19). To monitor a 12 V
lead-acid battery that reaches 12.6 V full and up to about 14.4 V while charging, scale it down with a divider so the
highest possible input stays below 5 V:

```text
   battery + ──[ R1 = 20 kΩ ]──●── to analog input
                               │
                          [ R2 = 10 kΩ ]
                               │
   battery − (GND) ────────────┘

   Vout = Vin × 10 / 30 = Vin / 3
   12.6 V → 4.2 V;   14.4 V → 4.8 V   (both under 5 V)
```

```bash
node examples/divider.mjs 12.6 20k 10k
```

```text
Output, no load:      4.2 V
Divider current:      420 µA
Output resistance:    6.67 kΩ (Thévenin)
```

The program then multiplies the reading by 3. The divider's 420 µA drain is small, and `R_th` of 6.7 kΩ is under the
10 kΩ the ADC wants. Use 1 % resistors so the ratio is accurate. The battery's negative terminal must be connected to
the board's GND, or the reading means nothing (chapter 3's common ground).

## Analog-to-digital conversion, briefly

The microcontroller's **ADC** (analog-to-digital converter) turns the voltage on an analog pin into a number. The
classic Arduino Uno's ADC has **10 bits**: it maps 0 V to 5 V onto the numbers 0 to 1023.

```text
reading = Vin / Vref × 1023        (Vref = 5 V on a classic Uno)
Vin     = reading × Vref / 1023

1 step  = 5 V / 1024 ≈ 4.9 mV
```

A reading of 512 means about 2.5 V. Chapter 19 covers the ADC properly, including reference voltages and noise. The
key electrical point for now: the ADC measures *relative to its reference*. If the 5 V supply is really 4.8 V,
every reading shifts. A divider-based sensor powered from the same 5 V cancels that out (the sensor's output scales
with the supply too), which is one more reason the divider is so popular.

## The Plant Station's moisture sensor

The simplest soil-moisture sensor is two metal prongs pushed into the soil. Water with dissolved salts conducts,
so wet soil has a lower resistance between the prongs than dry soil. Assume the probe ranges from about 2 kΩ
(just watered) to about 200 kΩ (dry enough to water).

```text
   +5V ──[ probe ]──●── Vout ──► analog input A0
                    │
               [ R_fixed ]
                    │
                   GND
```

Choose the fixed resistor:

```text
R_fixed ≈ √(2 kΩ × 200 kΩ) = 20 kΩ   → nearest standard value 22 kΩ
```

Then:

| Soil | Probe | Vout = 5 × 22 / (R_probe + 22) | ADC reading |
|---|---|---|---|
| Soaked | 2 kΩ | 4.58 V | about 938 |
| Moist | 20 kΩ | 2.62 V | about 536 |
| Dry | 200 kΩ | 0.50 V | about 101 |

The program waters when the reading drops below a threshold you calibrate by experiment.

Two practical problems, which real designs handle:

1. **Corrosion.** A steady DC current through wet soil causes electrolysis: one prong corrodes away within weeks.
   The fix is to power the probe only while reading it, from a microcontroller output pin instead of the 5 V rail,
   for a few milliseconds every few minutes. The divider then also costs no power the rest of the time. The capstone
   uses this.
2. **The probe's resistance depends on the soil's salt content and temperature**, not just water. Calibrate in your
   actual pot.

> **Just a taste — you don't need to learn or fully understand this now.** Most cheap "capacitive soil moisture
> sensors" avoid corrosion entirely: the prongs are insulated, and a small circuit measures how the soil changes their
> capacitance (chapter 15), outputting a voltage you read the same way.

## Key takeaways

- A divider outputs `Vout = Vin × R2 / (R1 + R2)`. Only the ratio sets the voltage.
- The resistors' size trades wasted current (bigger is better) against loading (smaller is better). For a
  microcontroller's analog input, 10–100 kΩ total is typical.
- A divider's output behaves like a source with `R_th = R1 ∥ R2`. Dividers are for signals, never for powering loads.
- A potentiometer is an adjustable divider. Never use one alone as a current limiter.
- Resistive sensors go in a divider with a fixed resistor. Choose `R_fixed ≈ √(R_min × R_max)` for the most
  sensitivity across a wide range.
- Scale down voltages above the board's supply with a divider, with 1 % resistors and a common ground.
- A 10-bit ADC maps 0–Vref to 0–1023, about 4.9 mV per step at 5 V.

## Exercises

1. **Basic divider.** Find Vout for: (a) 9 V, R1 = 1 kΩ, R2 = 2 kΩ; (b) 3.3 V, R1 = 10 kΩ, R2 = 10 kΩ; (c) 12 V,
   R1 = 47 kΩ, R2 = 10 kΩ.

<details>
<summary>Solution</summary>

```text
(a) 9 × 2 / 3   = 6.0 V
(b) 3.3 × 10 / 20 = 1.65 V
(c) 12 × 10 / 57 ≈ 2.11 V
```

</details>

2. **Design for 3.3 V.** An ESP32 board's analog input must stay below 3.3 V. Design a divider that turns 0–5 V into
   0–3.3 V or less, using standard values and drawing no more than 0.5 mA at 5 V.

<details>
<summary>Solution</summary>

Ratio: `R2 / (R1 + R2) = 3.3 / 5 = 0.66`. That's close to 2/3 (R1 = R, R2 = 2R), which gives 3.33 V — slightly over.
Pick a ratio a little lower for margin: R1 = 10 kΩ, R2 = 18 kΩ gives `5 × 18 / 28 ≈ 3.21 V`. Current:
`5 V / 28 kΩ ≈ 0.18 mA`. Output resistance: `10 ∥ 18 ≈ 6.4 kΩ`, fine for an ADC. (Another common pair: 1 kΩ and
2 kΩ gives 3.33 V and draws 1.7 mA, too much current here and a hair over the limit.)

</details>

3. **Loading.** A divider of 100 kΩ (top) and 100 kΩ (bottom) across 10 V is measured with a cheap analog meter that
   has a 20 kΩ input resistance. What does it read? What would a 10 MΩ digital meter read?

<details>
<summary>Solution</summary>

`V_th = 5 V`, `R_th = 50 kΩ`.

```text
analog meter:  5 × 20 / (50 + 20)       ≈ 1.43 V
digital meter: 5 × 10 000 / (50 + 10 000) ≈ 4.98 V
```

The low-resistance meter loads the divider heavily and reads far too low. That's why digital meters have a 10 MΩ input
(chapter 14), and why you should know your source resistance when a measurement looks wrong.

</details>

4. **LDR night light.** An LDR measures about 5 kΩ in a lit room and 500 kΩ in the dark. Pick a fixed resistor and
   calculate Vout at both extremes with the LDR on top of a 5 V divider. Does Vout go up or down when it gets dark?

<details>
<summary>Solution</summary>

`√(5 k × 500 k) = 50 kΩ` → use 47 kΩ.

```text
lit:  5 × 47 / (5 + 47)   ≈ 4.52 V
dark: 5 × 47 / (500 + 47) ≈ 0.43 V
```

Vout goes **down** when it gets dark (the LDR's resistance rises and takes more of the voltage). Swap them to make it
go up.

</details>

5. **Probe current.** The Plant Station's moisture probe is powered from a pin only during readings: 10 ms every
   5 minutes. With the probe at its lowest resistance (2 kΩ) in series with 22 kΩ, what is the current during a
   reading, and what's the average current over time? Compare it with leaving the probe on the 5 V rail permanently.

<details>
<summary>Solution</summary>

```text
during a reading: 5 V / 24 kΩ ≈ 0.21 mA
duty cycle:       10 ms / 300 s = 1 / 30 000
average:          0.21 mA / 30 000 ≈ 7 nA
```

Left on permanently, it would draw up to 0.21 mA continuously, 30 000 times more. 0.21 mA is small compared with a
running board, but for a board sleeping at microamps (chapter 12) it would dominate, and the constant current would
corrode the probe.

</details>

---
← Previous: [Kirchhoff's laws and circuit analysis](09-kirchhoffs-laws-and-circuit-analysis.md) | Next: [Wires and wire thickness](11-wires-and-wire-thickness.md) →
