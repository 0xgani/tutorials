# Chapter 15 — Capacitors and RC circuits

## What you'll learn

- What a capacitor is physically, and what it stores
- The farad, and the relationship `Q = C × V`
- What sets a capacitor's capacitance
- How a capacitor charges and discharges through a resistor, and the RC time constant
- The energy a capacitor stores, and why big ones can be dangerous
- Capacitors in parallel and in series
- The types you'll meet (ceramic, electrolytic, tantalum, film, supercapacitors), their voltage ratings and polarity
- Decoupling and bulk capacitors: why every chip has a capacitor next to it
- RC filters, and smoothing a noisy signal
- Where capacitors go in the Plant Station

## What a capacitor is

A **capacitor** is two conductive plates separated by an insulator (the **dielectric**). Charge can't cross the
insulator, but it can pile up on the plates.

```text
         plate   dielectric   plate
           │    (insulator)    │
   ────────┤ + │           │ − ├────────
           │ + │           │ − │
           │ + │           │ − │
           │ + │           │ − │
   electrons are pulled off   electrons are pushed onto
   this plate by the source   this plate by the source

   schematic symbol:   ──┤├──        polarised (electrolytic):  ──┤(── with + marked
```

Connect it to a battery and the battery pulls electrons off one plate and pushes the same number onto the other. The
capacitor ends up with `+Q` on one plate and `−Q` on the other; overall it's still neutral. Charge flows *around the
circuit* while this happens, even though none crosses the gap. As the plates fill, the voltage between them rises,
until it equals the battery's and current stops.

What a capacitor stores is **energy in the electric field** between the plates. Disconnect the battery and the charge
stays (in a good capacitor, for minutes to days), with the voltage still across it. Connect a load and it gives the
charge back.

In the water analogy, a capacitor is a tank with a rubber membrane across the middle: water can push the membrane
over (filling one side, emptying the other) but can't pass through it. The more it's stretched, the harder it pushes
back. The membrane's stretch is the voltage.

## Capacitance and the farad

How much charge a capacitor holds per volt is its **capacitance**:

```text
Q = C × V

Q   charge, coulombs
C   capacitance, farads (F)
V   voltage across it, volts
```

**One farad** stores one coulomb per volt. That's huge: practical capacitors are measured in microfarads (µF),
nanofarads (nF) and picofarads (pF) (chapter 6). A 100 µF capacitor at 5 V holds `100 × 10⁻⁶ × 5 = 0.5 mC`.

The capacitance of a parallel-plate capacitor is:

```text
C = ε × A / d

ε   permittivity of the dielectric (how well it supports an electric field)
A   area of the plates
d   distance between them
```

Bigger plates, thinner dielectric and a better dielectric material all increase capacitance. Electrolytic capacitors
reach thousands of microfarads by using a rolled-up foil with an extremely thin oxide layer as the dielectric;
ceramic multilayer capacitors stack hundreds of thin layers.

## The current–voltage relationship

Rearranging `Q = C × V` for changes over time gives the defining behaviour of a capacitor:

```text
I = C × (ΔV / Δt)
```

**Current flows only while the voltage across the capacitor is changing.** A steady voltage means no current: to DC,
a charged capacitor is an open circuit. A rapidly changing voltage means a large current. And the voltage across a
capacitor **can't jump instantly**: that would need infinite current. Capacitors resist sudden changes of voltage.
Almost every use of capacitors follows from this one fact:

- **Decoupling**: the capacitor holds the supply voltage steady while a chip's current changes suddenly.
- **Smoothing**: it fills in the dips of a varying voltage.
- **Timing**: it takes a predictable time to charge through a resistor.
- **Blocking DC**: it passes changes (signals) but blocks a steady voltage.

**Worked example.** A 1000 µF capacitor must supply 0.5 A for 2 ms during a motor surge. How far does its voltage
drop?

```text
ΔV = I × Δt / C = 0.5 × 0.002 / 0.001 = 1 V
```

## Charging and discharging through a resistor

Connect a capacitor to a supply through a resistor:

```text
   Vs ──/ ──[ R ]──●── Vc
                   │
                  ═╪═ C
                   │
                  GND
```

At the moment you close the switch the capacitor is empty (0 V across it), so the whole supply voltage is across the
resistor and the current is `Vs / R`. As the capacitor charges, its voltage rises, the voltage left for the resistor
shrinks, and the current falls. The capacitor charges ever more slowly, approaching `Vs` without quite reaching it:

```text
   Vc
   Vs ┤                         .........................
      │                 .......'
 0.95 ┤             ..''          ← 95 % at 3τ
      │          .''
 0.63 ┤       .'·                 ← 63 % at 1τ
      │     .'
      │   .'
      │  /
    0 ┼─/─────┬──────┬──────┬──────┬──────┬────► time
      0       τ      2τ     3τ     4τ     5τ
```

