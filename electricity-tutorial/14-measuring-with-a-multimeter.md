# Chapter 14 — Measuring with a multimeter

## What you'll learn

- What a digital multimeter measures, and what its jacks and dial positions mean
- How to measure DC and AC voltage, and what the meter's own resistance does to the circuit
- How to measure resistance, and why you must do it with the power off
- How to use continuity and diode-test modes
- How to measure current, why the meter must be in series, and how to avoid blowing its fuse
- Burden voltage, accuracy specifications, counts and true RMS
- Safety (CAT) ratings, and what to look for when buying a meter
- A systematic method for finding faults in a circuit

## Why a meter

Electricity is invisible. Without a meter you can only guess why the LED is dim or the board resets. With one, every
idea in this tutorial becomes something you can check: Ohm's law, voltage drops in a wire, a battery's internal
resistance, the divider's output. A basic auto-ranging digital multimeter (DMM) is the most important tool you'll buy
for Arduino work, and a decent one is inexpensive.

## The meter's layout

```text
     ┌─────────────────────────┐
     │        [ 4.98 V ]       │   display
     │                         │
     │      ╭─────────────╮    │   dial: V⎓ (DC volts), V~ (AC volts), Ω,
     │      │  ⎓V ~V  Ω   │    │         continuity (•))), diode (►|),
     │      │  •)))  ►|   │    │         mA/µA, A, sometimes F (capacitance), Hz
     │      │  µA mA A    │    │
     │      ╰─────────────╯    │
     │                         │
     │  (10A)  (mA µA)  (COM)  (VΩ)   jacks
     └─────────────────────────┘
```

- **COM**: the common (black) lead. It always goes here.
- **VΩ** (often also marked with the diode and continuity symbols): the red lead for voltage, resistance, continuity
  and diode tests.
- **mA/µA**: the red lead for small currents, protected by a small fuse (often 200–500 mA).
- **10A** (or 20A): the red lead for large currents, with its own large fuse (or none, on cheap meters).

Most of the time the red lead stays in **VΩ**. Moving it to a current jack turns the meter into a near-short circuit
between its leads; leaving it there by mistake is the classic way to blow the fuse (below).

**Auto-ranging** meters pick the scale themselves. **Manual-ranging** meters make you choose a range (20 V, 200 mA…);
pick one above the value you expect, then go down for more resolution. A display of `1` or `OL` means "over limit":
choose a higher range.

## Measuring voltage

Voltage is measured **across** a component, with the meter **in parallel** (chapter 3). Nothing needs to be
disconnected.

1. Red lead in **VΩ**, black in **COM**.
2. Dial to **V⎓** (DC) — for anything powered by batteries, USB or an adapter.
3. Black probe on the lower-potential point (usually GND), red probe on the point you're measuring.
4. Read. A minus sign means red is lower than black; no harm done.

```text
   +5V ──[ 330Ω ]──●──►|── GND
                   │        │
                (red)    (black)
                   └─(V)────┘      reads the LED's forward voltage, ~2.0 V
```

Good habits:

- Measure "node voltages" against GND: black probe clipped to ground, red probe touring the circuit. It's the
  fastest way to understand a circuit (and it matches how schematics label voltages).
- To get the voltage across one component, either probe both ends directly, or subtract its two node voltages.

### The meter's input resistance

A meter in voltage mode has a very high resistance, typically **10 MΩ**, so it draws almost no current and barely
disturbs the circuit. Chapter 10's exercise showed what happens with a low-resistance meter: it loads a high-resistance
divider and reads low. With 10 MΩ, the error stays below 1 % as long as the source resistance is under about 100 kΩ.
If you measure a divider made of 1 MΩ resistors and get a strangely low value, this is why.

### AC voltage

Use **V~** for AC, such as a transformer's output. Cheap meters assume a sine wave and read RMS correctly only for
sine waves (chapter 13); a **true RMS** meter also reads non-sinusoidal waveforms correctly. Measuring PWM signals
(chapter 19) on DC voltage mode gives the *average* voltage, which is often what you want.

Don't measure mains as a learning exercise. If you ever must, use a meter and leads rated for it (CAT, below).

## Measuring resistance

