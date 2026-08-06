# Zebra ZXP Series 9 over SNMP

The ZXP Windows driver reports nothing usable: it answers "online" unconditionally and none of its
status codes track reality. That is what made the old QZ Tray system lose badges, because a jam or a
ribbon-out looked identical to a successful print.

The printer firmware itself is far more honest, and it answers SNMP directly on the network. This is
the status channel the print agent uses. The driver is used only to move pixels onto a card.

Probed against a live unit on 2026-08-05:

```
$ snmpget -v2c -c public 10.0.0.92 1.3.6.1.2.1.1.1.0
SNMPv2-MIB::sysDescr.0 = STRING: ZXP Series 9 Card Printer
```

Community `public`, SNMP v2c, no authentication. Model ZXP Series 9, serial `Z9J181600026`,
firmware `FZ9MG.01.03.00`.

Raw baseline walks live next to this file (`baseline-idle-*.txt`) for diffing against fault states.

## Network topology

Laravel runs on the public internet. The printer and the Windows 7 host sit on a private LAN with no
inbound route. **Laravel therefore cannot poll SNMP and must never try.** Only the agent, which runs
on the LAN, talks to the printer. It maps raw firmware strings to a small stable vocabulary and
pushes that up over HTTPS, so the server and the POS deal in conditions, never in OIDs.

```
POS browser  <--- HTTPS ---  Laravel  <--- HTTPS (agent-initiated) ---  agent  --- SNMP/LAN ---  ZXP9
                                                                          |
                                                                          +--- Windows spooler ---> ZXP9
```

## The job table: per-card truth

`1.3.6.1.4.1.10642.8.5` is a rolling window of the **last 7 jobs**, newest last, maintained by
firmware. This is the single most valuable find: it gives per-card confirmation with no driver
involvement.

| OID column | Meaning | Observed values |
|---|---|---|
| `10642.8.5.1.2.1.N` | printer-side job id | monotonic integer, e.g. `52`..`58` |
| `10642.8.5.1.3.1.N` | job UUID | `cf60b800-5bbc-4766-936e-5358a82a3670` |
| `10642.8.5.1.4.1.N` | job state | `done_ok`, `cleaning_up` |
| `10642.8.5.1.5.1.N` | physical card location | `not_in_printer`, `transferring` |
| `10642.8.5.1.9/10/11.1.N` | message slots | empty when healthy |

Captured mid-print, the newest row read `cleaning_up` / `transferring` while older rows read
`done_ok` / `not_in_printer`. So a card can be followed through the machine and a terminal `done_ok`
is a real completion signal, not a spooler guess.

## Consumables

`1.3.6.1.2.1.43.11.1.1` (standard Printer MIB) has two supply rows:

| OID | Meaning | Observed |
|---|---|---|
| `43.11.1.1.5.1.1` | supply type | `7` = inkRibbon |
| `43.11.1.1.6.1.1` | description | `YMCK` |
| `43.11.1.1.7.1.1` | unit | `8` = sheets |
| `43.11.1.1.8.1.1` | max capacity | `627` |
| `43.11.1.1.9.1.1` | **level remaining** | `313` |

So the agent knows exactly how many cards the ribbon has left and can warn staff to swap it *before*
the queue stops. Row 2 carries no description and is presumed to be the retransfer film; both rows
read identically on this unit, which needs re-checking once they diverge.

## Fault signalling

| OID | Meaning | Healthy value |
|---|---|---|
| `1.3.6.1.2.1.25.3.5.1.2.1` | `hrPrinterDetectedErrorState` bitfield | `00 00 00 00` |
| `1.3.6.1.2.1.25.3.5.1.1.1` | `hrPrinterStatus` | `idle(3)` |
| `10642.8.4.1.1.1` | Zebra printer state | `idle`, `printing` |
| `10642.8.2.1.3/4/5.1` | alarm slots | `none` |
| `10642.8.8.1.19.1` | sensor fault | `none` |

`hrPrinterDetectedErrorState` is the RFC 3805 bitfield, MSB first:
`lowPaper, noPaper, lowToner, noToner, doorOpen, jammed, offline, serviceRequested,
inputTrayMissing, outputTrayMissing, markerSupplyMissing, outputNearFull, outputFull,
inputTrayEmpty, overduePreventMaint`.

`prtAlertTable` (`1.3.6.1.2.1.43.18`) is **not implemented** on this firmware, so the bitfield plus
the Zebra alarm slots are the fault channel.

### The fault vocabulary is learned, not documented

Zebra has never published a MIB for the card printer line. Their developer portal states the card
printer MIB "has not been solidified" because supply monitoring was not a priority for that product,
so the `10642.8` subtree is undocumented and the strings the firmware writes into its alarm slots
cannot be looked up anywhere. The healthy values are known from observation (`none`, `idle`,
`printing`, `done_ok`); the fault values are not.

Guessing at them is how the previous system went wrong, so the agent does not guess. It writes down
anything it cannot explain and keeps printing stopped until a human says otherwise.

**During operation**, `agent/vocabulary.py` appends any unrecognised reading to a journal at
`%APPDATA%\BadgePrintAgent\condition-journal.jsonl`, including the full SNMP walk. It deduplicates by
reading shape, so a jam that lasts ten minutes produces one entry rather than hundreds, and it
survives an agent restart without re-logging the same fault.

**Afterwards**, summarise it:

```bash
python3 print-agent/tools/journal_report.py
```

That prints the unrecognised strings and the context each appeared in. Hand that over along with
what was physically wrong with the printer, and the strings get mapped into
`ALARM_CONDITIONS` in `agent/zebra.py`. The entries stay valid indefinitely, so this can be done
long after the convention.

**Deliberately, in advance**, the same vocabulary can be captured by inducing faults on purpose
while watching every OID change live:

```bash
python3 print-agent/tools/snmp_probe.py 10.0.0.92 --log jam-test.log
```

Then, one at a time: open the cover, remove the ribbon, empty the card hopper, and jam a card.

Until a string is mapped, it classifies as `unknown`, which is a stop. The queue pauses and staff
are alerted rather than cards being fed to a broken printer. Learning the string later only improves
the message shown; it does not change whether the agent stops.

## Condition abstraction

The agent collapses raw readings into this fixed vocabulary, which is what Laravel and the POS see.
`stop` conditions pause the batch and require a human.

| Condition | Stop? | Derived from |
|---|---|---|
| `ok` | no | error bitfield clear, alarm slots `none` |
| `printing` | no | Zebra state `printing` |
| `ribbon_low` | no | supply level under threshold |
| `ribbon_out` | yes | supply level 0, or `noToner` / `markerSupplyMissing` bit |
| `film_low` / `film_out` | no / yes | supply row 2 |
| `cards_out` | yes | `noPaper` / `inputTrayEmpty` bit |
| `card_jam` | yes | `jammed` bit, or a job row stuck in a transit location |
| `cover_open` | yes | `doorOpen` bit |
| `reject_bin_full` | yes | `outputFull` bit |
| `service_required` | yes | `serviceRequested` bit |
| `offline` | yes | SNMP unreachable, or `offline` bit |
| `unknown` | **yes** | anything unrecognised |

**`unknown` deliberately counts as a stop.** The old system treated absence of a known error as
success, which is precisely how cards were reported printed while the printer sat jammed. An
unmapped state pauses the queue and asks a human instead of assuming the best.