The shape is an exponential curve set by the **time constant**:

```text
τ = R × C              (tau; seconds, with R in ohms and C in farads)

charging:     Vc(t) = Vs × (1 − e^(−t/τ))
discharging:  Vc(t) = V0 × e^(−t/τ)
```

| Time | Charged to | (Discharged to) |
|---|---|---|
| 1τ | 63 % | 37 % |
| 2τ | 86 % | 14 % |
| 3τ | 95 % | 5 % |
| 5τ | over 99 % | under 1 % |

The rule of thumb: **a capacitor is "fully" charged or discharged after about 5τ.** The unit shortcut from chapter 6
helps: **kΩ × µF = ms**. 10 kΩ and 100 µF give `τ = 1000 ms = 1 s`.

```bash
node examples/rc.mjs 10k 100uF 5
```

```text
Time constant τ = R × C:  1 s
Low-pass cut-off 1/(2πRC): 159 mHz

time      charged to
0.5τ  500 ms     39.3 %  = 1.97 V
1τ    1 s        63.2 %  = 3.16 V
2τ    2 s        86.5 %  = 4.32 V
3τ    3 s        95.0 %  = 4.75 V
4τ    4 s        98.2 %  = 4.91 V
5τ    5 s        99.3 %  = 4.97 V
```

RC timing is how simple delays, button debouncing (chapter 19) and the classic 555 timer work. It's also why a
microcontroller's analog input wants a low source resistance (chapter 10): its internal sampling capacitor must charge
through the source's resistance in a few microseconds.

### Inrush current

The first instant of charging has a current of `Vs / R`. With no resistor, only the wire's and the supply's
resistance limit it: plugging a big capacitor bank into a battery makes a spark and a large current spike, which can
weld connector contacts, trip a supply's protection or reset a board. Large capacitor banks use a small "pre-charge"
resistor for this reason.

## Energy stored

```text
E = ½ × C × V²     joules
```

A 100 µF capacitor at 5 V stores `0.5 × 100 × 10⁻⁶ × 25 = 1.25 mJ`: harmless. A 470 µF capacitor at 400 V inside a
power supply stores `0.5 × 470 × 10⁻⁶ × 160 000 ≈ 38 J`, delivered in microseconds at a lethal voltage. That's why
chapter 13 says never to open mains equipment, even unplugged.

## Capacitors in parallel and series

The rules are the reverse of resistors':

- **In parallel**, capacitances **add**: `C = C1 + C2 + …`. It's like making the plates bigger. All share the same
  voltage; the lowest voltage rating applies.
- **In series**, they combine like resistors in parallel: `1/C = 1/C1 + 1/C2 + …`. It's like making the gap
  wider. Two equal capacitors in series have half the capacitance; the voltage is shared between them.

You'll mostly use parallel: a 100 nF ceramic next to a 10 µF electrolytic on the same supply rail, each doing a
different job (below).

## Types of capacitor

| Type | Range | Polarised? | Strengths | Weaknesses | Use |
|---|---|---|---|---|---|
| Ceramic (MLCC, and discs) | 1 pF – 100 µF | No | Tiny, cheap, very low ESR, fast | Capacitance drops with DC voltage and temperature for high-value types | Decoupling, filtering, timing |
| Aluminium electrolytic | 0.1 µF – 100 000 µF | **Yes** | Large capacitance, cheap | Bigger, higher ESR, wear out (dry out) over years, especially when hot | Bulk storage on supply rails, smoothing |
| Tantalum | 0.1 – 1000 µF | **Yes** | Compact, stable | Fail short-circuit, sometimes in flames, if over-voltaged or reversed | Compact bulk decoupling |
| Film (polyester, polypropylene) | 1 nF – 100 µF | No | Precise, stable, low loss | Large for their value | Audio, timing, mains filters |
| Supercapacitor | 0.1 – 3000 F | Yes | Enormous capacitance | Low voltage (2.7 V per cell), high self-discharge | Backup power, energy harvesting |

### Voltage ratings

Every capacitor has a maximum voltage. Exceed it and the dielectric breaks down: ceramics crack or short,
electrolytics vent (they have scored tops so they split open rather than burst), tantalums can burn. Choose a rating
of **at least 1.5–2 times** the highest voltage the capacitor will see. On a 12 V rail, use 25 V parts; on 5 V, 10 V
or 16 V parts.

High-value ceramic capacitors have a quirk worth knowing: their capacitance **drops with the DC voltage across them**
(the "DC bias" effect). A 10 µF, 6.3 V ceramic at 5 V may only be 3–4 µF. Using a higher voltage rating or a larger
package reduces the effect; datasheets give curves.