1. **Turn the circuit's power off** and, ideally, take the component out of the circuit (or lift one leg).
2. Red in **VΩ**, black in **COM**, dial to **Ω**.
3. Touch the probes to the component's two ends.

The meter measures resistance by pushing a small known current through the component and measuring the voltage
(Ohm's law, `R = V / I`). That's why:

- **Power must be off.** Any voltage in the circuit adds to or fights the meter's own, giving nonsense readings, and
  can damage the meter.
- **In-circuit readings are often wrong.** The meter measures everything connected between the two points, not just
  the component: other paths in parallel lower the reading (chapter 8). A 10 kΩ resistor with a 10 kΩ path in
  parallel through the rest of the circuit reads 5 kΩ.
- **Don't hold both leads with your fingers.** Your body (100 kΩ or so) is then in parallel with the resistor; it
  matters for anything above a few kΩ.

For low resistances, short the probes together first: the leads themselves read about 0.1–0.5 Ω, which you subtract.
Resistance mode is useful for checking resistor values (faster than reading colour bands), testing fuses (≈ 0 Ω when
good, `OL` when blown), checking a motor's winding, and checking that switches open and close.

## Continuity and diode test

**Continuity** mode (the speaker symbol `•)))`) beeps when the resistance between the probes is below a threshold
(typically 30–50 Ω). It's resistance mode with sound, so you don't have to look at the display. Use it to:

- Check that a wire or a solder joint connects what it should.
- Find which breadboard holes or connector pins are connected.
- Check that two things that must *not* be connected (such as the 5 V and GND rails) aren't. **Do this before
  powering up a new board or circuit**: a beep between the supply rails is a short circuit.

**Diode test** (`►|`) pushes a small current through the probes and shows the voltage across them. On a good diode
it shows the forward voltage (about 0.5–0.7 V for a silicon diode, 0.2–0.4 V for a Schottky) with red on the anode,
and `OL` the other way round. Many meters' diode tests can light an LED dimly, which is a handy way to test LEDs and
identify their polarity (chapter 17).

## Measuring current

Current is measured **through** the circuit (chapter 3), so the meter must be **in series**: you open the circuit and
let the current flow through the meter.

1. **Power off.**
2. Red lead into **mA** (for up to a few hundred mA) or **10A** (for more, or if unsure, start there).
3. Dial to **A⎓** / **mA⎓**.
4. **Open the circuit** at the point you want to measure, and bridge the gap with the meter.
5. Power on and read.

```text
   +5V ──[ 330Ω ]──►|──┐                 +5V ──[ 330Ω ]──►|── (red)(A)(black) ── GND
                       │       →                             meter inserted into
   GND ────────────────┘                                      the gap: ~9 mA
   original circuit                     
```

### Why the current mode is dangerous

In current mode, the meter's resistance between its leads is very low (a fraction of an ohm), so as not to disturb
the current it measures. If you put the probes **across** a voltage source in current mode — the natural reflex when
you meant to measure voltage — you've shorted the source through the meter:

```text
   battery's + ──(red)(meter in A mode, ~0.1 Ω)(black)── battery's −
   I = V / R = 9 V / 0.1 Ω... limited only by the battery: several amps → the fuse blows
```

With a fuse, you lose the fuse (keep spares; replace only with the same rating and type). Without one, or on the
unfused 10 A input of a cheap meter connected to a big battery, the meter or its leads can be damaged and the battery
overheats. Two habits prevent it:

- **Always move the red lead back to VΩ** right after a current measurement.
- **Think "series" before touching probes in current mode.** If the probes are about to touch two different nodes of a
  live circuit, stop.

### Burden voltage

The meter's small internal resistance still drops a little voltage, the **burden voltage**, typically 0.1–1 mV per mA
depending on range. Measuring a microcontroller's 50 mA through a meter on a µA range can drop enough voltage to
reset it. If a circuit behaves differently with the meter inserted, use a higher range or measure the voltage across a
known series resistor instead.

### Measuring current without breaking the circuit

Two alternatives:

- **The shunt method.** Put a small known resistor in series permanently (say 1 Ω) and measure the voltage across it
  in voltage mode: `I = V / R`. 45 mV across 1 Ω means 45 mA. Many current-sensing chips work this way.
- **Clamp meters** sense the magnetic field around a wire (chapter 16) without contact. Cheap ones measure only AC;
  DC clamp meters exist and are useful for motors and battery leads.

## Accuracy, resolution and counts

A meter's display has a number of **counts**: a "2000-count" meter displays up to 1999, a "6000-count" up to 5999.
More counts mean more resolution: on a 6000-count meter you can read 5.012 V; on a 2000-count, 5.01 V.

**Accuracy** is specified as `±(percentage of reading + digits)`:

```text
DC voltage accuracy: ±(0.5 % + 2 digits)

reading 5.012 V on the 6 V range (last digit = 0.001 V):
error ≤ 0.5 % × 5.012 + 2 × 0.001 = 0.025 + 0.002 ≈ ±0.027 V
true value: between about 4.985 V and 5.039 V
```

A cheap meter is still accurate enough for everything in Arduino work. Treat readings to the last digit with
suspicion, and compare like with like (the same meter, the same range).

## Safety ratings (CAT)

A meter's **measurement category** (CAT) rating says what kind of electrical environment it can survive, including
voltage spikes:

| Category | Environment |
|---|---|
| CAT I | Protected electronics, not connected to mains |
| CAT II | Appliances plugged into a socket |
| CAT III | Building wiring: distribution boards, fixed installations |
| CAT IV | The supply to the building: meters, overhead lines |

Each comes with a voltage, e.g. "CAT III 600 V". For Arduino work any meter is fine, but buy one with a real CAT II or
CAT III rating from a reputable brand, with fused current inputs and proper probe shrouds. Very cheap no-name meters
sometimes print ratings their internals can't meet, which matters the day you measure something you shouldn't.

Features worth having: auto-ranging, continuity beeper, a fused 10 A input, diode test, and a capacitance range
(chapter 15). Nice extras: true RMS, frequency and duty-cycle measurement, temperature with a thermocouple.

## A method for finding faults

When a circuit doesn't work, don't swap parts at random. Measure, following the energy from the source to the load:

1. **Check the supply at the source.** Is the adapter or battery providing its voltage, measured under load?
2. **Check the supply at the circuit.** Measure between the board's or breadboard's `+` and GND rails. A big
   difference from step 1 means a bad wire, connector or breadboard rail (chapter 11), or a split rail (chapter 7).
3. **Check ground.** With the black probe on the supply's `−`, measure the GND points of each part: they should all
   read 0 V. A part whose "ground" sits at a few volts is missing its ground connection.
4. **Follow the signal or the power path**, measuring node voltages against GND. Compare each with what you expect
   from the schematic (Ohm's law, the divider formula, the LED's forward voltage).
5. **Look for the big voltage across one part.** In a series path, a part with the full supply voltage across it is
   open (chapter 9). A part with 0 V across it while it should have voltage may be shorted.
6. **Power off and check resistance and continuity** for suspected breaks or shorts.
7. **Halve the problem.** If a long chain fails, measure in the middle. Working half? Then the fault is in the other
   half. Repeat.

**Example.** The Plant Station's status LED doesn't light.

```text
   +5V ──[ 330Ω ]──●──►|── GND
              A    B         C
```

| Measurement (black on GND) | Reading | Meaning |
|---|---|---|
| Node A (supply side of the resistor) | 5.0 V | Supply reaches the resistor |
| Node B (between resistor and LED) | 5.0 V | No drop across the resistor → **no current** flows: the path is open somewhere after B |
| Node C (LED's cathode) | about 3 V, should be 0 V | The cathode isn't connected to GND: the **ground connection** is missing |

Three measurements, and the fault is found: a missing ground jumper. (Node C doesn't read exactly 0 or 5 V because
the meter's own 10 MΩ input is then the only path to ground, so a tiny current trickles through the LED and the
reading lands somewhere in between.) If node C had read 0 V instead, the open part would be the LED itself, between B
and C: in backwards or dead. Compare a different fault, where node B reads 0 V: then the resistor has the full 5 V
across it, so the resistor or its connection to node B is open, or the LED is shorted to ground.

## Key takeaways

- Voltage: in parallel, red in VΩ, measure across. The 10 MΩ input barely disturbs most circuits.
- Resistance: power off, component out of circuit if possible, fingers off the leads.
- Continuity: check for opens and, before powering up, for shorts between supply rails.
- Diode test: shows forward voltage; lights LEDs dimly.
- Current: in series, red in mA or A. Never put a meter in current mode across a voltage source. Move the red lead
  back to VΩ afterwards.
- Burden voltage can disturb what you measure; a shunt resistor and voltage mode is an alternative.
- Accuracy is `±(% of reading + digits)`; a cheap meter is accurate enough for Arduino work.
- Buy a meter with genuine CAT ratings and fused current inputs.
- Debug by following voltage from the source to the load: a part with the whole supply across it is open.

## Exercises

1. **Which mode, where?** For each, say which dial setting and jacks you use and how you connect the probes: (a) the
   voltage of a AA cell; (b) the current an Arduino draws from its 9 V supply; (c) whether a fuse has blown; (d) which
   leg of an LED is the anode.

<details>
<summary>Solution</summary>

(a) V⎓, red in VΩ, black in COM; probes across the cell (red on `+`).

(b) mA⎓ (or 10A if unsure), red in mA (or 10A), black in COM; power off, break the positive lead from the supply,
insert the meter in series, power on.

(c) Continuity or Ω, red in VΩ, power off and fuse removed (or the circuit unpowered); probes on the two ends of
the fuse. Beep / ≈ 0 Ω = good; OL = blown.

(d) Diode test, red in VΩ; touch the probes to the legs. When the LED glows faintly (or a forward voltage of about
1.6–3 V appears), the red probe is on the anode.

</details>

2. **Accuracy.** A meter with DC accuracy `±(0.8 % + 3 digits)` reads 12.46 V on its 20 V range (last digit
   0.01 V). What range could the true voltage be in?

<details>
<summary>Solution</summary>

```text
error = 0.008 × 12.46 + 3 × 0.01 = 0.100 + 0.03 ≈ 0.13 V
true value between about 12.33 V and 12.59 V
```

</details>

3. **Strange resistance.** You measure a 4.7 kΩ resistor still soldered on a board and read 3.2 kΩ. The resistor's
   colour bands are correct. Give two possible reasons.

<details>
<summary>Solution</summary>

1. Another path through the circuit is in parallel with the resistor (other resistors, a chip's internal
   structures), lowering the reading. `4.7 k ∥ 10 k ≈ 3.2 kΩ`, for example.
2. You're holding both probe tips with your fingers, adding your body's resistance in parallel (less likely to have
   this large an effect, but possible with damp hands). Also possible: the circuit is still powered, which makes
   resistance readings meaningless. Lift one leg of the resistor and measure again.

</details>

4. **Blown fuse.** After measuring an LED's current, a student switches the dial to V⎓ to check a 9 V battery and the
   meter reads 0. They then notice the meter now reads 0 mA in current measurements as well. What happened?

<details>
<summary>Solution</summary>

They switched the dial but left the red lead in the **mA** jack. In that jack the meter is a near-short between its
leads whatever the dial says (on many meters the voltage reading then shows 0 because the input isn't connected to
the voltage circuit). Touching the battery shorted it through the mA fuse, which blew. Now the current input is open,
so current measurements read 0. Replace the fuse with the same type and rating, and move the red lead back to VΩ after
every current measurement.

</details>

5. **Debugging.** A 12 V supply feeds a pump through a switch and a 2 m pair of wires. The pump runs slowly. You
   measure 12.0 V at the supply's terminals and 9.8 V at the pump's terminals with the pump running, and 0.1 V across
   the closed switch. Where are the other volts going, and what do you check?

<details>
<summary>Solution</summary>

`12.0 − 9.8 = 2.2 V` are lost between the supply and the pump; the switch accounts for 0.1 V, so about 2.1 V are lost
in the wires and connections. For a pump drawing, say, 0.5 A, that's `2.1 / 0.5 = 4.2 Ω` of resistance — far more
than 4 m of any reasonable wire (22 AWG would be 0.2 Ω). So look for bad connections: measure across each
connector, terminal and splice with the pump running. The one with a large voltage across it is the culprit (loose
screw terminal, corroded contact, breadboard rail, a thin jumper). If the wires themselves show the drop, they're far
too thin (chapter 11).

</details>

---
← Previous: [AC, mains and electrical safety](13-ac-mains-and-electrical-safety.md) | Next: [Capacitors and RC circuits](15-capacitors-and-rc-circuits.md) →
