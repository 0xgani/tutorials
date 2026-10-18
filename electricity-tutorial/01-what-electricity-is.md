# Chapter 1 — What electricity is

## What you'll learn

- What atoms are made of, and which part of them electricity is about
- What electric charge is, how it is measured, and why like charges repel
- Why some materials (copper) conduct, some (plastic) insulate, and some (silicon) sit in between
- The difference between static electricity and electric current
- The water analogy for circuits: where it helps, and where it misleads
- What "electricity" actually means, since the word is used for at least four different things
- The Plant Station, the project you will design throughout this tutorial

## Why start with atoms?

You could learn Arduino by copying circuits: "put a 220 Ω resistor here, connect this wire there". It works until the
first time something doesn't: the LED is dim, the motor resets the board, the button reads random values, a wire gets
warm. At that point you need a mental model of what is physically happening. That model starts with the electron.

Everything in this tutorial — volts, amps, ohms, watts, wire thickness, capacitors, transistors — is a way of
describing what electrons do and how much energy they carry. Ten minutes on atoms now saves hours of confusion later.

## Atoms: protons, neutrons and electrons

All ordinary matter is made of atoms. An atom has two regions:

- A tiny, heavy **nucleus** in the middle, made of **protons** and **neutrons**.
- A cloud of much lighter **electrons** around it.

```text
                 electron cloud
              .  -  .  -  .  -  .
           -                       -
         .        ┌─────────┐        .
        -         │ nucleus │         -
         .        │ + + + 0 │        .
           -      │ 0 + 0   │      -
              .   └─────────┘   .
                 -  .  -  .  -

   +  proton   (positive charge, in the nucleus)
   0  neutron  (no charge, in the nucleus)
   -  electron (negative charge, around the nucleus)
```

The picture is not to scale. If the nucleus were a marble in the middle of a football stadium, the electrons would be
somewhere out in the stands. An atom is almost entirely empty space.

| Particle | Where | Charge | Mass (relative) |
|---|---|---|---|
| Proton | Nucleus | +1 elementary charge | 1836 |
| Neutron | Nucleus | 0 | 1839 |
| Electron | Around the nucleus | −1 elementary charge | 1 |

Two facts in this table drive all of electricity:

1. **Protons and electrons carry exactly equal and opposite charge.** An atom with as many electrons as protons has a
   total charge of zero: it is *neutral*.
2. **Electrons are about 1800 times lighter than protons and live on the outside.** Protons are locked into the
   nucleus and essentially never move in a circuit. Electrons, at least some of them, can be pulled away. In
   circuits, it is always the electrons that move.

## Electric charge

**Charge** is a basic property of particles, like mass. It comes in two kinds, which we call *positive* and
*negative* (the names are arbitrary; Benjamin Franklin picked them in the 1740s). Charges push and pull on each other:

- **Like charges repel**: two negatives push apart, two positives push apart.
- **Opposite charges attract**: a negative and a positive pull together.
- The force gets stronger as the charges get closer (it grows with the inverse square of the distance).

This attraction is what holds atoms together: the positive nucleus pulls on the negative electrons. It is also the
force that, in the end, pushes electrons through a wire.

### Measuring charge: the coulomb

The charge of one electron is tiny. It is called the **elementary charge**, `e`:

```text
e = 1.602176634 × 10⁻¹⁹ coulombs   (exact, by definition since 2019)
```

The SI unit of charge is the **coulomb** (symbol **C**). One coulomb is the charge of about
6.24 × 10¹⁸ electrons — six and a quarter billion billion. You will rarely calculate in coulombs directly, but the
coulomb is the foundation of the units you *will* use every day: the ampere is coulombs per second (chapter 3), and
the volt is energy per coulomb.

If you have never seen numbers like `10⁻¹⁹` before, don't worry: chapter 6 covers powers of ten and unit prefixes
properly. For now, "10⁻¹⁹" just means "unimaginably small".

### Charge is conserved

Charge is never created or destroyed; it only moves. When you rub a balloon on your hair, the balloon doesn't
*create* negative charge — it steals electrons from your hair. The balloon ends up with extra electrons (negative),
your hair with missing electrons (positive), and the total is unchanged. This **conservation of charge** becomes
Kirchhoff's current law in chapter 9: whatever current flows into a point in a circuit must flow out.

## Conductors, insulators and semiconductors

Whether a material lets charge flow depends on how tightly its atoms hold their outermost electrons.

### Conductors

In metals, atoms are packed into a crystal, and each atom gives up one or two of its outermost electrons to a shared
"sea" of **free electrons** that wander through the whole piece of metal. The atoms left behind are positive ions,
fixed in place.

