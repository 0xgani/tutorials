# Chapter 8 — Series and parallel circuits

## What you'll learn

- What "in series" and "in parallel" mean precisely
- How current and voltage behave in each
- How to combine resistors in series (`R = R1 + R2 + …`) and in parallel (`1/R = 1/R1 + 1/R2 + …`)
- Shortcuts for two resistors in parallel and for equal resistors
- How to simplify a network of resistors step by step
- Why every LED needs its own resistor when LEDs are in parallel
- Why your house is wired in parallel, and what happens when one part of a series string fails

## Two ways to connect things

Most circuits are built from two basic arrangements.

**In series**, components are connected end to end, in a single path. The same current has to flow through each one
in turn.

```text
   series:   ──[ R1 ]──[ R2 ]──[ R3 ]──
```

**In parallel**, components are connected across the same two nodes. Each one has its own path, and they all have the
same voltage across them.

```text
   parallel:    ┌──[ R1 ]──┐
             ───┼──[ R2 ]──┼───
                └──[ R3 ]──┘
```

The precise test: two components are **in series** if they share a node and *nothing else* is connected to that node
(so all current through one must go through the other). Two components are **in parallel** if they are connected
between the *same pair* of nodes.

## Series circuits

```text
          I →
   +9V ───[ R1 = 1 kΩ ]───[ R2 = 2 kΩ ]─── GND
           ◄── V1 ──►      ◄── V2 ──►
```

**Current is the same through every series component.** There's only one path, and charge can't pile up (chapter 1).

**Voltages add up.** The supply's 9 V is shared between the components: `V1 + V2 = 9 V`. That's Kirchhoff's voltage
law (chapter 9) in its simplest form.

**Resistances add up.** Each resistor makes the electrons push through more material, like a longer pipe:

```text
R_total = R1 + R2 + R3 + …
```

Worked through:

```text
R_total = 1 kΩ + 2 kΩ = 3 kΩ
I       = 9 V / 3 kΩ = 3 mA          (same through both)
V1      = 3 mA × 1 kΩ = 3 V
V2      = 3 mA × 2 kΩ = 6 V          (3 V + 6 V = 9 V ✓)
```

**The voltage splits in proportion to resistance.** R2 has twice the resistance, so it takes twice the voltage. That
is the idea behind the voltage divider (chapter 10).

**Power splits the same way** (since the current is the same, `P = I² × R`): R1 dissipates 9 mW, R2 18 mW.

### Series facts to remember

- The total resistance is **larger than the largest** resistor.
- **One break stops everything.** If any component in the series path fails open, current stops in the whole loop.
  Old Christmas lights wired in series all went out when one bulb failed.
- **A large resistor dominates.** 10 kΩ in series with 10 Ω is 10.01 kΩ; the 10 Ω hardly matters. This is why you
  can usually ignore wire resistance in series with kilohm resistors.
- An LED and its resistor are in series: the same current flows through both, and their voltages add up to the supply
  (chapter 4).

## Parallel circuits

```text
          I_total →
   +9V ───┬─────────────┐
          │ I1 ↓         │ I2 ↓
       [R1 = 1 kΩ]  [R2 = 2 kΩ]
          │              │
   GND ───┴──────────────┘
```

**Voltage is the same across every parallel component.** They are connected to the same two nodes, and every point on
a node has the same potential. Both resistors have 9 V across them.

**Currents add up.** The current from the supply splits at the top node and recombines at the bottom one:
`I_total = I1 + I2`. That's Kirchhoff's current law (chapter 9).

```text
I1      = 9 V / 1 kΩ = 9 mA
I2      = 9 V / 2 kΩ = 4.5 mA
I_total = 13.5 mA
```

**The combined resistance is smaller than either one.** Each extra path lets more current flow, as if you were
widening the pipe. The equivalent resistance is what the supply "sees":

```text
R_total = V / I_total = 9 V / 13.5 mA ≈ 667 Ω
```

### The parallel formula

Conductances (`G = 1 / R`, chapter 4) add in parallel, because each path adds its own ability to conduct:

```text
1 / R_total = 1 / R1 + 1 / R2 + 1 / R3 + …
```

For the example: `1/1000 + 1/2000 = 0.001 + 0.0005 = 0.0015`, so `R_total = 1 / 0.0015 ≈ 667 Ω`. ✓

**Shortcut for exactly two resistors** ("product over sum"):

```text
R_total = (R1 × R2) / (R1 + R2)

(1 kΩ × 2 kΩ) / (1 kΩ + 2 kΩ) = 2 / 3 kΩ ≈ 667 Ω
```

**Shortcut for N equal resistors:** `R_total = R / N`. Two 1 kΩ in parallel make 500 Ω; four make 250 Ω. That's a
handy way to make an odd value from parts you have, or to share the heat of one resistor across several.

