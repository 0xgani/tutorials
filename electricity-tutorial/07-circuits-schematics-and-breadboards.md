# Chapter 7 — Circuits, schematics and breadboards

## What you'll learn

- The four ingredients of every circuit: a source, a load, conductors and a closed path
- Open circuits and short circuits, and why a short is dangerous
- How switches control a circuit, and why switch placement matters
- The schematic symbols you'll meet in Arduino projects
- How to read a schematic: nets, junctions, crossings, power and ground labels
- Schematics vs. wiring diagrams vs. physical layout
- How a breadboard is connected inside, and the mistakes everyone makes with one
- The Plant Station's LED circuit drawn properly

## What makes a circuit

A **circuit** is a closed path that current can flow around. Every working circuit has:

1. **A source** that provides the voltage: a battery, a USB port, a power supply, a microcontroller pin.
2. **A load** that uses the energy: an LED, a motor, a resistor, a whole board.
3. **Conductors** that connect them: wires, PCB tracks, breadboard strips.
4. **A complete loop**: from the source's `+`, through the load, back to its `−`.

```text
            conductor
      ┌───────────────────┐
      │                   │
   + ─┴─                ┌─┴─┐
 source  battery        │   │ load (lamp)
   − ─┬─                └─┬─┘
      │                   │
      └───────────────────┘
          return conductor
```

The return path is as important as the outward one. Current that leaves the `+` terminal must get back to the `−`
terminal of the *same* source. Beginners often wire the `+` side carefully and forget that the load's other end must
go back to the source's `−` (ground). No return path, no current.

## Open, closed and short circuits

- A **closed circuit** has a complete path through the load. Current flows and the load works.
- An **open circuit** has a break somewhere: a switch that's off, a loose wire, a broken solder joint, a blown fuse.
  No current flows anywhere in that loop. The full source voltage appears **across the gap** (chapter 9 explains
  why), which is how you find breaks with a multimeter (chapter 14).
- A **short circuit** is a path from `+` to `−` that **bypasses the load** with almost no resistance. Ohm's law says
  a near-zero resistance means a huge current, limited only by the source's internal resistance and the wire.

```text
   closed                      open                        short
  ┌──────┐                   ┌──  ──┐                    ┌──────┐
  │     [L]                  │  gap [L]                  │  ┌──[L]  ← bypassed, gets nothing
 (+)     │                  (+)     │                   (+) │   │
 (−)     │                  (−)     │                   (−) │   │
  └──────┘                   └──────┘                    └──┴───┘  ← near-zero resistance path
 current flows             no current                 huge current, through the wire
```

A short is dangerous: the wire, the battery or the supply gets hot, a lithium battery can catch fire, a USB port or
a board's regulator can be damaged. On a breadboard, the classic short is a stray jumper from the `+` rail to the `−`
rail, or a component leg bridging both. Sensible power supplies have current limiting or fuses (chapter 20); develop
the habit of disconnecting power before changing wiring.

"Short" is also used more loosely for any unintended low-resistance connection: "pins 3 and 4 are shorted by a solder
blob".

## Switches

A **switch** opens or closes a circuit. Its contacts are either touching (closed, "on") or apart (open, "off").

| Kind | What it does | Typical use |
|---|---|---|
| Push button, momentary (normally open) | Closed only while pressed | Arduino buttons, reset |
| Toggle or slide, SPST | On/off, stays where you put it | Power switch |
| SPDT | Connects a common terminal to one of two others | Selecting between two options |
| Reed switch | Closed by a magnet | Door sensors |
| Relay | Closed by an electromagnet (chapter 16) | Switching big loads from small signals |
| Transistor | An electronic switch (chapter 18) | Everything a microcontroller switches |

SPST means "single pole, single throw": one circuit, one on position. "Pole" counts independent circuits switched
together; "throw" counts the positions each pole can connect to. You'll see DPDT (two circuits, two positions each)
in motor-reversing circuits.

