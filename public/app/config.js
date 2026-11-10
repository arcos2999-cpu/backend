/*
 * Configuración de la PWA Buzón BUAP SJC.
 * Lo leen tanto la página como el service worker (sw.js), por eso usa "self".
 */
self.BUZON_CONFIG = {
  /*
   * Dirección de la API. Por defecto es relativa: la PWA y la API viven en el mismo
   * servidor (la PWA en /app/ y la API en /api/v1/), así no hace falta configurar CORS.
   */
  apiUrl: '../api/v1/',

  /*
   * Notificaciones push (opcional). Con null la app funciona igual, solo sin avisos.
   * Para activarlas, copia los datos de tu app web de Firebase
   * (Consola de Firebase > Configuración del proyecto > Tus apps > App web)
   * y la clave VAPID (Cloud Messaging > Certificados push web):
   *
   * firebase: {
   *   apiKey: 'AIza...',
   *   authDomain: 'tu-proyecto.firebaseapp.com',
   *   projectId: 'tu-proyecto',
   *   messagingSenderId: '1234567890',
   *   appId: '1:1234567890:web:abc123',
   *   vapidKey: 'BOrr...'
   * },
   */
  firebase: null,
};
