# Chapter 16 — Magnetism, inductors, relays and motors

## What you'll learn

- How electric current creates a magnetic field, and how a coil makes an electromagnet
- How a changing magnetic field creates a voltage (induction)
- What an inductor is, the henry, and why inductors resist changes in current
- Inductive kickback: why switching off a coil can produce hundreds of volts, and how a flyback diode tames it
- How relays work, and how to drive one safely
- How DC motors work: torque, speed, back-EMF, and why starting and stall currents are so large
- Motor electrical noise, and a quick look at servos, steppers and brushless motors
- The Plant Station's pump, electrically

## Current makes magnetism

In 1820 Hans Christian Ørsted noticed that a wire carrying current deflected a compass needle. **Every current
creates a magnetic field** around it, in circles around the wire. Moving charge and magnetism are two sides of the same
thing.

```text
          magnetic field circles the wire
             ↻  ↻  ↻  ↻
   ════════════════════════════► current
             ↻  ↻  ↻  ↻

   "right-hand rule": point your right thumb along the (conventional) current;
   your fingers curl in the direction of the field
```

The field of a straight wire is weak. Wind the wire into a **coil** and the fields of all the turns add up inside it,
producing a field like a bar magnet's, with a north and a south pole. Put an iron core inside, and the iron
concentrates the field hundreds or thousands of times: an **electromagnet**. Its strength is roughly proportional to
**turns × current** (ampere-turns). Unlike a permanent magnet, you can switch it on and off, which is what relays,
solenoids, motors and loudspeakers are built on.

Magnets also push on currents: a wire carrying current in a magnetic field feels a sideways force (the force that
turns every electric motor). Two magnets, permanent or electro-, attract or repel by their poles.

## Changing magnetism makes voltage: induction

In 1831 Michael Faraday found the reverse: a **changing** magnetic field through a coil **induces a voltage** in it.
Move a magnet into a coil and a voltage appears; hold it still and the voltage disappears; pull it out and the voltage
reverses. The faster the change and the more turns, the larger the voltage.

This is how generators make electricity (spin a magnet near coils), how transformers work (chapter 13: the changing
current in one coil changes the field through the other), how wireless chargers transfer power, and how a clamp meter
measures AC current without touching the wire (chapter 14). And it has a consequence for every coil in your circuits.

## Inductors

