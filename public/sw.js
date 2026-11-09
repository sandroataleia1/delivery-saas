// Service worker do app instalado da equipe. Não guarda telas em cache: o sistema depende do servidor
// para tudo. Quando a rede cai, mostra um aviso em vez da página de erro do navegador.
const OFFLINE_PAGE = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sem conexão</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f7f4ef;color:#1f1a17;font-family:system-ui,sans-serif;text-align:center;padding:24px}button{margin-top:16px;background:#c8321f;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-size:15px;cursor:pointer}</style></head>
<body><main><h1>Sem conexão com a internet</h1><p>Verifique a rede e tente de novo.</p><button onclick="location.reload()">Tentar de novo</button></main></body></html>`;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => new Response(OFFLINE_PAGE, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } })),
    );
});
