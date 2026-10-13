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

  /* ═══════════════════════════════════════════════════════════════════════
     THE HTML VERSIONS

     Email is not the web. The rules below are not stylistic preferences,
     they are what the clients actually support:

       · Layout is nested TABLES. Flexbox and grid do not exist in Outlook,
         which renders mail through Microsoft Word.
       · Every style is INLINE. Gmail strips most of what is in <head>.
       · No web fonts. Gmail will not load Cormorant Garamond, so the
         display face is Georgia — the closest serif that is already on
         essentially every device.
       · Images are blocked by default in most clients, so the masthead has
         to read correctly with the logo missing. The alt text is styled to
         carry it on its own.
       · Cream paper with a forest band, rather than the site's dark ground.
         A dark email is a gamble: several clients force-invert dark mail and
         the result is unreadable. This is the same treatment as the PDFs.
       · border-radius is ignored by Outlook. Corners will be square there.
         That is fine; it is not worth VML to round them.
     ═══════════════════════════════════════════════════════════════════════ */

  const SITE = process.env.SITE_URL || 'https://woodlandcottage.pk';

  const C = {
    ink: '#07120C', ink2: '#0F2117',
    paper: '#F1ECE1', card: '#FBF8F2',
    text: '#2A3830', soft: '#6E7A6E',
    sage: '#6E8469', moss: '#A9C68B', mossInk: '#0E1A0A',
    rule: '#DED6C6',
    beige: '#EFE9DC', beigeDim: '#9AA294',
  };
  const SERIF = "Georgia,'Times New Roman',Times,serif";
  const SANS = "-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";

  const esc = (v) =>
    String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

  /** A row of the stay table. `strong` is used for the total. */
  const row = (label, value, strong) => `
    <tr>
      <td style="padding:11px 0;border-bottom:1px solid ${C.rule};font-family:${SANS};font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:${C.sage};vertical-align:top;white-space:nowrap;">${esc(label)}</td>
      <td style="padding:11px 0;border-bottom:1px solid ${C.rule};font-family:${SERIF};font-size:${strong ? '20px' : '16px'};line-height:1.4;color:${strong ? C.ink : C.text};text-align:right;">${value}</td>
    </tr>`;

  const button = (href, label, solid) => `
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 10px;">
      <tr><td align="center" bgcolor="${solid ? C.moss : C.card}" style="border-radius:999px;border:1px solid ${solid ? C.moss : C.rule};">
        <a href="${href}" style="display:inline-block;padding:13px 26px;font-family:${SANS};font-size:11px;font-weight:500;letter-spacing:.18em;text-transform:uppercase;color:${solid ? C.mossInk : C.text};text-decoration:none;">${esc(label)}</a>
      </td></tr>
    </table>`;

  /** Masthead, body, signoff. `preheader` is the grey line of text the
      inbox shows next to the subject — if it is not set, clients help
      themselves to whatever text comes first, which looks careless. */
  const shell = (preheader, eyebrow, heading, bodyHtml) => `<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
<title>${esc(heading)}</title>
</head>
<body style="margin:0;padding:0;background-color:${C.paper};-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;">${esc(preheader)}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="${C.paper}" style="background-color:${C.paper};">
  <tr><td align="center" style="padding:28px 14px 40px;">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">

      <tr><td bgcolor="${C.ink}" align="center" style="background-color:${C.ink};padding:34px 28px 30px;border-radius:14px 14px 0 0;">
        <img src="${SITE}/media/logo-word.png" width="150" alt="Woodland Cottages"
             style="display:block;width:150px;max-width:62%;height:auto;border:0;font-family:${SERIF};font-size:21px;color:${C.beige};">
        <div style="font-family:${SANS};font-size:9px;letter-spacing:.3em;text-transform:uppercase;color:${C.beigeDim};padding-top:13px;">Galiyat &middot; Northern Pakistan</div>
      </td></tr>

      <tr><td bgcolor="${C.card}" style="background-color:${C.card};padding:36px 32px 30px;">
        <div style="font-family:${SANS};font-size:10px;letter-spacing:.26em;text-transform:uppercase;color:${C.sage};">${esc(eyebrow)}</div>
        <h1 style="margin:14px 0 0;font-family:${SERIF};font-size:29px;line-height:1.18;font-weight:normal;color:${C.ink};">${esc(heading)}</h1>
        ${bodyHtml}
      </td></tr>

      <tr><td bgcolor="${C.ink}" style="background-color:${C.ink};padding:24px 32px 26px;border-radius:0 0 14px 14px;">
        <div style="font-family:${SERIF};font-size:15px;color:${C.beige};">Woodland Cottages</div>
        <div style="font-family:${SANS};font-size:11px;line-height:1.9;color:${C.beigeDim};padding-top:5px;">
          <a href="mailto:${esc(to)}" style="color:${C.beigeDim};text-decoration:none;">${esc(to)}</a>
          &nbsp;&middot;&nbsp;
          <a href="https://wa.me/${WHATSAPP.replace(/\D/g, '').replace(/^0/, '92')}" style="color:${C.beigeDim};text-decoration:none;">${WHATSAPP}</a><br>
          <a href="${SITE}" style="color:${C.beigeDim};text-decoration:none;">woodlandcottage.pk</a>
        </div>
      </td></tr>

    </table>
  </td></tr>
</table>
</body></html>`;

  const stayTable = `
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:26px 0 0;border-top:1px solid ${C.rule};">
      ${row('Cottage', esc(cottage))}
      ${row('Arriving', esc(prettyDate(checkin)))}
      ${row('Leaving', esc(prettyDate(checkout)))}
      ${row('Nights', String(nights))}
      ${row('Guests', esc(guests || 'not stated'))}
      ${row('Estimate', `PKR ${money(total)}${units === 2 ? `<span style="font-family:${SANS};font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:${C.soft};display:block;padding-top:4px;">both cottages</span>` : ''}`, true)}
    </table>`;

  const para = (t) => `<p style="margin:20px 0 0;font-family:${SERIF};font-size:16px;line-height:1.62;color:${C.text};">${t}</p>`;

  const guestHtml = shell(
    `Your dates are with us — we will confirm within the day.`,
    'Request received',
    'We have your request.',
    `${para(`Dear ${esc(first)}, thank you for writing to us. Your request has reached Woodland Cottages and we have it in front of us.`)}
     ${stayTable}
     <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 0;">
       <tr><td bgcolor="#EDF1E6" style="background-color:#EDF1E6;border-left:3px solid ${C.moss};padding:16px 18px;">
         <p style="margin:0;font-family:${SERIF};font-size:15px;line-height:1.6;color:${C.text};"><strong style="font-weight:normal;color:${C.ink};">Nothing is booked and nothing is charged yet.</strong> We will come back to you within the day to confirm the cottage is free and send you the final number — seasonal rates and longer stays we quote by hand rather than by calculator.</p>
       </td></tr>
     </table>
     ${para(`When you come, check-in is ${CHECKIN_WINDOW} and check-out is by ${CHECKOUT_BY}.`)}
     ${para(`If you would rather talk it through first, we are on WhatsApp.`)}
     <div style="padding-top:22px;">${button(`https://wa.me/${WHATSAPP.replace(/\D/g, '').replace(/^0/, '92')}`, 'Message us on WhatsApp', true)}</div>`
  );

  const ownerHtml = shell(
    `${cottage} · ${prettyDate(checkin)} · ${nights} nights · PKR ${money(total)}`,
    'New booking request',
    `${esc(name)} wants ${esc(cottage)}.`,
    `${stayTable}
     <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:24px 0 0;">
       ${row('Name', esc(name))}
       ${row('WhatsApp', `<a href="https://wa.me/${esc(waIntl)}" style="color:${C.ink};text-decoration:none;border-bottom:1px solid ${C.moss};">${esc(phone)}</a>`)}
       ${row('Email', `<a href="mailto:${esc(email)}" style="color:${C.ink};text-decoration:none;border-bottom:1px solid ${C.moss};">${esc(email)}</a>`)}
     </table>
     ${message ? `
     <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;">
       <tr><td bgcolor="#EDF1E6" style="background-color:#EDF1E6;border-left:3px solid ${C.moss};padding:16px 18px;">
         <div style="font-family:${SANS};font-size:9.5px;letter-spacing:.22em;text-transform:uppercase;color:${C.sage};padding-bottom:8px;">What they said</div>
         <p style="margin:0;font-family:${SERIF};font-size:15px;line-height:1.6;color:${C.text};white-space:pre-wrap;">${esc(message)}</p>
       </td></tr>
     </table>` : ''}
     <div style="padding-top:26px;">
       ${button(`https://wa.me/${esc(waIntl)}`, 'Reply on WhatsApp', true)}
       ${button(`mailto:${esc(email)}`, 'Reply by email', false)}
     </div>
     <p style="margin:18px 0 0;font-family:${SANS};font-size:11px;line-height:1.7;color:${C.soft};">Hitting reply on this email also goes straight to ${esc(name)} — the reply-to is set to their address.</p>`
  );

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
      html: ownerHtml,
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
      html: guestHtml,
    });
  } catch (err) {
    guestMailed = false;
    console.error('[booking] guest mail failed:', err.message);
  }

  return res.status(200).json({ ok: true, nights, total, guestMailed });
};