```text
   Copper: a lattice of fixed positive ions in a sea of free electrons

     (+)   -   (+)    -  (+)   -   (+)
        -        -          -
     (+)    (+)   -  (+)    (+)  -
       -       -        -      -
     (+)  -  (+)    (+)   -  (+)

   (+) copper ion, fixed in the crystal
    -  free electron, able to move anywhere in the metal
```

Copper has roughly 8.5 × 10²⁸ free electrons per cubic metre — about one per atom. Because so many charges are free
to move, a tiny push makes a large current flow. That is what a **conductor** is. Good conductors, from best: silver,
copper, gold, aluminium. Copper is used for almost all wiring because it is the best conductor that is also cheap,
easy to draw into wire and easy to solder. Gold is used on connector contacts not because it conducts better (it
doesn't) but because it doesn't corrode.

Other conductors you will meet: steel (screws, cases), tin and solder, graphite (pencil lead), salt water, and the
human body (a poor conductor, but a conductor — chapter 13).

### Insulators

In plastic, glass, rubber, dry wood, air and ceramic, every electron is tightly bound to its own atom or molecule.
There are almost no free charges, so even a large push produces essentially no current. These materials are
**insulators**. The coloured plastic around a wire is an insulator: it keeps the current inside the copper and keeps
your fingers out of it.

"Insulator" doesn't mean "infinitely resistant". A strong enough push rips electrons free and the insulator breaks
down: lightning through air, a spark across a gap, a burnt-through capacitor. Every insulator has a breakdown voltage,
and wire insulation has a voltage rating printed on it for that reason.

### Semiconductors

Silicon and germanium sit in between. Pure silicon at room temperature has a few free electrons, far fewer than a
metal, so it conducts poorly. The trick is that adding tiny, controlled amounts of other elements (**doping**) changes
how well it conducts — and that conductivity can then be switched on and off with a voltage. Diodes, LEDs,
transistors and the microcontroller on an Arduino are all made this way. Chapters 17 and 18 explain how.

| Class | Free charges | Examples | Role in circuits |
|---|---|---|---|
| Conductor | Very many | Copper, silver, aluminium, gold, solder | Wires, traces, contacts |
| Semiconductor | Few, controllable | Silicon, germanium, gallium arsenide | Diodes, LEDs, transistors, chips |
| Insulator | Almost none | Plastic, rubber, glass, ceramic, air | Wire insulation, PCB material, cases |

## Static electricity vs. electric current

Both are about charge, but they behave very differently.

**Static electricity** is charge that has been separated and *stays put*: the extra electrons on a rubbed balloon, or
on you after walking across a carpet. Static charges can be at very high "pressure" (thousands of volts) but there is
very little charge, and it isn't going anywhere — until it finds a path. Then it all rushes across at once: the
spark when you touch a door handle. That spark can be several thousand volts, and while it can't hurt you, it can
easily destroy a chip. Chapter 20 covers ESD (electrostatic discharge) protection.

**Electric current** is charge *moving continuously* around a closed path. A battery keeps pushing electrons out of
one terminal, through the circuit, and back into the other terminal, for as long as the path is complete. Almost
everything in electronics is about current: how much flows, where, and what it does along the way.

## The water analogy

A circuit is invisible, so people explain it with water in pipes. The analogy is useful enough that you'll see it
everywhere, so it is worth learning properly, including its limits.

```text
         pump (battery)                    narrow section (resistor)
        ┌──────────┐                     ┌──────────────┐
  ┌────►│ pushes   ├─────────────────────┤  ══════════  ├─────┐
  │     │ water    │   wide pipe (wire)  └──────────────┘     │
  │     └──────────┘                                          │
  │                                                           │
  └──────────────────────── return pipe ◄─────────────────────┘
```

| Water | Electricity | Unit | Chapter |
|---|---|---|---|
| Amount of water | Charge | coulomb (C) | 1, 3 |
| Flow rate (litres per second) | Current | ampere (A) | 3 |
| Pressure difference created by the pump | Voltage | volt (V) | 3 |
| Narrow section that restricts flow | Resistance | ohm (Ω) | 4 |
| Pump's work per second | Power | watt (W) | 5 |
| Wide vs. narrow pipe | Thick vs. thin wire | — | 11 |
| A tank that fills up | Capacitor | farad (F) | 15 |
| A heavy water wheel that resists speeding up or slowing down | Inductor | henry (H) | 16 |
| A one-way valve | Diode | — | 17 |
| A valve controlled by a small pilot pipe | Transistor | — | 18 |

What the analogy gets right:

- **The pipes are already full.** Turning on the pump doesn't have to wait for water to travel from the pump to the
  narrow section; the whole loop starts moving at once. Wires are likewise full of free electrons before you connect
  anything. Chapter 2 explains why this is why a lamp lights instantly.
- **Flow needs a closed loop.** Water pumped out must come back. Current pumped out of a battery must return to it.
- **Pressure is a difference.** A pump creates a pressure *difference* between its outlet and inlet. Voltage is
  likewise always measured *between two points*.
- **Flow is the same everywhere in a single loop.** With no branches, every section of pipe carries the same litres
  per second, even the narrow one. Every part of a single-loop circuit carries the same current.
- **A narrow pipe for the same flow needs more pressure.** And a thin wire carrying a lot of current "uses up" more
  voltage and gets hotter (chapter 11).

Where it misleads:

- **Electrons don't flow because they are pushed from behind like water.** They are pushed by an electric field that
  is set up along the whole wire almost at the speed of light (chapter 2).
- **A break in a wire doesn't leak.** Cut a water pipe and water sprays out. Cut a wire and current simply stops; the
  electrons stay in the metal.
- **Energy doesn't travel inside the wire the way water's energy travels inside the pipe.** In electricity the energy
  flows through the electromagnetic field around the wires. You won't need this for Arduino work, but it is the truth
  behind several puzzles (chapter 2).
- **Voltage alone doesn't tell you what will happen.** High pressure with no flow (static) is harmless to a pipe;
  likewise 10 000 V of static charge is just a spark. What matters for heating and for danger is the current that
  actually flows and for how long.

Use the analogy to get intuition, then trust the formulas.

## Four meanings of "electricity"

The everyday word "electricity" is ambiguous. When people say...

| ...they say | ...they usually mean | The precise word |
|---|---|---|
| "Electricity flows through the wire" | Moving charge | **Current** (amperes) |
| "Careful, that's high electricity" | The push behind the charge | **Voltage** (volts) |
| "This uses a lot of electricity" | Energy used over time | **Energy** (watt-hours, kilowatt-hours) or **power** (watts) |
| "Static electricity" | Charge that has built up | **Charge** (coulombs) |

From now on this tutorial uses the precise words, and you should too. Most confusion in beginner forums comes from
mixing them up: "my 9 V battery has lots of power" (it has little energy), "this power supply will push 5 A into my
Arduino" (it won't, chapter 4), "thicker wire carries more voltage" (it carries more current, chapter 11).

## What can electricity do?

Moving charge carries energy, and circuits are ways of turning that energy into something useful:

- **Heat**: every conductor with resistance warms up when current flows (chapter 5). Kettles and toasters do it on
  purpose; wires and chips do it as waste.
- **Light**: an LED turns electrical energy into light directly (chapter 17). An incandescent bulb heats a filament
  until it glows.
- **Motion**: current in a wire creates a magnetic field, which can push magnets — motors, relays, speakers
  (chapter 16).
- **Information**: a microcontroller uses voltages to represent 1s and 0s and switches them billions of times a day
  (chapter 19).
- **Chemistry**: charging a battery stores energy as chemical changes; electroplating deposits metal (chapter 12).

## The Plant Station

You will learn faster with a goal. Throughout this tutorial you design the electrical side of a **Plant Station**: a
small box that keeps a houseplant alive. Later, when you start Arduino, you can build it for real.

```text
  ┌───────────────────────────── Plant Station ─────────────────────────────┐
  │                                                                         │
  │   [ power supply ] ──► [ microcontroller board ] ──► status LED          │
  │                              ▲        │                                 │
  │                              │        └──► switch ──► 12 V water pump    │
  │        push button ──────────┤                                          │
  │        soil-moisture probe ──┘                                          │
  │                                                                         │
  └─────────────────────────────────────────────────────────────────────────┘
```

It does four things:

1. Reads a **soil-moisture probe** stuck in the pot.
2. When the soil is dry, runs a small **12 V water pump** for a few seconds.
3. Shows its state on a **status LED** (green: fine; blinking: watering; off: no power).
4. Has a **push button** to water manually.

Every one of those parts raises a question this tutorial answers:

| Question | Answered in |
|---|---|
| What resistor goes in front of the LED, and why is one needed at all? | Chapters 4, 17 |
| How much power does the whole thing use, and how long would a battery last? | Chapters 5, 12 |
| How does a probe in wet soil become a number the board can read? | Chapters 10, 19 |
| How thick must the wire to the pump be? | Chapter 11 |
| Which power supply should it use? | Chapter 12 |
| Why can't the board's pin just power the pump directly? | Chapters 16, 18 |
| Why does the button need an extra resistor? | Chapter 19 |
| What stops a wiring mistake from destroying it? | Chapters 17, 20 |

You don't need to know any of the answers yet. Keep the napkin sketch in mind; each chapter adds one piece.

## Key takeaways

- Atoms have a positive nucleus (protons, neutrons) and light negative electrons around it. In circuits, only
  electrons move.
- Charge comes in two kinds; like charges repel, opposite charges attract. It is measured in coulombs; one electron
  carries 1.6 × 10⁻¹⁹ C. Charge is conserved.
- Conductors (metals) have a sea of free electrons; insulators have almost none; semiconductors have a few, and their
  conductivity can be controlled, which is what makes diodes, transistors and chips possible.
- Static electricity is charge that has built up and stays put; current is charge moving around a closed loop.
- The water analogy (pressure = voltage, flow = current, narrow pipe = resistance) is a good first model. Its biggest
  lesson is that wires are already full of charge, and every loop must be closed.
- Say current, voltage, power, energy or charge instead of "electricity".

## Exercises

1. **Particles.** Which particle moves when current flows in a copper wire, and why not the others?

<details>
<summary>Solution</summary>

The electrons — specifically the free (conduction) electrons in copper's electron sea. Protons and neutrons are
bound in the nuclei, which are fixed in the metal's crystal lattice and about 1800 times heavier; they vibrate in
place but do not travel along the wire. Neutrons also have no charge, so an electric push has no effect on them.

</details>

2. **Counting electrons.** A coulomb is about 6.24 × 10¹⁸ electrons. A small balloon rubbed on your hair picks up
   roughly 0.1 microcoulombs (0.1 × 10⁻⁶ C, which is 10⁻⁷ C). About how many extra electrons is that?

<details>
<summary>Solution</summary>

```text
electrons = charge / charge per electron
          = 1 × 10⁻⁷ C / 1.6 × 10⁻¹⁹ C
          ≈ 6 × 10¹¹ electrons
```

About 600 billion electrons. That sounds like a lot, but it is a tiny fraction of the electrons in the balloon. The
same number flowing through an LED would light it for only a few microseconds.

</details>

3. **Classify.** Sort these into conductor, insulator or semiconductor: aluminium foil, a rubber band, a pencil lead,
   tap water, a silicon chip's die, dry air, a stainless-steel spoon, the PCB (green board) under an Arduino's chips.

<details>
<summary>Solution</summary>

- Conductors: aluminium foil, pencil lead (graphite), tap water (because of dissolved salts; pure water conducts
  very poorly), stainless-steel spoon.
- Insulators: rubber band, dry air, the PCB material (fibreglass and epoxy, "FR-4"; the copper tracks on it are
  conductors).
- Semiconductor: the silicon die.

</details>

4. **Fix the vocabulary.** Rewrite each sentence with the precise word: (a) "This cable can carry a lot of
   electricity, it's thick." (b) "My battery is out of electricity." (c) "Don't touch that, it has dangerous
   electricity."

<details>
<summary>Solution</summary>

(a) "This cable can carry a lot of **current**." Thickness sets how much current a wire can carry without overheating
(chapter 11), not its voltage.

(b) "My battery is out of **energy**" (or "its charge is used up"; strictly, a battery stores chemical energy, not
charge, chapter 12).

(c) "Don't touch that, it is at a dangerous **voltage**." Voltage is what can drive a dangerous current through your
body (chapter 13).

</details>

5. **Stretch the analogy.** Using the water analogy, explain why a single closed loop of wire with a battery and a
   lamp carries the same current through the battery, the wires and the lamp. Then describe one thing that happens
   in a real circuit that the water analogy predicts wrongly.

<details>
<summary>Solution</summary>

In a single closed loop of full pipe, water can't pile up anywhere (it's incompressible and the pipes are full), so
every cross-section must pass the same litres per second, including the narrow section and the pump itself. Charge
in a conductor behaves the same: it can't pile up in a wire (charge is conserved and a wire stays neutral), so every
point in a single loop carries the same current.

Things the analogy predicts wrongly, any one of:

- Cutting a wire doesn't spill electrons; current just stops.
- The push on the electrons is an electric field established along the whole circuit almost at the speed of light,
  not a pressure wave pushed from the pump through the "fluid".
- Energy travels through the field around the wires, not inside the copper.

</details>

---
← Previous: [README](README.md) | Next: [How electrons move](02-how-electrons-move.md) →
