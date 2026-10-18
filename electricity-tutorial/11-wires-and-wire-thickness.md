# Chapter 11 — Wires and wire thickness

## What you'll learn

- The two reasons wire thickness matters: voltage drop and heat
- What's inside a wire, and what's printed on its insulation
- Wire sizes: AWG gauges and metric mm², and how to convert between them
- How to calculate a wire run's resistance, voltage drop and power loss — remembering the return wire
- Why low-voltage projects suffer from voltage drop much more than mains appliances
- How much current a wire can carry, and why thick wires carry less current per mm²
- Solid vs. stranded wire, copper vs. copper-clad aluminium, insulation types
- Why connectors, breadboards, jumpers and USB cables are often the real weak link
- How to size the wires for the Plant Station's pump

## Why thickness matters

Chapters 2, 4 and 5 already contain everything this chapter needs:

- A wire's resistance is `R = ρ × L / A` (chapter 4). **A thinner wire has more resistance.** Halve the diameter and
  the cross-section drops to a quarter, so the resistance goes up four times.
- Current through that resistance **drops voltage**: `V = I × R` (chapter 4). The load at the far end gets less than
  the supply provides.
- And it **makes heat**: `P = I² × R` (chapter 5). Too much heat melts insulation, and in the worst case starts a
  fire.
- Physically, the same current squeezed through a smaller cross-section means a higher current density and faster
  electron drift, with more energy lost to collisions per metre (chapter 2).

So "how thick a wire do I need?" has two separate answers, and you need the larger of the two:

1. **Thick enough that the voltage drop is acceptable** for the load.
2. **Thick enough that the wire stays at a safe temperature** for the current.

For short signal wires both are trivially satisfied, and any wire works. For power — a motor, an LED strip, a pump,
the supply feeding a whole project — you have to check.

## What's in a wire

```text
   ┌──────────────────────── insulation (PVC, silicone, PTFE…) ──────┐
   │  ═══════════════════════════════════════════════════════════  │ ← conductor: one solid core
   └─────────────────────────────────────────────────────────────────┘    or many fine strands

   printed on the insulation, for example:
   "UL1007 22AWG 80°C 300V VW-1"
      │      │     │     │    └── flame test passed
      │      │     │     └─ maximum voltage the insulation is rated for
      │      │     └─ maximum conductor temperature
      │      └─ size
      └─ standard style (UL1007 is a common hook-up wire)
```

- The **conductor** is almost always copper (sometimes tin-plated, which looks silver and solders easily). Its
  cross-sectional area sets the resistance.
- The **insulation** keeps current in and fingers out. Its **temperature rating** (commonly 80 °C or 105 °C for PVC,
  200 °C for silicone and PTFE) sets how much current the wire can carry. Its **voltage rating** sets how much voltage
  it can safely hold off; for Arduino projects any rating is plenty, but it matters for mains (chapter 13).

## Wire sizes: AWG and mm²

Two systems are in use:

- **AWG** (American Wire Gauge): a number where **bigger means thinner**. 30 AWG is hair-thin wire-wrap wire; 10 AWG
  is as thick as a pencil lead. It's used in North America and on most hobby electronics wire worldwide.
- **mm²** (cross-sectional area in square millimetres): used for building and appliance wiring in Europe and most of
  the world (IEC standard sizes: 0.5, 0.75, 1, 1.5, 2.5, 4 mm²…). It's the more useful number because resistance is
  directly proportional to 1/area.

The AWG scale has a few handy patterns:

- Every **3 gauges** smaller → double the area, half the resistance (22 → 19 AWG doubles the copper).
- Every **6 gauges** smaller → double the diameter.
- Every **10 gauges** smaller → ten times the area.

### Reference table

Resistance is for solid copper at 20 °C; stranded wire of the same gauge is within a few percent.

