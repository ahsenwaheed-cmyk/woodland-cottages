/**
 * POST /api/booking
 *
 * Takes a booking request from the website, emails it to the owners, and
 * emails the guest an acknowledgement. Nothing is stored and nothing is
 * charged — this is an enquiry, not a reservation.
 *
 * ENVIRONMENT VARIABLES  (Vercel → Project → Settings → Environment Variables)
 *
 *   RESEND_API_KEY   required. From resend.com → API Keys. Without it this
 *                    endpoint returns 503 and the website quietly falls back
 *                    to WhatsApp, which is a working route, not a failure.
 *
 *   BOOKING_TO       where the request goes. Default hello@woodlandcottage.pk
 *
 *   BOOKING_FROM     the sender. Until woodlandcottage.pk is verified in
 *                    Resend this MUST stay as the default onboarding address —
 *                    Resend refuses to send from a domain it has not verified,
 *                    and while you are on the unverified default it will only
 *                    deliver to the address that owns the Resend account. So
 *                    during setup you will get the owner copy and the guest
 *                    copy will be rejected. That is expected. Verify the
 *                    domain, then set this to:
 *                       Woodland Cottages <booking@woodlandcottage.pk>
 *
 *   BOOKING_BCC      optional second address for the owner copy.
 *
 * WHY THE PRICE IS RECALCULATED HERE
 *   The browser sends a total, but anything the browser sends can be edited
 *   before it is sent. The figure that reaches the inbox is worked out again
 *   on this side from the dates and the cottage, so what you read is what the
 *   stay actually costs.
 */

const RATE = 45000;        // PKR per cottage per night
const MIN_NIGHTS = 2;
const MAX_NIGHTS = 60;
const WHATSAPP = '0346 9663367';

const CHECKIN_WINDOW = '2:00 pm – 7:00 pm';
const CHECKOUT_BY = '11:00 am';

/* A lambda keeps its memory between nearby invocations, so this catches the
   obvious case — one person hammering the button or a crude script — without
   needing a database. It is a speed bump, not a lock. */
const recent = new Map();
const WINDOW_MS = 60 * 1000;
const MAX_PER_WINDOW = 4;

function rateLimited(key) {
  const now = Date.now();
  const hits = (recent.get(key) || []).filter((t) => now - t < WINDOW_MS);
  hits.push(now);
  recent.set(key, hits);
  if (recent.size > 500) {
    for (const [k, v] of recent) if (!v.some((t) => now - t < WINDOW_MS)) recent.delete(k);
  }
  return hits.length > MAX_PER_WINDOW;
}

const clean = (v, max = 400) =>
  String(v == null ? '' : v).replace(/[\u0000-\u001f\u007f]/g, ' ').trim().slice(0, max);

const isEmail = (v) => /^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/.test(v);
const isDate = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v) && !Number.isNaN(Date.parse(v + 'T00:00:00Z'));

const money = (n) => n.toLocaleString('en-US');

function prettyDate(iso) {
  const d = new Date(iso + 'T00:00:00Z');
  return d.toLocaleDateString('en-GB', {
    day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC',
  });
}

async function sendMail(payload) {
  const r = await fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${process.env.RESEND_API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });
  const text = await r.text();
  if (!r.ok) {
    const err = new Error(`resend ${r.status}: ${text.slice(0, 300)}`);
    err.status = r.status;
    throw err;
  }
  return text;
}

