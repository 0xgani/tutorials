# Chapter 17 — Diodes and LEDs

## What you'll learn

- How doping turns silicon into N-type and P-type semiconductors
- What happens at a PN junction, and why a diode conducts only one way
- A diode's forward voltage, its current–voltage curve, and its ratings
- The diode types you'll use: rectifier, signal, Schottky, Zener and LED
- How diodes turn AC into DC (rectifiers) and why a capacitor follows them
- How LEDs make light, their colours and forward voltages, and how to drive them properly
- Why an LED must never be driven from a voltage without current limiting
- Reverse-polarity protection, and combining two power sources with diodes
- The Plant Station's diodes

## Semiconductors and doping

Chapter 1 said pure silicon conducts poorly: each silicon atom shares its four outer electrons with four neighbours
in a tight crystal, leaving very few free to move. **Doping** adds a tiny amount (parts per million) of another
element to change that:

- **N-type**: add an element with **five** outer electrons, such as phosphorus. Four bond with silicon; the fifth is
  free to move. N-type silicon has free **electrons** (negative charges).
- **P-type**: add an element with **three** outer electrons, such as boron. One bond is left incomplete: a missing
  electron, called a **hole**. A neighbouring electron can hop into the hole, which moves the hole the other way.
  Holes behave like free **positive** charges.

Both types are still electrically neutral overall; they just have different kinds of mobile charge. The magic
happens where they meet.

## The PN junction

Put P-type and N-type silicon together. Near the boundary, free electrons from the N side fill holes on the P side.
That leaves a thin **depletion region** with no free charges at all, and an internal electric field across it that
stops further crossing. It acts like a barrier with a "height" of about **0.6–0.7 V** in silicon.

```text
       P side (holes)        depletion      N side (free electrons)
   ┌────────────────────┬───────────────┬────────────────────┐
   │  +  +  +  +  +  +  │   no free     │  −  −  −  −  −  −  │
   │  +  +  +  +  +  +  │   charges     │  −  −  −  −  −  −  │
   └────────────────────┴───────────────┴────────────────────┘
        anode                 barrier ~0.6–0.7 V              cathode

   symbol:   anode ──►│── cathode        (current flows in the arrow's direction)
```

- **Forward bias**: connect `+` to the P side (the **anode**) and `−` to the N side (the **cathode**). The external
  voltage pushes against the barrier. Once it exceeds about 0.6 V, the barrier collapses, charges flood across, and
  current flows freely.
- **Reverse bias**: connect it the other way. The external voltage adds to the barrier, the depletion region widens,
  and almost no current flows (a leakage of nanoamps to microamps).

So a **diode** is a one-way valve for current. The arrow in its symbol points in the direction conventional current
can flow (chapter 2), from anode to cathode. On the component, a **band marks the cathode**.

## The diode's curve

```text
   current
      ▲
      │                                   │
      │                                   │  forward: current rises steeply
      │                                  ╱    once past ~0.6–0.7 V
      │                                ╱
      │                             _─'
 ─────┼───────────────────────────────────────► voltage
      │█                       0     0.7 V
      │█ breakdown at a large
      │█ reverse voltage     reverse: tiny leakage
      │
```

- Below about 0.5 V forward: almost nothing.
- Around **0.6–0.7 V** (silicon): current rises steeply. Each extra ~60 mV multiplies the current by about ten. In
  practice, a conducting silicon diode has **about 0.7 V across it**, whatever the current (within reason). That's the
  **forward voltage**, `V_F`.
- In reverse: tiny leakage, until the **reverse breakdown voltage**, where it conducts heavily (destructive for most
  diodes; useful for Zeners).

Because the voltage stays almost constant while the current varies, a diode **can't set its own current**. Something
else in the circuit — a resistor, the load — must limit it. That's the LED rule from chapter 4, and it applies to
every diode.

A conducting diode dissipates `P = V_F × I`. A rectifier diode carrying 1 A wastes about 0.7–1 W.

## Diode types

| Type | Example parts | Forward voltage | Key ratings | Use |
|---|---|---|---|---|
| Rectifier | 1N4001 … 1N4007 | about 0.7–1.0 V | 1 A; 50 V (4001) to 1000 V (4007) reverse | Power, reverse-polarity protection, flyback |
| Small signal | 1N4148 | about 0.6–1.0 V | 200 mA, fast | Signals, logic, small flyback |
| Schottky | 1N5819 (1 A), 1N5822 (3 A), SS34 (3 A SMD) | **0.2–0.5 V** | 40 V typical; higher leakage | Low-loss protection and power paths, fast flyback |
| Zener | 1N47xx (1 W), BZX55 (0.5 W) | 0.7 V forward | Precise **reverse** breakdown, e.g. 3.3 V, 5.1 V | Voltage references, clamping, simple protection |
| LED | everywhere | 1.2–3.5 V depending on colour | 20 mA typical, **5 V reverse max** | Light |
| Photodiode | BPW34 | — | — | Light sensing |

