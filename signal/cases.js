window.SignalCases = [
  {
    "id": "1",
    "title": "Shifted signal",
    "cipher": "XQPDVN",
    "instruction": "Every letter moved three places forward. Move each back three places.",
    "hint": "X becomes U; Q becomes N.",
    "answer": "UNMASK",
    "callsign": "BEACON",
    "lines": [
      "Beneath the noise is a pattern.",
      "Every detail carries a signal.",
      "Ask what lies beyond.",
      "Curiosity opens another door.",
      "Observe the hidden rhythm.",
      "Notice where the trail begins."
    ],
    "retired": true
  },
  {
    "id": "2",
    "title": "Mirror transmission",
    "cipher": "KSAMNU",
    "instruction": "The project name was transmitted backwards. Read from right to left.",
    "hint": "Start with the last letter: U.",
    "answer": "UNMASK",
    "callsign": "ORBIT",
    "lines": [
      "Observe the hidden rhythm.",
      "Read between the traces.",
      "Beneath the noise is a pattern.",
      "Inspect the edges.",
      "Trace the line back home."
    ],
    "retired": true
  },
  {
    "id": "3",
    "title": "Number station",
    "cipher": "21 \u00b7 14 \u00b7 13 \u00b7 1 \u00b7 19 \u00b7 11",
    "instruction": "Replace each number with its alphabet position: A=1 through Z=26.",
    "hint": "21 is U; 14 is N.",
    "answer": "UNMASK",
    "callsign": "PRISM",
    "lines": [
      "Pause before the next step.",
      "Read between the traces.",
      "Inspect the edges.",
      "Small clues lead somewhere.",
      "Make room for a new angle."
    ],
    "retired": true
  },
  {
    "id": "4",
    "title": "Broken rhythm",
    "cipher": "U X N X M X A X S X K X",
    "instruction": "Keep every other letter, starting with the first. Ignore the separators.",
    "hint": "Keep U, skip X, keep N.",
    "answer": "UNMASK",
    "callsign": "ECHO",
    "lines": [
      "Every detail carries a signal.",
      "Curiosity opens another door.",
      "Hold the quiet moment.",
      "Observe the hidden rhythm."
    ],
    "retired": true
  },
  {
    "id": "5",
    "title": "Reflected alphabet",
    "cipher": "FMNZHP",
    "instruction": "Use a mirrored alphabet: A\u2194Z, B\u2194Y, C\u2194X, and so on.",
    "hint": "F mirrors U; M mirrors N.",
    "answer": "UNMASK",
    "callsign": "NOVA",
    "lines": [
      "Notice where the trail begins.",
      "Observe the hidden rhythm.",
      "View it from another side.",
      "Ask what lies beyond."
    ],
    "retired": true
  },
  {
    "id": "6",
    "title": "Dotted transmission",
    "cipher": "..- / -. / -- / .- / ... / -.-",
    "instruction": "Decode the Morse groups using this key: A .- | K -.- | M -- | N -. | S ... | U ..-",
    "hint": "Each slash separates one letter. ..- is U.",
    "answer": "UNMASK",
    "callsign": "PULSE",
    "lines": [
      "Pause before the next step.",
      "Uncover the missing piece.",
      "Look past the obvious.",
      "Small clues lead somewhere.",
      "Every detail carries a signal."
    ],
    "retired": true
  },
  {
    "id": "7",
    "title": "Hex dispatch",
    "cipher": "55 4E 4D 41 53 4B",
    "instruction": "Read the hexadecimal character codes using this key: 41=A, 4B=K, 4D=M, 4E=N, 53=S, 55=U.",
    "hint": "55 is U; 4E is N.",
    "answer": "UNMASK",
    "callsign": "EMBER",
    "lines": [
      "Every detail carries a signal.",
      "Make room for a new angle.",
      "Beneath the noise is a pattern.",
      "Every detail carries a signal.",
      "Read between the traces."
    ],
    "retired": true
  },
  {
    "id": "8",
    "title": "Scrambled packets",
    "cipher": "4:A \u00b7 1:U \u00b7 6:K \u00b7 3:M \u00b7 5:S \u00b7 2:N",
    "instruction": "The number is the position of its letter. Put the packets in order from 1 to 6.",
    "hint": "Position 1 contains U; position 2 contains N.",
    "answer": "UNMASK",
    "callsign": "RAVEN",
    "lines": [
      "Read between the traces.",
      "Ask what lies beyond.",
      "View it from another side.",
      "Every detail carries a signal.",
      "Notice where the trail begins."
    ],
    "retired": true
  },
  {
    "id": "9",
    "title": "Thirteen steps",
    "cipher": "HAZNFX",
    "instruction": "Each letter moved thirteen places around the alphabet. Move thirteen more to recover it.",
    "hint": "H becomes U; A becomes N.",
    "answer": "UNMASK",
    "callsign": "ATLAS",
    "lines": [
      "Ask what lies beyond.",
      "Trace the line back home.",
      "Look past the obvious.",
      "Ask what lies beyond.",
      "Small clues lead somewhere."
    ],
    "retired": true
  },
  {
    "id": "10",
    "title": "Paired fragments",
    "cipher": "UN / MA / SK",
    "instruction": "The message arrived as three fragments. Join them in the order shown.",
    "hint": "Remove the slashes and spaces.",
    "answer": "UNMASK",
    "callsign": "SPARK",
    "lines": [
      "Small clues lead somewhere.",
      "Pause before the next step.",
      "Ask what lies beyond.",
      "Read between the traces.",
      "Keep looking closer."
    ],
    "retired": true
  },
  {
    "id": "11",
    "title": "The missing blue hour",
    "difficulty": "Approachable",
    "minutes": 4,
    "story": "In a fictional gallery, a photograph disappeared between two timed lights. Its label survived, but the hour was rubbed out.",
    "instruction": "The caretaker wrote the times in order. Enter the missing time in HH:MM format.",
    "cipher": "18:10 \u2192 18:25 \u2192 [ ? ] \u2192 18:55",
    "answer": "18:40",
    "hint": "Each clock advances by the same number of minutes.",
    "traceTitle": "Which photograph left the wall?",
    "tracePrompt": "Compare the gallery register with the courier's receipt. Enter the title of the only photograph that could have been collected.",
    "callsign": "LOW TIDE",
    "traceHint": "The receipt gives a frame colour and a collection window. Both must match one register entry.",
    "resolution": "Low Tide had the blue frame and was available at 18:40. The other blue photograph did not become available until 18:50.",
    "evidence": [
      {
        "location": "canvas",
        "title": "Gallery register",
        "body": "Fictional gallery inventory: LOW TIDE \u2014 blue frame, available from 18:30. NIGHT BUS \u2014 red frame, available from 18:00. STILL WATER \u2014 blue frame, available from 18:50. These are the only three works in the room."
      },
      {
        "location": "map",
        "title": "Courier receipt",
        "body": "Fictional collection receipt: one blue-framed photograph, collected at the missing time from the first puzzle. Nothing was collected before its listed availability time."
      }
    ]
  },
  {
    "id": "12",
    "title": "A platform with no train",
    "difficulty": "Intermediate",
    "minutes": 6,
    "story": "A fictional station printed a departure that never happened. Someone left a route number in the announcement tape.",
    "instruction": "Read the number of letters in each word, in order. Enter the resulting four digits.",
    "cipher": "GO / NORTH / BY / RAIL",
    "answer": "2524",
    "hint": "Count letters, not the spaces or slashes. The first word contributes one digit.",
    "traceTitle": "Follow the cancelled service",
    "tracePrompt": "Use the route ledger and platform notice to identify the destination of the cancelled service. Enter its destination name.",
    "callsign": "WILLOW",
    "traceHint": "First find the route from your four-digit answer. Then apply the notice to its platform, not its destination.",
    "resolution": "Route 2524 used platform C. Platform C was the cancelled departure, and its destination was Willow.",
    "evidence": [
      {
        "location": "projects",
        "title": "Route ledger",
        "body": "Fictional station ledger: route 2524 \u2192 platform C \u2192 WILLOW; route 2542 \u2192 platform A \u2192 QUARRY; route 2254 \u2192 platform B \u2192 HARBOR. Each route uses exactly one platform."
      },
      {
        "location": "room",
        "title": "Station notice",
        "body": "Fictional station notice: platform A departed; platform B departed; platform C was cancelled. A handwritten correction says: identify the destination, not the platform letter."
      }
    ]
  },
  {
    "id": "13",
    "title": "The conservator's switch",
    "difficulty": "Intermediate",
    "minutes": 7,
    "story": "Three fictional restoration crates arrived with their labels deliberately swapped. A packing diagram contains the only reliable opening instruction.",
    "instruction": "Apply the swaps from left to right to the letters ABC. First swap positions 1 and 3, then positions 1 and 2. Enter the final three letters.",
    "cipher": "ABC \u2192 swap 1\u21943 \u2192 swap 1\u21942",
    "answer": "BCA",
    "hint": "Carry the changed arrangement forward into the second swap; do not restart from ABC.",
    "traceTitle": "Find the glass crate",
    "tracePrompt": "Every label is wrong. Use the inspection slip to deduce which numbered crate contains GLASS. Enter the crate number only.",
    "callsign": "3",
    "traceHint": "Crate 2 is known. Eliminate its contents from the other crates, then remember that each remaining label must also be wrong.",
    "resolution": "Crate 2 contains wood. Crate 1 cannot contain its labelled glass, so it contains paper. That leaves glass in crate 3.",
    "evidence": [
      {
        "location": "projects",
        "title": "Packing list",
        "body": "Fictional crates: 1 is labelled GLASS; 2 is labelled PAPER; 3 is labelled WOOD. Each contains exactly one of glass, paper, or wood, with no duplicates. Every label is wrong."
      },
      {
        "location": "room",
        "title": "Inspection slip",
        "body": "Fictional inspection: the crate opened using sequence BCA was crate 2. Inside was WOOD. No contents were moved during inspection."
      }
    ]
  },
  {
    "id": "14",
    "title": "A letter in the rain",
    "difficulty": "Approachable",
    "minutes": 5,
    "story": "Rain erased alternate letters from a fictional messenger's note. The surviving words point to a meeting that neither person attended.",
    "instruction": "Take the first letter of each line, from top to bottom. Enter the word they form.",
    "cipher": "Under the awning\nNothing moved\nTwo cups cooled\nInside the caf\u00e9\nLeaving no footsteps",
    "answer": "UNTIL",
    "hint": "Ignore the rest of each line; only its beginning matters.",
    "traceTitle": "Decode the revised meeting",
    "tracePrompt": "The messenger obeyed the final amendment, not the original appointment. Combine the two notes and enter the new meeting place.",
    "callsign": "CLOCKTOWER",
    "traceHint": "The amendment changes the place according to whether the original appointment was before or after noon. It does not change that original time.",
    "resolution": "The original appointment was at 11:20, before noon. The amendment therefore moved it from the fountain to the clocktower.",
    "evidence": [
      {
        "location": "map",
        "title": "Original appointment",
        "body": "Fictional appointment: meet at the FOUNTAIN at 11:20. The time uses the ordinary 24-hour clock."
      },
      {
        "location": "canvas",
        "title": "Final amendment",
        "body": "Fictional amendment: UNTIL further notice, appointments originally before 12:00 move to the CLOCKTOWER. Appointments at or after 12:00 move to the GREENHOUSE. This amendment supersedes the original place."
      }
    ]
  },
  {
    "id": "15",
    "title": "The borrowed minute",
    "difficulty": "Challenging",
    "minutes": 9,
    "story": "A fictional archive's three clocks disagree. A delivery disappeared from the record because someone sorted the receipts by their displayed times.",
    "instruction": "Clock A runs 7 minutes fast. It shows 14:03. Enter the real time as HH:MM.",
    "cipher": "CLOCK A: 14:03\nOFFSET: +7 minutes",
    "answer": "13:56",
    "hint": "A fast clock shows a later time than reality. Subtract its offset.",
    "traceTitle": "Put the arrivals back in order",
    "tracePrompt": "Correct all three displayed times using the clock offsets. Enter the parcel name that actually arrived FIRST.",
    "callsign": "CEDAR",
    "traceHint": "Convert each receipt to real time before comparing. The earliest displayed time is not necessarily the earliest arrival.",
    "resolution": "Cedar arrived at 13:56, Amber at 13:57, and Flint at 14:00. Cedar was first even though its displayed time was latest.",
    "evidence": [
      {
        "location": "room",
        "title": "Clock maintenance card",
        "body": "Fictional offsets: clock A is 7 minutes fast; clock B is 4 minutes slow; clock C is correct. Offsets are constant for the whole afternoon."
      },
      {
        "location": "projects",
        "title": "Delivery receipts",
        "body": "Fictional arrivals: CEDAR at 14:03 on clock A; AMBER at 13:53 on clock B; FLINT at 14:00 on clock C. All receipts are from the same day."
      }
    ]
  },
  {
    "id": "16",
    "title": "The room behind the plan",
    "difficulty": "Intermediate",
    "minutes": 7,
    "story": "A fictional surveyor left a route through a building that no longer exists. The destination was removed from the drawing, but the floor plan and footsteps survived separately.",
    "instruction": "Use A=1, B=2, \u2026 Z=26 to decode the room code. Enter the three letters.",
    "cipher": "13 \u00b7 1 \u00b7 16",
    "answer": "MAP",
    "hint": "Convert each number independently. The middle number is the first letter of the alphabet.",
    "traceTitle": "Walk the missing corridor",
    "tracePrompt": "Start at the marked entrance, follow every move, and enter the name of the final room. North means one row up; east means one column right.",
    "callsign": "WORKSHOP",
    "traceHint": "Track your row and column after each move. You may pass through a room more than once.",
    "resolution": "From the southwest entrance: north reaches Kitchen, east reaches Study, north reaches Gallery, east reaches Observatory, south reaches Workshop.",
    "evidence": [
      {
        "location": "map",
        "title": "Three-by-three floor plan",
        "body": "Fictional building grid. North row, west to east: LIBRARY | GALLERY | OBSERVATORY. Middle row: KITCHEN | STUDY | WORKSHOP. South row: ENTRANCE | COURTYARD | VAULT. Each move crosses exactly one grid cell."
      },
      {
        "location": "room",
        "title": "Footstep strip",
        "body": "Fictional route, starting at ENTRANCE: NORTH, EAST, NORTH, EAST, SOUTH. There are no walls blocking these moves. Enter the final room name, not a room passed along the way."
      }
    ]
  },
  {
    "id": "17",
    "title": "The negative space",
    "difficulty": "Challenging",
    "minutes": 10,
    "story": "A fictional printmaker concealed a word in the parts of a picture nobody inked. Two plates survive in different corners of the archive.",
    "instruction": "The sequence doubles each time and then adds one. Enter the next integer.",
    "cipher": "2 \u2192 5 \u2192 11 \u2192 23 \u2192 ?",
    "answer": "47",
    "hint": "Check that the same operation takes 2 to 5 and 5 to 11, then apply it to 23.",
    "traceTitle": "Read what neither plate covers",
    "tracePrompt": "Combine both sets of inked positions. Using the strip, read the letters at positions that are inked by NEITHER plate, from left to right. Enter the resulting word.",
    "callsign": "GHOST",
    "traceHint": "Cross out the union of the two position lists. A position appearing on both lists is still crossed out; it does not cancel.",
    "resolution": "The combined ink covers positions 2, 4, 6, 8, and 10. The remaining positions 1, 3, 5, 7, and 9 spell GHOST.",
    "evidence": [
      {
        "location": "canvas",
        "title": "Letter strip and plate A",
        "body": "Fictional strip, positions 1 through 10: G X H Y O Z S Q T R. Plate A inks positions 2, 6, and 8."
      },
      {
        "location": "projects",
        "title": "Plate B register",
        "body": "Fictional plate B inks positions 4, 8, and 10. The plates are overlaid without erasing existing ink. Read only letters left uncovered by both plates."
      }
    ]
  },
  {
    "id": "18",
    "title": "The keeper's testimony",
    "difficulty": "Challenging",
    "minutes": 12,
    "story": "Three fictional keepers disagree about which drawer holds a missing key. The rules of their testimony are more reliable than the testimony itself.",
    "instruction": "Read the word backwards, then shift each resulting letter one place BACK in the alphabet. Enter the decoded word.",
    "cipher": "UGJS",
    "answer": "RIFT",
    "hint": "First reverse the whole string. Only after that, move each letter back one place.",
    "traceTitle": "One true statement",
    "tracePrompt": "Exactly ONE keeper's statement is true. The key is in exactly one drawer. Determine its drawer and enter the drawer name.",
    "callsign": "BRASS",
    "traceHint": "Try each of the three possible drawers and count true statements. Keep the option with exactly one true statement.",
    "resolution": "If the key is in Brass, only the third statement is true. Oak and Slate each make two statements true. Brass is the unique fit.",
    "evidence": [
      {
        "location": "room",
        "title": "Drawer register",
        "body": "Fictional drawers: OAK, BRASS, and SLATE. The missing key is in exactly one. There are no other drawers or hiding places."
      },
      {
        "location": "canvas",
        "title": "Keeper statements",
        "body": "Fictional testimony: Keeper A says, 'The key is in OAK.' Keeper B says, 'The key is not in BRASS.' Keeper C says, 'The key is not in OAK.' Exactly ONE of these three statements is true."
      }
    ]
  },
  {
    "id": "19",
    "title": "A light on the far bank",
    "difficulty": "Intermediate",
    "minutes": 8,
    "story": "A fictional ferryman answered three lights from the opposite shore. The light colours were recorded, but the order of the reply was lost.",
    "instruction": "Use the supplied binary place values 8, 4, 2, 1. A 1 includes its place value and a 0 excludes it. Enter the decimal value of 1011.",
    "cipher": "Places: 8 4 2 1\nSignal: 1 0 1 1",
    "answer": "11",
    "hint": "Add only the place values beneath a 1.",
    "traceTitle": "Reconstruct the shore signal",
    "tracePrompt": "Sort the three light sightings from earliest to latest, then translate their colours using the signal key. Enter the resulting three-letter word.",
    "callsign": "ROW",
    "traceHint": "Time order comes first; letter conversion comes second. Do not read the log in its printed order.",
    "resolution": "At 21:04 blue meant R; at 21:07 amber meant O; at 21:09 white meant W. The reply was ROW.",
    "evidence": [
      {
        "location": "map",
        "title": "Far-bank sightings",
        "body": "Fictional sightings, all on the same evening: WHITE at 21:09; BLUE at 21:04; AMBER at 21:07. These were the only three lights."
      },
      {
        "location": "projects",
        "title": "Ferryman's signal key",
        "body": "Fictional colour key: AMBER = O; WHITE = W; BLUE = R. Read the translated letters in the order the lights appeared, earliest first."
      }
    ]
  },
  {
    "id": "20",
    "title": "The last delivery",
    "difficulty": "Challenging",
    "minutes": 11,
    "story": "A fictional courier carried three envelopes through a city with a closed bridge. The shortest open route determines which envelope should have arrived last.",
    "instruction": "A courier travels 3 blocks east, 2 north, then 1 west. Enter the total number of blocks walked, not the distance from the start.",
    "cipher": "EAST 3 / NORTH 2 / WEST 1",
    "answer": "6",
    "hint": "Every block walked counts, including movement that partly reverses an earlier direction.",
    "traceTitle": "Route around the closure",
    "tracePrompt": "Use the road lengths and bridge closure. Starting at DEPOT, find the shortest open route to each destination. Which envelope has the longest of those three shortest routes? Enter its destination name.",
    "callsign": "MILL",
    "traceHint": "For each destination, compare possible open routes separately. The direct Depot\u2013Mill bridge is closed, so its short length cannot be used.",
    "resolution": "The shortest open routes are Depot\u2013Market = 2, Depot\u2013Market\u2013Tower = 5, and Depot\u2013Market\u2013Mill = 6. Mill therefore has the longest shortest route.",
    "evidence": [
      {
        "location": "map",
        "title": "Road survey",
        "body": "Fictional undirected roads, lengths in blocks: DEPOT\u2013MARKET 2; MARKET\u2013TOWER 3; TOWER\u2013MILL 2; MARKET\u2013MILL 4; DEPOT\u2013MILL 1. There are no other roads. Roads can be travelled in either direction."
      },
      {
        "location": "room",
        "title": "Closure and delivery sheet",
        "body": "Fictional closure: the DEPOT\u2013MILL road is a bridge and is CLOSED. All other roads are open. One envelope is addressed to each of MARKET, TOWER, and MILL. Compare separate journeys from DEPOT at equal speed, with no stopping time; do not plan a combined delivery tour."
      }
    ]
  }
];
