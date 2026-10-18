# Chapter 5 — Power and energy

## What you'll learn

- The difference between energy (joules) and power (watts), and why both matter
- The power formula `P = V × I`, and its two other forms `P = I² × R` and `P = V² / R`
- Why every resistance turns power into heat, and how that heat sets component and wire limits
- Resistor power ratings, and how much margin to leave
- Energy units for batteries and bills: watt-hours, kilowatt-hours and milliamp-hours
- Efficiency, and where the "missing" energy goes
- A first power budget for the Plant Station

## Energy and power

**Energy** is the capacity to do work: to lift, heat, light, move. Its SI unit is the **joule** (J). One joule is
small: it's about the energy needed to lift an apple one metre, or the energy a 1 W LED uses in one second.

**Power** is the **rate** at which energy is transferred or converted: how many joules per second. Its unit is the
**watt** (W):

```text
1 watt = 1 joule per second

P = E / t        and so        E = P × t

P   power, watts (W)
E   energy, joules (J)
t   time, seconds (s)
```

A 60 W bulb converts 60 joules of electrical energy into light and heat every second. Run it for an hour (3600 s) and
it has used `60 × 3600 = 216 000 J`.

The distinction matters because people mix them up constantly:

- **Power** is how hard something works *right now*: a 2 kW kettle, a 5 W phone charger, a 0.25 W resistor rating.
  Power decides **how hot** things get and **how big a supply** you need.
- **Energy** is how much work is done *in total*: how long a battery lasts, what your electricity bill says. Energy is
  power multiplied by time.

## The power formula

Chapter 3 defined voltage as energy per coulomb, and current as coulombs per second. Multiply them:

```text
volts × amps = (joules / coulomb) × (coulombs / second) = joules / second = watts

P = V × I
```

That is the most useful formula in this chapter. The power converted in any component equals the voltage across it
times the current through it. It works for every component: resistors, LEDs, motors, entire boards — ohmic or not.

**Worked example.** A USB port supplies 5 V at 0.5 A. How much power can it deliver?

```text
P = V × I = 5 V × 0.5 A = 2.5 W
```

### Two more forms, for resistors

For a resistor (or any ohmic part), you can substitute Ohm's law `V = I × R` into `P = V × I`:

```text
P = V × I = (I × R) × I     →   P = I² × R
P = V × I = V × (V / R)     →   P = V² / R
```

All three give the same answer for a resistor. Use whichever matches the two values you know.

| You know | Use |
|---|---|
| Voltage and current | `P = V × I` |
| Current and resistance | `P = I² × R` |
| Voltage and resistance | `P = V² / R` |

The squared terms have an important consequence: **power in a resistance grows with the square of the current**.
Double the current through a wire and you get four times the heat. Triple it, nine times. This is why overloading a
wire is so much worse than it sounds, and why power lines use very high voltage to keep current low (chapter 13).

## Power becomes heat

When current flows through a resistance, the electrical energy given up by the electrons goes into the lattice as
heat (chapter 2). This is called **Joule heating** or **I²R loss**. Every resistance heats:

- **On purpose**: kettles, toasters, 3D-printer hot ends, the nichrome foam cutter from chapter 4
  (`P = 12² / 3.3 ≈ 44 W`).
- **As waste**: resistors, wires, transistors, voltage regulators, the battery's internal resistance, even the copper
  tracks on a board.

A component can only get rid of heat so fast, through its surface into the air and through its leads into the
board. If it is given heat faster than it can shed it, its temperature keeps rising until something fails: the
plastic insulation melts, a resistor burns, a chip's internal junction overheats, a battery vents. Almost every
"power rating" on a part is really a **heat limit**.

## Resistor power ratings

Every resistor has a maximum power it can dissipate continuously:

| Resistor | Typical rating |
|---|---|
| Small SMD (0402, 0603) | 1/16 W (0.0625 W) to 1/10 W |
| SMD 0805 / 1206 | 1/8 W to 1/4 W |
| Standard through-hole (the ones in kits) | 1/4 W (0.25 W) |
| Larger through-hole | 1/2 W, 1 W, 2 W |
| Wirewound, aluminium-clad | 5 W to 100 W+, often bolted to a heatsink |

A resistor at its full rated power runs **hot**: well over 100 °C on the surface, hot enough to burn a finger and to
discolour the board. Ratings also assume cool surrounding air (usually 70 °C or below) and drop above it. So the
working rule is:

> **Keep a resistor's actual power at no more than half its rating.**

**Worked example: the Plant Station LED resistor.** Chapter 4 chose 330 Ω with 3.0 V across it and about 9.1 mA
through it:

```text
P = V × I = 3.0 V × 0.0091 A = 0.027 W = 27 mW
```

27 mW is about a tenth of a 0.25 W resistor's rating. Fine. Most signal-level resistors are like this, which is why
nobody worries about their power — until they do something like this:

**Worked example: a resistor that burns.** You want to drop 12 V to 5 V for a 200 mA load by putting a resistor in
series. The resistor takes `12 − 5 = 7 V` at 200 mA:

```text
R = 7 V / 0.2 A = 35 Ω
P = V × I = 7 V × 0.2 A = 1.4 W
```

