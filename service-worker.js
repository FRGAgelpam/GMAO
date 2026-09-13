// Service worker minimal : sert uniquement à rendre l'application installable
// (critère PWA de Chrome/Android). Volontairement aucun cache, aucune interception
// des requêtes réseau : sans réseau local, l'application reste simplement inaccessible.

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});
