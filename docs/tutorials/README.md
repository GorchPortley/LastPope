# Tutorial drafts

Drafts for the blog's **Tutorials** category. Each file is one post. Copy the body into the post editor and skip this README.

## Slugs and links

The guides link to each other with the blog URL `/blog/tutorials/<slug>`. Create each post with the slug below, or those links will break.

| File | Post title | Slug |
|---|---|---|
| `introduction-to-sdlabs.md` | Introduction to SDLabs | `introduction-to-sdlabs` |
| `adding-a-driver.md` | Adding a driver to the database | `adding-a-driver` |
| `publishing-a-design.md` | Publishing a speaker design | `publishing-a-design` |
| `getting-paid-with-stripe.md` | Getting paid: setting up Stripe | `getting-paid-with-stripe` |
| `selling-in-the-marketplace.md` | Selling kits, speakers and used gear | `selling-in-the-marketplace` |
| `buying-on-sdlabs.md` | Buying designs and gear | `buying-on-sdlabs` |

If the category slug isn't `tutorials`, find and replace `/blog/tutorials/` across the files.

## Screenshots

Every place that needs one is marked like this:

`[SCREENSHOT: what to capture]`

Take them on the dev site with demo accounts, so no real names, orders or money show up.

## Things to check before publishing

I wrote these from the code, not from clicking through the site. A few points are worth a look:

- **The "Private Information" section on the design form.** The description written there still shows on the public design page, in the Description tab. Only the uploaded files are private. The guide says this plainly. You may want to rename the section in the form instead.
- **Fee and payout numbers** (10% fee, payouts 14 days after shipping, 14-day problem window, 3 days before escalating) are the current defaults in `config/marketplace.php`. If you change them in the server `.env`, update the guides too.
- **Stripe's processing fee:** the guides don't say who pays it, because that's a business decision. Add a line if you want sellers to know.