A 0.25 W resistor would char in seconds. You'd need a 3 W resistor, and it would still run hot, wasting more power
than the load uses. (It also wouldn't work well, because the load's current varies and so would the voltage drop.
Chapter 12 explains the proper solution: a voltage regulator.)

## Power in wires

Wires are resistors too, just small ones. A wire's power loss is:

```text
P = I² × R_wire
```

**Worked example.** The Plant Station's 12 V pump draws 0.4 A through 2 m of wire (1 m to the pump and 1 m back)
made of thin 28 AWG ribbon cable, with about 0.21 Ω per metre:

```text
R_wire = 2 m × 0.21 Ω/m = 0.42 Ω
P      = I² × R = 0.4² × 0.42 = 0.16 × 0.42 ≈ 0.067 W
```

About 67 mW spread over 2 m of wire: barely warm. Now imagine a 5 A motor on the same wire:
`5² × 0.42 = 10.5 W` — enough to soften the insulation. Chapter 11 does wire sizing properly.

## Measuring energy in practice: Wh, kWh and mAh

The joule is the SI unit, but it's inconvenient for batteries and bills. Two alternatives are common.

### Watt-hours and kilowatt-hours

A **watt-hour** (Wh) is the energy used by 1 W for one hour:

```text
1 Wh = 1 W × 3600 s = 3600 J
1 kWh = 1000 Wh = 3.6 million J
```

Electricity bills charge per kWh. Laptop and power-bank batteries are labelled in Wh (airlines limit what you can
carry by Wh, typically 100 Wh without approval).

**Worked example.** A 10 W LED lamp runs 5 hours a day for 30 days:

```text
E = P × t = 10 W × (5 h × 30) = 1500 Wh = 1.5 kWh
```

At, say, 0.30 per kWh in your currency, that's 0.45 a month.

### Milliamp-hours

Small batteries are usually rated in **milliamp-hours** (mAh), or amp-hours (Ah). Strictly speaking, mAh is a unit
of **charge**, not energy: 1 mAh is 1 mA flowing for one hour, which is 3.6 coulombs. To get energy, multiply by the
battery's voltage:

```text
E (Wh) = capacity (Ah) × voltage (V)
       = capacity (mAh) × voltage (V) / 1000
```

**Worked example.** Which stores more energy: a 2000 mAh AA NiMH cell (1.2 V) or a 2000 mAh lithium-ion cell
(3.7 V)?

```text
NiMH:   2000 mAh × 1.2 V / 1000 = 2.4 Wh
Li-ion: 2000 mAh × 3.7 V / 1000 = 7.4 Wh
```

Same mAh, three times the energy. **Never compare batteries by mAh alone unless their voltages are the same.** Power
banks are especially misleading: a "10 000 mAh" power bank quotes the capacity of its internal 3.7 V cells, so it
holds about 37 Wh, and after converting up to 5 V for USB (losing some energy) it delivers roughly 6 000 mAh at 5 V.

## Efficiency

Energy is never destroyed; it is converted. But not all of it ends up where you want it. **Efficiency** is the useful
fraction:

```text
efficiency = useful power out / power in        (as a percentage, × 100 %)
```

| Device | Typical efficiency | Where the rest goes |
|---|---|---|
| Incandescent bulb | about 2–3 % as light | Heat |
| LED lamp | about 30–50 % as light | Heat in the LED and its driver |
| Small DC motor or pump | 30–70 % | Heat in the windings, friction, noise |
| Linear voltage regulator, 12 V → 5 V | about 42 % (5/12) | Heat in the regulator |
| Switching regulator (buck converter) | 85–95 % | Heat in the switch, coil and diode |
| Phone charger | 80–90 % | Heat |
| Copper wire run, sized well | 97–99 %+ | Heat in the wire |

Losses all end up as **heat**, and heat is what kills components. A device that is 80 % efficient and delivers 40 W
must get rid of 10 W of heat (`50 W in − 40 W out`). Chapter 12 shows why a linear regulator can get dangerously hot
while a switching regulator stays cool doing the same job.

## A first power budget for the Plant Station

A **power budget** lists every load, its voltage and current, and from that the power and energy it uses. It answers
"how big a power supply?" and "how long would a battery last?".

Use these estimates for now (later chapters refine them):

| Load | Voltage | Current | Power | On-time per day | Energy per day |
|---|---|---|---|---|---|
| Microcontroller board, running | 5 V | 50 mA | 0.25 W | 24 h | 6.0 Wh |
| Status LED + resistor (whole branch) | 5 V | 9.1 mA | 0.045 W | 24 h | 1.1 Wh |
| Water pump | 12 V | 0.4 A | 4.8 W | 30 s (2 × 15 s) | 0.04 Wh |
| **Total** | | | **5.1 W peak** | | **about 7.1 Wh** |

```text
Board:  P = 5 V × 0.05 A = 0.25 W;   E = 0.25 W × 24 h = 6.0 Wh
LED:    P = 5 V × 0.0091 A ≈ 0.045 W; E = 0.045 W × 24 h ≈ 1.1 Wh
Pump:   P = 12 V × 0.4 A = 4.8 W;     E = 4.8 W × (30 / 3600) h = 0.04 Wh
```

