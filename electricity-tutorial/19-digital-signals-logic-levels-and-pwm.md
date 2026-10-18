# Chapter 19 — Digital signals, logic levels and PWM

## What you'll learn

- How a microcontroller represents 1 and 0 as voltages, and what input thresholds are
- Output levels, and how they sag under load
- 5 V and 3.3 V logic, which combinations are safe, and how to level-shift
- Floating inputs, and why an unconnected pin reads random values
- Pull-up and pull-down resistors, including the built-in ones
- Wiring a push button, and dealing with contact bounce
- Pin current limits, and how to protect pins
- PWM: making "analog-like" output from a digital pin, for LEDs and motors
- The analog-to-digital converter in more detail
- The Plant Station's pin plan

## Digital signals are voltages

A digital circuit treats a voltage as one of two states: **HIGH** (logic 1) or **LOW** (logic 0). On a 5 V Arduino
Uno, HIGH is close to 5 V and LOW is close to 0 V. On 3.3 V boards (ESP32, Raspberry Pi Pico, Arduino Nano 33 and many
others), HIGH is close to 3.3 V.

The point of digital logic is tolerance: a signal doesn't have to be exactly 5.00 V to count as HIGH. Every logic
input has **thresholds**, and the gap between them gives noise immunity.

```text
   input voltage (ATmega328P on an Uno R3, supply 5 V)

   5.0 V ┤████████████████████  HIGH  (≥ 0.6 × VCC = 3.0 V guaranteed to read 1)
         │
   3.0 V ┤- - - - - - - - - - - V_IH (min)
         │░░░░░░░░░░░░░░░░░░░░  undefined: may read either, may flicker
   1.5 V ┤- - - - - - - - - - - V_IL (max)
         │
   0.0 V ┤████████████████████  LOW   (≤ 0.3 × VCC = 1.5 V guaranteed to read 0)
```

| Symbol | Meaning | ATmega328P at 5 V |
|---|---|---|
| `V_IH` | Minimum input voltage guaranteed to read HIGH | 3.0 V |
| `V_IL` | Maximum input voltage guaranteed to read LOW | 1.5 V |
| `V_OH` | Output HIGH voltage (at a stated current) | about 4.2 V or more at 20 mA |
| `V_OL` | Output LOW voltage (at a stated current) | about 0.9 V or less at 20 mA |

Every microcontroller's datasheet has this table. The values for other chips differ; for example, many 3.3 V chips
use 0.7 × VCC and 0.3 × VCC.

A voltage in the undefined zone is a bug: a slowly rising signal, a sagging divider, a long wire with noise. The input
may read HIGH one moment and LOW the next.

## Outputs sag under load

A digital output pin is, electrically, a pair of small transistors: one connects the pin to VCC (HIGH), the other to
GND (LOW). Each has some internal resistance, roughly 25–50 Ω on an ATmega328P. So the output behaves like a source
with an internal resistance (chapter 12), and its voltage sags as you draw current:

```text
   no load:     HIGH ≈ 5.0 V
   10 mA load:  HIGH ≈ 4.7 V
   20 mA load:  HIGH ≈ 4.4 V (typical; datasheet guarantees ≥ 4.2 V)
```

That's another reason to keep pin currents low: the more you draw, the less the pin looks like a clean 5 V, and the
more heat the chip makes inside.

## 5 V and 3.3 V logic

Mixing boards and modules from different families is common: a 5 V Uno with a 3.3 V sensor, a 3.3 V ESP32 with a 5 V
display. The rules:

| Driving side | Receiving side | Safe? |
|---|---|---|
| 5 V output | 5 V input | Yes |
| 3.3 V output | 3.3 V input | Yes |
| 3.3 V output (HIGH ≈ 3.3 V) | 5 V input | **Often works** if `V_IH` ≤ about 3 V (ATmega328P at 5 V: exactly 3.0 V — little margin) |
| 5 V output | 3.3 V input | **Usually not**: it exceeds the input's absolute maximum (typically VCC + 0.3 V ≈ 3.6 V) and can damage it, unless the datasheet says the pin is "5 V tolerant" |

Ways to level-shift:

- **5 V → 3.3 V, one direction**: a voltage divider (chapter 10), for example 1 kΩ on top and 2 kΩ below
  (`5 × 2/3 ≈ 3.33 V`), or 10 kΩ and 20 kΩ for slow signals. Simple and cheap; too slow for fast signals with large
  resistor values.
- **Bidirectional (I²C and similar)**: the common "logic level converter" boards use a small MOSFET (BSS138) and two
  pull-up resistors per channel.
- **Fast or many signals**: dedicated level-shifter chips (74AHCT125 for 3.3 V → 5 V, TXS/TXB-series for both ways).

