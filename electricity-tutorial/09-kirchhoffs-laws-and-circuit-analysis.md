# Chapter 9 — Kirchhoff's laws and circuit analysis

## What you'll learn

- Kirchhoff's current law (KCL): what goes into a node comes out
- Kirchhoff's voltage law (KVL): the voltages around any loop add up to zero
- How to keep signs straight, so the laws give the right answers
- Node-voltage analysis: a reliable method that solves any resistor circuit
- Superposition: handling several sources one at a time
- Thévenin equivalents: replacing any source-and-resistor network with one voltage and one resistance
- Why the full supply voltage appears across an open switch

## Why you need more than series and parallel

Chapter 8's simplification handles most circuits you'll build, but not all. It fails as soon as a circuit has two
sources (a battery and a USB supply both connected; a sensor powered from one rail feeding a pin powered from
another) or a "bridge" shape that's neither series nor parallel. Two laws, published by Gustav Kirchhoff in 1845,
solve every case. They're also the reasoning behind every rule of thumb in chapter 8, so learning them makes the
earlier rules make sense rather than something to memorise.

## Kirchhoff's current law (KCL)

> **The total current flowing into a node equals the total current flowing out of it.**

This is conservation of charge (chapter 1). Charge can't pile up at a junction of wires, so whatever arrives must
leave.

```text
                 I2 = 3 mA
                    ▲
                    │
   I1 = 5 mA ──────►●──────► I3 = ?
                    │
                    ▼
                 I4 = 1 mA

   in:  I1 = 5 mA
   out: I2 + I3 + I4 = 3 + I3 + 1
   KCL: 5 = 4 + I3   →   I3 = 1 mA
```

Equivalently, if you count currents *into* the node as positive and *out of* it as negative, they **sum to zero**.

KCL is why parallel currents add (chapter 8): the supply's current splits at the top node and recombines at the
bottom. It's also why every current that leaves a supply's `+` terminal must come back to its `−` terminal.

## Kirchhoff's voltage law (KVL)

> **Around any closed loop, the sum of the voltage rises equals the sum of the voltage drops.**

Equivalently, adding up all voltages around a loop with consistent signs gives zero. This is conservation of energy:
a charge that goes round a loop and returns to where it started has the same potential it started with, like walking
round a hill and coming back to your starting point (chapter 3).

```text
        +9V ──[ R1 ]──●──[ R2 ]──┐
         ▲     drop 3V │ drop 6V  │
     rise 9V           │          │
         │             │          │
        GND ───────────┴──────────┘

   rises: 9 V (through the battery from − to +)
   drops: 3 V + 6 V = 9 V (through the resistors, in the direction of current)
```

KVL is why series voltages add up to the supply (chapter 8), and why components in parallel have the same voltage:
two components connected between the same two nodes form a loop together, so their voltages must cancel around it,
which means they're equal.

### KVL with an open switch

Here's a puzzle KVL solves. A 9 V battery, a switch (open) and a lamp are in series. What's the voltage across the
open switch?

```text
   +9V ──/ ──[ lamp ]── GND
        open
```

No current flows, so the lamp has no voltage drop (`V = I × R = 0 × R = 0`). KVL says the drops around the loop must
total 9 V. The only place left is the gap: **the full 9 V appears across the open switch**. Similarly, the full
voltage appears across a blown fuse, a broken wire or a cracked solder joint. That's how you find an open circuit
with a voltmeter (chapter 14): measure across each part in turn, and the one with the whole supply across it is the
break.

## Keeping signs straight

Most mistakes with Kirchhoff's laws are sign mistakes. Use one convention consistently:

1. **Label a direction for each unknown current** with an arrow. If you guess wrong, the answer simply comes out
   negative, which means the current flows the other way. That's fine; don't redraw.
2. **Across a resistor**, conventional current flows from higher to lower potential. So the end where your current
   arrow *enters* is the `+` end, and the drop is `I × R`.