Two lessons that surprise most beginners:

1. **The pump sets the peak power; the always-on parts set the energy.** The pump is twenty times more powerful than
   the board, but it runs for 30 seconds a day, so it uses under 1 % of the daily energy. The board and the LED,
   which never sleep, use the rest. To make a battery last, you cut the always-on current (sleep modes, a dimmer LED;
   chapters 12 and 19), not the pump.
2. **The supply must handle the peak, not the average.** The average power is about `7.1 Wh / 24 h ≈ 0.3 W`, but the
   supply must deliver 5.1 W when the pump runs (and more as it starts, chapter 16).

## Key takeaways

- Energy (joules) is the total amount of work; power (watts) is the rate. `E = P × t`; 1 W = 1 J/s.
- `P = V × I` for any component. For resistances also `P = I² × R` and `P = V² / R`.
- Power in a resistance grows with the **square** of the current: twice the current, four times the heat.
- Wasted power becomes heat; component ratings are heat limits. Run resistors at no more than half their rated
  power.
- 1 Wh = 3600 J; bills use kWh. mAh is charge: multiply by voltage to get Wh, and never compare batteries of
  different voltages by mAh.
- Efficiency is useful power out over power in; the rest is heat.
- A power budget lists each load's power and on-time. Peak power sizes the supply; always-on loads dominate energy.

## Exercises

1. **Power three ways.** A 100 Ω resistor has 5 V across it. Calculate the current, then the power using all three
   formulas. Is a 0.25 W resistor adequate?

<details>
<summary>Solution</summary>

```text
I = V / R = 5 / 100 = 0.05 A
P = V × I   = 5 × 0.05        = 0.25 W
P = I² × R  = 0.05² × 100     = 0.0025 × 100 = 0.25 W
P = V² / R  = 25 / 100        = 0.25 W
```

0.25 W is exactly the rating of a standard resistor, so it would run at its limit, very hot. Use a 0.5 W resistor
(or larger) to stay at half the rating or below.

</details>

2. **Square law.** A wire carrying 2 A dissipates 0.5 W. How much does it dissipate at 6 A (assuming its resistance
   stays the same)?

<details>
<summary>Solution</summary>

Three times the current gives nine times the power: `0.5 W × 3² = 4.5 W`. (In reality the wire would get hotter, its
resistance would rise and the power would be a bit higher still.)

</details>

3. **Battery energy.** Compare the energy stored in: a 9 V battery rated 550 mAh, four 2500 mAh AA alkaline cells
   (1.5 V each) in series, and an 18650 lithium-ion cell rated 3000 mAh at 3.6 V.

<details>
<summary>Solution</summary>

```text
9 V battery:  550 mAh × 9 V / 1000   ≈ 5.0 Wh
4 × AA:       2500 mAh × 6 V / 1000  = 15 Wh   (in series the voltage adds, the mAh stays the same)
18650 cell:   3000 mAh × 3.6 V / 1000 ≈ 10.8 Wh
```

The 9 V battery stores the least energy by far, despite its high voltage; it's a poor choice for powering an Arduino
(chapter 12). Real alkaline capacity also drops at higher current, so the AA figure is optimistic for heavy loads.

</details>

4. **Cost.** A 3D printer draws an average of 150 W during a 20-hour print. How many kWh is that, and what does it
   cost at 0.30 per kWh?

<details>
<summary>Solution</summary>

```text
E = 150 W × 20 h = 3000 Wh = 3 kWh
cost = 3 kWh × 0.30 = 0.90
```

</details>

5. **Heat from inefficiency.** A small motor driver delivers 18 W to a motor at 90 % efficiency. How much power does
   it draw from the supply, and how much heat must the driver and its wiring get rid of?

<details>
<summary>Solution</summary>

```text
power in = power out / efficiency = 18 W / 0.9 = 20 W
heat     = 20 W − 18 W = 2 W
```

2 W of heat is enough to need a small heatsink on most driver chips.

</details>

6. **Plant Station budget.** Redo the Plant Station power budget with these changes: the board sleeps most of the
   time, drawing an average of 5 mA; the LED is replaced by a short blink that averages 0.5 mA; and the pump runs
   for 60 s a day. What is the new daily energy? Which item now dominates?

<details>
<summary>Solution</summary>

```text
Board: 5 V × 0.005 A = 0.025 W × 24 h      = 0.60 Wh
LED:   5 V × 0.0005 A = 0.0025 W × 24 h    = 0.06 Wh
Pump:  4.8 W × (60 / 3600) h               = 0.08 Wh
Total                                       ≈ 0.74 Wh per day
```

About a tenth of the original 7.1 Wh. The board still dominates, but the pump is now a noticeable share (about
11 %). Further savings mean a lower sleep current; this is exactly the kind of trade-off chapter 12 uses for battery
life.

</details>

---
← Previous: [Resistance and Ohm's law](04-resistance-and-ohms-law.md) | Next: [Units, prefixes and part markings](06-units-prefixes-and-part-markings.md) →
