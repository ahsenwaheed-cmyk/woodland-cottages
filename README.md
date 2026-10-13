# Woodland Cottages

Two cottages in Galiyat, Northern Pakistan. Static site plus two serverless
functions, deployed on Vercel.

    /                     index.html — the main page
    /the-walk.html        the scroll-scrub walkthrough
    /house-rules.html     DRAFT — not approved
    /policies.html        DRAFT — not approved
    /media/               photographs, the tour video, logos
    /docs/                the two PDFs
    /api/booking.js       takes a booking request, emails both sides
    /api/availability.js  reads the Airbnb calendars, returns taken nights
    /legacy-wordpress/    the old WordPress plugins. Not deployed. Kept for reference.

Every page is one self-contained HTML file — no build step, no bundler, no
dependencies. Edit the file, push, Vercel deploys it.

## Environment variables

Vercel → Project → Settings → Environment Variables. Add to Production **and**
Preview, then redeploy — variables are read at request time but a deployment
only picks up ones that existed when it was built.

| Variable | Needed | What it is |
|---|---|---|
| `RESEND_API_KEY` | **yes** | resend.com → API Keys. Without it the form falls back to WhatsApp. |
| `BOOKING_TO` | no | Where requests land. Defaults to `hello@woodlandcottage.pk`. |
| `BOOKING_FROM` | after domain verification | Leave unset until woodlandcottage.pk is verified in Resend, then set to `Woodland Cottages <booking@woodlandcottage.pk>`. |
| `BOOKING_BCC` | no | A second address on the owner copy. |
| `AIRBNB_ICS_BLUE` | no | Airbnb export link(s), comma separated. |
| `AIRBNB_ICS_GREEN` | no | Same, for Green Cottage. |

### The Resend domain step, and why the guest email fails before it

An unverified Resend account can only send **from** `onboarding@resend.dev`,
and only **to** the address that owns the account. So before verification the
owner copy arrives and the guest copy is rejected — the form still reports the
request as received, because it was.

To fix it: Resend → Domains → add `woodlandcottage.pk` → copy the DKIM and SPF
records into Cloudflare DNS. One warning: **woodlandcottage.pk may already have
an SPF record** from the Brevo setup. A domain may only have one. Merge
Resend's `include:` into the existing line rather than adding a second record,
or both stop working.

## The booking flow

1. The page works the stay out in the browser and shows an estimate.
2. On submit it POSTs JSON to `/api/booking`.
3. The function **recalculates the nights and the total from the dates** —
   the browser's figure is a convenience, not a source of truth — then emails
   the owners (reply-to the guest) and the guest (reply-to the owners).
4. The owner copy is sent first and on its own. If the guest copy fails, the
   request has still arrived and can still be answered by hand.
5. If anything fails, the guest is handed to WhatsApp with the whole request
   already written out. Nothing is ever silently dropped.

Rate limited to 4 requests a minute per IP, with a honeypot field for bots.

## Still open

- House rules and policies are **drafts** carrying visible draft banners. They
  must not be published as binding terms without the owner's approval.
- The reviews section shows clearly-labelled placeholders. Live Google reviews
  need a Maps JavaScript API key, referrer-restricted to the domain, and the
  Place ID. **Never ship placeholder reviews as if they were real.**
- No AI-generated images or video anywhere, by the owner's decision. Every
  frame is his own footage.