Ratings to check on a datasheet (chapter 6): maximum average **forward current** (`I_F`), maximum **reverse voltage**
(`V_RRM`), forward voltage at your current, and, for fast-switching uses, reverse-recovery time.

### Zener diodes

A Zener is designed to break down in reverse at a precise voltage, without damage as long as its current and power
are limited. Reverse-biased through a resistor, it holds a nearly constant voltage across itself:

```text
   +9V ──[ 470 Ω ]──●── about 5.1 V
                    │
                  ──┴──  Zener 5.1 V (cathode up: reverse-biased)
                   ╱▲
                    │
                   GND

   resistor current = (9 − 5.1) / 470 ≈ 8.3 mA; Zener power ≈ 5.1 × 8.3 mA ≈ 42 mW
```

It's a crude regulator (the voltage shifts with current and temperature, and it wastes power), but Zeners are handy as
**clamps**: a 5.1 V Zener from an input pin to ground, with a series resistor, stops a stray higher voltage from
reaching the pin.

## Rectifiers: AC to DC

Since a diode passes current one way, it can turn AC into DC. This is the first stage of every power adapter (chapter
13).

```text
   half-wave: one diode                   full-wave bridge: four diodes in a diamond
                                          (each triangle points up, toward + out)
   AC 1 ──►│──●── + out                                + out
               │                                         │
            [load]                                  ┌────┴────┐
               │                                  D1▲         ▲D2
   AC 2 ───────●── − out                            │         │
                                         AC 1 ──────●         ●────── AC 2
                                                    │         │
                                                  D3▲         ▲D4
                                                    └────┬────┘
                                                         │
                                                       − out

   output: only the positive halves       output: every half, flipped positive
   ╭╮  ╭╮  ╭╮                             ╭╮╭╮╭╮╭╮╭╮
  ─╯╰──╯╰──╯╰──                           ╯╰╯╰╯╰╯╰╯╰
```

When AC 1 is positive, current flows through D1, the load (between + out and − out) and back through D4 to AC 2.
When AC 2 is positive, it flows through D2, the load in the *same* direction, and D3.

A **bridge rectifier** (four diodes, also sold as one four-pin part) uses both halves of the wave. Its output still
pulses from zero to the peak 100 or 120 times a second, so a large **smoothing capacitor** follows it (chapter 15),
charging to the peak and supplying the load between peaks. What's left is a small **ripple**. Two diodes conduct at a
time in a bridge, so the output peak is about 1.4 V below the AC peak.

## LEDs

A **light-emitting diode** is a diode made from compound semiconductors (gallium arsenide, gallium nitride…) in which
an electron falling into a hole across the junction releases its energy as a photon of light. The semiconductor
material sets the energy of each photon, and so both the **colour** and the **forward voltage**: bluer light means
more energetic photons, which need a higher voltage.

| Colour | Wavelength | Typical forward voltage at 10–20 mA |
|---|---|---|
| Infrared | 850–940 nm | 1.2–1.6 V |
| Red | 620–630 nm | 1.8–2.2 V |
| Orange, yellow | 590–610 nm | 2.0–2.2 V |
| Yellow-green (older "green") | 565–570 nm | 2.0–2.4 V |
| True green | 520–530 nm | 2.8–3.4 V |
| Blue | 460–470 nm | 2.8–3.4 V |
| White (blue LED + phosphor) | — | 2.8–3.4 V |

