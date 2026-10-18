# Chapter 13 — AC, mains and electrical safety

## What you'll learn

- What alternating current is: frequency, period, peak, peak-to-peak and RMS
- Why "230 V" mains actually peaks at about 325 V
- Why the power grid uses AC, and how transformers change voltage
- How mains is wired: live, neutral and earth, fuses, breakers and residual-current devices
- How a wall adapter turns mains AC into safe low-voltage DC
- What electric current does to the human body, and why current, not voltage, is what harms you
- Which voltages are considered safe, and the hazards that remain at low voltage
- The safety rules for Arduino work

## Alternating current

Direct current flows one way. **Alternating current** reverses direction periodically, because the voltage driving
it swings between positive and negative. Mains electricity is a **sine wave**:

```text
   voltage
   +325 V ┤        ****                          ****
          │      **    **                      **    **
          │     *        *                    *        *
      0 V ┼────*──────────*──────────────────*──────────*────────► time
          │                *                *
          │                 **            **
   −325 V ┤                   ****    ****
          │◄────────── one period: 20 ms at 50 Hz ──────►│
```

| Term | Meaning | Mains example (Europe) |
|---|---|---|
| Frequency `f` | Cycles per second, in hertz (Hz) | 50 Hz |
| Period `T` | Time for one cycle: `T = 1 / f` | 20 ms |
| Peak voltage `V_peak` | The highest point of the wave | about 325 V |
| Peak-to-peak `V_pp` | From the lowest to the highest point: `2 × V_peak` | about 650 V |
| RMS voltage `V_rms` | The "effective" voltage (below) | 230 V |

North America uses 120 V at 60 Hz (period 16.7 ms, peak about 170 V). Japan uses 100 V at 50 Hz in the east and 60 Hz
in the west. Most of the rest of the world uses 220–240 V at 50 Hz.

### RMS: the voltage that matters for power

The voltage of a sine wave changes all the time, so what does "230 V" mean? It's the **RMS** (root-mean-square)
value: the DC voltage that would deliver the **same heating power** into a resistor. A kettle on 230 V AC RMS gets
just as hot as it would on 230 V DC.

For a sine wave:

```text
V_rms = V_peak / √2 ≈ 0.707 × V_peak
V_peak = V_rms × √2 ≈ 1.414 × V_rms

230 V RMS → 325 V peak
120 V RMS → 170 V peak
```

Every mains voltage you see quoted (on sockets, appliances, adapter labels) is RMS. Insulation, though, must withstand
the **peak**, and the capacitors inside a power supply charge to the peak: the inside of a "230 V" device holds about
325 V DC (more on that below). The `P = V × I` and Ohm's law calculations work with RMS values for resistive loads.

The √2 relationship holds only for pure sine waves. For other waveforms (PWM, square waves, chapter 19) the ratio is
different, and cheap multimeters that assume a sine wave show wrong AC readings for them. "True RMS" meters measure
correctly.

## Why the grid uses AC

In the 1880s Edison promoted DC distribution, and Westinghouse and Tesla promoted AC. AC won for one reason: with AC,
the **voltage can be changed efficiently with a transformer**, and changing voltage is how you move power over
distance (chapter 11).

Power station to your home, roughly:

```text
  generator      step-up         transmission           step-down        street        your home
  ~20 kV   ──►  transformer ──►  110–765 kV  ──────►   transformers ──►  10–20 kV ──► 230 / 120 V
                                 (low current,
                                  low I²R loss over
                                  hundreds of km)
```

Raising the voltage a thousandfold lowers the current a thousandfold for the same power, and the `I²R` losses in the
lines a millionfold. Then transformers near your home bring it back down to a voltage that's safer to use. (Today,
very long links sometimes use high-voltage DC, because modern semiconductor converters can now change DC voltages
efficiently. The principle — high voltage for distance — is the same.)

### Transformers

