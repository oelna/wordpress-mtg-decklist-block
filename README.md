# MTG Decklist Block (WordPress)

A WordPress/Gutenberg block that turns a pasted **Magic: The Gathering** decklist into a clean, link-rich deck display on the frontend.

This plugin was mainly coded with ChatGPT 5.2, so the code is not too pretty. I did most of the CSS of the three major styles for the frontend, since the generated designs were somewhat … meh. Still, I would never have been able to put this together so quickly and I saved a lot of time not having to look up the details of how to make a new Wordpress block.

The frontend UI is not ideal for custom styling just yet, but I'm looking into it. I guess you can override most styles just fine via the class names. YMMV.

## Features

- **Block Editor input**: paste decklists from Moxfield, MTG Arena, MTGO, or plain text
- **Frontend rendering**:
  - Table output with columns: **Amount**, **Mana Cost**, **Card Name**
  - Card names link to the corresponding **Scryfall** page
  - Hover tooltip showing the card image
  - **Copy decklist** button to copy the original source text to clipboard
- **Per-block options**:
  - Style variants: **A / B / C**
  - Grouping: **Alphabetical / Mana value / Color identity**
- **Auto-Updates**
  - Updates the plugin to the most recent version via Github automatically

## Scryfall Data

On post save, the plugin parses all decklist blocks in the post and fetches card data via Scryfall’s API (batched). A minimal subset of card data is stored in post meta for rendering/grouping and tooltips.

## Installation

1. Download the latest release ZIP.
2. In WordPress Admin → **Plugins** → **Add New** → **Upload Plugin**.
3. Activate **MTG Decklist Block**.
4. In the block editor, add **MTG Decklist** and paste your list.

## License / Warranty

This project is provided **as-is**, without warranty of any kind.  
No guarantees are made regarding correctness, availability, or fitness for any purpose.

# Change Log

## Changes in 1.2.0
- Lands are separated and displayed after non-land cards (per section), always sorted alphabetically.
- Colorless is sorted after colored groups when grouping by Color identity.
- Group header rows are shown only for Color identity grouping.
- Added columns:
  - CI: <span class="mtgdl-ci-badge" data-ci="...">...</span>
  - Mana: <span class="mtgdl-mana"><span class="mtgdl-mana-num">..</span><span class="mtgdl-mana-sym ...">..</span>...</span>

Per-block settings
- Style: A / B / C
- Grouping: Alphabetical / Mana value / Color identity
