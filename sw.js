const CACHE_NAME = "mseleni-v2";

// Only cache the most important files first
const ESSENTIAL = [
  "./",
  "./index.html",
  "./manifest.json",
  "./icon-192.png",
  "./icon-512.png"
];

self.addEventListener("install", (event) => {
  // Finish installing as fast as possible
  self.skipWaiting();

  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      // Try to cache, but don't fail if some files fail
      return Promise.allSettled(
        ESSENTIAL.map((url) =>
          cache.add(url).catch((err) => {
            console.log("Failed to cache:", url, err);
          })
        )
      );
    })
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  // Network first, fall back to cache
  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // Optionally update cache in background
        const responseClone = response.clone();
        caches.open(CACHE_NAME).then((cache) => {
          cache.put(event.request, responseClone);
        });
        return response;
      })
      .catch(() => {
        return caches.match(event.request).then((cached) => {
          return cached || caches.match("./index.html");
        });
      })
  );
});