Always use the datasheet value for the actual part: forward voltages vary between manufacturers and even within a
batch (chapter 6's worst-case exercise).

### Identifying the leads

```text
         ___
        /   \          the LONG leg is the anode (+)
       |     |         the flat edge of the rim and the SHORT leg are the cathode (−)
       |_____|         inside: the larger "anvil" is usually the cathode
        |   |
        |   |          if in doubt, use the multimeter's diode test (chapter 14)
        |
       anode cathode
       (long) (short)
```

Inserted backwards in a low-voltage circuit, an LED simply doesn't light. But its **reverse voltage limit is only
about 5 V**. More than that in reverse can damage it.

### Driving LEDs properly

Everything from chapter 4 still applies, now with the reason: past its forward voltage, an LED's current rises
exponentially with voltage, so **the current must be set by something other than the LED**.

```text
R = (V_supply − V_F) / I_LED,  rounded up to a standard value
P_R = (V_supply − V_F) × I_LED
```

Design notes:

- **Choose the current from the job, not the maximum.** Modern indicator LEDs are clearly visible at 1–5 mA. 20 mA is
  usually a maximum, not a target. Lower current means a cooler LED, a longer life, less load on the pin and longer
  battery life.
- **Respect the pin.** An Uno R3 pin is good for 20 mA, an Uno R4 pin only 8 mA, and many 3.3 V microcontrollers'
  pins 4–12 mA (chapter 19). For more current, use a transistor (chapter 18).
- **Brightness is roughly proportional to current**, but the eye's response is logarithmic: 10 mA doesn't look twice
  as bright as 5 mA. To dim an LED, use PWM (chapter 19) rather than changing the resistor.
- **From 3.3 V, blue and white LEDs leave very little voltage for the resistor** (3.3 − 3.0 = 0.3 V), so the current
  varies a lot with the LED's exact forward voltage. Use a lower current, accept the variation, or drive them from 5 V
  through a transistor.
- **High-power LEDs** (1 W and above) are driven by **constant-current drivers**, not resistors, and need heatsinks.
- **Each LED in parallel needs its own resistor** (chapter 8).

> **Just a taste — you don't need to learn or fully understand this now.** "Addressable" LEDs (WS2812, "NeoPixels")
> contain a tiny controller chip and take a 5 V supply plus a data signal. They don't need resistors, but they do
> draw up to about 60 mA each at full white, so a strip of 60 can need 3.6 A at 5 V (chapters 11 and 12 apply).

## Reverse-polarity protection

Connect a battery or adapter backwards and many circuits are destroyed instantly. A diode in series with the supply
prevents it: reversed, the diode blocks.

```text
   + in ──►│──●── + to the circuit
     (series diode)

   − in ──────●── GND
```

The trade-off is the diode's voltage drop and power loss:

| Protection | Drop at the load current | Loss at 0.5 A |
|---|---|---|
| Silicon rectifier (1N4007) | about 0.8–0.9 V | about 0.45 W |
| Schottky (1N5822) | about 0.35–0.4 V | about 0.2 W |
| P-channel MOSFET (chapter 18) | a few tens of mV | a few mW |

The Arduino Uno R3 has exactly such a diode (a 1 A rectifier) after its barrel jack, which is why the voltage on its
`VIN` pin is about 0.7 V lower than the adapter's. On a 12 V system losing 0.4 V in a Schottky is usually acceptable;
on a 3.3 V system it's not, and the MOSFET method is used.

### Combining two power sources

Two diodes can let a circuit run from either of two supplies, preventing one from back-feeding the other (chapter 9's
problem):

```text
   USB 5 V ──►│──┐
                 ●── to the circuit (gets whichever is higher, minus one diode drop)
   battery ──►│──┘
```

With Schottky diodes this "diode-OR" costs only a few tenths of a volt. Many boards use this or a MOSFET-based
equivalent to switch between USB and external power automatically.

## The Plant Station's diodes

| Diode | Part | Why |
|---|---|---|
| Status LED | Green, `V_F` ≈ 2.0 V | With the board's 8–20 mA pin limits, run it at about 4 mA |
| Reverse-polarity protection on the 12 V input | 1N5822 (3 A Schottky) | Protects everything if the adapter is wrong or wired backwards. Rated for the pump's 1.2 A surge with margin; drop about 0.35 V, loss about 0.15 W |
| Flyback across the pump | 1N5819 (1 A Schottky) or 1N4007 | Clamps the pump's kickback when the MOSFET switches off (chapters 16 and 18) |

Recalculate the LED for 4 mA, to suit any Uno, including the R4's 8 mA limit with plenty of margin:

```bash
node examples/led-resistor.mjs 5 2.0 4mA
```

```text
Voltage across the resistor: 3 V
Exact resistor:              750 Ω
Nearest E12 value above it:  820 Ω
LED current with it:         3.66 mA
Resistor power:              11 mW (use a resistor rated at least 22 mW)
```

**820 Ω**, about 3.7 mA. (Chapter 4's 330 Ω at 9 mA was fine for an Uno R3 but over an Uno R4's limit.)

## Key takeaways

- Doping makes N-type (free electrons) and P-type (holes) silicon. Their junction has a barrier of about 0.6–0.7 V.
- A diode conducts from anode to cathode (the band) once forward-biased past its forward voltage, and blocks in
  reverse until breakdown.
- A conducting diode has a nearly constant voltage across it, so it can't limit its own current. Always limit it.
- Know the types: rectifier (1N400x), signal (1N4148), Schottky (low drop, 1N5819/1N5822), Zener (precise reverse
  breakdown), LED.
- Bridge rectifier plus smoothing capacitor turns AC into DC with some ripple.
- LED colour sets forward voltage (red ≈ 2 V, blue/white ≈ 3 V). Long leg = anode, flat side = cathode. Reverse limit
  ≈ 5 V. Choose the current for the job (often 1–5 mA), respect pin limits, one resistor per LED.
- A series diode (preferably Schottky) protects against reversed supplies; two diodes combine supplies safely.

## Exercises

1. **Which way?** In each case, does the diode conduct? (a) Anode at 5 V, cathode at 0 V. (b) Anode at 3 V, cathode at
   3.2 V. (c) Anode at 12.7 V, cathode at 12 V. (d) A 1N4007 with its cathode at 230 V peak and its anode at 0 V.

<details>
<summary>Solution</summary>

(a) Yes: forward-biased by more than 0.7 V (and something must limit the current!). (b) No: reverse-biased by 0.2 V.
(c) Yes: forward-biased by 0.7 V, typical for conduction. (d) No: reverse-biased by 230 V, which is within the
1N4007's 1000 V rating, so it just blocks.

</details>

2. **LED resistors.** Calculate standard resistors (round up) for: (a) a red LED (`V_F` 1.9 V) at 5 mA from 5 V;
   (b) a white LED (`V_F` 3.0 V) at 10 mA from 5 V; (c) a white LED at 2 mA from 3.3 V. Comment on (c).

<details>
<summary>Solution</summary>

```text
(a) (5 − 1.9) / 5 mA  = 620 Ω  → 680 Ω (E12), I ≈ 4.6 mA
(b) (5 − 3.0) / 10 mA = 200 Ω  → 220 Ω, I ≈ 9.1 mA
(c) (3.3 − 3.0) / 2 mA = 150 Ω → 150 Ω, I = 2 mA
```

In (c) only 0.3 V is left for the resistor. If this LED's forward voltage is really 2.9 V, the current becomes
`0.4 / 150 ≈ 2.7 mA`; if it's 3.2 V, `0.1 / 150 ≈ 0.7 mA`. Brightness varies a lot between parts. It still works as an
indicator, but for consistent brightness drive white LEDs from a higher voltage.

</details>

3. **Zener clamp.** A 5.1 V Zener with a 1 kΩ series resistor protects an input from a sensor signal that can reach
   12 V by mistake. How much current flows through the Zener, and how much power does it dissipate, when the input is
   at 12 V? Is a 0.5 W Zener enough?

<details>
<summary>Solution</summary>

```text
I = (12 − 5.1) / 1000 = 6.9 mA
P_Zener = 5.1 × 0.0069 ≈ 35 mW
P_resistor = 6.9 × 0.0069 ≈ 48 mW
```

Comfortably within 0.5 W for the Zener and 0.25 W for the resistor.

</details>

4. **Protection loss.** A 12 V, 1.5 A load is protected by a series 1N4007 (assume 0.9 V drop at this current). How
   much voltage reaches the load, and how much heat does the diode make? What would a Schottky with a 0.45 V drop
   change? Is the 1N4007 even suitable?

<details>
<summary>Solution</summary>

```text
1N4007:   load gets 11.1 V; heat = 0.9 × 1.5 = 1.35 W
Schottky: load gets 11.55 V; heat = 0.45 × 1.5 ≈ 0.68 W
```

The 1N4007 is rated for only 1 A average forward current, so at 1.5 A it's over its rating and would overheat. Use a
3 A part (a 1N5822 Schottky, or a 1N540x rectifier), or a P-channel MOSFET for almost no loss.

</details>

5. **Bridge output.** A 12 V RMS transformer feeds a bridge rectifier (two diode drops of 0.7 V each in the path) and a
   large smoothing capacitor. With no load, what DC voltage does the capacitor charge to?

<details>
<summary>Solution</summary>

The capacitor charges to the AC peak minus two diode drops:

```text
V_peak = 12 × 1.414 ≈ 17.0 V
V_dc   ≈ 17.0 − 1.4 = 15.6 V
```

With a load, the capacitor droops between peaks (ripple), so the average is lower. This is why an unregulated "12 V"
adapter can measure 15–17 V with no load (chapter 12).

</details>

---
← Previous: [Magnetism, inductors, relays and motors](16-magnetism-inductors-relays-and-motors.md) | Next: [Transistors as switches](18-transistors-as-switches.md) →