A **transformer** is two coils of wire wound on a shared iron core. AC in the first coil (the primary) creates a
changing magnetic field in the core, which induces a voltage in the second coil (the secondary). Chapter 16 explains
the magnetism. The voltage ratio equals the ratio of turns:

```text
V_secondary / V_primary = N_secondary / N_primary

230 V primary, 1000 turns; secondary 52 turns  →  230 × 52 / 1000 ≈ 12 V
```

Power is (nearly) conserved, so stepping the voltage down steps the current up by the same ratio. Transformers only
work with **changing** current, so they do nothing with DC.

A transformer's two coils are not connected electrically: the energy crosses through the magnetic field. This
**galvanic isolation** is what makes a properly built adapter safe: its low-voltage output has no direct connection to
the mains.

## How mains is wired

A typical socket circuit has three conductors:

| Conductor | IEC / Europe colour | North America colour | Role |
|---|---|---|---|
| **Live** (line, phase, hot) | Brown | Black (or red) | Carries the alternating voltage relative to earth |
| **Neutral** | Blue | White | The return path; connected to earth at the transformer or the building's supply |
| **Protective earth** (ground) | Green/yellow | Green or bare copper | Connects metal cases to earth; carries current only during a fault |

The live wire is the dangerous one: it is at 230 V (or 120 V) RMS relative to the neutral **and relative to the earth
you stand on**. Touch the live wire while any part of your body touches something earthed (a tap, a radiator, a damp
floor, the case of another appliance) and current flows through you.

Protection devices in the consumer unit (fuse box):

- **Fuses and circuit breakers** protect the **wiring** from overload and short circuits: they trip at currents like
  10, 16 or 32 A, set by the wire size (chapter 11). They do **not** protect people: tens of milliamps can kill, and
  a 16 A breaker won't notice them.
- **Residual-current devices** (RCD, RCCB or RCBO; **GFCI** or **GFI** in North America) protect **people**. They
  compare the current in the live wire with the current returning in the neutral. If the two differ by more than about
  30 mA (5 mA for North American GFCIs), the missing current must be going somewhere else — possibly through a person
  — and the device disconnects within about 30 milliseconds.
- **Protective earth** makes sure that if a live wire touches a metal case, a large fault current flows to earth and
  blows the fuse or trips the breaker, instead of leaving the case live for the next person to touch.

## From the socket to your Arduino

Inside a modern wall adapter (a **switch-mode power supply**):

```text
  230 V AC ─► rectifier ─► ~325 V DC ─► high-frequency ─► small ─► rectifier ─► regulated
   (mains)    (diodes,      (big         switch            transformer      and filter   low-voltage DC
              chapter 17)   capacitor)   (~100 kHz)        (isolation!)                  e.g. 5 V or 12 V
                                                                      ▲
                                                 feedback that holds the output voltage steady
```

The mains is rectified to DC (about 325 V), switched at high frequency, and passed through a small transformer that
both steps the voltage down and provides isolation. Switching at a high frequency is why modern adapters are small
and light: transformer size shrinks as frequency rises. Old adapters used a big 50/60 Hz transformer followed by a
rectifier and capacitor, which is why they were heavy.

