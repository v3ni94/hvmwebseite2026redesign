// @ts-check
// Lokaler Fake-Empfänger für E2E-Tests im Dateimodus (STORAGE_MODE=datei), ohne Abhängigkeiten:
// - HTTP: POST /webhook nimmt den n8n-Webhook an und prüft die Signatur wie der Code-Node in docs/n8n-webhook.md
//   (HMAC-SHA256 über timestamp + "." + roher Body, Zeitfenster 5 Minuten);
//   GET /_empfangen liefert alle empfangenen Webhooks und Mails als JSON; GET /_status für den Start.
// - SMTP: minimaler Server ohne TLS und ohne Anmeldung, speichert die Rohdaten jeder Mail.
// Aufruf: node tests/e2e/helpers/fake-empfaenger.js (Ports über E2E_FAKE_PORT und E2E_SMTP_PORT).
// Nur für lokale Tests, lauscht ausschließlich auf 127.0.0.1.

const http = require('node:http');
const net = require('node:net');
const crypto = require('node:crypto');

const HTTP_PORT = Number(process.env.E2E_FAKE_PORT || 8091);
const SMTP_PORT = Number(process.env.E2E_SMTP_PORT || 8025);
const SECRET = process.env.E2E_WEBHOOK_SECRET || '';

/** @type {Array<{headers: Record<string, string|string[]|undefined>, body: string, signaturOk: boolean}>} */
const webhooks = [];
/** @type {Array<{from: string, to: string[], raw: string, text?: string}>} */
const mails = [];

function signaturOk(headers, body) {
  const ts = String(headers['x-hvm-timestamp'] || '');
  const sig = String(headers['x-hvm-signature'] || '');
  if (!/^\d+$/.test(ts) || Math.abs(Date.now() / 1000 - Number(ts)) > 300 || !/^[a-f0-9]{64}$/.test(sig)) {
    return false;
  }
  const erwartet = crypto.createHmac('sha256', SECRET).update(ts + '.' + body).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(erwartet, 'hex'), Buffer.from(sig, 'hex'));
}

/** Rohdaten plus dekodierte base64-Blöcke (PHPMailer kodiert Text, HTML und Anhänge in base64). */
function dekodiert(raw) {
  const bloecke = raw.match(/(?:^[A-Za-z0-9+/=]{16,}\r?\n?)+/gm) || [];
  return raw + '\n' + bloecke.map((b) => Buffer.from(b.replace(/\s+/g, ''), 'base64').toString('utf8')).join('\n');
}

http
  .createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const body = Buffer.concat(chunks).toString('utf8');
      if (req.method === 'POST' && req.url === '/webhook') {
        webhooks.push({ headers: req.headers, body, signaturOk: signaturOk(req.headers, body) });
        res.writeHead(200, { 'Content-Type': 'application/json' }).end('{"ok":true}');
        return;
      }
      if (req.method === 'GET' && req.url === '/_empfangen') {
        res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ webhooks, mails }));
        return;
      }
      if (req.method === 'GET' && req.url === '/_status') {
        res.writeHead(200, { 'Content-Type': 'text/plain' }).end('ok');
        return;
      }
      res.writeHead(404).end();
    });
  })
  .listen(HTTP_PORT, '127.0.0.1');

net
  .createServer((socket) => {
    socket.setEncoding('utf8');
    let puffer = '';
    let daten = false;
    let mail = { from: '', to: [], raw: '' };
    const antwort = (zeile) => socket.write(zeile + '\r\n');
    antwort('220 fake-smtp.local ESMTP');
    socket.on('data', (chunk) => {
      puffer += chunk;
      while (true) {
        if (daten) {
          const ende = puffer.indexOf('\r\n.\r\n');
          if (ende === -1) {
            return;
          }
          mail.raw = puffer.slice(0, ende).replace(/\r\n\.\./g, '\r\n.');
          puffer = puffer.slice(ende + 5);
          mail.text = dekodiert(mail.raw);
          mails.push(mail);
          mail = { from: '', to: [], raw: '' };
          daten = false;
          antwort('250 OK: angenommen');
          continue;
        }
        const pos = puffer.indexOf('\r\n');
        if (pos === -1) {
          return;
        }
        const zeile = puffer.slice(0, pos);
        puffer = puffer.slice(pos + 2);
        const befehl = zeile.slice(0, 4).toUpperCase();
        if (befehl === 'EHLO') {
          antwort('250-fake-smtp.local');
          antwort('250-8BITMIME');
          antwort('250 SIZE 20000000');
        } else if (befehl === 'HELO') {
          antwort('250 fake-smtp.local');
        } else if (befehl === 'MAIL') {
          mail.from = zeile.replace(/^MAIL FROM:\s*/i, '');
          antwort('250 OK');
        } else if (befehl === 'RCPT') {
          mail.to.push(zeile.replace(/^RCPT TO:\s*/i, '').replace(/[<>]/g, ''));
          antwort('250 OK');
        } else if (befehl === 'DATA') {
          daten = true;
          antwort('354 Ende mit <CRLF>.<CRLF>');
        } else if (befehl === 'RSET') {
          mail = { from: '', to: [], raw: '' };
          antwort('250 OK');
        } else if (befehl === 'NOOP') {
          antwort('250 OK');
        } else if (befehl === 'QUIT') {
          antwort('221 Tschüss');
          socket.end();
          return;
        } else {
          antwort('502 nicht unterstützt');
        }
      }
    });
    socket.on('error', () => {});
  })
  .listen(SMTP_PORT, '127.0.0.1');
