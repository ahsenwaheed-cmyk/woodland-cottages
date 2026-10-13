/**
 * GET /api/availability
 *
 * Reads the iCal calendars from Airbnb (and any other channel that gives you
 * an .ics link) and returns the nights that are already taken, so the booking
 * form can refuse dates that are gone.
 *
 *   { "updated": "...", "cottages": { "blue": ["2026-10-03"], "green": [] },
 *     "stale": false, "configured": true }
 *
 * WHY THIS HAS TO BE SERVER-SIDE
 *   Airbnb's .ics endpoint sends no CORS headers, so a browser on
 *   woodlandcottage.pk is not allowed to read it — the request is made and
 *   then thrown away. A function has no such limit.
 *
 * ENVIRONMENT VARIABLES
 *   AIRBNB_ICS_BLUE    one or more .ics URLs, comma separated
 *   AIRBNB_ICS_GREEN   same, for the other cottage
 *
 *   Airbnb → Calendar → the listing → Availability → "Connect calendars" →
 *   "Connect to another website" → copy the export link.
 *
 *   Treat those links as private. Anyone holding one can read your calendar.
 *   If one leaks, regenerate it on Airbnb.
 *
 * With nothing configured this returns empty lists and `configured: false`,
 * which the website reads as "no information" rather than "everything free".
 */

const CACHE_SECONDS = 1800;   // Airbnb itself only refreshes every few hours

function feeds(name) {
  return String(process.env[name] || '')
    .split(',')
    .map((s) => s.trim())
    .filter((s) => /^https:\/\//i.test(s));
}

/**
 * Pull the occupied nights out of an iCal file.
 *
 * Two things about the format matter:
 *  - Long lines are folded: a continuation starts with a space or tab and
 *    belongs to the line above. Unfold first or dates come back truncated.
 *  - For all-day events DTEND is EXCLUSIVE. A booking of DTSTART the 3rd,
 *    DTEND the 5th occupies the nights of the 3rd and the 4th — the guest
 *    leaves on the morning of the 5th and that night is free. Getting this
 *    wrong blocks one extra night on every single booking.
 */
function blockedNights(ics) {
  const unfolded = ics.replace(/\r\n|\r/g, '\n').replace(/\n[ \t]/g, '');
  const nights = [];
  let inside = false, start = null, end = null;

  for (const line of unfolded.split('\n')) {
    if (line.startsWith('BEGIN:VEVENT')) { inside = true; start = end = null; continue; }
    if (line.startsWith('END:VEVENT')) {
      if (start && end) {
        let cur = Date.parse(`${start.slice(0,4)}-${start.slice(4,6)}-${start.slice(6,8)}T00:00:00Z`);
        const fin = Date.parse(`${end.slice(0,4)}-${end.slice(4,6)}-${end.slice(6,8)}T00:00:00Z`);
        let guard = 0;
        while (cur < fin && guard++ < 1000) {
          nights.push(new Date(cur).toISOString().slice(0, 10));
          cur += 86400000;
        }
      }
      inside = false;
      continue;
    }
    if (!inside) continue;
    let m = /^DTSTART[^:]*:(\d{8})/.exec(line);
    if (m) { start = m[1]; continue; }
    m = /^DTEND[^:]*:(\d{8})/.exec(line);
    if (m) { end = m[1]; }
  }
  return nights;
}

async function readFeed(url) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 9000);
  try {
    const r = await fetch(url, {
      signal: ctrl.signal,
      headers: { 'User-Agent': 'WoodlandCottages/1.0 (+https://woodlandcottage.pk)' },
    });
    if (!r.ok) return null;
    const body = await r.text();
    return body.includes('BEGIN:VCALENDAR') ? body : null;
  } catch {
    return null;
  } finally {
    clearTimeout(timer);
  }
}

module.exports = async function handler(req, res) {
  const sources = { blue: feeds('AIRBNB_ICS_BLUE'), green: feeds('AIRBNB_ICS_GREEN') };
  const configured = sources.blue.length + sources.green.length > 0;

  const cottages = {};
  let stale = false;

  for (const [cottage, urls] of Object.entries(sources)) {
    const nights = new Set();
    for (const url of urls) {
      const ics = await readFeed(url);
      if (ics === null) { stale = true; continue; }
      for (const n of blockedNights(ics)) nights.add(n);
    }
    cottages[cottage] = [...nights].sort();
  }

  /* A partial answer is cached only briefly — a feed that was down for a
     moment should not keep a cottage looking wrong for half an hour. */
  res.setHeader(
    'Cache-Control',
    configured && !stale
      ? `public, s-maxage=${CACHE_SECONDS}, stale-while-revalidate=600`
      : 'public, s-maxage=60'
  );

  return res.status(200).json({
    updated: new Date().toISOString(),
    cottages,
    stale,
    configured,
  });
};
