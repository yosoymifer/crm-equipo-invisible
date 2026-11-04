/* CRM Equipo Invisible — chat para tu web.
   Uso: <script src="https://TU-CRM/widget.js" data-titulo="Sofía" data-color="#4f46e5"></script> */
(function () {
  'use strict';
  if (window.__ceiWidget) return;
  window.__ceiWidget = true;

  var script = document.currentScript || (function () {
    var s = document.getElementsByTagName('script');
    for (var i = s.length - 1; i >= 0; i--) if (/widget\.js(\?|#|$)/.test(s[i].src)) return s[i];
    return null;
  })();
  if (!script || !script.src) return;

  var BASE = script.src.replace(/\/widget\.js([?#].*)?$/, '');
  var API = BASE + '/api/chat.php';
  var TITULO = script.getAttribute('data-titulo') || 'Escríbenos';
  var COLOR = script.getAttribute('data-color') || '#4f46e5';
  if (!/^#[0-9a-fA-F]{3,8}$/.test(COLOR)) COLOR = '#4f46e5';

  // ---------- sesión del visitante
  function aleatorio(n) {
    var c = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', s = '';
    var arr = null;
    try { arr = new Uint8Array(n); (window.crypto || window.msCrypto).getRandomValues(arr); } catch (e) { arr = null; }
    for (var i = 0; i < n; i++) s += c.charAt((arr ? arr[i] : Math.floor(Math.random() * 256)) % c.length);
    return s;
  }
  var CLAVE = 'cei_sesion', sesion = null;
  try { sesion = window.localStorage.getItem(CLAVE); } catch (e) {}
  if (!sesion || !/^[A-Za-z0-9]{8,64}$/.test(sesion)) {
    sesion = aleatorio(24);
    try { window.localStorage.setItem(CLAVE, sesion); } catch (e) {}
  }

  var CSS =
    ':host{all:initial}' +
    '.cei-raiz{position:fixed;right:20px;bottom:20px;z-index:2147483000;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.4;color:#1f2330}' +
    '.cei-raiz *{box-sizing:border-box;margin:0;padding:0;font-family:inherit}' +
    '.cei-burbuja{width:60px;height:60px;border-radius:50%;border:0;cursor:pointer;background:var(--cei-color);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(0,0,0,.22);transition:transform .15s}' +
    '.cei-burbuja:hover{transform:scale(1.06)}' +
    '.cei-burbuja svg{width:28px;height:28px;fill:#fff}' +
    '.cei-punto{position:absolute;top:2px;right:2px;width:14px;height:14px;border-radius:50%;background:#ef4444;border:2px solid #fff;display:none}' +
    '.cei-panel{position:absolute;right:0;bottom:76px;width:370px;max-width:calc(100vw - 32px);height:540px;max-height:calc(100vh - 110px);background:#fff;border-radius:18px;box-shadow:0 16px 48px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden}' +
    '.cei-abierto .cei-panel{display:flex}' +
    '.cei-cabeza{background:var(--cei-color);color:#fff;padding:14px 16px;display:flex;align-items:center;gap:10px}' +
    '.cei-avatar{width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:17px;flex:none}' +
    '.cei-titulos{flex:1;min-width:0}' +
    '.cei-titulo{font-weight:700;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.cei-sub{font-size:12px;opacity:.9}' +
    '.cei-cerrar{background:transparent;border:0;color:#fff;font-size:26px;line-height:1;cursor:pointer;padding:4px 6px;border-radius:8px}' +
    '.cei-cerrar:hover{background:rgba(255,255,255,.15)}' +
    '.cei-msjs{flex:1;overflow-y:auto;padding:14px;background:#f4f5f9;display:flex;flex-direction:column;gap:8px}' +
    '.cei-m{max-width:82%;padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere;font-size:14.5px}' +
    '.cei-m-ellos{align-self:flex-start;background:#fff;border:1px solid #e6e8ef;border-bottom-left-radius:4px}' +
    '.cei-m-yo{align-self:flex-end;background:var(--cei-color);color:#fff;border-bottom-right-radius:4px}' +
    '.cei-m-aviso{align-self:center;background:#fff4e5;color:#8a5300;font-size:13px;text-align:center}' +
    '.cei-escribiendo{opacity:.65;font-style:italic}' +
    '.cei-form{display:flex;gap:8px;padding:10px;border-top:1px solid #e6e8ef;background:#fff}' +
    '.cei-input{flex:1;min-width:0;border:1px solid #d6d9e3;border-radius:22px;padding:10px 14px;font-size:16px;outline:none;background:#fff;color:#1f2330}' +
    '.cei-input:focus{border-color:var(--cei-color)}' +
    '.cei-enviar{width:44px;height:44px;flex:none;border-radius:50%;border:0;background:var(--cei-color);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center}' +
    '.cei-enviar:disabled{opacity:.5;cursor:default}' +
    '.cei-enviar svg{width:20px;height:20px;fill:#fff}' +
    '.cei-firma{text-align:center;font-size:11px;color:#9aa0ae;padding:0 0 8px;background:#fff}' +
    '@media (max-width:600px){.cei-raiz{right:14px;bottom:14px}.cei-abierto .cei-panel{position:fixed;inset:0;width:100%;max-width:none;height:100%;max-height:none;border-radius:0}.cei-abierto .cei-burbuja{display:none}}';

  var ICONO_CHAT = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3C6.5 3 2 6.9 2 11.7c0 2.4 1.1 4.6 3 6.2L4.2 21.5c-.1.4.3.7.7.5l4-2.1c1 .3 2 .4 3.1.4 5.5 0 10-3.9 10-8.6S17.5 3 12 3z"/></svg>';
  var ICONO_ENVIAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/></svg>';

  var vistos = {}, ultimo = 0, abierto = false, cargado = false, enviando = false, temporizador = null;
  var raiz, panel, lista, input, boton, punto;

  function el(tag, clase, html) {
    var e = document.createElement(tag);
    if (clase) e.className = clase;
    if (html) e.innerHTML = html;
    return e;
  }

  function burbuja(clase, texto) {
    var d = el('div', 'cei-m ' + clase);
    d.textContent = texto;
    lista.appendChild(d);
    lista.scrollTop = lista.scrollHeight;
    return d;
  }

  function pintar(m) {
    if (!m || vistos[m.id]) return;
    vistos[m.id] = true;
    if (m.id > ultimo) ultimo = m.id;
    burbuja(m.de === 'cliente' ? 'cei-m-yo' : 'cei-m-ellos', m.texto);
    if (!abierto && m.de !== 'cliente') punto.style.display = 'block';
  }

  function pedir(metodo, url, cuerpo, listo) {
    try {
      var x = new XMLHttpRequest();
      x.open(metodo, url, true);
      // text/plain evita la consulta previa (CORS) y funciona en cualquier web
      if (cuerpo) x.setRequestHeader('Content-Type', 'text/plain;charset=UTF-8');
      x.timeout = 45000;
      x.onreadystatechange = function () {
        if (x.readyState !== 4) return;
        var d = null;
        try { d = JSON.parse(x.responseText); } catch (e) {}
        listo(x.status, d);
      };
      x.ontimeout = function () {};
      x.send(cuerpo ? JSON.stringify(cuerpo) : null);
    } catch (e) { listo(0, null); }
  }

  function traer() {
    pedir('GET', API + '?sesion=' + encodeURIComponent(sesion) + '&desde=' + ultimo + '&_=' + Date.now(), null, function (st, d) {
      if (st === 200 && d && d.mensajes) d.mensajes.forEach(pintar);
    });
  }

  function cargarInicio() {
    if (cargado) return;
    cargado = true;
    pedir('GET', API + '?sesion=' + encodeURIComponent(sesion) + '&desde=0&_=' + Date.now(), null, function (st, d) {
      var saludo = (d && d.saludo) ? d.saludo : '¡Hola! 👋 ¿En qué te puedo ayudar?';
      lista.innerHTML = '';
      burbuja('cei-m-ellos', saludo);
      if (st === 200 && d && d.mensajes) d.mensajes.forEach(pintar);
      if (st !== 200) burbuja('cei-m-aviso', 'No pude conectar con el chat. Intenta más tarde.');
    });
  }

  function enviar(e) {
    if (e) e.preventDefault();
    var texto = input.value.replace(/^\s+|\s+$/g, '');
    if (!texto || enviando) return;
    if (texto.length > 1000) texto = texto.slice(0, 1000);
    enviando = true;
    boton.disabled = true;
    input.value = '';
    var mio = burbuja('cei-m-yo', texto);
    var esc = burbuja('cei-m-ellos cei-escribiendo', 'Escribiendo…');
    pedir('POST', API, { sesion: sesion, texto: texto }, function (st, d) {
      if (esc.parentNode) esc.parentNode.removeChild(esc);
      enviando = false;
      boton.disabled = false;
      if (st === 200 && d && d.mensajes) {
        d.mensajes.forEach(function (m) {
          if (m.de === 'cliente' && !vistos[m.id]) { vistos[m.id] = true; if (m.id > ultimo) ultimo = m.id; return; }
          pintar(m);
        });
      } else {
        burbuja('cei-m-aviso', (d && d.error) ? d.error : 'No se pudo enviar. Revisa tu conexión e inténtalo de nuevo.');
        if (!d || !d.error) input.value = texto;
        if (mio && st === 0 && mio.parentNode) mio.parentNode.removeChild(mio);
      }
      input.focus();
    });
  }

  function alternar(forzar) {
    abierto = typeof forzar === 'boolean' ? forzar : !abierto;
    raiz.className = 'cei-raiz' + (abierto ? ' cei-abierto' : '');
    if (abierto) {
      punto.style.display = 'none';
      cargarInicio();
      if (!temporizador) temporizador = setInterval(function () { if (abierto && !enviando) traer(); }, 5000);
      setTimeout(function () { try { input.focus(); } catch (e) {} }, 50);
    }
  }

  function iniciar() {
    var host = el('div');
    host.id = 'cei-widget';
    host.setAttribute('style', 'all:initial !important;display:block !important;position:fixed !important;width:0 !important;height:0 !important;border:0 !important;margin:0 !important;padding:0 !important;z-index:2147483000 !important');
    var destino = host;
    if (host.attachShadow) {
      try { destino = host.attachShadow({ mode: 'open' }); } catch (e) { destino = host; }
    }
    var estilo = el('style');
    estilo.textContent = CSS;
    destino.appendChild(estilo);

    raiz = el('div', 'cei-raiz');
    raiz.style.setProperty('--cei-color', COLOR);

    panel = el('div', 'cei-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', TITULO);
    var cabeza = el('div', 'cei-cabeza');
    var av = el('div', 'cei-avatar');
    av.textContent = TITULO.charAt(0).toUpperCase();
    var tit = el('div', 'cei-titulos');
    var t1 = el('div', 'cei-titulo'); t1.textContent = TITULO;
    var t2 = el('div', 'cei-sub'); t2.textContent = 'En línea · respondemos al instante';
    tit.appendChild(t1); tit.appendChild(t2);
    var cerrar = el('button', 'cei-cerrar');
    cerrar.type = 'button';
    cerrar.setAttribute('aria-label', 'Cerrar chat');
    cerrar.innerHTML = '&times;';
    cerrar.onclick = function () { alternar(false); };
    cabeza.appendChild(av); cabeza.appendChild(tit); cabeza.appendChild(cerrar);

    lista = el('div', 'cei-msjs');
    var form = el('form', 'cei-form');
    input = el('input', 'cei-input');
    input.type = 'text';
    input.maxLength = 1000;
    input.placeholder = 'Escribe tu mensaje…';
    input.setAttribute('aria-label', 'Escribe tu mensaje');
    boton = el('button', 'cei-enviar', ICONO_ENVIAR);
    boton.type = 'submit';
    boton.setAttribute('aria-label', 'Enviar');
    form.appendChild(input); form.appendChild(boton);
    form.onsubmit = enviar;

    panel.appendChild(cabeza); panel.appendChild(lista); panel.appendChild(form);
    panel.appendChild(el('div', 'cei-firma', 'Atendido con IA'));

    var bur = el('button', 'cei-burbuja', ICONO_CHAT);
    bur.type = 'button';
    bur.setAttribute('aria-label', 'Abrir chat');
    bur.onclick = function () { alternar(); };
    punto = el('span', 'cei-punto');
    bur.appendChild(punto);
    bur.style.position = 'relative';

    raiz.appendChild(panel);
    raiz.appendChild(bur);
    destino.appendChild(raiz);
    document.body.appendChild(host);

    window.CRMEquipoInvisible = { abrir: function () { alternar(true); }, cerrar: function () { alternar(false); } };
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