### Polarity

Electrolytic and tantalum capacitors are **polarised**: their `+` lead must be at a higher voltage than their `−`
lead. Reversed, they conduct, heat, and can vent or burst within seconds.

- Through-hole electrolytics: the **longer lead is `+`**, and a stripe with `−` signs marks the **negative** lead.
- SMD electrolytics: the bevelled corners or a marked half of the base indicate polarity; check the board's marking.
- Tantalum chips: the stripe marks the **positive** end — the opposite of electrolytics. Read the datasheet.

### ESR

Real capacitors have a small **equivalent series resistance** (ESR) and inductance in their leads and internal
structure. ESR limits how fast they can deliver current and makes them heat up when large ripple currents pass
through them. Ceramics have very low ESR; electrolytics have more. It's one reason designs combine both.

## Decoupling: the capacitor next to every chip

Look at any circuit board and you'll find a small ceramic capacitor, usually 100 nF, right next to every chip's power
pins. Why?

A digital chip draws current in sharp spikes, every time its transistors switch, millions of times a second. The
supply is centimetres away through wires and tracks that have resistance and inductance (chapter 16), which resist
sudden changes of current. Without help, each spike briefly drags the chip's supply voltage down — a glitch that can
make the chip misbehave or radiate noise into everything else.

A capacitor right at the chip's pins acts as a **local reservoir**: it supplies the spike's charge over a path of
millimetres, then quietly recharges from the supply between spikes. The supply sees a smooth average current.

```text
   long supply wires                     short path
   (resistance, inductance)              ←──→
   +5V ═══════════════════════════●──────┬──── VCC pin
                                  │      │
                              [10 µF]  [100 nF]     ┌──────┐
                              bulk     decoupling   │ chip │
                                  │      │          └──────┘
   GND ═══════════════════════════●──────┴──── GND pin
```

The standard recipe:

- **100 nF ceramic** at every chip's supply pin (each pair of supply pins, for larger chips), as close as possible.
- **A bulk capacitor**, 10–100 µF electrolytic or ceramic, where power enters a board or module, to cover larger,
  slower demands.
- **Larger bulk capacitors** (100–1000 µF) near loads that draw big surges: motors, servos, LED strips, radio modules.
  LED strip manufacturers recommend about 1000 µF across the strip's power input.

Arduino boards already have decoupling capacitors for their own chips. Breakout modules usually do too. Bare chips
on your breadboard need you to add them.

## Smoothing and filtering

A resistor and capacitor together form a **low-pass filter**: it lets slow changes through and smooths out fast
ones.

```text
   Vin ──[ R ]──●── Vout
                │
               ═╪═ C
                │
               GND

   cut-off frequency  f_c = 1 / (2π × R × C)
```

Signals much slower than `f_c` pass almost unchanged; faster ones are smoothed away (at `f_c` itself the output is
reduced to about 71 %). With 10 kΩ and 100 nF, `f_c ≈ 159 Hz`. Uses you'll meet:

- **Cleaning up a noisy sensor**: a 100 nF capacitor on an analog input, with the sensor's divider resistance as R,
  removes electrical noise from long wires.
- **Turning PWM into a steady voltage** (chapter 19): a microcontroller's 490 Hz PWM through a filter with
  `f_c ≈ 10 Hz` gives an almost steady DC equal to the average.
- **Smoothing a rectifier's output** (chapter 17): a large capacitor after the diodes fills in the dips of the
  rectified AC.

> **Just a taste — you don't need to learn or fully understand this now.** For AC signals, a capacitor's opposition
> to current is called reactance, `X_C = 1 / (2π × f × C)` ohms: high at low frequencies, low at high frequencies.
> It's the basis of all filter design in analog electronics.

## Capacitors in the Plant Station

| Where | Part | Why |
|---|---|---|
| Across the 12 V input, near the screw terminal | 470 µF, 25 V electrolytic | Bulk reservoir for the pump's starting surge; keeps the 12 V rail from dipping |
| The board's `VIN` input and its 5 V regulator | Already on the board | Regulators need input and output capacitors to be stable; modules and boards include them |
| Moisture-sensor analog input to GND | 100 nF ceramic | Filters noise picked up on the 1.5 m probe wires |
| Across the pump's terminals | 100 nF ceramic | Suppresses electrical noise from the motor's brushes (chapter 16) |
| Microcontroller supply | Already on the board | Decoupling |

Check the moisture filter's time constant: the divider's Thévenin resistance is at most the 22 kΩ fixed resistor in
parallel with the probe, so at most about 20 kΩ; with 100 nF, `τ ≈ 2 ms`. The program must wait about 5τ ≈ 10 ms after
powering the probe before reading. That's why chapter 10's probe was powered for 10 ms.