A coil's changing current changes its *own* magnetic field, which induces a voltage in the coil *itself* that
**opposes the change** (Lenz's law). A coil therefore resists any change in the current through it. That property is
**inductance**, and a component made to have it is an **inductor**.

```text
   V = L × (ΔI / Δt)

   L   inductance, in henries (H); practical values: µH to mH, sometimes H
   V   voltage across the inductor
   ΔI/Δt  how fast the current is changing (amperes per second)

   schematic symbol:  ──∩∩∩∩──   (with lines alongside for an iron core)
```

Compare it with the capacitor (chapter 15), and you'll see a mirror image:

| | Capacitor | Inductor |
|---|---|---|
| Stores energy in | Electric field | Magnetic field |
| Resists sudden changes of | **Voltage** | **Current** |
| Equation | `I = C × ΔV/Δt` | `V = L × ΔI/Δt` |
| To steady DC (after settling) | Open circuit | Just the wire's resistance |
| Stored energy | `½ C V²` | `½ L I²` |
| With a resistor, time constant | `τ = R × C` | `τ = L / R` |
| Water analogy | A stretchy membrane | A heavy water wheel (flywheel) in the pipe: hard to start, hard to stop |

When you first connect a voltage across an inductor in series with a resistor, the current doesn't jump to `V / R`;
it rises over about `5 × L/R`, the same exponential curve as a capacitor's voltage. Every coil, from a relay winding to
a motor to a long wire (every wire has a little inductance), behaves this way. Switching regulators (chapter 12) use
inductors deliberately, storing energy in each switching cycle and releasing it to the output.

## Inductive kickback

Now the important part for Arduino work. Suppose current is flowing through a coil (a relay, a solenoid valve, a
motor's winding) and you switch it off suddenly with a transistor or a switch.

The inductor insists on keeping the current flowing. To force current through the now-open switch, its voltage
**jumps as high as it has to**, in the opposite polarity to before:

```text
V = L × ΔI / Δt

a 10 mH relay coil carrying 0.4 A, switched off in 1 µs:
V = 0.01 × 0.4 / 0.000 001 = 4000 V   (in theory)
```

In practice the voltage rises until something breaks down and conducts: an arc across switch contacts (which erodes
them), or the transistor that was doing the switching, which is destroyed (often not immediately, but after a few
hundred spikes). This voltage spike is **inductive kickback** (also "flyback" or "back-EMF spike"), and it is the
most common way beginners destroy transistors and microcontroller pins.

### The flyback diode

The cure is a **diode** across the coil, connected so it is reverse-biased (not conducting) in normal operation:

```text
   +12V ─────┬──────────┐
             │          │
          ┌──┴──┐      ─┴─   ← bar = cathode, to +12 V
          │coil │       ▲      diode (triangle points from anode to cathode)
          └──┬──┘       │    ← anode, to the switched end
             │          │
             ●──────────┘
             │
        to the transistor switch (chapter 18), which connects this end to GND when "on"
```

While the coil is on, the diode sees `+` on its cathode and doesn't conduct (chapter 17). When the switch opens, the
coil's voltage reverses; the diode then conducts and gives the coil's current a loop to circulate in, which dies away
within milliseconds as the energy turns into heat in the coil's resistance. The voltage at the switch never rises more
than about 0.7 V above the supply.

Rules:

- **Every inductive load switched by a transistor needs a flyback diode**: relays, solenoids, motors, pumps, fans
  (unless the fan or module has its own).
- Mount it **at the load or right at the switch**, cathode to the positive supply.
- Use a diode rated for at least the load current: **1N4001–1N4007** (1 A) for relays and small motors, a **Schottky**
  such as the **1N5819** (1 A, faster) or larger types for bigger motors.
- The diode slows the coil's turn-off a little (the current decays slowly through it). For a relay that must release
  quickly, a Zener diode in series speeds it up; you won't need that for now.

Many "relay modules" and motor-driver boards include the diode and transistor already. Bare relays and motors don't.

## Relays

A **relay** is a switch operated by an electromagnet. Current in the coil pulls an iron armature, which moves the
switch contacts. The coil circuit and the contact circuit are electrically separate, so a small, low-voltage current
can switch a large current or a different voltage (even mains, though chapter 13 says don't, as a beginner).

```text
   coil side                     contact side
   ┌──────────┐                  COM ──┐
   │  ∩∩∩∩∩∩  │  pulls ───►           └──/── NO  (normally open: closes when energised)
   │  coil    │                       └───── NC  (normally closed: opens when energised)
   └──────────┘
```

Things to read on a relay's datasheet:

| Spec | Example (a common 5 V relay) | Meaning |
|---|---|---|
| Coil voltage | 5 V DC | What to put across the coil |
| Coil resistance / current | 70 Ω, about 70 mA | **More than a microcontroller pin can supply** |
| Contact rating | 10 A 250 V AC, 10 A 30 V DC | Maximum current and voltage the contacts can switch |
| Pick-up / drop-out voltage | 3.75 V / 0.5 V | Coil voltage at which it closes and releases |

A 5 V relay coil draws about 70 mA: three times an Uno R3 pin's recommended maximum and nine times an Uno R4's. So a
relay is always driven through a transistor (chapter 18), with a flyback diode across its coil. Relays are slow
(milliseconds), click, and wear out after perhaps 100 000 operations at full load. For switching DC loads such as the
Plant Station's pump, a MOSFET (chapter 18) is smaller, silent, faster and lasts indefinitely; relays remain useful
for isolation and for AC.

## DC motors

The small brushed DC motors in toys, fans and pumps turn electricity into rotation using the force on a current in a
magnetic field. A coil (the armature) sits between permanent magnets; current through it makes it turn. A rotating
switch, the **commutator**, with sliding contacts called **brushes**, reverses the coil's current every half turn so
the force keeps turning it the same way.

### Torque, speed and current

For a brushed DC motor, roughly:

- **Torque is proportional to current.** The harder the motor works (a heavier load, water to push), the more current
  it draws.
- **Speed is proportional to voltage.** Lower the voltage and it slows down; reverse it and it runs backwards.

### Back-EMF: why a spinning motor draws less current

A spinning motor is also a generator: its coil moving past the magnets induces a voltage that **opposes** the supply,
called **back-EMF**. The faster it spins, the higher the back-EMF. The current is set by what's left over:

```text
I = (V_supply − V_back-EMF) / R_winding
```

- **Running freely**, back-EMF is nearly as high as the supply, so the current is small.
- **At start-up or stalled** (blocked), the motor isn't spinning, there's **no back-EMF**, and the current is limited
  only by the winding's resistance: the **stall current**, often 3–10 times the running current.

**Worked example: the Plant Station's pump.** The pump's datasheet says 12 V, 0.4 A running. Its winding measures
10 Ω on a multimeter (power off, chapter 14).

```text
stall / start current = 12 V / 10 Ω = 1.2 A        (3× the running current)
back-EMF while running: 12 − 0.4 × 10 = 8 V
```

That 1.2 A is the surge chapters 11 and 12 planned for. A stalled motor draws it continuously and heats up at
`I² × R = 1.2² × 10 ≈ 14 W`, which burns out small motors within minutes. A jammed pump (a dry run with debris) is
exactly this case: the fuse in chapter 20 protects against it.

### Motors and electrical noise

Every time the brushes switch the coil's current, they cause tiny inductive spikes and sparks. A brushed motor
sprays electrical noise into its supply wires and the air, which can reset microcontrollers, disturb analog readings
and interfere with radios. The standard defences:

- A **100 nF ceramic capacitor across the motor's terminals**, soldered at the motor (chapter 15).
- A **flyback diode** across the motor when it's switched by a transistor.
- **Separate power wiring** for the motor, a bulk capacitor on its supply, and connecting the motor's ground to the
  board's ground at one point (chapter 20).
- Twisting the motor's two wires together, which keeps their magnetic fields compact.

### Other motor types

> **Just a taste — you don't need to learn or fully understand this now.** Hobby **servos** contain a DC motor, a
> gearbox, a position sensor and a control circuit; they take power on two wires and a position command as a pulse
> on the third. **Stepper motors** move in precise steps when their coils are energised in sequence, and need a
> driver chip. **Brushless DC motors** (drones, PC fans) have no brushes; an electronic controller switches their
> coils. All draw surges, all are inductive, and all should be powered separately from the microcontroller.

## Key takeaways

- Current creates a magnetic field; a coil with an iron core makes an electromagnet, with strength ∝ turns × current.
- A changing magnetic field induces a voltage: generators, transformers, wireless charging.
- An inductor resists changes in current: `V = L × ΔI/Δt`. It's the mirror image of a capacitor; its time constant is
  `L / R`.
- Switching off a coil makes its voltage spike as high as needed, destroying transistors. **Every switched inductive
  load needs a flyback diode**, cathode to the positive supply.
- Relays switch big or separate circuits with a small coil current, but their coils draw more than a microcontroller
  pin can supply: drive them through a transistor.
- DC motors: torque ∝ current, speed ∝ voltage. Back-EMF limits running current; start and stall currents are
  `V / R_winding`, several times higher.
- Motors are electrically noisy: add a capacitor across them, a flyback diode, and separate power wiring.

## Exercises

1. **Kickback.** A solenoid valve with 50 mH of inductance carries 0.3 A. A transistor turns it off in 2 µs. What
   voltage would the solenoid try to produce? What component prevents it, and how is it connected?

<details>
<summary>Solution</summary>

```text
V = L × ΔI / Δt = 0.05 × 0.3 / 0.000 002 = 7500 V   (in theory)
```

A flyback diode across the solenoid, reverse-biased in normal operation: cathode to the positive supply, anode to the
end connected to the transistor. When the transistor turns off, the diode conducts and clamps the spike to about
0.7 V above the supply.

</details>

2. **Stall current.** A 6 V motor runs at 150 mA and has a winding resistance of 8 Ω. What are its stall current and
   the back-EMF when running? How much heat does it make when stalled?

<details>
<summary>Solution</summary>

```text
stall current  = 6 / 8 = 0.75 A      (5× the running current)
back-EMF       = 6 − 0.15 × 8 = 4.8 V
stall heat     = 0.75² × 8 = 4.5 W   (vs. 0.15² × 8 = 0.18 W in the winding while running)
```

</details>

3. **Relay drive.** A 12 V relay has a 400 Ω coil. What current does it draw? Can an Arduino Uno R3 pin drive it
   directly? What do you need?

<details>
<summary>Solution</summary>

`12 V / 400 Ω = 30 mA` — but at 12 V, which an Arduino pin can't provide at all (its pins output at most 5 V). Even a
5 V relay's coil current (typically 70 mA) exceeds a pin's limit. You need a transistor switch (chapter 18) powered
from the relay's supply, with the pin driving the transistor, a flyback diode across the coil, and a common ground.

</details>

4. **RL time constant.** A relay coil has 0.1 H inductance and 100 Ω resistance. How long does its current take to
   reach (nearly) its full value after switch-on? What is that full value at 5 V?

<details>
<summary>Solution</summary>

```text
τ = L / R = 0.1 / 100 = 1 ms;  5τ = 5 ms
final current = 5 V / 100 Ω = 50 mA
```

This is part of why relays take a few milliseconds to close; the armature's mechanical movement adds more.

</details>

5. **Noise.** An Arduino controlling a small DC fan through a transistor resets randomly when the fan starts. The fan
   runs from the same 5 V supply as the board. List four changes that would help, and say why each one does.

<details>
<summary>Solution</summary>

1. **Flyback diode across the fan**: stops voltage spikes when the transistor switches off.
2. **100 nF capacitor across the fan's terminals**: absorbs brush noise at its source.
3. **Bulk capacitor (e.g. 470 µF) on the supply near the fan**: covers the starting surge so the 5 V rail doesn't dip
   below what the board needs.
4. **Separate supply, or at least separate wires, for the fan**, with the grounds joined at the supply: the fan's
   surge current then doesn't flow through the board's ground wire, where its voltage drop would shift the board's
   "0 V" (chapter 20).

Also good: a thicker, shorter supply wire (chapter 11), or running the fan from a higher-voltage supply through its
own regulator.

</details>

---
← Previous: [Capacitors and RC circuits](15-capacitors-and-rc-circuits.md) | Next: [Diodes and LEDs](17-diodes-and-leds.md) →