| AWG | Diameter | Area | Resistance | Typical use |
|---|---|---|---|---|
| 30 | 0.26 mm | 0.051 mm² | 339 mΩ/m | Wire-wrap, tiny repairs |
| 28 | 0.32 mm | 0.081 mm² | 213 mΩ/m | Ribbon cable, cheap USB cable power wires |
| 26 | 0.40 mm | 0.129 mm² | 134 mΩ/m | Sensor leads, servo cables |
| 24 | 0.51 mm | 0.205 mm² | 84 mΩ/m | Network cable cores, light signal and power wiring |
| 22 | 0.64 mm | 0.326 mm² | 53 mΩ/m | **Standard breadboard and hook-up wire** |
| 20 | 0.81 mm | 0.518 mm² | 33 mΩ/m | Small motors, LED strips, good USB cables |
| 18 | 1.02 mm | 0.823 mm² | 21 mΩ/m | Power leads, lamp cords |
| 16 | 1.29 mm | 1.31 mm² | 13 mΩ/m | Higher-current DC, speaker wire |
| 14 | 1.63 mm | 2.08 mm² | 8.3 mΩ/m | Battery leads, North American 15 A house wiring |
| 12 | 2.05 mm | 3.31 mm² | 5.2 mΩ/m | Large batteries, 20 A circuits |
| 10 | 2.59 mm | 5.26 mm² | 3.3 mΩ/m | High-current DC, e-bike and solar battery leads |

Metric equivalents, approximately:

| mm² | Nearest AWG | Resistance |
|---|---|---|
| 0.14 | 26 | 123 mΩ/m |
| 0.25 | 23–24 | 69 mΩ/m |
| 0.5 | 20 | 34 mΩ/m |
| 0.75 | 18–19 | 23 mΩ/m |
| 1.0 | 17–18 | 17 mΩ/m |
| 1.5 | 15–16 | 11.5 mΩ/m |
| 2.5 | 13–14 | 6.9 mΩ/m |

The AWG formula, if you ever need a gauge not in the table: `diameter (mm) = 0.127 × 92^((36 − AWG) / 39)`. The
calculator in [`examples/wire-drop.mjs`](examples/wire-drop.mjs) uses it.