### Parallel facts to remember

- The total resistance is **smaller than the smallest** resistor.
- **The current splits in inverse proportion to resistance**: the smaller resistor takes more current. (1 kΩ took
  9 mA, 2 kΩ took 4.5 mA.)
- **A small resistor dominates.** 10 Ω in parallel with 10 kΩ is about 9.99 Ω. A short circuit (0 Ω) in parallel
  with anything gives 0 Ω: it takes all the current and the other part gets nothing.
- **Each branch works independently.** If one branch breaks, the others keep going with the same voltage.

## Why houses are wired in parallel

Every socket and lamp in a building is connected in parallel across the mains supply:

```text
   live ────┬──────────┬──────────┬────
            │          │          │
          [lamp]    [kettle]    [TV]
            │          │          │
   neutral ─┴──────────┴──────────┴────
```

- Each appliance gets the full mains voltage it was designed for, regardless of what else is on.
- Switching one off doesn't affect the others.
- The currents add up in the shared wires back to the fuse box. That's why a circuit has a breaker: plug in too many
  heaters and the total current exceeds what the wires can carry (chapter 11, chapter 13).

The same structure appears in every Arduino project: the sensors, LEDs and the board itself are all in parallel across
the 5 V and GND rails, each drawing its own current. The supply must provide the total.

## LEDs in series and in parallel

**Never put LEDs directly in parallel with a single shared resistor.**

```text
   WRONG                                      RIGHT
   +5V ──[150Ω]──┬──►│──┐                    +5V ──┬──[330Ω]──►│──┐
                 └──►│──┤                          └──[330Ω]──►│──┤
                        GND                                        GND
```