3. **Across a source**, the `+` terminal is higher by the source's voltage, whatever the current does.
4. **Walk around the loop** in one direction. Going from `−` to `+` is a rise; going from `+` to `−` is a drop.

## Node-voltage analysis

The most reliable general method, and the one circuit simulators use internally:

1. **Pick ground** (0 V) — usually the supply's `−`.
2. **Name every other node** whose voltage you don't know: `Va`, `Vb`, …
3. **Write KCL at each unknown node**, expressing every current with Ohm's law as
   `(voltage at one end − voltage at the other) / R`.
4. **Solve** the equations for the node voltages.
5. **Work out any current** you need from the node voltages.

### Worked example: two sources

A 12 V supply and a 5 V supply both feed a node X through resistors, and a load resistor goes from X to ground. This
shape is common: imagine a board powered by USB (5 V) with a 12 V adapter also connected through some path.

```text
   +12V ──[ R1 = 1 kΩ ]──●──[ R2 = 2 kΩ ]── +5V
                         │ X
                    [ R3 = 1 kΩ ]
                         │
                        GND
```

There is one unknown node, X. Assume all three currents flow **into R3's direction** — from 12 V to X, from 5 V to
X, and from X down to ground. KCL at X (in = out):

```text
(12 − Vx) / R1  +  (5 − Vx) / R2  =  Vx / R3
```

Working in kΩ and mA (V / kΩ = mA):

```text
(12 − Vx) / 1  +  (5 − Vx) / 2  =  Vx / 1

multiply by 2:  24 − 2Vx + 5 − Vx = 2Vx
                29 = 5Vx
                Vx = 5.8 V
```

Now the currents:

```text
I1 = (12 − 5.8) / 1 kΩ = 6.2 mA     from the 12 V supply into X
I2 = (5 − 5.8) / 2 kΩ  = −0.4 mA    negative: flows from X INTO the 5 V supply
I3 = 5.8 / 1 kΩ        = 5.8 mA     from X to ground

KCL check: 6.2 + (−0.4) = 5.8 ✓
```

The negative `I2` is the interesting result: the 12 V supply is pushing current *backwards into* the 5 V supply.
That's what happens when two supplies are connected together without protection. Back-feeding a USB port or a
regulator that way can damage it, which is why boards that accept two power sources use diodes or switching circuits
to stop it (chapters 17 and 20). The analysis tells you about it before you build anything.

### Bigger circuits

With two unknown nodes you get two equations in two unknowns, and so on. Solving them by hand is ordinary algebra
(substitution or elimination). Beyond three nodes, use a circuit simulator: free ones like Falstad's online circuit
simulator or the SPICE-based LTspice and ngspice do exactly this calculation, and let you see currents animate. They
are well worth a look once you've solved a couple of circuits by hand.

## Superposition

When a circuit has several sources and only resistors (a *linear* circuit), you can find the effect of each source
separately and add them:

1. Keep one source; replace every other voltage source with a **wire** (0 V) and every current source with a gap.
2. Solve the simpler circuit.
3. Repeat for each source and **add** the results.

The same two-source circuit again:

```text
12 V alone (5 V source replaced by a wire, so R2 goes to ground):
   R2 ∥ R3 = (2 × 1) / (2 + 1) = 0.667 kΩ
   Vx = 12 × 0.667 / (1 + 0.667) = 4.8 V

5 V alone (12 V source replaced by a wire, so R1 goes to ground):
   R1 ∥ R3 = 0.5 kΩ
   Vx = 5 × 0.5 / (2 + 0.5) = 1.0 V

Total: Vx = 4.8 + 1.0 = 5.8 V ✓
```

Superposition is mostly a thinking tool. In electronics it's how you reason about a signal riding on top of a DC
level: analyse the DC and the signal separately and add them.

## Thévenin equivalents

Here's one of the most useful ideas in electronics:

> **Any network of voltage sources and resistors, seen from two terminals, behaves exactly like a single voltage
> source `V_th` in series with a single resistance `R_th`.**

