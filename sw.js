const CACHE='rcmp-v1';
const ASSETS=['app.html','manifest.json'];
self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS)));self.skipWaiting();});
self.addEventListener('activate',e=>{e.waitUntil(clients.claim());});
self.addEventListener('fetch',e=>{
  if(e.request.method!=='GET')return;               /* الرفع POST يذهب للشبكة مباشرة */
  if(e.request.url.includes('/api/'))return;         /* البيانات دائمًا حية من الخادم */
  e.respondWith(fetch(e.request).then(r=>{
    const cp=r.clone();caches.open(CACHE).then(c=>c.put(e.request,cp));return r;
  }).catch(()=>caches.match(e.request)));
});