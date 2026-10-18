# Chapter 4 — Resistance and Ohm's law

## What you'll learn

- What resistance is, physically, and its unit, the ohm (Ω)
- How material, length, thickness and temperature set a conductor's resistance (`R = ρ × L / A`)
- Ohm's law, `V = I × R`, in all three forms, and how to use it without memorising a triangle
- What resistors are, why circuits are full of them, and the main types
- Which components *don't* obey Ohm's law, and why that matters
- Conductance and the siemens
- How to pick the resistor for the Plant Station's status LED

## What resistance is

Chapter 2 showed that electrons drifting through a metal keep colliding with the vibrating lattice, so a steady push
is needed to keep them moving. **Resistance** measures how much push (voltage) a component needs for each unit of
flow (current) through it:

```text
R = V / I

R   resistance, ohms (Ω)
V   voltage across the component, volts (V)
I   current through it, amperes (A)
```

**One ohm** is the resistance that lets one ampere flow when one volt is across it: `1 Ω = 1 V / 1 A`. The unit is
named after Georg Ohm, who published the relationship in 1827. Its symbol is the Greek capital letter omega, **Ω**;
where you can't type it, people write "ohm", "R" (as in "220R") or sometimes just nothing ("a 10k resistor").

In the water analogy, resistance is how narrow the pipe is: a narrow, long, rough pipe needs much more pressure to
push the same flow.

### Typical resistances

| Thing | Resistance |
|---|---|
| 1 m of 22 AWG jumper wire | about 0.05 Ω |
| Short across a battery with a screwdriver | well under 0.1 Ω |
| A 60 W incandescent bulb, glowing, on 230 V | about 880 Ω |
| Typical resistor for an LED | 220–1 000 Ω |
| Pull-up resistor (chapter 19) | 4.7–47 kΩ (thousands of ohms) |
| Human body, hand to hand, dry skin | tens of kΩ to over 100 kΩ |
| Human body, wet skin | about 1 kΩ |
| Multimeter input in voltage mode | 10 MΩ (millions of ohms) |
| Plastic wire insulation | many GΩ (billions of ohms) |

## What sets a conductor's resistance

Picture a wire as a bundle of parallel paths for electrons. Four things decide its resistance:

```text
R = ρ × L / A

ρ   resistivity of the material, ohm-metres (Ω·m)   "rho"
L   length, metres
A   cross-sectional area, square metres
```

| Factor | Effect | Why |
|---|---|---|
| **Material** (ρ) | Silver and copper low; nichrome high; plastic astronomically high | How many free electrons and how often they collide |
| **Length** (L) | Twice as long → twice the resistance | Electrons must push through twice as much material, like two resistors in a row |
| **Cross-section** (A) | Twice the area → half the resistance | Twice as many parallel paths for the electrons |
| **Temperature** | Hotter metal → higher resistance | More lattice vibration, more collisions (chapter 2) |

Doubling a wire's *diameter* quadruples its area (area grows with the square of the diameter: `A = π × d² / 4`),
which cuts its resistance to a quarter. That square law is the heart of chapter 11 on wire thickness.

### Resistivity of common materials (at 20 °C)

| Material | Resistivity ρ (Ω·m) | Use |
|---|---|---|
| Silver | 1.59 × 10⁻⁸ | Best conductor; contacts, some RF parts |
| Copper (annealed, the standard for wire) | 1.72 × 10⁻⁸ | Almost all wiring and PCB tracks |
| Gold | 2.44 × 10⁻⁸ | Corrosion-free contact plating |
| Aluminium | 2.65 × 10⁻⁸ | Overhead power lines, some building wiring |
| Tungsten | 5.6 × 10⁻⁸ | Lamp filaments (melts at 3 422 °C) |
| Iron / steel | about 1 × 10⁻⁷ | Screws, cases; poor wire |
| Nichrome (nickel–chromium alloy) | about 1.1 × 10⁻⁶ | Heating elements, foam cutters |
| Carbon (graphite) | about 10⁻⁵ | Pencil leads, carbon resistors |
| Pure silicon | about 2 × 10³ | Raw material for chips; doping lowers it enormously |
| Glass, PTFE, rubber | 10¹⁰ to over 10²² | Insulators |

The spread from copper to PTFE is about 10³⁰. Few physical properties vary over a range that wide, which is why
electrical insulation works so well.

**Worked example.** What is the resistance of 2 m of copper wire with a cross-section of 0.5 mm²?

