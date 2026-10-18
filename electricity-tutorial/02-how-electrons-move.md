# Chapter 2 — How electrons move

## What you'll learn

- What free electrons in a wire are doing when no current flows
- What an electric field is, and how a battery creates one along a whole circuit
- What drift velocity is, and how to calculate it (it is astonishingly slow)
- Why a lamp still lights the instant you close the switch
- Why electrons and "current" flow in opposite directions on every diagram
- How collisions between electrons and atoms turn into resistance and heat
- How electrons move in alternating current
- Where the energy in a circuit actually travels
- How current density connects electron motion to wire thickness

## Electrons in a wire at rest

Take a piece of copper wire that isn't connected to anything. Chapter 1 said it is full of free electrons, about one
per copper atom. Are they sitting still?

No. They are moving extremely fast — around 1.6 × 10⁶ metres per second, about 0.5 % of the speed of light — but in
**completely random directions**. Each electron flies a few tens of nanometres, bounces off an imperfection or a
vibrating copper ion, and flies off in another direction. Billions of times a second.

```text
   A wire with no current: fast, random motion, no net movement

   ┌──────────────────────────────────────────────┐
   │   ↗   ←    ↓     ↖    →   ↙    ↑    ↘   ←     │
   │ ↓    ↗   ←   ↘    ↑   ←    ↗   ↓   →    ↖    │
   └──────────────────────────────────────────────┘
        For every electron going left, another goes right.
```

Because the directions are random, for every electron crossing a slice of the wire to the right, another crosses
to the left. The *net* movement of charge is zero. That is what "no current" means: not that electrons are still,
but that they go nowhere on average.

## The electric field

A charge creates an **electric field** around itself: a region where other charges feel a force. You can think of
it as an invisible slope. A positive charge is a hill top for electrons to roll *toward* (opposites attract); a
negative charge is a hill they roll *away* from.

A battery's chemistry keeps one terminal with a surplus of electrons (the **negative terminal**, `−`) and the other
with a shortage (the **positive terminal**, `+`). When you connect a wire from one terminal to the other, charges
rearrange on the surface of the wire within a tiny fraction of a second, and an electric field is set up *along the
inside of the entire wire*, pointing from `+` to `−`.

```text
          +  ─────────── wire ───────────  −
     ┌──────┐                            ┌──────┐
     │ +    │   E field: ──►──►──►──►──► │    − │
     │  bat │                            │ tery │
     └──────┘                            └──────┘
   (field points from + to −; electrons are pushed the other way, from − to +)
```

Every free electron in the wire now feels a small force against the field direction, toward the `+` terminal. That
force is the push behind current. The **voltage** of the battery (chapter 3) measures how strong that push is in
total, from one end of the circuit to the other.

## Drift velocity: slower than a snail

Under the field, each electron still zooms around randomly and still collides constantly, but between collisions it
is nudged slightly toward `+`. Averaged over all electrons, that nudge adds up to a slow, steady **drift**:

```text
   A wire with current: the same random motion plus a slow net drift

   ┌──────────────────────────────────────────────┐
   │   ↗   ←    ↓     ↖    →   ↙    ↑    ↘   ←     │   overall, electrons creep
   │ ↓    ↗   ←   ↘    ↑   ←    ↗   ↓   →    ↖    │   ◄◄◄ toward the + end
   └──────────────────────────────────────────────┘
```

How fast is the drift? You can calculate it. The current `I` through a wire is the charge passing per second:

```text
I = n × A × v × e

I   current, in amperes (coulombs per second)
n   free electrons per cubic metre        (copper: 8.5 × 10²⁸ m⁻³)
A   cross-sectional area of the wire, m²
v   drift velocity, m/s
e   charge of one electron                (1.6 × 10⁻¹⁹ C)
```

The picture behind the formula: in one second, every electron within `v` metres of a slice of the wire crosses it.
That is a cylinder of volume `A × v`, holding `n × A × v` electrons, each carrying `e`.