LEDs are non-ohmic (chapter 4), and their forward voltages differ slightly between parts. In parallel they are forced
to the same voltage, and the LED with the lowest forward voltage takes most of the current. It runs hotter, its
forward voltage drops further (it's a semiconductor), and it hogs even more — then fails, and the current moves to the
next one. **Give each LED its own resistor.** Then each branch is an independent series loop with its current set
by its own resistor.

**LEDs in series** share one current, so one resistor works, as long as the supply is high enough:

```text
   +12V ──[R]──►│──►│──►│── GND        three LEDs at 2.0 V each = 6.0 V

   R = (12 V − 6.0 V) / 10 mA = 600 Ω → 680 Ω (standard value)
```

LED strips use exactly this: groups of three LEDs in series with one resistor for 12 V strips, with the groups in
parallel along the strip. If one LED in a series group fails open, its whole group goes dark (that's the "every third
LED is out" symptom on a broken strip).

## Simplifying a network

Real circuits mix series and parallel. Simplify them from the inside out: find a group that's purely series or purely
parallel, replace it with one equivalent resistor, redraw, and repeat.

```text
   +12V ──[R1 = 1 kΩ]──●──[R2 = 3 kΩ]──┐
                       │               │
                   [R3 = 6 kΩ]         │
                       │               │
   GND ────────────────●───────────────┘
```

Step by step:

1. R2 runs from the middle node to GND (its right end joins the ground wire). R3 also runs from the middle node to
   GND. So **R2 and R3 are in parallel**: `(3 × 6) / (3 + 6) = 18 / 9 = 2 kΩ`.
2. Now R1 is in series with that 2 kΩ: `1 + 2 = 3 kΩ` total.
3. Current from the supply: `12 V / 3 kΩ = 4 mA`.
4. Voltage across R1: `4 mA × 1 kΩ = 4 V`, so the middle node is at `12 − 4 = 8 V`.
5. Currents in the parallel pair: R2 gets `8 V / 3 kΩ ≈ 2.67 mA`, R3 gets `8 V / 6 kΩ ≈ 1.33 mA`. Total
   `4 mA` ✓.

```text
  original           step 1                 step 2
  R1 + (R2 ∥ R3)  →  R1 + 2 kΩ           →  3 kΩ
```

`∥` is the shorthand for "in parallel with". Always check by adding the currents back up and the voltages around the
loop. If they don't match, you've made an error somewhere.

Some networks (for example a "bridge" with a resistor across the middle) can't be simplified with series and
parallel alone. Chapter 9's Kirchhoff's laws solve any circuit.

## Key takeaways

- **Series**: one path. Same current everywhere; voltages add; `R = R1 + R2 + …`; total larger than the largest;
  one break stops everything; voltage splits in proportion to resistance.
- **Parallel**: same two nodes. Same voltage across each; currents add; `1/R = 1/R1 + 1/R2 + …`; total smaller than
  the smallest; branches are independent; current splits in inverse proportion to resistance.
- Two in parallel: `R1 × R2 / (R1 + R2)`. N equal in parallel: `R / N`.
- Give each parallel LED its own resistor; LEDs in series can share one if the supply is high enough.
- Houses and Arduino projects are wired in parallel; their currents add up in the shared supply wires.
- Simplify networks from the inside out, then check currents and voltages add up.

## Exercises

1. **Series.** A 470 Ω and a 1 kΩ resistor are in series across 5 V. Find the total resistance, the current, and the
   voltage across each.

<details>
<summary>Solution</summary>

```text
R_total = 470 + 1000 = 1470 Ω
I       = 5 V / 1470 Ω ≈ 3.40 mA
V_470   = 3.40 mA × 0.47 kΩ ≈ 1.60 V
V_1k    = 3.40 mA × 1 kΩ   ≈ 3.40 V      (1.60 + 3.40 = 5.00 V ✓)
```

</details>

2. **Parallel.** Find the equivalent resistance of (a) 220 Ω ∥ 220 Ω, (b) 1 kΩ ∥ 4 kΩ, (c) 100 Ω ∥ 100 kΩ,
   (d) three 3 kΩ in parallel.

<details>
<summary>Solution</summary>

(a) Equal pair: `220 / 2 = 110 Ω`.
(b) `(1 × 4) / (1 + 4) = 0.8 kΩ = 800 Ω`.
(c) `(100 × 100 000) / 100 100 ≈ 99.9 Ω`. The small one dominates.
(d) `3 kΩ / 3 = 1 kΩ`.

</details>

3. **Make a value.** You need about 150 Ω but only have 100 Ω and 220 Ω resistors (several of each). Find two ways
   to get close.

<details>
<summary>Solution</summary>

- `100 + 100 ∥ 100 = 100 + 50 = 150 Ω` (one 100 Ω in series with two 100 Ω in parallel): exact.
- `220 ∥ 220 + 100 ∥ 100 = 110 + 50 = 160 Ω`: close (within about 7 %).
- `220 ∥ (220 + 220) = 220 × 440 / 660 ≈ 147 Ω`: within 2 %.

</details>

4. **Supply current.** An Arduino-style board (50 mA), two LEDs with their resistors (9 mA each) and a sensor (5 mA)
   are all connected across a 5 V supply. What total current must the supply provide, and what is the equivalent
   resistance the supply sees?

<details>
<summary>Solution</summary>

They're in parallel, so currents add: `50 + 9 + 9 + 5 = 73 mA`. Equivalent resistance: `5 V / 73 mA ≈ 68 Ω`. (The
board isn't a resistor, so this is only the equivalent at this moment.)

</details>

5. **Network.** Find the current from the supply and the current through R4 in this circuit.

```text
   +10V ──[R1 = 2 kΩ]──●───────────┬──────────┐
                       │           │          │
                  [R2 = 4 kΩ]  [R3 = 4 kΩ] [R4 = 2 kΩ]
                       │           │          │
   GND ────────────────●───────────┴──────────┘
```

<details>
<summary>Solution</summary>

R2, R3 and R4 all run from the middle node to GND, so all three are in parallel. R2 ∥ R3 = 2 kΩ, then 2 kΩ ∥ R4 (2 kΩ)
= 1 kΩ. In series with R1: `2 + 1 = 3 kΩ`.

```text
I_supply = 10 V / 3 kΩ ≈ 3.33 mA
V_R1     = 3.33 mA × 2 kΩ ≈ 6.67 V  →  middle node ≈ 3.33 V
I_R4     = 3.33 V / 2 kΩ  ≈ 1.67 mA
I_R2 = I_R3 = 3.33 V / 4 kΩ ≈ 0.83 mA each
check: 1.67 + 0.83 + 0.83 ≈ 3.33 mA ✓
```

</details>

6. **The hogging LED.** Three white LEDs are wired in parallel with one shared 47 Ω resistor from 5 V. Their forward
   voltages at 20 mA are 3.0 V, 3.1 V and 3.2 V. Explain qualitatively what happens over time, and redesign the
   circuit for about 15 mA per LED.

<details>
<summary>Solution</summary>

The 47 Ω sets the *total* current to roughly `(5 − 3.0) / 47 ≈ 43 mA`, but the split is decided by the LEDs, which are
forced to the same voltage. The 3.0 V LED takes most of it, probably well over 20 mA, while the 3.2 V one stays dim.
The hot LED's forward voltage drops further (silicon and LED forward voltages fall as they warm), so it hogs even more
until it fails, and then the remaining two share the full current and are over-driven in turn.

Redesign: one resistor per LED. With 3.1 V typical: `(5 − 3.1) / 15 mA ≈ 127 Ω` → use 150 Ω (about 12.7 mA each).

</details>

---
← Previous: [Circuits, schematics and breadboards](07-circuits-schematics-and-breadboards.md) | Next: [Kirchhoff's laws and circuit analysis](09-kirchhoffs-laws-and-circuit-analysis.md) →
