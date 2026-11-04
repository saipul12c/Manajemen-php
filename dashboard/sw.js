/**
 * Service Worker Terpadu - Manajemen-PHP PWA
 * Mendukung Aplikasi CBT Ujian, Presensi QR, dan E-Perpustakaan
 */

const CACHE_NAME = 'manajemen-pwa-v1.0.0';
const OFFLINE_URL = './offline.html';

// Asset statis inti yang dicache saat instalasi
const PRECACHE_ASSETS = [
  './Modul-ujian/icons/icon.svg',
  './presensi/icons/icon.svg',
  './perpustakaan/icons/icon.svg',
  'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap',
  'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4',
  'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      // Usahakan cache aset penting, abaikan jika koneksi internet sedang diblokir
      return Promise.allSettled(
        PRECACHE_ASSETS.map((url) => cache.add(url).catch(() => null))
      );
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Jangan cache POST/PUT/DELETE atau request API/DB dinamis
  if (req.method !== 'GET') {
    return;
  }

  // Khusus dokumen halaman navigasi (PHP/HTML): Network First
  if (req.mode === 'navigate' || req.destination === 'document') {
    event.respondWith(
      fetch(req).catch(async () => {
        const cached = await caches.match(req);
        if (cached) return cached;
        // Fallback offline message jika koneksi benar-benar terputus
        return new Response(
          `<!DOCTYPE html>
          <html lang="id">
          <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Koneksi Terputus - Aplikasi Terpasang</title>
            <style>
              body { font-family: system-ui, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; text-align: center; }
              .card { background: #1e293b; border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 32px; max-width: 440px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
              .btn { display: inline-block; background: #2563eb; color: #fff; text-decoration: none; padding: 12px 24px; border-radius: 12px; font-weight: bold; margin-top: 18px; cursor: pointer; border: none; }
            </style>
          </head>
          <body>
            <div class="card">
              <div style="font-size: 48px; margin-bottom: 12px;">📡</div>
              <h2 style="margin: 0 0 8px 0;">Koneksi Jaringan Terputus</h2>
              <p style="color: #94a3b8; font-size: 14px; line-height: 1.6;">
                Aplikasi ini membutuhkan jaringan internet atau koneksi intranet sekolah (Wi-Fi Lokal) untuk menyinkronkan data presensi, ujian, atau perpustakaan.
              </p>
              <button onclick="window.location.reload()" class="btn">Coba Hubungkan Ulang</button>
            </div>
          </body>
          </html>`,
          { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
      })
    );
    return;
  }

  // Statis assets (font, icon, css, cdn js): Stale While Revalidate
  if (
    url.origin === location.origin ||
    url.hostname.includes('jsdelivr.net') ||
    url.hostname.includes('googleapis.com') ||
    url.hostname.includes('gstatic.com')
  ) {
    event.respondWith(
      caches.match(req).then((cachedResp) => {
        const fetchPromise = fetch(req)
          .then((networkResp) => {
            if (networkResp && networkResp.status === 200) {
              const respClone = networkResp.clone();
              caches.open(CACHE_NAME).then((cache) => cache.put(req, respClone));
            }
            return networkResp;
          })
          .catch(() => null);

        return cachedResp || fetchPromise;
      })
    );
  }
});
