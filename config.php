<?php
// Carpeta donde se guardan tus datos (base de datos, registro y sesiones).
// No necesitas tocar nada aquí: todo lo demás se configura desde el panel (Ajustes y Mi agente).
define('CRM_DATOS', getenv('CRM_DATOS') ?: __DIR__ . '/datos');
