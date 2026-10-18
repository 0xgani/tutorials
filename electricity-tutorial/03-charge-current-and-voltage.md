# Chapter 3 — Charge, current and voltage

## What you'll learn

- The ampere: what "one amp" physically is, and the range of currents you'll meet
- The volt: voltage as energy per unit of charge
- Potential and potential difference, and why voltage is always *between two points*
- What "ground" means, and why everything in a circuit is measured against it
- The phrases "voltage across" and "current through", and why mixing them up causes mistakes
- DC and AC in one paragraph each
- Typical values, from a microcontroller in sleep to a lightning strike

## Current: the flow of charge

**Current** is the rate at which charge passes a point. Its unit is the **ampere** (symbol **A**, usually called an
"amp"):

```text
1 ampere = 1 coulomb per second

I = Q / t

I   current, amperes (A)
Q   charge that passed, coulombs (C)
t   time, seconds (s)
```

The letter `I` for current comes from the French *intensité de courant*. Get used to it; `C` is already taken by the
coulomb and by capacitance.

One amp means about 6.24 × 10¹⁸ electrons pass every second. It sounds enormous, but it is an ordinary current: a
phone charging at 1 A, a car headlight at about 5 A.

**Worked example.** An LED draws 20 mA (0.020 A) for one hour. How much charge passed through it?

```text
Q = I × t = 0.020 A × 3600 s = 72 C
```

That is the basis of battery ratings (chapter 12): "72 coulombs" is the same thing as "20 milliamp-hours", because
`0.020 A × 1 h = 20 mAh`.

### Currents you will meet

| Situation | Typical current |
|---|---|
| Microcontroller in deep sleep | 0.1–10 µA (microamps, millionths of an amp) |
| Input pin being read | under 1 µA |
| Indicator LED | 2–20 mA (milliamps, thousandths of an amp) |
| Arduino Uno board, running, no loads | about 30–50 mA |
| Maximum from one Arduino Uno R3 output pin | 20 mA recommended, 40 mA absolute maximum |
| Small hobby servo moving | 100–500 mA, more when stalled |
| Small 12 V water pump | 100–500 mA |
| USB 2.0 port | 500 mA; USB-C up to 3 A at 5 V |
| Phone fast-charging | 2–5 A |
| Kettle (230 V) | about 10 A |
| Car starter motor | 100–300 A |
| Lightning stroke | about 30 000 A for a few microseconds |

Note the very wide range. A board in sleep and a car starter differ by a factor of a billion. That is why unit
prefixes (micro, milli, kilo; chapter 6) matter so much.

### Current is measured *through* something

Current is a flow, so it is measured by making it pass **through** the meter. To measure the current in a wire you
must break the circuit and put the meter in the gap, so that all the current flows through it. Chapter 14 covers
this, and why getting it wrong blows the meter's fuse.