In a single loop, a switch works anywhere: the current is the same everywhere, so breaking the loop at any point
stops it. Where you put it still matters for safety and design:

- **Mains appliances** switch the live (hot) wire, so that a switched-off device has no dangerous voltage inside
  (chapter 13).
- **Microcontroller circuits** usually switch loads on their ground side ("low-side switching", chapter 18) and
  read buttons connected to ground (chapter 19).

## Schematics

A **schematic** (or circuit diagram) shows *what is connected to what*, using standard symbols. It does not show
where parts physically sit, how long the wires are, or what colour they are. That abstraction is the point: once you
can read schematics, you can understand any circuit from a datasheet, a forum post or an Arduino shield's
documentation.

### Common symbols

Two drawing standards exist: the American (ANSI/IEEE) and the international (IEC). You'll see both, often mixed. Here
they are as drawn in this tutorial's ASCII diagrams, with how they look in a real schematic:

| Component | ASCII here | Real schematic | Notes |
|---|---|---|---|
| Wire | `────` `│` | A line | Same potential along its whole length |
| Resistor | `──[ R ]──` | Zig-zag (US) or rectangle (IEC) | Labelled with value: `330Ω`, `4k7` |
| Battery / cell | `+│▌−` or `(+)/(−)` | Long line (+) and short line (−); several for a multi-cell battery | Long plate is positive |
| DC voltage source | `(+ −)` | Circle with + and − | A lab supply or an ideal source |
| Ground | `GND` or `⏚` | Downward-pointing set of lines or a triangle | All ground symbols are connected |
| Supply label | `+5V`, `VCC` | Arrow or bar with a label | All points with the same label are connected |
| Switch | `──/ ──` | A line hinged open | Push buttons have a little T-shaped plunger |
| LED | `──►│──` with arrows | Triangle pointing to a bar, two arrows out | Triangle is the anode (+), bar is the cathode (−) |
| Diode | `──►│──` | Triangle pointing to a bar | Conventional current flows in the arrow's direction |
| Capacitor | `──││──` | Two parallel plates | Polarised types show `+` or a curved plate |
| Inductor / coil | `──∩∩∩──` | A row of humps or a filled rectangle | |
| Potentiometer | `[ R ]` with an arrow `↑` | Resistor with an arrow touching it | Third terminal is the wiper |
| Motor | `──(M)──` | Circle with M | |
| Lamp | `──(X)──` | Circle with a cross | |
| Fuse | `──[~]──` | Rectangle with a line through, or an S-curve | |
| Transistor | (chapter 18) | Circle with three leads | BJT or MOSFET |
| Integrated circuit | A labelled box with numbered pins | Rectangle | Arduino boards appear this way |

### Reading the connections

The rules are few and strict:

- **Lines are connections.** Any two points joined by lines are electrically the same point: a **node** or **net**.
  Every point on a net has the same voltage (ignoring wire resistance).
