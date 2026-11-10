/*
 * Service worker de la PWA Buzón BUAP SJC.
 *  - Guarda la app (HTML, JS y CSS que genera Vite, íconos) para que abra rápido e incluso sin red.
 *  - NO guarda respuestas de la API ni fotos: los datos siempre vienen frescos del servidor.
 *  - Si config.js trae los datos de Firebase, recibe las notificaciones push.
 *
 * Los archivos que genera "npm run build" llevan un código en el nombre (index-a1b2c3.js), así
 * que este archivo no necesita cambiar en cada versión: la lista se lee del index.html publicado.
 * Sube VERSION solo si cambias este archivo.
 */
const VERSION = 'buzon-v1';

const FIJOS = [
  './',
  'config.js',
  'manifest.webmanifest',
  'icons/favicon.svg',
  'icons/icon-192.png',
  'icons/icon-512.png',
  'icons/maskable-512.png',
  'icons/apple-touch-icon.png',
];

importScripts('config.js');

const ALCANCE = new URL('./', self.location.href);

/** Archivos de /assets/ que usa un index.html (los que generó Vite). */
function archivosDe(html) {
  const lista = new Set();
  for (const m of html.matchAll(/(?:src|href)="([^"]*assets\/[^"]+)"/g)) {
    lista.add(new URL(m[1], ALCANCE).href);
  }
  return [...lista];
}

/** Guarda el index.html y sus archivos; borra los de versiones anteriores. */
async function guardarVersion(cache, respuestaIndex) {
  const html = await respuestaIndex.clone().text();
  const actuales = archivosDe(html);
  await cache.put(new URL('./', ALCANCE).href, respuestaIndex.clone());
  const faltan = [];
  for (const url of actuales) {
    if (!(await cache.match(url))) faltan.push(url);
  }
  await Promise.all(faltan.map((url) => fetch(url).then((r) => (r.ok ? cache.put(url, r) : null)).catch(() => null)));
  for (const req of await cache.keys()) {
    if (req.url.includes('/assets/') && !actuales.includes(req.url)) await cache.delete(req);
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(VERSION);
    await cache.addAll(FIJOS.map((u) => new Request(u, { cache: 'reload' })));
    const index = await fetch(new Request('./', { cache: 'reload' }));
    if (index.ok) await guardarVersion(cache, index);
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const claves = await caches.keys();
    await Promise.all(claves.filter((k) => k.startsWith('buzon-') && k !== VERSION).map((k) => caches.delete(k)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // Solo archivos de la propia app. La API y las fotos van directo a la red, siempre.
  if (url.origin !== ALCANCE.origin || !url.pathname.startsWith(ALCANCE.pathname)) return;
  if (req.headers.has('Authorization') || /\/(api|uploads)\//.test(url.pathname)) return;

  // Página: primero la red (para tomar la versión nueva) y, sin conexión, la guardada.
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      const cache = await caches.open(VERSION);
      try {
        const resp = await fetch(req);
        if (resp.ok) event.waitUntil(guardarVersion(cache, resp.clone()));
        return resp;
      } catch {
        return (await cache.match(new URL('./', ALCANCE).href)) || Response.error();
      }
    })());
    return;
  }

  // Archivos de /assets/: nunca cambian (el nombre cambia en cada versión), se sirven guardados.
  if (url.pathname.includes('/assets/')) {
    event.respondWith((async () => {
      const cache = await caches.open(VERSION);
      const guardada = await cache.match(req);
      if (guardada) return guardada;
      const resp = await fetch(req);
      if (resp.ok) cache.put(req, resp.clone());
      return resp;
    })());
    return;
  }

  // Resto (config.js, íconos, manifiesto): lo guardado y se actualiza en segundo plano.
  event.respondWith((async () => {
    const cache = await caches.open(VERSION);
    const guardada = await cache.match(req, { ignoreSearch: true });
    const red = fetch(req).then((resp) => {
      if (resp.ok && resp.type === 'basic') cache.put(req, resp.clone());
      return resp;
    }).catch(() => null);
    if (guardada) {
      event.waitUntil(red);
      return guardada;
    }
    return (await red) || Response.error();
  })());
});

// ------------------------------------------------------------------ notificaciones
function rutaDe(data) {
  const id = Number(data?.id_reporte);
  return id > 0 ? `reporte/${id}` : 'inicio';
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const ruta = event.notification.data?.ruta || 'inicio';
  event.waitUntil((async () => {
    const ventanas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const propia = ventanas.find((c) => c.url.startsWith(ALCANCE.href));
    if (propia) {
      await propia.focus();
      propia.postMessage({ tipo: 'abrir', ruta });
      return;
    }
    await self.clients.openWindow(new URL(`./#/${ruta}`, ALCANCE).href);
  })());
});

const fb = self.BUZON_CONFIG && self.BUZON_CONFIG.firebase;
if (fb) {
  try {
    importScripts(
      'https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js',
      'https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js',
    );
    const { vapidKey, ...opciones } = fb;
    self.firebase.initializeApp(opciones);
    // El servidor manda mensajes de solo datos: aquí se arma la notificación.
    self.firebase.messaging().onBackgroundMessage((payload) => {
      const d = payload.data || {};
      return self.registration.showNotification(d.titulo || 'Buzón BUAP SJC', {
        body: d.cuerpo || '',
        icon: 'icons/icon-192.png',
        badge: 'icons/icon-192.png',
        tag: d.id_reporte ? `reporte-${d.id_reporte}` : undefined,
        data: { ruta: rutaDe(d) },
        lang: 'es-MX',
      });
    });
  } catch (e) {
    // Sin conexión con Firebase la app sigue funcionando; solo no llegan avisos.
    console.warn('Push no disponible:', e);
  }
}