```text
   complicated network                     equivalent
  ┌──────────────────────┐                ┌──────────────────┐
  │  sources, resistors  ├── A            │ (V_th)──[R_th]───┼── A
  │                      │        ≡       │                  │
  │                      ├── B            │                  ├── B
  └──────────────────────┘                └──────────────────┘
```

Finding the two values:

- **`V_th`** is the voltage between A and B with **nothing connected** (the open-circuit voltage).
- **`R_th`** is the resistance between A and B with all sources replaced by wires (voltage sources) or gaps (current
  sources).

**Worked example.** A divider of two 1 kΩ resistors across 12 V, with its output at the middle:

```text
   +12V ──[1 kΩ]──●── A          V_th = 6 V       (half of 12 V, nothing connected)
                  │              R_th = 1 kΩ ∥ 1 kΩ = 500 Ω
               [1 kΩ]                 (12 V replaced by a wire: both resistors
                  │                    go from A to ground, in parallel)
   GND ───────────●── B
```

So this divider behaves like a 6 V source with 500 Ω in series. Now connect a 2 kΩ load between A and B. Instead of
re-solving the whole circuit, use the equivalent:

```text
V_load = 6 V × 2 kΩ / (500 Ω + 2 kΩ) = 6 × 0.8 = 4.8 V
```

The "6 V" output sagged to 4.8 V under load (and it agrees with the 4.8 V from superposition above, which was the
same circuit). This is the core idea of **loading**, which chapter 10 uses for voltage dividers and sensors, and
chapter 12 uses for batteries: a real battery is a Thévenin source, its EMF in series with its internal resistance,
and its voltage sags the same way when you draw current from it.

Why it matters in practice: whenever you connect one part of a circuit to another, ask "what's the source's `R_th`
and the load's resistance?" If the load's resistance is much larger (say ten times or more) than `R_th`, the voltage
barely changes. If they're comparable, the voltage sags noticeably.

> **Just a taste — you don't need to learn or fully understand this now.** Thévenin has a twin, the Norton
> equivalent: a current source `V_th / R_th` in parallel with `R_th`. It's the same circuit described differently,
> and you'll meet it in analog electronics books.

## Key takeaways

- **KCL**: current into a node = current out. It's conservation of charge.
- **KVL**: around any closed loop, rises = drops. It's conservation of energy.
- Choose current directions and stick to them; a negative answer means the current flows the other way.
- The full supply voltage appears across an open switch, fuse or break, which is how you find breaks.
- **Node-voltage analysis** solves any resistor circuit: pick ground, name nodes, write KCL at each with Ohm's law,
  solve.
- **Superposition**: in linear circuits, solve for each source alone and add.
- **Thévenin**: any source-and-resistor network looks like `V_th` in series with `R_th`. A load much larger than
  `R_th` barely changes the voltage; a comparable load makes it sag.
- Connecting two supplies together can push current backwards into one of them. Analyse before you connect.

## Exercises

1. **KCL.** Four wires meet at a node. Currents of 120 mA and 30 mA flow in; 80 mA flows out on the third wire. How
   much current flows on the fourth wire, and in which direction?

<details>
<summary>Solution</summary>

In: `120 + 30 = 150 mA`. Out so far: 80 mA. The fourth wire must carry `150 − 80 = 70 mA` **out** of the node.

</details>

2. **KVL.** A 9 V battery feeds three resistors in series. The voltages across the first two are 2.5 V and 4.0 V.
   What is the voltage across the third? If the third is 1 kΩ, what current flows?

<details>
<summary>Solution</summary>

KVL: `9 = 2.5 + 4.0 + V3`, so `V3 = 2.5 V`. Current through it (and through all three): `2.5 V / 1 kΩ = 2.5 mA`.

</details>

3. **Find the break.** A 12 V supply drives a fuse, a switch (closed) and a pump in series. The pump doesn't run. With
   a voltmeter you measure 0 V across the fuse, 0 V across the switch, and 12 V across the pump's terminals. Where is
   the fault? What if instead you'd measured 12 V across the fuse?