- **A dot means a junction.** Where lines meet at a dot, they're connected.
- **Lines that cross without a dot are not connected.** (Some old schematics draw a little hop over the crossing
  wire. Good schematics avoid four-way junctions entirely and draw T-junctions with dots, so there's no doubt.)
- **Labels connect too.** Every point labelled `+5V` is connected to every other `+5V`, and every ground symbol is
  connected to every other ground symbol, even when no line joins them. This keeps schematics readable: you don't draw
  the ground wire to every part.

```text
   connected (dot)          not connected (no dot)        connected by labels

        │                          │                     +5V          +5V
    ────●────                  ────┼────                  │            │
        │                          │                    [R1]         [R2]
                                                          │            │
                                                         GND          GND
```

- **Signal flows are usually left to right**, and **higher voltages are drawn at the top**, ground at the bottom.
  That's a convention, not a rule, but well-drawn schematics follow it, and it makes them read like the "potential
  hill" from chapter 3.

### Schematic vs. wiring diagram vs. layout

| Drawing | Shows | Good for |
|---|---|---|
| **Schematic** | Logical connections with symbols | Understanding and designing; the reference for everything else |
| **Wiring diagram / breadboard view** (e.g. Fritzing pictures) | Physical parts and wires as they look | Copying a build; useless for understanding why |
| **PCB layout** | Where parts and copper tracks physically are | Manufacturing a board |

Many Arduino tutorials only show colourful breadboard pictures. Learn to redraw them as schematics: it's the fastest
way to see what a circuit actually does, and to spot mistakes in the picture.

## Breadboards

A **solderless breadboard** lets you build circuits by pushing component leads and jumper wires into holes. Under the
plastic, metal spring clips connect groups of holes.

```text
         a b c d e   f g h i j
    +  ● ● ● ● ● ●   ● ● ● ● ● ●  +     ← power rail: whole column connected (top to bottom)
    −  ● ● ● ● ● ●   ● ● ● ● ● ●  −     ← ground rail
         ─────────   ─────────
     1   ● ● ● ● ●   ● ● ● ● ●          ← row 1: a–e connected together
     2   ● ● ● ● ●   ● ● ● ● ●                   f–j connected together
     3   ● ● ● ● ●   ● ● ● ● ●                   but a–e NOT connected to f–j
     4   ● ● ● ● ●   ● ● ● ● ●
     …                 ▲
                       └── centre gap: a chip straddles it, so each pin gets its own row
```

The internal connections:

- **Terminal strips**: each numbered row is two groups of five holes (`a–e` and `f–j`). The five holes in a group are
  connected; the two groups are separated by the centre channel.
- **Power rails**: the long columns along each edge, usually marked red `+` and blue `−`. Each rail is connected along
  its length. **On many full-size breadboards the rails are split in the middle**, so the top half and bottom half
  aren't connected. Look for a gap in the printed red/blue line, or check with a meter's continuity mode.
- **The centre channel** fits chips in DIP (dual in-line) packages: one row of pins on each side, so each pin gets its
  own strip.

### The Plant Station LED on a breadboard

Schematic:

```text
   +5V
    │
  [330Ω]
    │
    ├──── node A
    │
   ─┴─
   ╲ ╱  LED (green)   anode on top, cathode below
   ─┬─
    │
   GND
```

One way to place it:

```text
  + rail ──────●─────────────── (+5 V from the supply)
               │ red jumper
  row 10:  a ● ● ● ● ●            330 Ω from row 10 to row 14
                 ╲
  row 14:  a ● ● ● ● ●  ← resistor's other leg, and the LED's long leg (anode)
  row 15:  a ● ● ● ● ●  ← LED's short leg (cathode), and a black jumper
               │
  − rail ──────●─────────────── (0 V / GND)
```

Each **row** is one node of the schematic. Reading the breadboard back as a schematic, row by row, is the quickest
way to check your wiring.

### Common breadboard mistakes

| Mistake | Symptom |
|---|---|
| Both legs of a component in the same row (same five holes) | The component is shorted out; it does nothing |
| Using a split power rail as if it were continuous | Half the board has no power |
| Parts on the wrong side of the centre gap | Not connected to what you think |
| LED in backwards | Doesn't light (no damage at low voltage with a resistor) |
| Forgetting to connect the rail to the supply's ground | Nothing works; "no return path" |
| A jumper or component lead bridging `+` and `−` rails | Short circuit: supply shuts down, gets hot, or a board resets |
| Leads not pushed in far enough, or worn-out clips | Intermittent faults; wiggle a part and it starts working |
| Running motors or more than about 1 A through it | Clips heat up; contact resistance drops voltage (chapter 11) |

Breadboard contacts have resistance (tens of milliohms each, more as they wear) and the clips are thin. They're
designed for signal-level currents, not motors. For anything over roughly half an amp, use screw terminals,
soldered connections or proper connectors (chapter 11).

## Key takeaways

- A circuit needs a source, a load, conductors and a complete loop back to the *same* source's `−` terminal.
- An open circuit carries no current; the full voltage appears across the gap. A short circuit bypasses the load and
  draws a large current; it's how batteries and boards get destroyed.
- Switches open and close loops. In a single loop, they work anywhere; placement matters for safety and design.
- Schematics show connections, not physical layout. Lines and labels connect; dots mark junctions; crossings without
  dots don't connect; all grounds are one node.
- A breadboard's rows are groups of five connected holes; the rails run along the edges and are often split in the
  middle. Each row is one node.
- Breadboards are for low currents. Redraw breadboard pictures as schematics to understand them.

## Exercises

1. **Find the loop.** A beginner connects a battery's `+` to one leg of a lamp with a wire, and the lamp's other leg
   to nothing. The lamp doesn't light. Which of the four ingredients is missing? What does a voltmeter read between
   the lamp's free leg and the battery's `−` terminal?

<details>
<summary>Solution</summary>

The complete loop is missing: there's no return path to the battery's `−` terminal. With no current, there is no
voltage drop across the lamp, so its free leg is at the same potential as the battery's `+`. The meter reads the full
battery voltage between that leg and `−`. That's the "full voltage across an open gap" rule.

</details>

2. **Read a schematic.** In this schematic, which parts are connected to node B? Is R3 connected to R1?

```text
   +9V
    │
  [R1]
    │
    ●──── B ───[R2]────┐
    │                  │
  [R3]                 │
    │                  │
   GND                GND
```

<details>
<summary>Solution</summary>

Node B joins the bottom of R1, the top of R3 and the left end of R2 (all meet at the dot). So yes, R3 is connected to
R1, through node B. The bottoms of R3 and R2 are both on ground, so R2 and R3 are connected at both ends: they're in
parallel (chapter 8).

</details>

3. **Breadboard check.** A resistor is placed with both legs in row 12, holes `b` and `d`. An LED goes from row 12
   hole `e` to row 13. Does the resistor do anything?

<details>
<summary>Solution</summary>

No. Holes `b` and `d` of row 12 are in the same five-hole group, so both legs of the resistor are on the same node:
it is shorted out by the breadboard clip and has zero voltage across it. The LED's leg in row 12 is connected to
whatever else is in row 12, as if the resistor weren't there. Put the resistor across two different rows.

</details>

4. **Draw it.** Draw a schematic of a 9 V battery, an SPST switch, a 1 kΩ resistor and a red LED in a single loop,
   with the switch on the positive side. Then explain whether the LED still works if you move the switch to the
   ground side.

<details>
<summary>Solution</summary>

```text
   +9V ──/ ──[1kΩ]──►│── GND
        switch       LED
```

(with the battery's `+` as `+9V` and its `−` as GND.) Moving the switch to the ground side, between the LED's cathode
and GND, works identically: in a single loop, the current is the same everywhere, so breaking the loop at any point
stops it. The difference is only that with the switch on the ground side, the LED and resistor stay connected to
+9 V when "off", which matters for safety in mains equipment but not here.

</details>

5. **Split rails.** Your circuit on the bottom half of a long breadboard works, but the identical copy on the top
   half, using the same power rails, does nothing. What is the most likely cause, and how do you confirm and fix it?

<details>
<summary>Solution</summary>

The power rails are split in the middle, so the top half's rails aren't connected to the bottom half's (or to the
supply). Confirm with a multimeter in continuity mode between the top and bottom ends of a rail, or measure the
voltage between the top `+` and `−` rails (0 V). Fix it with two short jumpers bridging the gap in the `+` rail and in
the `−` rail.

</details>

---
← Previous: [Units, prefixes and part markings](06-units-prefixes-and-part-markings.md) | Next: [Series and parallel circuits](08-series-and-parallel-circuits.md) →