module.exports = async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');

  if (req.method !== 'POST') {
    res.setHeader('Allow', 'POST');
    return res.status(405).json({ ok: false, error: 'Use POST.' });
  }

  let body = req.body;
  if (typeof body === 'string') {
    try { body = JSON.parse(body); } catch { body = null; }
  }
  if (!body || typeof body !== 'object') {
    return res.status(400).json({ ok: false, error: 'Could not read the request.' });
  }

  /* A field no human ever sees, and therefore no human ever fills in. */
  if (clean(body.website)) {
    return res.status(200).json({ ok: true });
  }

  const ip =
    (req.headers['x-forwarded-for'] || '').split(',')[0].trim() ||
    req.socket?.remoteAddress ||
    'unknown';
  if (rateLimited(ip)) {
    return res.status(429).json({ ok: false, error: 'Too many requests in a row. Give it a minute.' });
  }

  const cottage = clean(body.cottage, 60);
  const checkin = clean(body.checkin, 10);
  const checkout = clean(body.checkout, 10);
  const guests = clean(body.guests, 20);
  const name = clean(body.name, 120);
  const phone = clean(body.phone, 40);
  const email = clean(body.email, 160);
  const message = clean(body.message, 2000);

  const missing = [];
  if (!name) missing.push('a name');
  if (!phone) missing.push('a WhatsApp number');
  if (!isEmail(email)) missing.push('a valid email address');
  if (!isDate(checkin) || !isDate(checkout)) missing.push('both dates');
  if (!/^(Blue Cottage|Green Cottage|Both cottages)$/.test(cottage)) missing.push('a cottage');
  if (missing.length) {
    return res.status(400).json({ ok: false, error: `We still need ${missing.join(', ')}.` });
  }

  const inMs = Date.parse(checkin + 'T00:00:00Z');
  const outMs = Date.parse(checkout + 'T00:00:00Z');
  const nights = Math.round((outMs - inMs) / 86400000);

  const todayMs = Date.parse(new Date().toISOString().slice(0, 10) + 'T00:00:00Z');
  if (inMs < todayMs) {
    return res.status(400).json({ ok: false, error: 'That arrival date has already passed.' });
  }
  if (nights < MIN_NIGHTS) {
    return res.status(400).json({ ok: false, error: `Two nights minimum, all year.` });
  }
  if (nights > MAX_NIGHTS) {
    return res.status(400).json({ ok: false, error: 'For a stay that long, please message us directly.' });
  }

  const units = cottage === 'Both cottages' ? 2 : 1;
  const total = nights * RATE * units;

  if (!process.env.RESEND_API_KEY) {
    return res.status(503).json({
      ok: false,
      error: 'The booking mailer is not configured yet.',
      hint: 'Set RESEND_API_KEY in the Vercel project settings.',
    });
  }

  const to = process.env.BOOKING_TO || 'hello@woodlandcottage.pk';
  const from = process.env.BOOKING_FROM || 'Woodland Cottages <onboarding@resend.dev>';
  const bcc = process.env.BOOKING_BCC;

  const stay =
    `  Cottage     ${cottage}\n` +
    `  Arriving    ${prettyDate(checkin)}\n` +
    `  Leaving     ${prettyDate(checkout)}\n` +
    `  Nights      ${nights}\n` +
    `  Guests      ${guests || 'not stated'}\n` +
    `  Estimate    PKR ${money(total)}${units === 2 ? '  (both cottages)' : ''}\n`;

  const waDigits = phone.replace(/\D/g, '');
  const waIntl = waDigits.startsWith('0') ? '92' + waDigits.slice(1) : waDigits;

  const ownerText =
    `New booking request from the website.\n\n` +
    stay +
    `\n  Name        ${name}\n` +
    `  WhatsApp    ${phone}   https://wa.me/${waIntl}\n` +
    `  Email       ${email}\n` +
    (message ? `\nWhat they said:\n${message}\n` : '') +
    `\nReply to this email and it goes straight to them.\n`;

  const first = (name.split(' ')[0] || name).trim();

  const guestText =
    `Dear ${first},\n\n` +
    `Thank you for writing to us. Your request has reached Woodland Cottages\n` +
    `and we have it in front of us.\n\n` +
    stay +
    `\nNothing is booked and nothing is charged yet. We will come back to you\n` +
    `within the day to confirm the cottage is free and to send you the final\n` +
    `number — seasonal rates and longer stays we quote by hand rather than by\n` +
    `calculator.\n\n` +
    `When you arrive, check-in is ${CHECKIN_WINDOW}, and check-out is by ${CHECKOUT_BY}.\n\n` +
    `If you would rather talk it through first, WhatsApp us on ${WHATSAPP}.\n\n` +
    `Warmly,\n` +
    `Woodland Cottages\n` +
    `Galiyat, Northern Pakistan\n` +
    `${to}  ·  ${WHATSAPP}\n`;

  /* The owner copy is the one that must not fail. Send it first and on its
     own: if the guest copy is rejected — which it will be until the domain is
     verified in Resend — the request has still arrived and can still be
     answered by hand. */
  try {
    await sendMail({
      from,
      to: [to],
      ...(bcc ? { bcc: [bcc] } : {}),
      reply_to: [email],
      subject: `Booking request — ${cottage} — ${prettyDate(checkin)} — ${name}`,
      text: ownerText,
    });
  } catch (err) {
    console.error('[booking] owner mail failed:', err.message);
    return res.status(502).json({
      ok: false,
      error: 'We could not reach our booking system just now.',
    });
  }

  let guestMailed = true;
  try {
    await sendMail({
      from,
      to: [email],
      reply_to: [to],
      subject: 'We have your request — Woodland Cottages',
      text: guestText,
    });
  } catch (err) {
    guestMailed = false;
    console.error('[booking] guest mail failed:', err.message);
  }

  return res.status(200).json({ ok: true, nights, total, guestMailed });
};