```text
A = 0.5 mm² = 0.5 × 10⁻⁶ m²
R = ρ × L / A = 1.72 × 10⁻⁸ Ω·m × 2 m / (0.5 × 10⁻⁶ m²)
  = 3.44 × 10⁻⁸ / 0.5 × 10⁻⁶
  = 0.069 Ω
```

Under a tenth of an ohm. For most short wires on a desk, you can treat wire resistance as zero. Chapter 11 shows when
you can't: long runs, thin wires and large currents.

## Ohm's law

For many materials (metals at constant temperature, and resistors), the current through them is **proportional** to
the voltage across them: double the voltage, double the current. The ratio between them, the resistance, stays
constant. That is **Ohm's law**:

```text
V = I × R        voltage = current × resistance
I = V / R        current = voltage / resistance
R = V / I        resistance = voltage / current
```

These are the same equation, rearranged. You don't need to memorise three versions, or the "VIR triangle" you'll see
online. Remember `V = I × R` and rearrange it like any equation: to get `I`, divide both sides by `R`.

What each form answers:

- **`I = V / R`**: "If I put this voltage across this resistance, how much current flows?" More voltage, more
  current; more resistance, less current.
- **`V = I × R`**: "If this much current flows through this resistance, how much voltage does it take (or drop)?"
  This is how you calculate the voltage lost in a wire.
- **`R = V / I`**: "What resistance do I need so that this voltage drives exactly this current?" This is how you pick
  resistors.

### Worked examples

**1. Current.** A 220 Ω resistor is connected directly across a 5 V supply. What current flows?

```text
I = V / R = 5 V / 220 Ω = 0.0227 A = 22.7 mA
```

**2. Voltage.** 15 mA flows through a 1 kΩ resistor. What is the voltage across it?

```text
V = I × R = 0.015 A × 1000 Ω = 15 V
```

Convert milliamps to amps and kilohms to ohms before multiplying, or use the shortcut that **milliamps × kilohms =
volts** (because `10⁻³ × 10³ = 1`). That shortcut covers most of everyday electronics: `15 mA × 1 kΩ = 15 V`.

**3. Resistance.** A 12 V pump draws 0.4 A when running. What is its effective resistance at that moment?

```text
R = V / I = 12 V / 0.4 A = 30 Ω
```

(A motor is not really a resistor; its current changes with load and is much larger when it starts. Chapter 16
explains. The number is still a useful snapshot.)

**4. A short circuit.** You accidentally connect a 9 V battery's terminals together with a piece of wire of about
0.05 Ω. What current does Ohm's law predict?

```text
I = V / R = 9 V / 0.05 Ω = 180 A
```

In reality you won't get 180 A, because the battery itself has internal resistance (around 1–2 Ω for a 9 V
battery, chapter 12), which limits the current to a few amps. A few amps is still enough to heat the wire, drain the
battery and possibly make it leak. With a lithium battery or a car battery, whose internal resistance is tiny, the
current really can be hundreds of amps — enough to melt the wire and cause a fire. That is why "never short a
battery" is a rule, and why fuses exist (chapter 20).

The calculator script does this for any two values:

```bash
node examples/ohm.mjs V=5 R=220
```

```text
V = 5 V
I = 22.7 mA
R = 220 Ω
P = 114 mW
```

(`P` is power, the subject of chapter 5.)

## Resistors

A **resistor** is a component made to have a specific, stable resistance. They are the most common part in
electronics. You'll use them to:

- **Limit current**, for example through an LED (later in this chapter).
- **Divide voltage**, for example to turn a sensor's resistance into a voltage a board can read (chapter 10).
- **Pull a signal** to a known level when nothing else drives it (pull-up and pull-down resistors, chapter 19).
- **Set timing** together with capacitors (chapter 15).
- **Measure current**, by measuring the small voltage across a low-value "shunt" resistor.

| Type | Typical use | Notes |
|---|---|---|
| Carbon film, through-hole | Hobby and kit parts | Cheap, ±5 % tolerance, beige body |
| Metal film, through-hole | Most kits today | ±1 % tolerance, blue body, lower noise |
| Surface-mount (SMD) chip | Every modern board, including Arduinos | Tiny rectangles with a printed code (chapter 6) |
| Wirewound / power | High power, current sensing | Can dissipate several watts |
| Potentiometer | Adjustable divider (chapter 10) | Three terminals; a knob or slider |
| Thermistor, LDR | Sensors (chapter 10) | Resistance changes with temperature or light |

