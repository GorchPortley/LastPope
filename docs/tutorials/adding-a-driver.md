# Adding a driver to the database

Every design on SDLabs is built from drivers, and every driver in a design has to exist in the database first. If you go to add a woofer to your design and it isn't in the list, this is the page you need.

Adding a driver is free. You don't need a Stripe account or anything else set up.

## Before you start

Check that the driver isn't already there. Go to **Drivers** in the top menu and search for the brand and model. If someone has already added it, use theirs. Duplicates make the database harder to search for everyone.

[SCREENSHOT: the Drivers browse page with a search typed in]

Things worth having on hand:

- The manufacturer's datasheet (for the Thiele/Small parameters)
- A photo of the driver. Your own is best; the manufacturer's product shot is fine.
- Any measurements you've taken yourself: frequency response, impedance, or both

## 1. Open My Drivers

In your dashboard, open **Manage My Data → My Drivers** in the sidebar. This lists every driver you've added. Click **New driver**.

[SCREENSHOT: My Drivers page with the New driver button highlighted]

## 2. The basics

- **Active**: leave this off while you're still filling things in. Inactive drivers don't show up publicly and can't be picked in designs. Switch it on when you're done.
- **Brand**: start typing and it suggests brands already in the database. Pick from the list if yours is there, so "SB Acoustics" doesn't end up next to "SB acoustics".
- **Model**: the model number as the manufacturer writes it, for example `SB17NRX2C35-8`.
- **Tag**: a short line that shows under the name, like "6.5 inch paper cone midwoofer". Optional.
- **Category**: woofer, tweeter, midrange and so on. Pick the closest one; **Other** is there if nothing fits.
- **Size**: nominal size in inches. A 6.5" driver is `6.5`.
- **Impedance**: nominal impedance in ohms.
- **Power**: rated power in watts.
- **MSRP**: what the driver costs new. It's for reference only; nobody buys anything through this field.
- **Link**: where to buy it or the manufacturer's product page.

[SCREENSHOT: the top half of the new driver form, filled in]

## 3. Photo and description

- **Driver Image**: upload one or more photos. The first one is used on the driver's card.
- **Summary**: one or two plain sentences. This shows on the card in search results.
- **Description**: as long as you like. Build notes, how it measured compared to the datasheet, what it's good and bad at. You can add images here too.

## 4. Factory specs

The **Factory Specs** table has a fixed list of parameters: Re, Fs, Qms, Qes, Qts, Rms, Mms, Cms, Vas, Sd, BL, Xmax, Le, SPL, EBP, Vd and Mmd. Copy them from the datasheet. Leave any blank that the datasheet doesn't give.

Put your own measurements somewhere else. Designers record what *they* measured when they add the driver to a design (see [Publishing a speaker design](/blog/guides/publishing-a-design)). This table is for the manufacturer's numbers, so the two can be compared.

[SCREENSHOT: the Factory Specs table partly filled in]

## 5. Measurement files

Open **Driver File Uploads** if you have measurements:

- **Frequency Measurements**: FRD or similar text files
- **Impedance Measurements**: ZMA or similar
- **Other Files**: anything else useful, such as the datasheet PDF or your test setup notes

Keep the filenames meaningful (`0deg-1m-baffle.frd` beats `meas3.frd`). They're kept as you named them.

## 6. Save, then switch it on

Click **Create**. The driver is saved but still inactive if you left the toggle off. When you're happy with it, flip the **Active** switch in the list. It's now public and designers can pick it.

## 7. Make the files downloadable (snapshots)

Files you upload aren't offered for download straight away. To publish them, click **New Snapshot** on the driver's row and give it a version name (the date is filled in for you, or type something like `v1.0`).

The site packs the files into a ZIP in the background and sends you a notification when it's ready. The ZIP then appears under **Download Files** on the driver's public page. Driver downloads are free for everyone.

If you add or replace files later, make a new snapshot. Older ones stay available.

[SCREENSHOT: the New Snapshot dialog]
[SCREENSHOT: Download Files on a driver's public page]

## For manufacturers

If your account has manufacturer status, drivers you add are marked as official. You also get a **Collect Data** button on each of your drivers in My Drivers. It downloads a spreadsheet of every design that uses that driver: where it sits in the design, the frequency range it covers, the box volume and the parameters each designer measured. It's a good way to see how your drivers behave in real builds.

Contact us if you're a manufacturer and your account doesn't have this yet.

## Next

- [Publishing a speaker design](/blog/guides/publishing-a-design)
- [Selling kits, speakers and used gear](/blog/guides/selling-in-the-marketplace): if you have spare drivers to sell