## Key takeaways

- A capacitor stores charge on two plates separated by an insulator, holding energy in the electric field. `Q = C × V`;
  1 F = 1 C/V; practical values are pF to mF.
- `I = C × ΔV/Δt`: current flows only while the voltage changes. A capacitor blocks DC, passes changes, and its voltage
  can't jump.
- Through a resistor it charges and discharges exponentially with time constant `τ = R × C` (kΩ × µF = ms): 63 % at
  1τ, about 99 % at 5τ.
- Stored energy is `½ C V²`. Large high-voltage capacitors are dangerous even when unplugged.
- In parallel capacitances add; in series they combine like parallel resistors.
- Respect voltage ratings (1.5–2× margin) and polarity (electrolytic stripe = `−`; tantalum stripe = `+`).
- Decouple every chip with 100 nF at its pins; add bulk capacitance where power enters and near surge loads.
- An RC low-pass filter smooths signals faster than `f_c = 1 / (2πRC)`.

## Exercises

1. **Charge.** How much charge does a 47 µF capacitor hold at 9 V? How much energy?

<details>
<summary>Solution</summary>

```text
Q = C × V = 47 × 10⁻⁶ × 9 ≈ 423 µC
E = ½ × C × V² = 0.5 × 47 × 10⁻⁶ × 81 ≈ 1.9 mJ
```

</details>

2. **Time constant.** A 4.7 kΩ resistor charges a 220 µF capacitor from 5 V. What's τ? How long until the capacitor
   reaches about 4.75 V? About 5 V?

<details>
<summary>Solution</summary>

```text
τ = 4.7 kΩ × 220 µF = 1034 ms ≈ 1.03 s       (kΩ × µF = ms)
4.75 V is 95 %  → 3τ ≈ 3.1 s
"5 V" (99 %+)   → 5τ ≈ 5.2 s
```

</details>

3. **Surge support.** A servo draws an extra 1.5 A for 5 ms when it starts. The 5 V supply's wires are too long to
   respond that fast, so a capacitor must supply the surge with no more than a 0.3 V dip. What capacitance is needed?

<details>
<summary>Solution</summary>

```text
C = I × Δt / ΔV = 1.5 × 0.005 / 0.3 = 0.025 F = 25 000 µF
```

That's a very large capacitor, which tells you the real fix is better wiring (thicker, shorter, a supply close to
the servo) with a sensible 470–1000 µF capacitor to cover the fastest part of the surge. The calculation shows when a
capacitor alone can't solve a problem.

</details>

4. **Combinations.** What's the total capacitance of (a) 10 µF and 100 nF in parallel, (b) two 10 µF in series, (c)
   three 1 µF in parallel? If (b) is placed across 20 V, what voltage is across each capacitor?

<details>
<summary>Solution</summary>

(a) `10 µF + 0.1 µF = 10.1 µF`. (b) `10 / 2 = 5 µF`. (c) `3 µF`. In (b) the two equal capacitors share the voltage
equally: 10 V each (so each needs a rating of at least 16 V, better 25 V).

</details>

5. **Rating and polarity.** You're adding a bulk capacitor across a 12 V input. You have: 1000 µF 10 V, 470 µF 16 V,
   470 µF 25 V and 220 µF 35 V electrolytics. Which do you use, and how do you connect it?

<details>
<summary>Solution</summary>

470 µF 25 V: about 2× the working voltage. The 10 V part would fail; 16 V gives only 1.33× margin, too little for a
supply that can carry spikes. The 220 µF 35 V would also work but stores less charge. Connect its long lead (`+`) to
12 V and the striped lead (`−`) to GND.

</details>

6. **PWM filter.** A microcontroller outputs a 490 Hz PWM signal. You want a steady DC level from it. Choose R and C
   for a cut-off frequency of about 5 Hz, using a 10 kΩ resistor. What's the trade-off of choosing an even lower
   cut-off?

<details>
<summary>Solution</summary>

```text
C = 1 / (2π × f_c × R) = 1 / (2π × 5 × 10 000) ≈ 3.2 µF   → use 3.3 µF
```

The ripple at 490 Hz is then about a hundredth of the PWM swing. A lower cut-off gives an even smoother output but
responds more slowly when you change the duty cycle: τ = 33 ms here, so a new level settles in about 5τ ≈ 165 ms.
Halving `f_c` doubles that settling time.

</details>

---
← Previous: [Measuring with a multimeter](14-measuring-with-a-multimeter.md) | Next: [Magnetism, inductors, relays and motors](16-magnetism-inductors-relays-and-motors.md) →