In a single loop with no branches, the current is **the same at every point** (chapter 1's conservation of charge).
It doesn't matter whether you measure it before the LED or after it: the LED doesn't "use up" current. What it uses
up is energy, which brings us to voltage.

## Voltage: energy per charge

When charge moves through a battery, the battery gives it energy. When that charge then moves through a lamp, it
gives the energy up as light and heat. **Voltage** measures how much energy each coulomb gains or loses between two
points:

```text
1 volt = 1 joule per coulomb

V = E / Q

V   voltage, volts (V)
E   energy, joules (J)
Q   charge, coulombs (C)
```

A **9 V battery** gives every coulomb that passes through it 9 joules of energy. That energy is then handed over to
whatever the charge passes through on its way back to the battery. A **1.5 V AA cell** gives each coulomb only
1.5 joules.

The volt is named after Alessandro Volta, who built the first battery in 1800. The symbol `V` is used for both the
quantity (voltage) and the unit (volt), so `V = 5 V` reads "the voltage is five volts". Some books use `U` or `E`
for the quantity to avoid that; you'll see `U` in European textbooks.

### Other names for voltage

You'll meet all of these; they mean the same thing or nearly so:

| Term | Usual meaning |
|---|---|
| Voltage | The everyday word |
| Potential difference (p.d.) | The precise physics word; the voltage between two points |
| Electromotive force (EMF) | The voltage a source (battery, generator) produces, before any internal losses (chapter 12) |
| Voltage drop | The voltage lost across a component that energy is delivered to, such as a resistor or a long wire |
| Supply voltage, rail | The voltage a power supply provides, e.g. "the 5 V rail" |
| `VCC`, `VDD`, `V+` | Labels for the positive supply in schematics |
| `GND`, `VSS`, `0V`, `V−` | Labels for the ground or negative supply |

## Potential and potential difference

Back to the hill picture from chapter 2. Every point in a circuit has an **electric potential**, like a height. A
positive charge at a high point has more energy available than one at a low point, the way a ball on a hill does.
Voltage is the **difference in height** between two points.

```text
  potential
     ▲
  9 V│  ●  battery +  ───────┐
     │                       │  resistor: charges "fall" 9 V,
     │                       │  giving up 9 J per coulomb as heat
     │                       ▼
  0 V│  ●  battery −  ◄──────┘
     └──────────────────────────────►
          path around the circuit
```

The battery lifts every coulomb up 9 V. The rest of the circuit lets it fall back down by exactly 9 V. Around any
complete loop, the rises equal the drops: you end up at the height you started. This is **Kirchhoff's voltage law**,
which chapter 9 uses to solve circuits.

The key consequence:

> **Voltage is always measured between two points.** "The voltage at this wire is 5 V" only makes sense if everyone
> agrees what the other point is.

A bird on a high-voltage power line is fine because both its feet are at the same potential: the voltage *across* the
bird is nearly zero. It would only be in trouble if it touched the line and something at a different potential (the
pole, another line) at the same time.

## Ground: the agreed reference point

Since voltage is between two points, circuits pick one point as the reference and call it **ground** (`GND`), with a
potential of 0 V by definition. Every other voltage is then quoted relative to ground: "pin 13 is at 5 V" means "pin
13 is 5 V above ground".

```text
       +9 V ─────┬────────┐
                 │        │
               [R1]       │
                 │        │
       +4.5 V ───┤  ← "this point is at 4.5 V" means 4.5 V above GND
                 │        │
               [R2]     battery
                 │        │
        0 V  ────┴────────┘  GND (the reference)
```

In battery-powered electronics, ground is simply the battery's negative terminal and everything connected to it. It
has nothing to do with the earth, despite the name. (In mains wiring, "earth" or "protective ground" *is* a real
connection to the earth, for safety; chapter 13.)

Two rules you will apply constantly once you start Arduino:

1. **All parts that exchange signals must share a common ground.** If an Arduino sends a 5 V signal to a motor
   driver powered from a separate battery, the two grounds must be connected. Otherwise "5 V" on the Arduino's side
   is measured from a different reference than the driver's, and the signal is meaningless. This is one of the most
   common beginner bugs (chapter 20).
2. **Voltages can be negative.** A point 2 V *below* ground is at −2 V. Most Arduino circuits only use positive
   voltages, and putting a negative voltage on a microcontroller pin damages it.

## "Across" and "through"

Precise language prevents mistakes:

- **Voltage is across** something: between its two ends. "The voltage across the resistor is 3 V." You measure it by
  touching the meter's probes to both ends, without breaking anything.
- **Current is through** something. "The current through the resistor is 10 mA." You measure it by breaking the
  circuit and inserting the meter.

```text
   measuring voltage ACROSS R          measuring current THROUGH R

        ──┬──[ R ]──┬──                    ──[ R ]──(A)──
          │         │                         meter inserted in
          └──(V)────┘                         the path: all the
      meter in parallel, touching            current goes through it
      both ends
```

Phrases like "the current across the LED" or "the voltage through the wire" are red flags in a forum post: they
usually mean the writer has the wrong picture.

## DC and AC at a glance

- **Direct current (DC)** flows in one direction, from a source whose voltage has a fixed polarity: batteries, USB,
  phone chargers, solar panels, and the output of the power adapter for an Arduino. Almost everything in this tutorial
  and in Arduino work is DC.
- **Alternating current (AC)** reverses direction periodically, from a source whose voltage swings between positive
  and negative, typically as a sine wave: wall sockets (230 V at 50 Hz in most of the world, 120 V at 60 Hz in North
  America). Chapter 13 explains AC and why the grid uses it.

The chargers and adapters you plug into the wall convert mains AC into low-voltage DC. Your Arduino never sees AC.

## Typical voltages

| Source | Voltage |
|---|---|
| Nerve signal | about 0.07 V (70 mV) |
| A single LED's forward voltage | 1.8–3.3 V (chapter 17) |
| AA / AAA alkaline cell | 1.5 V new, about 1.0 V when flat |
| Lithium-ion / LiPo cell | 3.7 V nominal, 4.2 V full, about 3.0 V empty |
| Logic level of an ESP32, Raspberry Pi Pico, Arduino Nano 33 | 3.3 V |
| USB, logic of an Arduino Uno | 5 V |
| "9 V" battery | 9 V |
| Car battery | 12.6 V (about 14.4 V while the engine charges it) |
| LED strips, small pumps, PC fans | 5, 12 or 24 V |
| Laptop charger (USB-C PD) | 5, 9, 15, 20 V (and up to 48 V with EPR) |
| Mains | 230 V (Europe, most of the world), 120 V (North America) |
| Static spark from your finger | 3 000–25 000 V |
| Long-distance power lines | 110 000–765 000 V |
| Lightning | hundreds of millions of volts |

The working rule of thumb for safety: below about 50 V DC in dry conditions, a voltage won't drive a dangerous
current through your body. Everything you build with Arduino stays far below that. Chapter 13 explains why, and the
exceptions.

## Voltage and current are different things

A static spark is 10 000 V but harmless; a car battery is 12 V but can weld a spanner. A 9 V battery can drive a
current of a few amps through a short circuit; a 5 V USB port is limited to whatever the port allows. The point is:

- **Voltage is the push.** It is a property of the source (and of each point in the circuit).
- **Current is the flow that results.** It depends on the push *and* on what is connected: how much the circuit
  resists the flow.

The relationship between the two is resistance, and it is the subject of chapter 4.

One important consequence, which confuses many beginners: **a power supply rated "5 V, 3 A" does not push 3 A into
your circuit.** It holds its output at 5 V and *can* deliver up to 3 A if the circuit draws that much. An Arduino on
that supply draws its 50 mA and no more. The current rating is a maximum, not a dose.

## Key takeaways

- Current is the rate of flow of charge, `I = Q / t`, measured in amperes (1 A = 1 C/s). Current is measured
  *through* a component, and is the same everywhere in a single loop.
- Voltage is energy per unit charge, `V = E / Q`, measured in volts (1 V = 1 J/C). A 9 V battery gives each coulomb
  9 J.
- Voltage is a potential difference, always between two points. Voltage is *across* a component.
- Ground (GND) is the circuit's agreed 0 V reference. All parts that exchange signals must share a common ground.
- DC flows one way (batteries, USB); AC alternates (wall sockets). Arduino work is DC.
- Voltage is the push, current is the flow that results. A supply's current rating is what it *can* deliver, not
  what it forces into the circuit.

## Exercises

1. **Charge and time.** A sensor draws 5 mA continuously for 10 minutes. How many coulombs pass through it? How many
   milliamp-hours is that?

<details>
<summary>Solution</summary>

```text
t = 10 min = 600 s
Q = I × t = 0.005 A × 600 s = 3 C

In milliamp-hours: 5 mA × (10 / 60) h ≈ 0.83 mAh
```

</details>

2. **Energy from voltage.** A 9 V battery pushes 2 coulombs of charge round a circuit. How much energy did the
   battery deliver? If the same charge went round a circuit powered by a 1.5 V cell, how much energy would it carry?

<details>
<summary>Solution</summary>

```text
E = V × Q
9 V:   E = 9 J/C × 2 C   = 18 J
1.5 V: E = 1.5 J/C × 2 C = 3 J
```

The same charge carries six times more energy when pushed by six times the voltage.

</details>

3. **Relative voltages.** In a circuit, point A is at 5 V and point B is at 3.3 V, both measured from ground. What
   is the voltage between A and B? What would a meter read with its red probe on B and black probe on A?

<details>
<summary>Solution</summary>

`V_AB = V_A − V_B = 5 − 3.3 = 1.7 V`. With the red probe on B and the black on A, the meter shows the potential of B
relative to A: `3.3 − 5 = −1.7 V`. A digital meter displays the minus sign; it's not an error, it tells you that the
red probe is at the lower potential.

</details>

4. **Supply rating.** You have a "12 V, 5 A" power adapter and a 12 V pump that draws 0.3 A. A friend warns you that
   5 A will burn out the pump. Are they right? What *would* damage the pump?

<details>
<summary>Solution</summary>

No. The adapter holds its output at 12 V; the pump draws the current its own resistance and mechanics require,
about 0.3 A. The 5 A rating only says the adapter can supply up to 5 A without overloading. What would damage the
pump is the wrong *voltage*: a 24 V supply would push roughly twice the current through it and overheat it. (A supply
with too *low* a current rating is also a problem, for the supply: it would overload, sag or shut down.)

</details>

5. **Common ground.** An Arduino powered from USB sends a "HIGH" (5 V) signal on one wire to a motor-driver board
   powered from a separate 12 V battery. No other wire connects the two. The driver ignores the signal. Explain why,
   in terms of potential, and say what single wire fixes it.

<details>
<summary>Solution</summary>

The signal is 5 V *relative to the Arduino's ground*. The driver judges its input relative to *its own* ground, the
battery's negative terminal. With no connection between the two grounds, the potential difference between the signal
wire and the driver's ground is undefined (it floats, and no current can flow to complete a loop), so the driver can't
see a meaningful voltage. Connect the Arduino's GND to the driver's GND. Then both sides share one reference and the
signal really is 5 V above the driver's ground.

</details>

---
← Previous: [How electrons move](02-how-electrons-move.md) | Next: [Resistance and Ohm's law](04-resistance-and-ohms-law.md) →