<details>
<summary>Solution</summary>

The full 12 V across the pump means the fuse and switch are conducting (no drop) and voltage reaches the pump, but no
current flows through it (otherwise the circuit would work). The pump itself is open: a broken winding, worn brushes,
or a loose wire at its terminals. It could also be a pump that's jammed — but a jammed pump would draw a large current
and the fuse would likely blow.

12 V across the fuse would mean the fuse is the open part: it has blown. (Then the pump, with no current, would show
0 V.) Replace it and find out *why* it blew before powering up again.

</details>

4. **Node analysis.** Solve for V_A:

```text
   +9V ──[ 3 kΩ ]──●── A ──[ 6 kΩ ]── GND
                   │
               [ 6 kΩ ]
                   │
                  +3V
```

(A 6 kΩ resistor connects node A to a separate +3 V supply.)

<details>
<summary>Solution</summary>

KCL at A, currents into A from the 9 V and 3 V supplies equal the current out to ground (kΩ and mA):

```text
(9 − Va) / 3 + (3 − Va) / 6 = Va / 6

multiply by 6:  18 − 2Va + 3 − Va = Va
                21 = 4Va
                Va = 5.25 V
```

Check: from 9 V: `(9 − 5.25) / 3 = 1.25 mA` in. From 3 V: `(3 − 5.25) / 6 = −0.375 mA` (out of A, into the 3 V
supply). To ground: `5.25 / 6 = 0.875 mA`. `1.25 − 0.375 = 0.875` ✓.

</details>

5. **Thévenin and loading.** A divider made of 10 kΩ (top) and 10 kΩ (bottom) across 5 V feeds an input. Find V_th
   and R_th. What voltage does the input see if it has a resistance of (a) 1 MΩ, (b) 10 kΩ, (c) 1 kΩ?

<details>
<summary>Solution</summary>

`V_th = 2.5 V` (half of 5 V). `R_th = 10 kΩ ∥ 10 kΩ = 5 kΩ`.

```text
(a) 2.5 × 1000 / (5 + 1000) ≈ 2.49 V   (load ≫ R_th: no visible sag)
(b) 2.5 × 10 / (5 + 10)     ≈ 1.67 V   (comparable: a third of the voltage lost)
(c) 2.5 × 1 / (5 + 1)       ≈ 0.42 V   (load ≪ R_th: the divider collapses)
```

A microcontroller's input resistance is many megohms, so a 10 kΩ divider is fine for it. Using a divider to power a
low-resistance load doesn't work (chapter 10).

</details>

6. **The balanced bridge.** In this "Wheatstone bridge", what is the voltage between nodes A and B? What current
   flows through R5? Why is this circuit used for precision measurement?

```text
           +10V
         ┌──┴──┐
       [1 kΩ] [2 kΩ]
         │     │
         A─[R5]─B
         │     │
       [1 kΩ] [2 kΩ]
         └──┬──┘
           GND
```

<details>
<summary>Solution</summary>

Ignore R5 first. The left divider puts A at `10 × 1 / (1 + 1) = 5 V`; the right divider puts B at
`10 × 2 / (2 + 2) = 5 V`. A and B are at the same potential, so there's 0 V across R5 and no current through it,
whatever its value: connecting it changes nothing. The bridge is **balanced**.

If one resistor is a sensor whose resistance changes slightly, A and B move apart and a small voltage appears between
them. Measuring a small difference from zero is much more precise than measuring a tiny change on top of a large
voltage, which is why bridges are used in strain gauges, scales (load cells) and precise temperature sensors. Note
that the bridge as drawn can't be simplified with series and parallel rules once R5 carries current; node analysis
handles it.

</details>

---
← Previous: [Series and parallel circuits](08-series-and-parallel-circuits.md) | Next: [Voltage dividers and sensors](10-voltage-dividers-and-sensors.md) →
