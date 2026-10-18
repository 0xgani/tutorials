# Chapter 18 — Transistors as switches

## What you'll learn

- Why a microcontroller pin can't drive a motor, a relay or a 12 V load directly
- What a transistor is: a switch controlled by a small signal
- How an NPN bipolar transistor (BJT) works, and how to calculate its base resistor so it switches fully on
- How an N-channel MOSFET works, and what "logic-level" means on its datasheet
- Gate resistors, pull-down resistors, and why a MOSFET needs both
- Low-side vs. high-side switching
- How much heat a transistor switch makes, and when it needs a heatsink
- Ready-made driver chips and modules
- Switching the Plant Station's pump with a MOSFET

## Why you need a transistor

A microcontroller pin is a weak, low-voltage source:

| | Arduino Uno R3 pin | Arduino Uno R4 pin | Plant Station pump |
|---|---|---|---|
| Voltage | 0 or 5 V | 0 or 5 V | needs 12 V |
| Current | 20 mA recommended, 40 mA absolute max | 8 mA | 0.4 A running, 1.2 A starting |

The pump needs sixty times more current than an R3 pin should give, at more than twice its voltage, and it's an
inductive load whose kickback (chapter 16) would destroy the pin. The same is true of relays, solenoids, motors,
LED strips and most heaters.

The solution is a **transistor**: a semiconductor switch in which a small current or voltage at one terminal controls
a much larger current between the other two. The pin only has to operate the switch; the load's power comes from its
own supply.

```text
   load supply (+12 V) ──[ load ]──┐
                                   │   large current, from the load's own supply
                              ┌────┴────┐
   pin ────────────────────►  │transistor│  small control signal
                              └────┬────┘
   GND (shared!) ──────────────────┘
```

