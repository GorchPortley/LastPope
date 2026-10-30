# Publishing a speaker design

A design page holds everything someone needs to build your speaker: the drivers, the enclosure drawings, the crossover, your measurements and your notes. You can share it for free or charge for it.

The form is long, but only the name, one photo and the category are required. You can save a rough version as a draft and come back to it as many times as you like.

## Before you start

- **Every driver in your design needs to be in the database.** Check now, because you can't pick a driver that isn't there. If one is missing, add it first ([Adding a driver to the database](/blog/guides/adding-a-driver)) and switch it to active.
- **To charge for the design**, you need a Stripe account connected for payouts. You can build the whole design without one, but a paid design can't be published until Stripe is set up. See [Getting paid: setting up Stripe](/blog/guides/getting-paid-with-stripe).
- Gather your files: photos, FRD/ZMA measurements, enclosure drawings or CAD, crossover schematic, parts list.

## 1. Open My Designs

In your dashboard, open **Manage My Data → My Designs** and click **New design**.

[SCREENSHOT: My Designs page with the New design button]

Leave **Published** off for now. You'll switch it on at the end.

## 2. Design Information

This is what people see first.

- **Name**: what the speaker is called.
- **Design Images**: photos of the finished build. The first one is the card image in search results. At least one is required.
- **Response Previews**: FRD files of the response you want to show on the page, either one file for the whole speaker or one per driver. The page draws them as a graph, so visitors see how it measures before they commit.
- **Tagline**: one short line under the name, like "Sealed two-way for small rooms". The placeholder is just an example; write your own.
- **Category**: two-way, three-way, subwoofer and so on.
- **Price**: leave it at 0 to share the design for free. Anything above 0 makes it a paid design.
- **Build cost**: roughly what it costs to build one speaker. Builders find this really useful.
- **Impedance** and **Power**: nominal impedance in ohms and power handling in watts.
- **Summary**: the pitch. What it sounds like, who it's for, why you built it. Keep it to a few paragraphs.

[SCREENSHOT: the Design Information section filled in, with a response preview uploaded]

## 3. Description, parts list and files

The next section is called **Private Information**, but only the files in it are private. The description and parts list show on the design page.

- **Description**: the long version. Build notes, how you tuned it, mistakes to avoid. Add photos of the build as you go.
- **Bill of materials**: one row per part. Put the part in the left column and the quantity or source in the right.
- **Design File Uploads**:
  - **Enclosure Files**: drawings, CAD files, cut lists
  - **Electronics Files**: crossover schematic, PCB files
  - **Other Design Files**: anything else (recordings, write-ups, extra photos)

These files go into the download package. On a paid design only buyers get them, and on a free design anyone logged in.

[SCREENSHOT: the Design File Uploads section open]

## 4. Add the drivers

Open **Design Drivers** and click **Add Driver To Design** once per driver position.

For each one:

- **Driver**: search by brand or model. Only active drivers show up. If yours is missing, see [Adding a driver to the database](/blog/guides/adding-a-driver).
- **Position**: which band it covers: LF, LMF, MF, HMF, HF, or Other.
- **Quantity**: how many of that driver per speaker. Two woofers in a 2.5-way is `2`.
- **Frequency Range**: the low and high frequency it plays in your design, in Hz.
- **Air volume**: the box volume that driver sees.
- **Driver File Uploads**: your measurements of that driver in this design (frequency, impedance, other).
- **Description**: anything specific to this driver here: modifications, damping, why you picked it.
- **Specifications**: the Thiele/Small parameters **you measured**, not the datasheet numbers. Fill in what you have and leave the rest blank.

The measured specs feed back to driver manufacturers, so the more designers fill them in, the more useful the database gets.

[SCREENSHOT: one driver entry opened, with position, range and specs filled in]

## 5. Save as a draft

Click **Create**. The design is saved but not public.

To check how it looks, click **View** on its row. Fix anything that looks wrong with **Edit**.

## 6. Make a snapshot

A snapshot is the download package: a ZIP of every file you uploaded plus a PDF of the design. It records the design as it is at that moment.

Click **New Snapshot** on the design's row and give it a version name. The date is filled in, but something like `v1.0` reads better for buyers. The site builds it in the background and notifies you when it's done.

- **Free designs** don't need a snapshot to be published, but without one there's nothing to download. Make one.
- **Paid designs** can't be published without a snapshot.

When you update the design later (a crossover revision, new drawings), make a new snapshot such as `v1.1`. Buyers keep access to every version. Each person who downloads gets a PDF with their name in the footer, as a polite reminder not to pass it around.

[SCREENSHOT: the New Snapshot dialog]

## 7. Publish

Switch **Published** on, either in the list or in the edit form.

If something is missing, the site won't publish it. It saves the design as a draft and tells you what to fix. For paid designs, that's one of these two:

- "Create at least one snapshot so buyers have files to download."
- "Finish the Stripe payout setup for your account before you charge for this item."

Once it's live, the design shows up on the **Designs** page and in search.

[SCREENSHOT: the published design's public page]

## After it's live

- **Reviews**: people who build it can leave a rating and review on the design page.
- **Discussion**: each design gets a forum thread. On paid designs the thread is only visible to you, your buyers and admins, so you can talk details without giving the design away.
- **Sales**: when someone buys a paid design, you're paid straight away (minus the platform fee). See [Getting paid: setting up Stripe](/blog/guides/getting-paid-with-stripe) for how and when the money arrives.
- **Kits**: if you want to sell kits or finished pairs of your design, list them in the marketplace and link them to the design. See [Selling kits, speakers and used gear](/blog/guides/selling-in-the-marketplace).