Resistors have no polarity: they work the same either way round. They do have a **resistance value**, a
**tolerance** (how far from the value they may be) and a **power rating** (how much heat they can safely shed,
chapter 5). Chapter 6 shows how to read the value from the colour bands.

### Resistance doesn't care about direction, but it does care about heat

A resistor's value drifts a little with temperature. For a metal-film resistor the change is tiny (around 50 parts
per million per °C), so you can ignore it. For copper wire, it's about 0.39 % per °C, which matters when wires run
hot. For a lamp filament it's dramatic, which brings us to the next point.

## When Ohm's law doesn't apply

Ohm's law describes **ohmic** components, whose resistance stays constant as the voltage changes. Many important
components are **non-ohmic**:

```text
   current                               current
      ▲          ohmic (resistor):          ▲         non-ohmic (LED / diode):
      │         a straight line             │         almost nothing, then a lot
      │           ╱                         │                      │
      │         ╱                           │                      │
      │       ╱   slope = 1 / R             │                     ╱
      │     ╱                               │                   ╱
      │   ╱                                 │               _.─'
      │ ╱                                   │ ____________─'
      └──────────────────► voltage          └───────────────┬────► voltage
                                                           ~2 V
```

- **A filament lamp** gets much hotter at higher voltage, so its resistance rises: the current increases less than
  proportionally. Cold, a filament has about a tenth of its hot resistance, which is why bulbs usually blow when
  switched on.
- **A diode or LED** passes almost no current below a threshold (its *forward voltage*, about 2 V for a red or green
  LED), then the current shoots up steeply for a small increase in voltage (chapter 17). There is no single
  "resistance of an LED".
- **A motor** draws a large current when stalled or starting and much less when spinning freely (chapter 16).
- **A transistor**'s current is controlled by a third terminal (chapter 18).

You can still compute `V / I` at a particular operating point, and that is sometimes useful, but you can't use it to
predict what happens at another voltage. The safe way to handle non-ohmic parts is to know their characteristic
(from the datasheet) and let an ohmic part, usually a resistor, set the current.

## Conductance

Occasionally it's easier to think about how well something conducts rather than how much it resists. **Conductance**
is the reciprocal of resistance:

```text
G = 1 / R      in siemens (S); 1 S = 1 / Ω
```

A 100 Ω resistor has a conductance of 0.01 S (10 mS). You'll meet it in one place in this tutorial: it makes the
formula for resistors in parallel obvious (chapter 8). Old texts call the unit the "mho" (ohm backwards).

## The Plant Station's status LED

Time for the first real design decision. The Plant Station's status LED will be driven from a 5 V source (later, an
Arduino pin at 5 V). Why can't you connect the LED straight to 5 V?

Look at the LED's characteristic above. A green LED starts conducting around 2 V. At 5 V, the curve is far up the
steep part: the current would be limited only by the tiny resistance of the LED and the wires, and the LED would
burn out in a moment (or the pin driving it would be damaged first). The LED needs a resistor in series to **limit
the current**.

```text
   5 V ──[ R ]──►|── GND
                LED
```

Here's how to size it:

1. **Choose the current.** Indicator LEDs are bright at 5–10 mA; their datasheet maximum is often 20 mA continuous.
   An Arduino Uno R3 pin is happy at 10 mA. Choose **10 mA** for now. (The newer Uno R4's pins are limited to 8 mA,
   so chapter 17 lowers this current; modern LEDs are clearly visible at 2–5 mA anyway.)
2. **Find the LED's forward voltage** from its datasheet: about **2.0 V** for this green LED at 10 mA.
3. **Find the voltage left for the resistor.** In a series loop the voltages add up to the supply (chapter 9), so the
   resistor gets what the LED doesn't take: `5 V − 2.0 V = 3.0 V`.
4. **Apply Ohm's law to the resistor**, which *is* ohmic:

```text
R = V / I = 3.0 V / 0.010 A = 300 Ω
```

5. **Pick a real value.** Resistors come in standard values (chapter 6): 270 Ω and 330 Ω are near 300 Ω. Round
   **up**, so the current is a bit lower rather than higher: **330 Ω**, giving `3.0 V / 330 Ω ≈ 9.1 mA`.

```bash
node examples/led-resistor.mjs 5 2.0 10mA
```

```text
Voltage across the resistor: 3 V
Exact resistor:              300 Ω
Nearest E12 value above it:  330 Ω
LED current with it:         9.09 mA
Resistor power:              27.3 mW (use a resistor rated at least 54.5 mW)
```

