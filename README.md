# CRM Equipo Invisible

Un CRM sencillo con un **agente de IA** que atiende a tus clientes por **WhatsApp** y por el **chat de tu web**, 24/7.
Ves todas las conversaciones en un solo lugar, entras tú cuando el cliente quiere hablar con una persona y llevas a cada contacto por etapas (Nuevo → Conversando → Interesado → Cliente).

Funciona en tu PC con Laragon. No necesitas saber programar.

---

## 1. Instalación en Laragon (3 pasos)

1. Abre **Laragon** y toca **Start All**. Luego toca **Terminal**.
2. Pega esto y presiona Enter:
   ```
   git clone https://github.com/yosoymifer/crm-equipo-invisible.git crm
   ```
3. Abre en tu navegador **http://localhost/crm** y crea tu cuenta (tu correo y una contraseña).

**Plan B (sin Git):** en la página de GitHub toca **Code → Download ZIP**, descomprímelo dentro de `C:\laragon\www` y renombra la carpeta a `crm`. Luego abre http://localhost/crm.

> ¿Sale un error de "pdo_sqlite" o "curl"? En Laragon: clic derecho → **PHP → Extensions** → marca `pdo_sqlite` y `curl`, y reinicia (Stop / Start All).

## 2. Dale cerebro: llave de Gemini (gratis)

1. Entra a **https://aistudio.google.com/apikey** con tu cuenta de Google.
2. Toca **Create API key** y cópiala.
3. En tu CRM: **Ajustes → Cerebro de tu agente** → pega la llave → **Probar conexión con Gemini**. Debe salir ✅.

## 3. Enséñale tu negocio

Ve a **Mi agente**:

- Ponle nombre a tu agente y escribe el saludo del chat.
- Rellena **"Lo que tu agente sabe de tu negocio"**: qué vendes y precios, horario, ubicación, formas de pago, preguntas frecuentes, cómo hablan y qué NO debe hacer.
- Pruébalo en **"Prueba tu agente"**, a la derecha. Si responde algo que no te gusta, mejora el texto y vuelve a probar.

Tu agente **solo** responde con lo que escribiste. Si no sabe algo, lo dice y te pasa la conversación. Cuando el cliente quiere comprar, pagar, agendar o hablar con una persona, la IA se aparta y la conversación sale en rojo: **"Quiere hablar con una persona"**.

## 4. Conecta WhatsApp (con el número de prueba de Meta)

**a) Crea la app en Meta**

1. Entra a **https://developers.facebook.com** → **My Apps → Create App**.
2. Elige el caso de uso **WhatsApp** (conectar con clientes por WhatsApp) y termina de crearla.
3. Ve a **WhatsApp → API Setup**. Ahí tienes:
   - Un **número de prueba** (el que va a contestar).
   - El **token de acceso temporal** (dura 24 horas). Toca *Generate access token*.
   - El **Phone number ID**.
   - En **To**, agrega **tu celular** como destinatario y confírmalo con el código que te llega.
4. En tu CRM: **Ajustes → WhatsApp** → pega el token y el Phone number ID → **Guardar**.
5. Prueba: escribe tu celular en **Enviar mensaje de prueba**. Te debe llegar un "Hello World".

**b) Abre el túnel (para que Meta llegue a tu PC)**

Meta necesita una dirección pública con https. El túnel gratis de Cloudflare te la da:

1. Descarga `cloudflared`: https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe
   (o en la terminal: `winget install Cloudflare.cloudflared`). Si lo descargaste, renómbralo a `cloudflared.exe`.
2. En la terminal, dentro de la carpeta donde está, corre:
   ```
   cloudflared tunnel --url http://localhost:80
   ```
3. Copia la dirección que aparece, tipo `https://algo-raro.trycloudflare.com`. **No cierres esa ventana.**

**c) Conecta el webhook**

1. En Meta: **WhatsApp → Configuration → Webhook → Edit**.
2. **Callback URL:** `https://algo-raro.trycloudflare.com/crm/webhook.php`
3. **Verify token:** el "Token de verificación" que aparece en **Ajustes → WhatsApp** de tu CRM.
4. Toca **Verify and save**.
5. En **Webhook fields**, suscribe el campo **messages**.
6. Desde tu celular, escríbele al número de prueba. Tu agente responde y la conversación aparece en tu CRM. 🎉

¿No llega nada? Mira **Ajustes → Registro**: ahí ves si Meta está llegando y cualquier error.

## 5. El chat en tu web (WordPress u otra)

1. Abre tu CRM con la dirección **del túnel** (o de tu servidor), no con `localhost`: así el código apunta a una dirección pública.
2. **Ajustes → Chat para tu web** → elige título y color → **Copiar código**.
3. En WordPress: edita la página → agrega un bloque **"HTML personalizado"** → pega el código → publica.

Aparece una burbuja abajo a la derecha. Lo que escriben tus visitantes llega a **Conversaciones** y puedes contestar tú desde el CRM.

## 6. Subirlo a tu servidor (para que funcione 24/7)

El proyecto trae un `Dockerfile`. En **EasyPanel**: crea un servicio tipo App desde este repositorio de GitHub, con Dockerfile, y **monta un volumen en `/var/www/datos`** (ahí se guarda tu base de datos; sin el volumen la perderías en cada actualización). Ponle tu dominio con https y usa `https://tu-dominio/webhook.php` como Callback URL en Meta.

## Seguridad

- Tus llaves (Gemini, Meta) y tus conversaciones se guardan **solo en tu PC**, en la carpeta `datos/`.
- Esa carpeta **nunca se sube a GitHub** y no se puede abrir desde el navegador.
- El panel pide usuario y contraseña. Solo son públicos el webhook de WhatsApp y el chat de la web.

## Límites que debes conocer

- La dirección del túnel **cambia cada vez** que lo abres: actualízala en Meta. Y tu PC debe estar **encendida** con Laragon y el túnel abiertos. Para 24/7, súbelo a un servidor.
- El **token temporal de Meta dura 24 horas**. Si vence, genera otro en API Setup y pégalo en Ajustes.
- Con el número de prueba solo puedes escribir a los números que agregaste como destinatarios (máximo 5).
- En el **nivel gratis de Gemini**, Google puede usar los datos para mejorar sus productos y hay límites de uso por minuto y por día. Para un negocio en serio, activa la facturación en Google AI Studio.