Rearranged for the drift velocity:

```text
v = I / (n × A × e)
```

**Worked example.** A current of 1 A in a copper wire with a cross-section of 1 mm² (that is 1 × 10⁻⁶ m², a typical
thickness for a lamp cord):

```text
v = 1 A / (8.5 × 10²⁸ m⁻³ × 1 × 10⁻⁶ m² × 1.6 × 10⁻¹⁹ C)
  = 1 / 13.6
  ≈ 0.073 mm/s   (7.3 × 10⁻⁵ m/s)
```

Less than a tenth of a millimetre per second. A snail is about twenty times faster. An electron entering a one-metre
lamp cord would take almost four hours to reach the lamp. A 20 mA LED current in a breadboard jumper wire drifts at
around 0.005 mm/s.

## Why the lamp lights instantly

If electrons crawl, why does the lamp light the moment you flick the switch?

Because the electrons that light the lamp are the ones **already in the lamp**. The wire is full of free electrons
from end to end. Closing the switch doesn't have to send electrons from the battery to the lamp; it lets the electric
field establish itself along the whole circuit, and that change spreads at a large fraction of the speed of light
(typically 50–99 % of it, depending on the insulation and the geometry of the wires). Within nanoseconds every
electron everywhere in the loop starts drifting together, including the ones in the lamp's filament.

The water analogy captures this well: when you open a tap, water comes out immediately. It isn't the water from the
reservoir many kilometres away; it's the water already in the pipe right behind the tap, pushed by a pressure change
that travelled down the full pipe much faster than any water molecule.

```text
  Two different speeds, not to be confused:

  signal (field) speed    ~ 2 × 10⁸ m/s    lights the lamp, carries your data
  random electron speed   ~ 1.6 × 10⁶ m/s   no net effect
  drift speed             ~ 10⁻⁴ m/s        the actual net flow of electrons
```

For Arduino work the practical consequence is that, in a circuit on your desk, a change of voltage at one end is
felt at the other end essentially at once. You only need to think about signal travel time in very fast circuits
(hundreds of megahertz and above), long cables, or radio.

## Conventional current vs. electron flow

Here is an accident of history you need to know about. Franklin decided which charge to call "positive" long before
anyone knew electrons existed. He guessed that the thing flowing was positive. It turned out to be the electron,
which by his naming is negative.

By then, every textbook, formula and diagram was written with current flowing from `+` to `−`. Rather than rewrite
everything, engineers kept the convention:

- **Conventional current** flows **out of the positive terminal**, through the circuit, **into the negative
  terminal**. It is the direction a positive charge would move.
- **Electron flow** goes the other way: out of `−`, through the circuit, into `+`.

```text
             conventional current I ─────►
        ┌──────────────────────────────────────┐
        │                                      │
      + │                                      │
     ┌──┴──┐                                ┌──┴──┐
     │ bat │                                │ lamp│
     └──┬──┘                                └──┬──┘
      − │                                      │
        │                                      │
        └──────────────────────────────────────┘
             ◄───── electron flow (actual electrons)
```

Both descriptions produce the same answers: a negative charge moving left carries charge the same way as a
positive charge moving right. Every schematic, datasheet and formula you will see (including every arrow on a diode
or transistor symbol) uses **conventional current**. So does this tutorial from now on. When it matters that
electrons are what physically moves, the text says "electrons" explicitly.

## Collisions, resistance and heat

Why do electrons need a continuous push to keep drifting? Why don't they just accelerate forever?

Because they keep colliding. In a perfect, motionless crystal an electron would glide through unhindered. Real metal
has atoms vibrating with heat, impurity atoms, and boundaries between crystal grains. Each collision knocks the
electron's extra drift motion away, and the energy the field gave it goes into making the lattice vibrate a little
harder. Lattice vibration *is* heat.

This gives you the physical picture behind three facts you'll use constantly:

1. **Resistance** (chapter 4) is a measure of how much push is needed to keep a given current flowing through
   something, because of those collisions.