Beware: cheap wire is sometimes undersized (a "22 AWG" that's really 24 AWG) or made of **copper-clad aluminium**
(CCA), which has about 1.6 times the resistance of copper. If a cheap cable runs warm or drops more voltage than it
should, that's often why.

## Voltage drop

**The most common mistake in wire calculations is forgetting the return wire.** Current flows out to the load *and
back*. If the load is 2 m away, the current travels through 4 m of wire.

```text
   supply                                        load
   +12V ●─────────────── 2 m ──────────────────● +
        │          R_wire (out)                 │
        │                                    [ pump ]
        │          R_wire (back)                │
   GND  ●─────────────── 2 m ──────────────────● −

   total wire resistance = resistance of 4 m
   the load sees: V_supply − I × R_loop
```

The procedure:

```text
1. Loop length:       L = 2 × one-way distance
2. Wire resistance:   R = (resistance per metre) × L        or R = ρ × L / A
3. Voltage drop:      V_drop = I × R
4. Percentage:        V_drop / V_supply × 100 %
5. Power lost:        P = I² × R  (heat in the wire)
```

**Worked example: an LED strip at 5 V.** A 5 V LED strip draws 3 A. It's 2 m from its power supply, wired with 22 AWG
hook-up wire.

```text
L      = 2 × 2 m = 4 m
R      = 4 m × 0.053 Ω/m = 0.21 Ω
V_drop = 3 A × 0.21 Ω = 0.63 V
%      = 0.63 / 5 = 12.7 %
P      = 3² × 0.21 = 1.9 W  (about 0.5 W per metre: noticeably warm)
```

The strip gets 4.37 V instead of 5 V. The LEDs at the far end look dimmer and redder (white LEDs need about 3 V
plus their resistor's share, and lose brightness fast as the supply sags). With 18 AWG instead:

```bash
node examples/wire-drop.mjs 3 2 18awg 5
```

```text
Cross-section:          0.823 mm²
Resistance (4 m loop): 83.6 mΩ
Voltage drop:           251 mV
Power lost in the wire: 752 mW (188 mW per metre)
Voltage at the load:    4.75 V (5.0 % lost)
For ≤ 3 % drop you need ≥ 1.4 mm², i.e. 15 AWG or thicker
For ≤ 5 % drop you need ≥ 0.83 mm², i.e. 17 AWG or thicker
```

### How much drop is acceptable?

| Load | Acceptable drop | Why |
|---|---|---|
| Microcontroller board, sensors | 2–3 % | A 5 V board is specified for 4.5–5.5 V, and the supply's own tolerance eats part of that |
| LEDs, LED strips | 3–5 % | Brightness and colour shift visibly |
| Motors, pumps, fans | 5–10 % | They just run a little slower |
| Heaters | 10 %+ | Only the power changes |

To find the wire you need, rearrange for area:

```text
A = ρ × L × I / V_drop_allowed

LED strip, 3 % of 5 V = 0.15 V:
A = 1.72 × 10⁻⁸ × 4 × 3 / 0.15 = 1.4 × 10⁻⁶ m² = 1.4 mm²  (about 15–16 AWG)
```

### Why low voltage makes it worse

The same wire with the same current loses the same *volts*, whatever the supply voltage. But the same volts are a
much larger *fraction* of a low voltage. And for the same power, a lower voltage needs more current (`P = V × I`), and
the drop grows with the current, while the heat grows with its square.

Deliver 15 W over 2 m of 22 AWG (0.21 Ω loop):

| Supply | Current for 15 W | Drop | % of supply | Wire loss |
|---|---|---|---|---|
| 5 V | 3.0 A | 0.63 V | 12.7 % | 1.9 W |
| 12 V | 1.25 A | 0.26 V | 2.2 % | 0.33 W |
| 24 V | 0.63 A | 0.13 V | 0.5 % | 0.08 W |
| 230 V | 0.065 A | 0.014 V | 0.006 % | 0.0009 W |

Going from 5 V to 24 V cut the wire's loss by a factor of about 24. That's the whole reason long LED installations
use 12 V or 24 V strips, USB-C fast charging raises the voltage to 9–20 V instead of raising the current, and the
power grid uses hundreds of kilovolts (chapter 13). **To move power over distance, raise the voltage.**

A practical consequence for Arduino projects: run the long wires at the higher voltage (12 V to the far side of the
room) and convert down to 5 V close to where it's needed (chapter 12).

## Heating and current capacity

The second limit is temperature. A wire's resistance turns `I² × R` into heat along its whole length; the wire's
temperature rises until it sheds heat to the surroundings as fast as it's produced. The maximum safe current, its
**ampacity**, is the current at which the hottest point reaches the insulation's temperature rating.

Ampacity is not a property of the wire alone. It depends on:

- **Insulation rating**: a 200 °C silicone wire can carry more than a 60 °C PVC wire of the same gauge.
- **Ambient temperature**: inside a warm enclosure there's less margin.
- **Bundling and enclosure**: wires in a bundle, a conduit or inside a wall heat each other and can't cool.
  Ampacity tables for building wiring are much lower than for a single wire in open air for this reason.
- **Duty**: a pump running 15 seconds is not a heater running all day.

### Why thick wires carry less current per mm²

Heat produced per metre is `I² × R_per_metre`, and `R_per_metre` is proportional to `1 / A`. So heat per metre goes
as `I² / A`. But heat leaves through the wire's **surface**, which grows only with the diameter, that is with `√A`.
Doubling the area doubles the heat a wire can handle per metre "inside", but only increases its surface by about
41 %. The result: ampacity grows more slowly than area. A 2.5 mm² wire carries roughly twice what a 1 mm² wire does,
not two and a half times. Thin wires can run at high current densities; thick cables must run at lower ones.

### Conservative rules of thumb for hobby DC wiring

For a **single, short, PVC-insulated copper wire in free air, at room temperature, in a low-voltage DC project**,
these currents keep the wire comfortably warm at most:

| AWG | mm² | Comfortable continuous current |
|---|---|---|
| 28 | 0.08 | 0.5 A |
| 26 | 0.13 | 1 A |
| 24 | 0.2 | 1.5 A |
| 22 | 0.33 | 2–3 A |
| 20 | 0.5 | 3–5 A |
| 18 | 0.8 | 5–7 A |
| 16 | 1.3 | 8–10 A |
| 14 | 2.1 | 12–15 A |

These are deliberately conservative engineering estimates, not a standard; published "chassis wiring" tables allow
more for single wires, and "power transmission" tables for bundled wires allow much less. Two warnings:

- **Voltage drop usually limits you first** on any run longer than a few tens of centimetres at 5 V. The LED strip
  above was within 22 AWG's heat limit at 3 A but lost 13 % of its voltage.
- **Mains wiring is a different world.** Building wire sizes are set by national electrical codes, together with the
  fuse or breaker that protects them, and must not be guessed from a hobby table (chapter 13).

### Temperature raises resistance

Copper's resistance rises about **0.39 % per °C**. A wire running at 60 °C instead of 20 °C has about 16 % more
resistance, so it drops more voltage and makes more heat, which makes it hotter still. In a properly sized wire this
settles at a stable temperature. In a badly overloaded one, it's how the insulation ends up melting. A fuse (chapter
20) protects the wire by breaking the circuit before that happens.

## Solid vs. stranded

| | Solid core | Stranded |
|---|---|---|
| Construction | One thick conductor | Many fine strands twisted together |
| Flexibility | Stiff; breaks if flexed repeatedly | Flexible; survives vibration and movement |
| Breadboards | **Pushes in cleanly** | Strands splay and short neighbouring holes (unless crimped to a pin) |
| Screw terminals | Clamps well | Use a crimped ferrule, or strands escape |
| Resistance | Slightly lower for the same gauge | Practically the same |
| Use | Breadboard jumpers, wiring inside a box that never moves | Anything that moves, leads to motors and batteries, cables |

Don't tin (pre-solder) stranded wire ends before clamping them in a screw terminal: solder slowly flows under
pressure ("cold flow"), the clamp loosens, and the joint can overheat. Use ferrules instead.

**Silicone-insulated** wire is extremely flexible and heat-resistant; it's the standard for battery and motor leads
in drones and RC models. **PTFE** (Teflon) insulation doesn't melt under a soldering iron, which makes it pleasant for
point-to-point wiring.

## The weakest link: connectors and cables

The wire is often not the limit. Every connection has **contact resistance**, and it's usually higher than the wire's.

| Connection | Practical current limit | Notes |
|---|---|---|
| Breadboard contacts | about 1 A | Thin spring clips; tens of mΩ each, more when worn |
| Dupont-style jumper wires | about 1 A | Thin (often 26–28 AWG) wire and small pins |
| Pin headers (2.54 mm) | about 2–3 A per pin | Use several pins in parallel for more |
| JST-XH connectors | about 3 A | Common for small batteries and modules |
| JST-PH (small, 2 mm) | about 1–2 A | LiPo cells in small gadgets |
| Screw terminals | 5–15 A depending on size | Must be tight; check the rating |
| XT30 / XT60 | 15 / 30 A continuous (rated) | Battery connectors for RC and e-bikes |
| Barrel jacks (5.5 × 2.1 mm) | about 2–5 A | The classic Arduino power input |

A bad crimp, a loose screw or a worn breadboard contact concentrates heat in one tiny spot. A connection that is warm
to the touch while the wires either side are cool is a problem connection.

### USB cables

USB cables are a common source of trouble. The data wires are thin, and so are the power wires in cheap cables, often
28 AWG. A 1 m cable has a 2 m loop:

```bash
node examples/wire-drop.mjs 2 1 28awg 5
```

```text
Cross-section:          0.0810 mm²
Resistance (2 m loop): 425 mΩ
Voltage drop:           850 mV
Power lost in the wire: 1.7 W (850 mW per metre)
Voltage at the load:    4.15 V (17.0 % lost)
For ≤ 3 % drop you need ≥ 0.46 mm², i.e. 20 AWG or thicker
For ≤ 5 % drop you need ≥ 0.28 mm², i.e. 22 AWG or thicker
```

At 2 A, 17 % of the voltage is gone before it reaches the device, and the connectors add more. Phones respond by charging slowly. An Arduino with servos attached, fed through a long thin USB
cable, may brown out and reset every time a servo moves. Good cables use 20–24 AWG for power, which is why they're
thicker and cost more. (USB-C cables rated for 5 A contain an identification chip and thick conductors.)

## Beyond DC: high frequencies and PCB tracks

> **Just a taste — you don't need to learn or fully understand this now.** At high frequencies, AC current crowds
> toward the surface of a conductor (the *skin effect*), so a wire's effective resistance rises. In copper the
> current flows mostly in the outer 9 mm at 50 Hz, but only the outer 0.066 mm at 1 MHz. It matters for radio,
> switching power supplies and audio-frequency transformers, not for Arduino wiring.

PCB **tracks** (traces) obey the same rules: their resistance is `ρ × L / A`, with `A` = width × copper thickness.
Standard "1 oz" copper is 35 µm thick, so a 0.25 mm-wide track has a cross-section of under 0.01 mm², thinner than
30 AWG wire. Board designers use online "trace width calculators" (based on the IPC-2221 standard) to make power
tracks wide enough. If you design boards later, you'll use one.

## Wire colours

Pick a convention and stick to it. For low-voltage DC:

- **Red** for positive supply, **black** for ground (0 V). Some use **blue** for ground or negative.
- Other colours for signals; yellow, orange or white for the main signals.

Mains wiring has its own, legally mandated, colours, which differ between countries; never assume. In current
European/IEC wiring brown is live, blue is neutral, green/yellow is protective earth. In North America black is hot,
white is neutral, green or bare copper is ground (chapter 13).

## Sizing the Plant Station's wires

The Plant Station's 12 V pump sits in a water reservoir about 1.5 m from the electronics box. It draws 0.4 A while
running, and, like any motor, roughly three times that for a moment when it starts (chapter 16), so plan for 1.2 A
peaks.

**1. Voltage drop.** A motor can tolerate 5 % (0.6 V). Try 24 AWG:

```bash
node examples/wire-drop.mjs 0.4 1.5 24awg 12
```

```text
Cross-section:          0.205 mm²
Resistance (3 m loop): 252 mΩ
Voltage drop:           101 mV
Power lost in the wire: 40.3 mW (13.4 mW per metre)
Voltage at the load:    11.9 V (0.8 % lost)
...
```

Under 1 % while running, and about `1.2 A × 0.25 Ω = 0.3 V` (2.5 %) during the starting surge. Fine.

**2. Heat.** 0.4 A, and briefly 1.2 A, are well within 24 AWG's 1.5 A. Fine.

**3. Robustness.** The pump wires get handled, may be pulled, and sit near water, so use **stranded**, and step up
to **22 AWG** for mechanical strength. The extra cost is nothing. Use a two-core cable (both wires in one sheath)
so the outward and return currents run side by side (chapter 2's "keep the field compact"; it reduces interference).

**4. Connections.** The pump's 1.2 A starting surge is above what breadboard contacts should carry. Bring the pump
wires to **screw terminals** or a JST-XH-style connector on the board, not through the breadboard.

**5. Signal wires.** The moisture probe carries well under 1 mA (chapter 10): any wire works electrically, so use
thin, flexible 26–28 AWG. Over long runs a signal wire can pick up interference; for 1.5 m at these slow readings it's
no problem.

| Wire | Current | Choice |
|---|---|---|
| 12 V supply to the box | Up to about 1.5 A total | 20–22 AWG, two-core, or the adapter's own cable |
| Box to pump | 0.4 A, 1.2 A peaks | 22 AWG stranded, two-core, via screw terminal |
| Moisture probe | under 1 mA | 26–28 AWG, two-core |
| Inside the box (LED, button) | under 20 mA | 22 AWG solid jumpers on the breadboard |

## Key takeaways

- Wire thickness matters for two reasons: **voltage drop** (`I × R`) and **heat** (`I² × R`). Choose the thicker of
  what each requires.
- AWG: bigger number, thinner wire; 3 gauges = 2× area. mm² is the area directly. Know a few values: 22 AWG ≈
  0.33 mm² ≈ 53 mΩ/m; 18 AWG ≈ 0.8 mm² ≈ 21 mΩ/m.
- **Always use the loop length**: out *and* back.
- The same drop in volts is a much bigger fraction of a low voltage. For power over distance, raise the voltage and
  regulate down near the load.
- Ampacity depends on insulation, surroundings and bundling. Use conservative values for hobby DC; follow the code
  for mains.
- Use solid wire for breadboards, stranded for anything that moves, ferrules in screw terminals.
- Connectors, breadboards, jumpers and thin USB cables are often the real limit. A warm connection is a bad
  connection.

## Exercises

1. **Gauge sense.** Without the table, which has more resistance per metre, 20 AWG or 26 AWG, and by roughly what
   factor? (Hint: 3 gauges = 2× area.)

<details>
<summary>Solution</summary>

26 AWG is thinner (bigger number), so it has more resistance. 26 is 6 gauges above 20, which is two steps of 3
gauges, each halving the area: a quarter of the area, so about **4 times** the resistance per metre. (Table:
134 vs. 33 mΩ/m, a factor of 4.0.)

</details>

2. **Voltage drop.** A 12 V, 2 A LED strip is 5 m from its supply, wired with 18 AWG. Calculate the drop, the
   percentage, and the heat in the wire. Is it acceptable?

<details>
<summary>Solution</summary>

```text
loop   = 10 m
R      = 10 × 0.021 = 0.21 Ω
V_drop = 2 × 0.21 = 0.42 V
%      = 0.42 / 12 = 3.5 %
P      = 2² × 0.21 = 0.84 W   (0.08 W per metre, barely warm)
```

3.5 % is just above the 3 % target for LEDs but inside 5 %. Acceptable; 16 AWG would bring it to about 2.2 %.

</details>

3. **Size it.** A 5 V, 1.5 A load is 3 m from its supply. What minimum cross-section keeps the drop at or below 3 %?
   Which AWG is that?

<details>
<summary>Solution</summary>

```text
V_drop allowed = 0.03 × 5 = 0.15 V
A = ρ × L × I / V_drop = 1.72 × 10⁻⁸ × 6 × 1.5 / 0.15
  = 1.03 × 10⁻⁶ m² ≈ 1.0 mm²   → 16 AWG (1.31 mm²); 17 AWG is about 1.04 mm² but rarely sold
```

That's a thick wire for a 1.5 A load. Better: send 12 V over the 3 m and put a small 12 V → 5 V switching regulator
at the load (chapter 12). At 12 V the current is only about 0.7 A (allowing for the regulator's efficiency) and the
allowed drop is much larger in volts; 22–24 AWG would do.

</details>

4. **Raise the voltage.** You need to deliver 24 W to a device 10 m away through 20 AWG wire. Compare the wire's
   power loss at 12 V and at 24 V. What fraction of the power is lost in each case?

<details>
<summary>Solution</summary>

```text
loop R = 20 m × 0.033 Ω/m = 0.66 Ω

12 V: I = 24 / 12 = 2 A;  P_loss = 2² × 0.66 = 2.64 W   (11 % of 24 W)
24 V: I = 24 / 24 = 1 A;  P_loss = 1² × 0.66 = 0.66 W   (2.75 %)
```

Doubling the voltage halves the current and quarters the loss. (Strictly, the device gets slightly less than 24 W
because of the drop; the conclusion is the same.)

</details>

5. **Hot spot.** A 12 V, 4 A heater pad is wired with 18 AWG wire, and it works, but after an hour the screw terminal
   on the board is too hot to touch while the wires are only slightly warm. What's going on, and what do you check?

<details>
<summary>Solution</summary>

The wire is fine (4 A is within 18 AWG's comfortable range), so the heat comes from a high **contact resistance** at
the terminal: `I² × R` concentrated in one tiny spot. 4 A through just 0.05 Ω of bad contact is `16 × 0.05 = 0.8 W`
in a few cubic millimetres. Check that the screw is tight, that the terminal is rated for at least 4 A, that the
stranded wire isn't tinned (cold flow loosens it) and is properly gripped (use a ferrule), and that the terminal
isn't corroded. A connection that runs hot gets worse over time, because heat oxidises the contacts and raises their
resistance further.

</details>

6. **USB brown-out.** An Arduino with two servos is powered through a 2 m USB cable whose power wires are 28 AWG.
   When the servos move together, the board draws 1.2 A for a moment. Estimate the voltage at the board. The board's
   regulator-free 5 V circuitry needs at least 4.5 V. Will it reset? What are two fixes?

<details>
<summary>Solution</summary>

```text
loop R = 4 m × 0.213 Ω/m ≈ 0.85 Ω   (plus connector resistance; ignore it for now)
V_drop = 1.2 A × 0.85 Ω ≈ 1.0 V
V_board ≈ 5.0 − 1.0 = 4.0 V
```

4.0 V is below 4.5 V, so the board will likely brown out and reset each time the servos move (and many USB ports
can't supply 1.2 A anyway). Fixes: power the servos from a separate 5–6 V supply (sharing ground with the board,
chapter 3), and/or use a short USB cable with 20–24 AWG power conductors. Adding a large capacitor near the servos
helps with brief surges (chapter 15).

</details>

---
← Previous: [Voltage dividers and sensors](10-voltage-dividers-and-sensors.md) | Next: [Batteries and power supplies](12-batteries-and-power-supplies.md) →