The important point for you: **all the dangerous parts are inside a certified, sealed box**, and the output is
isolated, low-voltage DC. Use a certified adapter (CE/UKCA, UL, or your region's marks from a reputable seller) and
never open one while plugged in. Its large input capacitor can stay charged at over 300 V for minutes after unplugging.

## What current does to the body

The body is a conductor (it's mostly salt water), and the nerves and heart are controlled by tiny electrical signals.
An external current that passes through the body interferes with them and heats tissue. **The damage depends on the
current through the body, the path it takes, and how long it flows.**

Approximate effects of 50/60 Hz AC from hand to hand or hand to foot, for an adult, from the IEC 60479 safety
standard and similar sources:

| Current through the body | Typical effect |
|---|---|
| 0.5–1 mA | Threshold of perception: a tingle |
| 1–5 mA | Painful, but you can let go |
| about 5–10 mA | "Let-go" threshold: muscles contract and you **may not be able to release your grip** |
| 10–30 mA | Severe muscle contraction, difficulty breathing |
| about 30 mA | RCDs are set here: the limit before serious danger for short exposures |
| 50–100 mA and above, for about a second | **Ventricular fibrillation** possible: the heart stops pumping. Often fatal without immediate defibrillation |
| Above 1 A | Severe burns, heart and breathing stop |

The current that flows is set by Ohm's law and the body's resistance, mostly the skin's:

| Condition | Approximate body resistance |
|---|---|
| Dry skin, light contact | 100 kΩ and more |
| Firm grip, normal skin | a few kΩ to 10 kΩ |
| Wet or broken skin, or sweaty | about 1 kΩ |
| Internal (once past the skin) | about 500 Ω |

```text
230 V across 1 kΩ (wet hands, firm contact):   I = 230 / 1000 = 230 mA   → likely fatal
230 V across 100 kΩ (dry, light touch):        I = 2.3 mA                 → painful
12 V across 1 kΩ (wet):                        I = 12 mA                  → unpleasant, rarely felt this way
5 V across 10 kΩ:                              I = 0.5 mA                 → not felt at all
```

Higher voltage also breaks down the skin's resistance, so the current at mains voltages tends to be higher than
these simple estimates. That's why the ranges overlap and why **mains voltage is always treated as lethal**.

### Safe and unsafe voltages

Electrical safety standards define **extra-low voltage** (ELV) as no more than about 50 V AC RMS or 120 V ripple-free
DC; the "touch-safe" figures used for most consumer products are lower still. Under dry conditions such voltages can't
drive a dangerous current through intact skin. Every Arduino project, USB device and 12 V adapter output sits well
inside this range. You can touch a 5 V or 12 V circuit with your fingers; you'll feel nothing.

Low voltage is not the same as no hazard, though:

- **High current causes burns and fires.** A 12 V car battery or a lithium pack can deliver hundreds of amps into a
  short. A ring on your finger or a wristwatch strap that bridges the terminals can heat red-hot in a second. Take
  rings off when working on large batteries.
- **Lithium batteries can catch fire** if shorted, punctured, overcharged or over-discharged (chapter 20).
- **Inductive kickback** from coils can generate hundreds of volts briefly from a 12 V supply (chapter 16). Usually
  only a sting, but it destroys transistors.
- **Large capacitors** in power supplies, camera flashes and microwave ovens store dangerous charge at high voltage
  even when unplugged.

## Safety rules for this tutorial and for Arduino work

1. **Don't work on mains as a learning project.** Arduino projects don't need it. If you must control a mains device,
   use a certified, enclosed product (a smart plug, or a commercially built relay module in a proper enclosure that a
   qualified person has wired) rather than wiring mains on a breadboard. Bare relay boards with exposed mains screw
   terminals next to low-voltage pins are a common source of shocks.
2. **Power your projects from certified low-voltage adapters, USB or batteries.** The mains stays inside the adapter.
3. **Never open an adapter, a power supply, a microwave or a CRT**, even unplugged.
4. **Disconnect power before changing wiring.** It also protects your circuit (chapter 7).
5. **Respect batteries.** No shorts, no rings near big batteries, no damaged or swollen lithium cells, charge only
   with the right charger (chapter 20).
6. **Use fuses** on anything powered by a battery or supply that can deliver more current than your wiring can carry
   (chapter 20).
7. **If someone is being shocked by mains, don't touch them.** Switch off the power at the switch, plug or consumer
   unit first, or push them clear with something dry and non-conductive. Call emergency services; electric shock can
   cause heart rhythm problems that appear later.

## Key takeaways

- AC reverses direction periodically. Mains is a sine wave at 50 Hz (230 V) or 60 Hz (120 V). Period `T = 1 / f`.
- Quoted AC voltages are RMS, the DC-equivalent for heating. For a sine wave `V_peak = √2 × V_rms`: 230 V mains peaks
  at about 325 V.
- The grid uses AC because transformers change voltage easily (`V2/V1 = N2/N1`), and high voltage makes long-distance
  transmission efficient. Transformers also provide isolation.
- Live is dangerous relative to neutral *and to earth*. Breakers protect wires; RCDs (GFCIs) protect people by
  detecting leakage of about 30 mA (5 mA for GFCIs).
- Current through the body does the harm: about 10 mA can stop you letting go; 50–100 mA through the chest can stop
  the heart. Mains can drive such currents; extra-low voltages (≤ about 50 V AC) can't, in dry conditions.
- Low voltage still means high-current, battery-fire and capacitor hazards.
- Keep mains inside certified boxes; build projects on low-voltage DC.

## Exercises

1. **Period and peak.** For 120 V, 60 Hz mains, calculate the period, the peak voltage and the peak-to-peak voltage.

<details>
<summary>Solution</summary>

```text
T     = 1 / 60 ≈ 16.7 ms
V_peak = 120 × 1.414 ≈ 170 V
V_pp   = 2 × 170 = 340 V
```

</details>

2. **RMS and power.** A 1150 W kettle runs on 230 V RMS. What RMS current does it draw, and what is its resistance?
   What is the peak current?

<details>
<summary>Solution</summary>

```text
I_rms  = P / V = 1150 / 230 = 5 A
R      = V / I = 230 / 5 = 46 Ω
I_peak = 5 × 1.414 ≈ 7.1 A
```

</details>

3. **Transformer.** A transformer has 2300 turns on its primary and 120 on its secondary. What secondary voltage do
   you get from 230 V mains? If the secondary supplies 2 A, roughly what current does the primary draw?

<details>
<summary>Solution</summary>

```text
V_secondary = 230 × 120 / 2300 = 12 V (RMS)
power ≈ 12 × 2 = 24 W, so I_primary ≈ 24 / 230 ≈ 0.10 A  (a little more in practice, because of losses)
```

</details>

4. **Body current.** Estimate the current through a person who grips a 24 V DC supply's two terminals with sweaty
   hands (about 1 kΩ), and through a person who touches 230 V live with a wet hand while standing barefoot on a wet
   floor (about 1 kΩ). Use the table to describe the likely effect of each.

<details>
<summary>Solution</summary>

```text
24 V:  I = 24 / 1000 = 24 mA      → in practice usually much less (skin breakdown doesn't occur at 24 V, and
                                    such firm, wet, hand-to-hand contact is unusual); at worst unpleasant
230 V: I = 230 / 1000 = 230 mA    → enough to cause ventricular fibrillation within a fraction of a second
```

An RCD should trip in the 230 V case within about 30 ms, because the current returns through earth, not the neutral.
That fraction of a second is often the difference between a shock and a death, which is why RCDs are required on
socket circuits in most countries.

</details>

5. **Why the breaker didn't trip.** A person receives a serious shock from a faulty lamp on a circuit protected by a
   16 A breaker but no RCD. Why didn't the breaker trip? What would an RCD have done differently?

<details>
<summary>Solution</summary>

The breaker trips on *total* current above 16 A (and quickly only at many times that). A shock current of, say,
100 mA is less than 1 % of 16 A, invisible to it. An RCD measures the *difference* between live and neutral
currents; 100 mA flowing through the person to earth never returns through the neutral, so the RCD sees a 100 mA
imbalance (well above its 30 mA threshold) and disconnects in tens of milliseconds.

</details>

---
← Previous: [Batteries and power supplies](12-batteries-and-power-supplies.md) | Next: [Measuring with a multimeter](14-measuring-with-a-multimeter.md) →