2. **Current in a resistance always produces heat** (chapter 5). The energy the battery gives the electrons ends up
   as warmth in whatever they collide in: mostly the resistor or the lamp filament, but also a little in every wire.
3. **Hot metal resists more.** The hotter the metal, the more its atoms vibrate, the more often electrons collide,
   and the higher its resistance. A filament lamp's resistance rises about tenfold from cold to glowing. Copper's
   resistance rises about 0.4 % per °C (chapter 11).

## Current density: where thickness comes in

The drift formula already contains the reason wire thickness matters. Rearrange it differently:

```text
I / A = n × v × e

I / A is the current density J, amperes per square metre (or per square millimetre)
```

For a fixed current, a **thinner wire** (smaller `A`) has a **higher current density**, which means each electron
must drift **faster** to carry the same current. Faster drift needs a stronger push along the wire (more voltage
"used up" in the wire), and causes more energetic collisions (more heat per unit length).

| Wire cross-section | Current | Current density | Drift velocity |
|---|---|---|---|
| 2.5 mm² (house wiring) | 1 A | 0.4 A/mm² | 0.029 mm/s |
| 1 mm² (lamp cord) | 1 A | 1 A/mm² | 0.073 mm/s |
| 0.33 mm² (22 AWG jumper) | 1 A | 3 A/mm² | 0.22 mm/s |
| 0.05 mm² (30 AWG wire-wrap) | 1 A | 20 A/mm² | 1.5 mm/s |

At 20 A/mm² a thin wire will get hot very quickly. Chapter 11 turns this intuition into numbers: resistance per
metre, voltage drop, and how much current a given wire can safely carry.

## Electrons in alternating current

In **direct current** (DC, from a battery) the field always points the same way, so electrons drift steadily in one
direction. In **alternating current** (AC, from a wall socket) the field reverses direction many times a second — 50
times per second in Europe and most of the world, 60 in North America. The electrons just jiggle back and forth.

How far? At 1 A in a 1 mm² wire at 50 Hz, the drift speed peaks at about 0.1 mm/s and reverses 100 times a second, so
each electron moves back and forth over roughly a third of a *micrometre* — less than the width of a bacterium. The
electrons in your kettle's cord never leave the cord. AC still transfers energy perfectly well, because energy is
carried by the field, not by electrons travelling to the kettle. Chapter 13 covers AC.

## Where the energy travels

If the electrons barely move, how does energy get from a battery to a lamp a metre away in nanoseconds?

The full answer from physics is that the energy flows through the **electromagnetic field in the space around and
between the wires**, guided by the wires, and enters the lamp's filament from the side. The electrons in the wires
are part of the guide, not tiny delivery trucks.

You do not need this for Arduino work, and every calculation in this tutorial works with the simpler "energy is
delivered where current flows through a resistance" model. It's worth knowing for two reasons: it removes the
puzzle of slow electrons and instant energy, and it is the root of very practical rules you'll meet later, such as
"keep a wire and its return wire close together" (chapter 20), which reduces interference because the field between
them stays compact.

## A battery is a pump, not a tank of electrons

One more misconception to clear up. A battery does not contain a store of electrons that it sends out and loses. In
a circuit, every electron that leaves the `−` terminal is matched by one entering the `+` terminal. The total number
of electrons in the battery stays the same.

What a battery stores is **chemical energy**. Its chemical reaction can only proceed if electrons are carried round
the external circuit from one electrode to the other, and in exchange it gives each coulomb that passes through it a
fixed amount of energy. That "energy per coulomb" is its voltage. When the chemicals are used up, the battery is
"flat": it still has all its electrons, but can no longer push them (chapter 12).

## Key takeaways

- Free electrons in a wire move very fast at random; with no field, there is no net movement and no current.
- A battery sets up an electric field along the whole circuit. The field pushes every free electron toward the `+`
  terminal, adding a slow net drift to their random motion.