Transistors can also amplify (that's how they were first used), but for Arduino work you'll almost always use them as
on/off switches, which keeps the design rules simple.

## The bipolar junction transistor (BJT)

A **BJT** is a sandwich of three doped layers: NPN or PNP. The **NPN** type is the one you'll use most:

```text
              collector (C)
                  │
                  │
   base (B) ──┤  ╱           NPN symbol: the arrow on the emitter points OUT
              │ ╱              ("Not Pointing iN")
              │╲
              │ ╲►
                  │
              emitter (E)
```

The rule: **a small current into the base allows a much larger current to flow from collector to emitter.**

```text
I_C = β × I_B        in the "active" region

β (or h_FE) = current gain, typically 100–300 for small transistors
```

The base–emitter junction is a diode (chapter 17), so the base sits about **0.7 V** above the emitter when current
flows into it.

### Switching: drive it into saturation

To use a BJT as a switch, you don't want it partly on. You give the base **more current than the load needs** divided
by β, so the transistor turns fully on (**saturation**). Then the collector–emitter voltage drops to a small
`V_CE(sat)`, typically 0.1–0.3 V, and the load gets almost the whole supply.

The standard design rule is to assume a "forced" gain of **10–20** instead of the datasheet's β, guaranteeing
saturation despite part-to-part variation and temperature:

```text
I_B = I_C / 10   (to /20)
R_B = (V_pin − 0.7 V) / I_B
```

**Worked example: a relay.** A 5 V relay coil draws 70 mA. Drive it with a 2N2222 (or PN2222, rated 600 mA) from a
5 V pin:

```text
   +5V ─────────┬────────────┐
                │            │
             [relay        ─┴─  flyback diode, cathode to +5 V
              coil 70 mA]   ▲
                │            │
                ●────────────┘
                │ C
   pin ──[ R_B ]─┤ 2N2222
                │ E
   GND ─────────┴── (shared with the board)

   I_B = 70 mA / 20 = 3.5 mA          (forced gain 20, within an Uno R4's 8 mA)
   R_B = (5 − 0.7) / 3.5 mA ≈ 1.23 kΩ → 1.2 kΩ (≈ 3.6 mA)
```

With forced gain 10 (7 mA, `R_B` = 560–620 Ω) it would be even more firmly on, but too close to an Uno R4 pin's limit.
Check the 2N2222's datasheet: its `V_CE(sat)` is specified with a base current of `I_C / 10` and is very low at 70 mA;
at a forced gain of 20 it's still well saturated at this small current.

Transistor heat when on: `P = V_CE(sat) × I_C ≈ 0.2 V × 70 mA = 14 mW`. Nothing.

Common small NPN transistors: **2N2222 / PN2222** (600 mA), **2N3904** (200 mA), **BC547** (100 mA, European). Note
that pin orders (E-B-C or C-B-E) differ between parts and packages; check the datasheet's pinout before inserting
one.

BJTs are simple and cheap, but the base current is a load on the pin, and their `V_CE(sat)` loses power at high
currents (0.3 V × 3 A ≈ 1 W). For loads above a few hundred milliamps, MOSFETs are better.

## The MOSFET

A **MOSFET** (metal-oxide-semiconductor field-effect transistor) has three terminals, **gate**, **drain** and
**source**. The **N-channel** type is the common low-side switch:

```text
              drain (D)
                 │
            ┃────┘
   gate ──┃ ┃◄──┐          N-channel MOSFET symbol (simplified)
   (G)      ┃────┤
                 │
              source (S)
```

The key difference from a BJT: the gate is **insulated** from the channel by a thin oxide layer. It draws **no
steady current**; it's controlled by **voltage**. Put a high enough voltage between gate and source (`V_GS`) and an
electric field opens a conductive channel from drain to source. Remove it and the channel closes.

When fully on, the channel behaves like a small resistor, `R_DS(on)`: often just **tens of milliohms**. The power lost
is `I² × R_DS(on)`, tiny even at amps.

### Reading the datasheet: threshold vs. "logic-level"

This is the most common MOSFET mistake in hobby projects. The datasheet lists two gate voltages:

- **`V_GS(th)`, the threshold voltage**: the gate voltage at which the MOSFET *starts* to conduct, at a tiny current
  such as 250 µA. At the threshold it is **barely on**.
- **`R_DS(on)` at a specified `V_GS`**: the on-resistance when the gate is driven to that voltage. **This is the
  number that matters.** The MOSFET is only fully on at (or above) the `V_GS` where `R_DS(on)` is specified.

A **logic-level** MOSFET has its `R_DS(on)` specified at `V_GS` = 4.5 V or 5 V (or 2.5 V, for 3.3 V logic). A standard
MOSFET specifies it only at 10 V.

| MOSFET | `R_DS(on)` spec | Driven from a 5 V pin | From 3.3 V |
|---|---|---|---|
| IRLZ44N (logic-level, TO-220) | 0.022 Ω at `V_GS` = 5 V | Fully on | Not specified: risky |
| IRLB8721 (logic-level, TO-220) | about 0.009 Ω at 4.5 V | Fully on | Marginal |
| AO3400 (logic-level, tiny SOT-23 SMD) | about 0.03 Ω at 4.5 V; specified at 2.5 V too | Fully on | Works |
| IRF520, IRF540 (standard, in many kits) | specified at 10 V only | **Partly on: drops volts and overheats** | Barely conducts |

The popular "IRF520 MOSFET module" is a trap: from a 5 V pin its `R_DS(on)` may be several ohms, so at 1 A it drops
several volts and gets hot. Use a logic-level part.

### Gate resistor and pull-down

```text
   +12V ──[ load ]──┬────────┐
                    │       ─┴─  flyback diode (inductive loads only)
                    │        ▲
                    ●────────┘
                    │
                  D │
                 ┌──┴──┐
   pin ─[220 Ω]─●┤G    │  N-channel logic-level MOSFET
                │└──┬──┘
                │ S │
           [100 kΩ] │
                │   │
   GND ─────────┴───┴──── (shared with the board)
```

- **Gate resistor (100–470 Ω)**: the gate is a small capacitor (a few nanofarads for power MOSFETs). Each time the pin
  switches, it charges or discharges that capacitor, with a brief current spike that only the pin's own resistance
  would otherwise limit. A small series resistor limits the spike and damps ringing in the wiring.
- **Pull-down resistor (10–100 kΩ, gate to source)**: while the microcontroller boots or is being programmed, its pins
  are inputs, which are **floating** (chapter 19). A floating gate can pick up enough charge to turn the MOSFET partly
  on, running the load, or leaving the MOSFET half-on and overheating. The pull-down holds the gate at 0 V unless the
  pin actively drives it high. It also discharges the gate if a wire comes loose.

Because the gate draws no steady current, a MOSFET puts essentially no static load on the pin, which matters with
the Uno R4's 8 mA limit or a battery-powered board.

## Low-side and high-side switching

So far the switch has been between the load and **ground**: **low-side switching**.

```text
   low-side (N-channel MOSFET or NPN)          high-side (P-channel MOSFET or PNP)

   +V ──[ load ]──┐                            +V ──┐
                  │                                 │
               [switch]  ← controlled              [switch]  ← controlled relative to +V
                  │        relative to GND          │
   GND ───────────┘        (easy from a pin)       [ load ]
                                                    │
                                              GND ──┘
```

Low-side is the easy default: the switch's source (or emitter) sits at ground, the same reference as the
microcontroller's pin, so a 0/5 V pin controls it directly, whatever the load's supply voltage (12 V, 24 V).

The catch: when switched off, the load's "ground" end floats up to the supply voltage. That's fine for motors, pumps,
relays, solenoids and LED strips, which have just two wires. It's a problem for loads that connect to other things
and need a solid ground, such as a sensor module with a data line.

**High-side switching** connects the switch on the supply side, so the load keeps a solid ground. A **P-channel
MOSFET** does this, but its gate must be pulled *below its source* (the supply) to turn on, so with a 12 V supply its
gate must swing between 12 V (off) and about 0–7 V (on), which a 5 V pin can't do alone.

> **Just a taste — you don't need to learn or fully understand this now.** The usual high-side circuit uses a small
> NPN transistor (driven by the pin) to pull a P-channel MOSFET's gate down, with a resistor pulling it up to the
> supply when the NPN is off. A P-channel MOSFET is also the low-loss reverse-polarity protection from chapter 17.

## Transistor heat

A switch that's fully on or fully off makes very little heat: off, no current; fully on, a tiny voltage drop. Heat
comes from being **partly on**, which is why saturation and logic-level gates matter, and from the remaining on-state
loss:

```text
BJT:     P = V_CE(sat) × I_C
MOSFET:  P = I² × R_DS(on)
```

A TO-220 package in open air, without a heatsink, can dissipate about **1–2 W** before it gets too hot to touch.
Small SMD packages (SOT-23) manage only about 0.3–1 W, depending on the copper around them.

| Switch | Load | Heat |
|---|---|---|
| IRLZ44N at 5 V gate, 0.4 A | `0.4² × 0.022` | 3.5 mW |
| IRLZ44N at 5 V gate, 5 A | `5² × 0.022` | 0.55 W: warm |
| IRF520 at 5 V gate (`R_DS` maybe 3 Ω), 1 A | `1² × 3` | 3 W: **too hot** without a heatsink |
| 2N2222 saturated, 0.5 A | `0.4 V × 0.5` | 0.2 W |

Switching it on and off fast (PWM, chapter 19) adds **switching losses**: during each transition the transistor is
briefly partly on. At a few hundred hertz with a gate resistor of a few hundred ohms, they're negligible for hobby
loads; at tens of kilohertz they start to matter, and dedicated gate-driver chips are used.

## Ready-made drivers

You don't always have to build the switch:

- **ULN2003 / ULN2803**: seven or eight Darlington (BJT pair) switches in one chip, each up to about 500 mA, **with
  flyback diodes built in**. Ideal for several relays, small stepper motors or solenoids.
- **MOSFET modules**: a logic-level MOSFET, gate resistor and pull-down on a small board with screw terminals. Check
  which MOSFET it uses.
- **Relay modules**: relay, transistor, flyback diode and often an indicator LED. Some are "active low" (the relay
  turns on when the pin is LOW).

> **Just a taste — you don't need to learn or fully understand this now.** To run a DC motor in **both** directions
> you need four switches in an **H-bridge**. Driver chips such as the DRV8833 or TB6612FNG package an H-bridge with
> protection, controlled by two pins per motor. The old L298N module also works but wastes about 2–4 V as heat.

## Switching the Plant Station's pump

Requirements: 12 V, 0.4 A running, 1.2 A start, inductive; controlled from a 5 V pin on any Uno (R4's 8 mA limit).

| Choice | Value | Reason |
|---|---|---|
| Switch | **IRLZ44N**, low-side | Logic-level: `R_DS(on)` 0.022 Ω at 5 V; 55 V rating far above 12 V; TO-220 is breadboard- and screw-terminal-friendly |
| Gate resistor | **220 Ω** | Limits the gate-charging spike |
| Pull-down | **100 kΩ** gate to source | Pump stays off while the board boots or if the pin floats |
| Flyback diode | **1N5819** across the pump, cathode to +12 V | Clamps kickback (chapter 16); 1 A rating covers the 0.4 A running current it carries at turn-off |
| Noise capacitor | **100 nF** across the pump's terminals | Brush noise (chapter 16) |

```text
   +12V (after the reverse-polarity Schottky) ──┬────────────┬─────────┐
                                                │            │         │
                                             [ pump ]     100 nF     ─┴─ 1N5819
                                                │            │        ▲
                                                ●────────────┴────────┘
                                                │
                                              D │
                                             ┌──┴──┐
   pin D9 ──[ 220 Ω ]──●─────────────────────┤G    │ IRLZ44N
                       │                     └──┬──┘
                       │                      S │
                   [ 100 kΩ ]                   │
                       │                        │
   GND ────────────────┴────────────────────────┴──── board GND (common)
```

Checks:

- On-state heat: `0.4² × 0.022 ≈ 3.5 mW` running, `1.2² × 0.022 ≈ 32 mW` during the start surge. No heatsink needed.
- Pin current: none in steady state; a brief spike at each switch, limited by 220 Ω to under `5 V / 220 Ω ≈ 23 mA`
  for a fraction of a microsecond. Within any board's limits.
- The pump's starting surge (1.2 A) flows in the **12 V–pump–MOSFET–ground loop**, not through the board. Route that
  loop's ground wire straight back to the supply's ground, and connect the board's GND to it at one point (chapter 20).

## Key takeaways

- Microcontroller pins give milliamps at 3.3 or 5 V. Anything bigger, higher-voltage or inductive needs a transistor
  switch powered from the load's own supply, with a common ground.
- **NPN BJT**: base current controls collector current. For switching, saturate it: `I_B = I_C / 10…20`,
  `R_B = (V_pin − 0.7) / I_B`.
- **N-channel MOSFET**: gate voltage controls a low-resistance channel; no steady gate current. Use a **logic-level**
  part whose `R_DS(on)` is specified at your pin voltage; ignore `V_GS(th)` for this purpose.
- Add a gate resistor (100–470 Ω) and a pull-down (10–100 kΩ). Add a flyback diode for inductive loads.
- Low-side switching is the easy default; high-side needs a P-channel MOSFET and a level shift.
- A fully-on switch makes little heat: `V_CE(sat) × I` or `I² × R_DS(on)`. Partly-on switches overheat.
- ULN2003 arrays, MOSFET modules, relay modules and H-bridge chips package all this for you.

## Exercises

1. **Base resistor.** An NPN transistor must switch a 150 mA load from a 3.3 V pin. Using a forced gain of 15,
   calculate `I_B` and `R_B` and pick a standard value. Is the base current acceptable for a pin limited to 12 mA?

<details>
<summary>Solution</summary>

```text
I_B = 150 / 15 = 10 mA
R_B = (3.3 − 0.7) / 10 mA = 260 Ω → 270 Ω (I_B ≈ 9.6 mA)
```

9.6 mA is under 12 mA but uses most of the pin's budget; a logic-level MOSFET (no steady gate current) would be the
better choice. A Darlington or a ULN2003 needs much less base current too.

</details>

2. **Datasheet reading.** A MOSFET datasheet says: `V_GS(th)` = 2.0–4.0 V; `R_DS(on)` = 0.077 Ω at `V_GS` = 10 V. A
   seller calls it suitable for Arduino "because the threshold is below 5 V". Are they right?

<details>
<summary>Solution</summary>

No. The threshold is where it *starts* to conduct (at a tiny current), and some parts reach 4.0 V before even that.
The only guaranteed on-resistance is at 10 V. At 5 V gate drive it may be partly on, with an on-resistance many times
higher, so at amps of load current it would drop volts and overheat. Choose a part with `R_DS(on)` specified at 4.5 V
or lower (logic-level).

</details>

3. **Heat.** Compare the heat in (a) an IRLZ44N (0.022 Ω) and (b) a TIP120 Darlington (`V_CE(sat)` about 2 V at this
   current) switching a 12 V, 3 A LED strip. Which needs a heatsink?

<details>
<summary>Solution</summary>

```text
(a) P = 3² × 0.022 ≈ 0.2 W      no heatsink needed
(b) P = 2 V × 3 A = 6 W          needs a substantial heatsink, and the strip loses 2 V
```

This is why the TIP120, popular in old tutorials, has been replaced by logic-level MOSFETs.

</details>

4. **Missing pull-down.** A project's 12 V pump switches on for a moment every time the Arduino is reset or a new
   program is uploaded. The MOSFET circuit has a gate resistor but nothing else. Explain and fix.

<details>
<summary>Solution</summary>

During reset and upload, the microcontroller's pins are inputs (high impedance), so the gate is floating. Its charge
and stray coupling can lift it above the threshold, turning the MOSFET on. Add a pull-down resistor (10–100 kΩ) from
gate to source (ground), so the gate sits at 0 V whenever the pin isn't driving it.

</details>

5. **Spot the faults.** Someone switches a 12 V solenoid valve (0.5 A) with an IRF520 module from an Arduino pin.
   The board and the valve's 12 V supply aren't connected anywhere, and there's no diode across the valve. The valve
   doesn't open reliably, and after a few days the MOSFET fails. List the faults and fixes.

<details>
<summary>Solution</summary>

1. **No common ground**: the gate voltage has no defined reference relative to the MOSFET's source (the 12 V supply's
   ground), so the switch can't be controlled reliably. Connect the board's GND to the 12 V supply's ground.
2. **IRF520 isn't logic-level**: at 5 V gate drive it's only partly on, dropping volts (the valve may not open) and
   heating up. Use a logic-level MOSFET (IRLZ44N, or an AO3400 on a small board).
3. **No flyback diode**: every turn-off of the solenoid sends a high-voltage spike into the MOSFET, which eventually
   destroys it. Add a 1N4007 or 1N5819 across the valve, cathode to +12 V.

Also add a gate pull-down if the module lacks one.

</details>

---
← Previous: [Diodes and LEDs](17-diodes-and-leds.md) | Next: [Digital signals, logic levels and PWM](19-digital-signals-logic-levels-and-pwm.md) →