Notice the trick in step 4: you can't apply Ohm's law to the LED, but you *can* apply it to the resistor, because you
know the voltage across it. Separating a circuit into parts you can calculate is the core skill of circuit analysis.

The last line is about power. Every resistor turns some energy into heat, and you need to check it can cope. That's
chapter 5.

## Key takeaways

- Resistance, in ohms (Ω), is the voltage needed per ampere of current: `R = V / I`. 1 Ω lets 1 A flow per volt.
- A conductor's resistance is `R = ρ × L / A`: proportional to length, inversely proportional to cross-sectional area,
  set by the material's resistivity, and rising with temperature in metals.
- Doubling a wire's diameter quarters its resistance, because area goes with the square of the diameter.
- Ohm's law, `V = I × R`, holds for resistors and metals; rearrange it as needed. Milliamps × kilohms = volts.
- Short circuits are limited only by tiny resistances, including the battery's own; the result can be very large
  currents.
- LEDs, diodes, lamps, motors and transistors are non-ohmic. Let a resistor set their current.
- An LED's series resistor is `(supply voltage − LED forward voltage) / desired current`, rounded up to a standard
  value. For the Plant Station: 330 Ω at 5 V.

## Exercises

1. **Three forms.** (a) What current flows through a 470 Ω resistor with 9 V across it? (b) What voltage is across a
   2.2 kΩ resistor carrying 3 mA? (c) What resistance draws 50 mA from 12 V?

<details>
<summary>Solution</summary>

```text
(a) I = V / R = 9 / 470 = 0.0191 A = 19.1 mA
(b) V = I × R = 3 mA × 2.2 kΩ = 6.6 V        (mA × kΩ = V)
(c) R = V / I = 12 / 0.05 = 240 Ω
```

</details>

2. **Thicker and longer.** A 1 m length of wire has a resistance of 0.2 Ω. What is the resistance of (a) 5 m of the
   same wire, (b) 1 m of wire of the same material with twice the diameter, (c) 3 m of wire with half the diameter?

<details>
<summary>Solution</summary>

(a) Resistance is proportional to length: `5 × 0.2 = 1.0 Ω`.

(b) Twice the diameter is four times the area, so a quarter of the resistance: `0.2 / 4 = 0.05 Ω`.

(c) Half the diameter is a quarter of the area, so four times the resistance per metre (0.8 Ω/m), times 3 m:
`2.4 Ω`.

</details>

3. **Nichrome heater.** A foam cutter uses 30 cm of nichrome wire with a cross-section of 0.1 mm². What is its
   resistance? What current flows if you put 12 V across it?

<details>
<summary>Solution</summary>

```text
R = ρ × L / A = 1.1 × 10⁻⁶ Ω·m × 0.3 m / (0.1 × 10⁻⁶ m²)
  = 3.3 × 10⁻⁷ / 1 × 10⁻⁷
  = 3.3 Ω

I = V / R = 12 / 3.3 ≈ 3.6 A
```

A lot of current, which is the point: it heats the wire enough to melt foam (chapter 5 computes the power). The same
length of copper would be about 0.05 Ω and draw a dangerous short-circuit current.

</details>

4. **LED resistor.** You want a red LED (forward voltage 1.8 V) to run at 5 mA from a 3.3 V board. Calculate the
   resistor and pick a standard value from this list: 100, 150, 220, 330, 470, 680, 1000 Ω. What current do you get?

<details>
<summary>Solution</summary>

```text
V across resistor = 3.3 − 1.8 = 1.5 V
R = 1.5 V / 0.005 A = 300 Ω   → round up to 330 Ω
I = 1.5 V / 330 Ω ≈ 4.5 mA
```

</details>

5. **Not a resistor.** Someone measures an LED with a multimeter: 2.0 V across it and 10 mA through it, and concludes
   "the LED is a 200 Ω resistor, so at 5 V it will draw 25 mA". What is wrong with that reasoning? Roughly what
   would actually happen if you put 5 V directly across the LED?

<details>
<summary>Solution</summary>

`V / I = 200 Ω` is only true at that operating point. An LED is non-ohmic: above its forward voltage, its current
rises extremely steeply with voltage. At 5 V directly across it, the current would be limited only by its small
internal resistance and the supply, likely hundreds of milliamps or more, and the LED would overheat and fail within
moments (or the supply or the pin driving it would be damaged first). That's why LEDs need a series resistor, which
*is* ohmic, to set the current.

</details>

---
← Previous: [Charge, current and voltage](03-charge-current-and-voltage.md) | Next: [Power and energy](05-power-and-energy.md) →