- Drift velocity is tiny, about 0.07 mm/s for 1 A in a 1 mm² copper wire (`v = I / (n × A × e)`). The lamp lights
  instantly because the field spreads at near light speed and the wire is already full of electrons.
- Conventional current flows from `+` to `−`; electrons flow from `−` to `+`. All diagrams and formulas use
  conventional current.
- Collisions between electrons and the vibrating lattice cause resistance and turn electrical energy into heat.
  Hotter metal resists more.
- For the same current, a thinner wire has a higher current density, faster drift, more voltage lost and more heat.
- In AC, electrons only jiggle back and forth; energy is carried by the field around the wires.
- A battery stores chemical energy, not electrons. It gives each coulomb passing through it a fixed amount of
  energy: its voltage.

## Exercises

1. **Directions.** In a circuit made of a battery and an LED, which way does conventional current go through the
   LED, and which way do the electrons go? Which direction do datasheets use?

<details>
<summary>Solution</summary>

Conventional current flows out of the battery's `+` terminal, through the LED from its anode to its cathode, and back
into `−`. Electrons go the opposite way: out of `−`, through the LED from cathode to anode, into `+`. Datasheets,
schematics and diode symbols (the arrow points from anode to cathode) all use conventional current.

</details>

2. **Drift in a jumper wire.** A breadboard jumper is 22 AWG, about 0.33 mm² (3.3 × 10⁻⁷ m²). Calculate the drift
   velocity of electrons when it carries 20 mA (0.02 A), the current of a typical LED.

<details>
<summary>Solution</summary>

Work out the denominator first, keeping the plain numbers and the powers of ten separate:

```text
n × A × e = 8.5 × 10²⁸ × 3.3 × 10⁻⁷ × 1.6 × 10⁻¹⁹
          = (8.5 × 3.3 × 1.6) × 10^(28 − 7 − 19)
          = 44.9 × 10²
          ≈ 4490   (coulombs of free charge per metre of wire)

v = I / (n × A × e)
  = 0.02 A / 4490 C/m
  ≈ 4.5 × 10⁻⁶ m/s = 0.0045 mm/s
```

About four and a half micrometres per second. Note how keeping track of the powers of ten separately is the safest
way to do this kind of calculation; chapter 6 practises it.

</details>

3. **Instant light.** A friend says: "Electrons are fast, that's why the light comes on immediately." Explain what's
   wrong with that and give the correct explanation in two or three sentences.

<details>
<summary>Solution</summary>

The electrons' *net* (drift) speed is tiny, fractions of a millimetre per second; their high speed is random and
carries nothing. The light comes on immediately because the wire is already full of free electrons, and closing the
switch establishes the electric field along the whole circuit at close to the speed of light, so the electrons
already inside the lamp start moving at once.

</details>

4. **Heating and thickness.** Using the idea of current density, explain why a thin wire carrying the same current
   as a thick wire gets hotter.

<details>
<summary>Solution</summary>

The same current through a smaller cross-section means a higher current density (`J = I / A`), so the electrons
must drift faster. Pushing electrons faster through the same lattice needs a stronger field along the wire, and the
energy the field delivers is lost in collisions with the lattice as heat. The thin wire therefore converts more
energy into heat per metre (chapters 5 and 11 make this exact: heat per metre is proportional to `I² / A`), and with
less surface area to shed that heat, its temperature rises further.

</details>

5. **AC travel.** In a wire carrying AC at 60 Hz, how many times per second does the direction of current reverse?
   Roughly how far does an electron travel along a household cable in a day of continuous AC use?

<details>
<summary>Solution</summary>

At 60 Hz there are 60 full cycles per second, and the direction reverses twice per cycle: 120 times per second.

Over a day, an electron's net travel is essentially zero: it moves back and forth over a fraction of a micrometre
and ends up where it started. The energy delivered to appliances is carried by the field, not by electrons
travelling from the power station.

</details>

---
← Previous: [What electricity is](01-what-electricity-is.md) | Next: [Charge, current and voltage](03-charge-current-and-voltage.md) →