And keep the supplies straight: never feed 5 V into a 3.3 V board's `3V3` pin.

## Floating inputs

A pin configured as an **input** has a very high input resistance (megohms or more) and draws almost no current. That
makes it a perfect voltmeter — and also means that if nothing drives it, its voltage is undefined. It **floats**.

A floating input picks up whatever is nearby: electric fields from your hand, mains wiring, neighbouring signals.
It reads random HIGHs and LOWs, often changing when you move your hand near the board. "My button reads pressed when
I'm not touching it" is almost always a floating input.

```text
   floating:                            pulled up:

   pin ──────────── (nothing)            +5V
                                          │
   reads garbage                       [ 10 kΩ ]   pull-up resistor
                                          │
                                   pin ───●───/ ──── GND
                                              button
                                   released: pin = 5 V (HIGH) through the resistor
                                   pressed:  pin = 0 V (LOW), connected to GND
```

## Pull-up and pull-down resistors

A **pull-up** resistor connects an input to VCC, so that when nothing else drives it, it reads HIGH. A **pull-down**
connects it to GND, so it reads LOW by default. Something stronger (a button, a sensor's output, another chip) can
override it, because the resistor only weakly holds the line.

How big?

- **Small enough** to overpower leakage and noise: up to about 100 kΩ for short wires indoors; 4.7–10 kΩ for longer
  wires or noisy places.
- **Large enough** not to waste current when the button is pressed: `I = 5 V / 10 kΩ = 0.5 mA` while pressed. 1 kΩ
  would waste 5 mA.
- **Small enough for speed** on data buses: I²C lines use 2.2–10 kΩ pull-ups, because each pull-up and the wiring's
  capacitance form an RC circuit that sets the rise time (chapter 15).

**10 kΩ** is the standard default for buttons.

### Built-in pull-ups

Most microcontrollers have **internal pull-up resistors** that software can enable. On the ATmega328P they are 20–50 kΩ.
In Arduino code that's `pinMode(pin, INPUT_PULLUP)`; then a button wired from the pin to GND needs no external parts.
Some chips (ESP32, RP2040, Uno R4) also offer internal pull-downs.

> **Just a taste — you don't need to learn or fully understand this now.** In Arduino code (C++), reading such a
> button looks like this. You'll learn the language with Arduino itself.

```cpp
void setup() {
  pinMode(2, INPUT_PULLUP);       // enable the internal pull-up on pin 2
}

void loop() {
  bool pressed = digitalRead(2) == LOW;   // pressed connects the pin to GND: "active low"
}
```

Wiring buttons to ground with a pull-up (so "pressed" reads LOW) is the conventional choice: no wire from the button
carries VCC around the project, and the internal pull-up does the work.

## Contact bounce

A mechanical switch doesn't close cleanly. Its contacts bounce for about 1–10 ms, making and breaking many times:

```text
   pin voltage when the button is pressed

   5 V ────────┐ ┌┐ ┌─┐┌┐
               │ ││ │ │││
   0 V         └─┘└─┘ └┘└──────────────────
               ◄─ ~1–10 ms ─►
               a fast program sees several "presses"
```

A microcontroller reading thousands of times per millisecond sees each bounce as a separate press. Two fixes:

- **Software debounce** (the usual): accept a change only after the input has been stable for 10–50 ms.
- **Hardware debounce**: an RC filter (chapter 15) smooths the bounces. With the 10 kΩ pull-up, a 100 nF capacitor
  from the pin to GND gives `τ = 1 ms`; the bounces are mostly filtered out. Chips with Schmitt-trigger inputs (most
  microcontrollers' digital inputs have some hysteresis) then turn the slow edge into a clean transition.

## Pin current limits and protection

Each output pin, and the chip as a whole, has current limits (chapter 6's absolute maximum ratings):

| Board / chip | Per-pin | Total |
|---|---|---|
| Arduino Uno R3 (ATmega328P) | 20 mA recommended, 40 mA absolute max | about 200 mA through VCC and GND pins |
| Arduino Uno R4 Minima / WiFi (RA4M1) | 8 mA | see the board's documentation |
| ESP32 | about 20–40 mA, depends on configured drive strength | |
| Raspberry Pi Pico (RP2040) | 2–12 mA, configurable drive strength | about 50 mA for all I/O |

Rules that keep pins alive:

- **Never connect an output pin straight to GND, VCC or another output.** If one is HIGH and the other LOW, the
  current is limited only by their internal transistors: a short circuit inside the chip.
- **Use a resistor in series with anything a pin drives directly**: LEDs (always), and a 220 Ω–1 kΩ resistor when
  connecting a pin to something that might be misconfigured as an output, or to a connector outside the box.
- **Never put a voltage on a pin outside 0 V…VCC** (beyond the chip's −0.5 V / VCC + 0.5 V absolute limits). Every pin
  has internal **clamp diodes** to VCC and GND; a higher voltage forces current through them, which can damage the chip
  or power the whole board "backwards" through the pin. A series resistor of 10 kΩ or more limits that current if an
  out-of-range signal is possible.
- **Use transistors for anything above a few milliamps** (chapter 18).

## PWM: pulse-width modulation

Digital pins can only be HIGH or LOW. To control an LED's brightness or a motor's speed, microcontrollers switch the
pin on and off rapidly and vary the **fraction of time it's on**: the **duty cycle**.

```text
   25 % duty           50 % duty           75 % duty
   ┌┐  ┌┐  ┌┐          ┌─┐ ┌─┐ ┌─┐         ┌──┐┌──┐┌──┐
   ││  ││  ││          │ │ │ │ │ │         │  ││  ││  │
  ─┘└──┘└──┘└──       ─┘ └─┘ └─┘ └─       ─┘  └┘  └┘  └─
   average 1.25 V      average 2.5 V       average 3.75 V      (of 5 V)

   average voltage = duty cycle × V_HIGH
```

| Term | Meaning |
|---|---|
| Period | Time for one on–off cycle |
| Frequency | `1 / period`; the classic Uno's `analogWrite()` uses about 490 Hz (980 Hz on pins 5 and 6) |
| Duty cycle | On-time / period, 0–100 %; `analogWrite(pin, 0…255)` maps 0 → 0 %, 255 → 100 % |

What PWM does to different loads:

- **An LED** blinks hundreds of times per second, too fast to see; your eye averages it, so it looks dimmer. The
  current *while on* is still set by the resistor (chapter 17), so the LED is never overdriven. This is the right way
  to dim an LED.
- **A motor** (through a MOSFET, chapter 18) has inertia and inductance that average the pulses: its speed follows the
  duty cycle. The flyback diode carries the current during every off period, so it's essential.
- **A heater** heats in proportion to the duty cycle.
- **A circuit that needs a real analog voltage** sees a square wave, not a steady voltage. Add an RC low-pass filter
  (chapter 15) to smooth it, or use a board with a true DAC (digital-to-analog converter) output.

A multimeter in DC volts shows PWM's average. Remember that the *instantaneous* voltage is still 0 or 5 V, which
matters for anything with a maximum voltage.

> **Just a taste — you don't need to learn or fully understand this now.** Setting an LED to quarter brightness in
> Arduino code:

```cpp
analogWrite(5, 64);   // pin 5: 64 / 255 ≈ 25 % duty cycle
```

## The analog-to-digital converter

Chapter 10 introduced the ADC. More precisely:

- **Resolution**: an N-bit ADC divides its range into 2ᴺ steps. 10 bits (classic Uno): 1024 steps; 12 bits (ESP32,
  Pico, and the Uno R4 when configured): 4096; 14 bits (Uno R4's maximum): 16 384.
- **Reference**: the voltage that corresponds to full scale. By default it's the board's supply (5 V on an Uno), so
  any error in the supply is an error in every reading. Boards offer internal references (for example 1.1 V on the
  ATmega328P) for more stable measurements of small voltages.
- **Step size**: `V_ref / 2ᴺ`; 5 V / 1024 ≈ 4.9 mV.
- **Accuracy**: a few steps of error are normal from offset, gain error and noise. A 12-bit ADC doesn't mean
  12-bit accuracy. Averaging several readings reduces noise.
- **Source resistance**: as chapter 10 noted, keep it under about 10 kΩ, or add a 100 nF capacitor at the input.

```text
reading = V_in / V_ref × (2ᴺ − 1)          (Arduino convention; 1023 for 10 bits)
V_in    = reading × V_ref / (2ᴺ − 1)
```

## The Plant Station's pin plan

Putting chapters 10 to 19 together, for an Arduino Uno (R3 or R4):

| Pin | Mode | Connected to | Electrical notes |
|---|---|---|---|
| D2 | Input with internal pull-up | Push button to GND | Active low; debounce in software; optional 100 nF to GND |
| D5 | PWM output | Status LED via 820 Ω to GND | 3.7 mA when fully on; PWM for dimming and blinking |
| D7 | Digital output | Top of the moisture probe | Powered only while reading (chapter 10); 0.21 mA max |
| A0 | Analog input | Probe/22 kΩ divider midpoint, 100 nF to GND | Wait ≈ 10 ms after D7 goes HIGH, then read (chapter 15) |
| D9 | PWM output | IRLZ44N gate via 220 Ω; 100 kΩ gate pull-down | Pump on/off; PWM could soft-start the pump |
| GND | — | Common ground with the 12 V supply's ground | One point; see chapter 20 |

Total pin current: under 5 mA. Well within every board's limits.

## Key takeaways

- HIGH and LOW are voltage ranges defined by `V_IH` and `V_IL`; voltages between them are undefined. Outputs sag
  under load.
- 3.3 V into 5 V inputs often works (check `V_IH`); 5 V into 3.3 V inputs usually damages them unless 5 V tolerant.
  Level-shift with a divider, a MOSFET converter or a chip.
- Unconnected inputs float and read garbage. Use a pull-up or pull-down; 10 kΩ or the internal pull-up is the default.
- Wire buttons to GND with a pull-up (active low) and debounce them.
- Respect per-pin and total current limits; never short outputs; keep inputs within 0 V…VCC; use series resistors
  and transistors.
- PWM varies the duty cycle of a fast square wave; the average is `duty × V_HIGH`. It dims LEDs and controls motor
  speed; filter it for true analog.
- An N-bit ADC has 2ᴺ steps of `V_ref / 2ᴺ`; the reference and the source resistance matter.

## Exercises

1. **Thresholds.** For an ATmega328P at 5 V (`V_IL` = 1.5 V, `V_IH` = 3.0 V), how is each input read: (a) 0.4 V,
   (b) 2.2 V, (c) 3.3 V from a 3.3 V sensor, (d) 4.6 V?

<details>
<summary>Solution</summary>

(a) LOW. (b) Undefined: it could read either; fix the circuit. (c) HIGH, but only 0.3 V above `V_IH`: works, with
little noise margin. (d) HIGH.

</details>

2. **Level shift.** A 5 V Arduino sends a signal to a 3.3 V module whose input absolute maximum is 3.6 V. Design a
   divider using standard values that gives between 3.0 V and 3.4 V from 5 V and draws under 0.5 mA.

<details>
<summary>Solution</summary>

10 kΩ (top) and 20 kΩ (bottom; or 22 kΩ): `5 × 20 / 30 = 3.33 V` (with 22 kΩ: `5 × 22 / 32 ≈ 3.44 V`, still under 3.6 V
but outside the requested range, so prefer 20 kΩ). Current `5 / 30 kΩ ≈ 0.17 mA`. Fine for slow signals; for fast
ones, the module's input capacitance and these resistances form an RC filter that rounds the edges, so use a level-
shifter chip.

</details>

3. **Pull-up size.** A button on a 3 m cable to a 5 V board picks up noise with the internal 35 kΩ pull-up. What
   external pull-up would you try, and how much current flows while the button is held?

<details>
<summary>Solution</summary>

A stronger pull-up such as 4.7 kΩ (or 2.2 kΩ): `5 V / 4.7 kΩ ≈ 1.1 mA` while held. Adding 100 nF at the pin helps too
(`τ = 4.7 kΩ × 100 nF ≈ 0.5 ms`), filtering noise and bounce. For long cables, a series resistor (say 1 kΩ) at the
pin also protects it from static discharges picked up on the cable (chapter 20).

</details>

4. **PWM average.** A pin outputs 5 V PWM with `analogWrite(pin, 191)`. What is the duty cycle and the average
   voltage? An LED with a 220 Ω resistor and a 2.0 V forward voltage is on this pin: what current flows while the pin
   is HIGH, and what is the average current?

<details>
<summary>Solution</summary>

```text
duty    = 191 / 255 ≈ 75 %
average = 0.75 × 5 V ≈ 3.75 V
I while HIGH = (5 − 2.0) / 220 ≈ 13.6 mA
average I    = 0.75 × 13.6 ≈ 10.2 mA
```

Note that the LED's current is either 13.6 mA or 0, never "3.75 V worth": you can't compute the LED current from the
average voltage, because the LED is non-ohmic.

</details>

5. **Over-voltage input.** A 12 V signal is connected through a 10 kΩ resistor to a 5 V Arduino input. Estimate the
   current into the pin's clamp diode, and say whether it's safe. What does that current do to the board's 5 V rail
   if the board draws very little?

<details>
<summary>Solution</summary>

The clamp diode holds the pin at about 5.5 V (5 V + a diode drop), so the resistor has about `12 − 5.5 = 6.5 V` across
it: `6.5 V / 10 kΩ ≈ 0.65 mA` into the 5 V rail. Small enough not to damage the diode (most datasheets tolerate around
1 mA of injection current, but check), but it flows into the 5 V rail. If the board is unpowered or draws less than
0.65 mA, the rail rises, which can power the board "backwards" or raise its supply above 5 V. The right design is a
divider (chapter 10) that scales 12 V to under 5 V.

</details>

---
← Previous: [Transistors as switches](18-transistors-as-switches.md) | Next: [Protecting circuits and good practice](20-protecting-circuits-and-good-practice.md) →
