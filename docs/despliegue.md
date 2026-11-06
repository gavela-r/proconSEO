# Despliegue en el servidor de Proconsi

Lista de comprobación para instalar proconSEO en producción. Cubre SEG-10, 12, 13, 15, 17 y 18 de la especificación. Lo marcado con **[sistemas]** lo tiene que hacer o confirmar el equipo de sistemas.

Antes de abrirla al uso: pentest del equipo de ciberseguridad (SEG-19).

---

## Instalación paso a paso

Es el orden recomendado. Cada paso remite a su sección.

1. Instalar los requisitos (sección 1) y crear los usuarios del sistema (5).
2. Descargar el código en `/srv/proconseo` y ejecutar `composer install --no-dev --optimize-autoloader`.
3. Crear la base de datos y sus dos usuarios (6).
4. Copiar `.env.example` como `.env` y rellenarlo: datos de MySQL, `APP_ENV=produccion`, `PROXIES_DE_CONFIANZA` (2) y la clave de PageSpeed (9). Darle permisos `640`.
5. Ejecutar `php bin/migrar.php` con el usuario `proconseo_admin`.
6. Configurar el servidor web y HTTPS (3), PHP (4) y el cortafuegos de salida (7).
7. Dejar el worker como servicio (8).
8. Ejecutar **`php bin/comprobar.php --produccion --red`**: tiene que terminar en «Todo listo». Si algo falla, cada línea dice qué falta y cómo arreglarlo.
9. Hacer la prueba de la sección 13.

La revisión automática de GitHub hace los pasos 2, 5 y 8 en una máquina vacía con cada cambio. Si un cambio rompiera la instalación desde cero, no se podría fusionar.

## 1. Requisitos

- PHP 8.2 o superior con `curl`, `dom`, `intl`, `json`, `mbstring`, `openssl`, `pdo_mysql` y `zlib`.
  - Opcional: `gd`, para adjuntar capturas en las revisiones humanas.
- MySQL 8 con `utf8mb4`.
- Composer.
- Un servidor web (Apache o Nginx) con PHP-FPM.
- No hace falta instalar ningún navegador, ni Node, ni otros programas.

## 2. Acceso a la herramienta (SEG-10) [sistemas]

- [ ] La herramienta **no tiene login**: solo debe ser accesible desde la red interna de Proconsi o la VPN. Nunca desde internet.
- [ ] Si hay un proxy o balanceador delante, poner sus IPs en `PROXIES_DE_CONFIANZA` del `.env`. Si no, dejarlo vacío. Sin esto, los límites por IP y la auditoría no ven la IP real de cada persona.

## 3. HTTPS y cabeceras (SEG-18)

- [ ] Certificado válido y HTTPS obligatorio. Lo que llegue por `http://` se redirige a `https://`.
- [ ] Cabecera `Strict-Transport-Security: max-age=31536000` en el servidor web.
- [ ] `APP_ENV=produccion` en el `.env`, para que la cookie de sesión vaya marcada como `Secure`.
- [ ] La raíz pública del servidor web es **solo** la carpeta `public/`. `.env`, `storage/`, `vendor/` y el resto no deben poder descargarse.

Ejemplo para Nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name proconseo.interno.proconsi.com;   # el que decida sistemas
    root /srv/proconseo/public;

    add_header Strict-Transport-Security "max-age=31536000" always;

    location / { try_files $uri /index.php$is_args$args; }
    location ~ ^/index\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/proconseo-web.sock;
    }
    location ~ \.php$ { return 404; }   # ningún otro PHP se ejecuta
}
server { listen 80; server_name proconseo.interno.proconsi.com; return 301 https://$host$request_uri; }
```

## 4. PHP en producción

En el `php.ini` (o el pool de PHP-FPM):

```ini
display_errors = Off
log_errors = On
expose_php = Off
session.cookie_httponly = 1
session.use_strict_mode = 1
allow_url_fopen = Off          ; la red solo se usa a través de cURL, en src/Fetch
```

- [ ] cURL tiene un almacén de certificados actualizado (`curl.cainfo`). Sin él, ninguna web HTTPS se puede analizar.

## 5. Usuarios del sistema (SEG-13) [sistemas]

Dos usuarios sin privilegios y sin shell:

| Usuario | Para qué | Puede escribir en |
|---|---|---|
| `proconseo-web` | PHP-FPM, la web | `storage/` |
| `proconseo-worker` | El worker que descarga y analiza las webs | `storage/` |

- [ ] El código es propiedad de otro usuario (el que despliega). Los dos usuarios de arriba solo pueden **leerlo**.
- [ ] `storage/` (y dentro, `storage/capturas/`, que se crea sola) es lo único en lo que pueden escribir. Ahí se guardan las capturas que se adjuntan en las revisiones; nunca se sirven directamente, solo a través de la aplicación.
- [ ] `.env` con permisos `640`, legible solo por esos dos usuarios.
- [ ] Ninguno de los dos tiene acceso a otros ficheros del servidor.

## 6. Base de datos (SEG-13 y SEG-15) [sistemas]

Dos usuarios de MySQL:

- **`proconseo_admin`**: solo para `composer migrar` al desplegar (crea y cambia tablas). Su contraseña no va en el `.env` del servidor.
- **`proconseo`**: el que usa la aplicación (va en el `.env`). Solo puede leer y escribir datos, nunca cambiar tablas.

```sql
-- Lectura y escritura normal
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.proyectos          TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.analisis           TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.fases_analisis     TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.recursos           TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.grupos_causa       TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.evidencias         TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.resultado_evidencia TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.eventos_seguridad  TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.ajustes_sistema    TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.programaciones     TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON proconseo.avisos             TO 'proconseo'@'localhost';
GRANT SELECT                         ON proconseo.perfiles_ejecucion TO 'proconseo'@'localhost';
GRANT SELECT                         ON proconseo.migraciones        TO 'proconseo'@'localhost';   -- para bin/comprobar.php

-- Solo se añaden, nunca se cambian: resultados originales, revisiones y auditoría
GRANT SELECT, INSERT ON proconseo.resultados         TO 'proconseo'@'localhost';
GRANT SELECT, INSERT ON proconseo.revisiones         TO 'proconseo'@'localhost';
GRANT SELECT, INSERT ON proconseo.registro_auditoria TO 'proconseo'@'localhost';
```

- [ ] Revisar esta lista cuando se añadan tablas. Para ver las que hay: `SHOW TABLES FROM proconseo;`. A 6 de noviembre de 2026 son las de arriba más `migraciones`. `programaciones` y `avisos` llegan con el PR #54.
- [ ] **Plazo de conservación** de `registro_auditoria`: pendiente de decidir. La purga la hace sistemas con `proconseo_admin`; la aplicación no puede.
- [ ] Copias de seguridad diarias de la base de datos.

## 7. Cortafuegos de salida (SEG-12) [sistemas]

Es una segunda barrera por si fallara la validación del código (SEG-01 y SEG-02): aunque alguien consiguiera colar una dirección interna, la conexión no saldría.

- [ ] `proconseo-worker` solo puede conectarse a **IPs públicas** en los puertos **80 y 443**, al **servidor DNS** y a MySQL.
- [ ] `proconseo-web` no necesita salir a internet: solo DNS (para validar la URL antes de encolar) y MySQL.
- [ ] Bloqueado en ambos: redes privadas, loopback (salvo MySQL), link-local y metadatos, CGNAT y equivalentes IPv6.

Ejemplo con nftables (adaptar al servidor):

```nft
table inet proconseo_salida {
    set internas4 { type ipv4_addr; flags interval;
        elements = { 0.0.0.0/8, 10.0.0.0/8, 100.64.0.0/10, 127.0.0.0/8, 169.254.0.0/16,
                     172.16.0.0/12, 192.168.0.0/16, 198.18.0.0/15, 224.0.0.0/3 } }
    set internas6 { type ipv6_addr; flags interval;
        elements = { ::/127, ::ffff:0:0/96, 64:ff9b::/96, 2002::/16, 2001::/32, fc00::/7, fe80::/10, ff00::/8 } }

    chain salida {
        type filter hook output priority 0; policy accept;
        meta skuid { "proconseo-worker", "proconseo-web" } jump limitar
    }
    chain limitar {
        ct state established,related accept
        udp dport 53 ip daddr <IP_DEL_DNS> accept
        tcp dport 53 ip daddr <IP_DEL_DNS> accept
        tcp dport 3306 ip daddr 127.0.0.1 accept
        meta skuid "proconseo-web" drop
        ip daddr @internas4 drop
        ip6 daddr @internas6 drop
        tcp dport { 80, 443 } accept
        drop
    }
}
```

- [ ] Probarlo: como `proconseo-worker`, `curl http://169.254.169.254/` y `curl http://192.168.1.1/` deben fallar, y `curl https://www.proconsi.com/` debe funcionar.

## 8. El worker

Analiza las webs en segundo plano. Se deja como servicio para que arranque solo y se reinicie si falla:

```ini
# /etc/systemd/system/proconseo-worker.service
[Unit]
Description=proconSEO worker
After=network-online.target mysql.service

[Service]
User=proconseo-worker
WorkingDirectory=/srv/proconseo
ExecStart=/usr/bin/php bin/worker.php
Restart=always
RestartSec=5
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/srv/proconseo/storage
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

Si no se puede usar un servicio, vale un cron cada minuto con `php bin/worker.php --una-vez`:

```cron
* * * * * proconseo-worker cd /srv/proconseo && php bin/worker.php --una-vez >> storage/worker.log 2>&1
```

Sin el worker no funciona nada en segundo plano:

- los análisis se quedan «en cola» para siempre;
- los **análisis programados** no se lanzan;
- no se crean **avisos**.

`php bin/comprobar.php` avisa si hay análisis esperando más de 5 minutos.

Después de cada actualización del código hay que **reiniciar el worker**, porque si no sigue con el código anterior. Con cron no hace falta.

## 9. Claves de APIs (SEG-17) [sistemas]

La clave de Google PageSpeed sirve para medir la velocidad de carga en móvil y ordenador (SEO-11 y ACC-05). Sin ella todo funciona, pero esas mediciones salen como «no verificable». Es gratuita.

Cómo crearla, con una cuenta de Google de Proconsi (no personal):

1. En https://console.cloud.google.com, crear un proyecto, por ejemplo «proconSEO».
2. En «APIs y servicios → Biblioteca», activar **PageSpeed Insights API**.
3. En «APIs y servicios → Credenciales», crear una **clave de API**.
4. Restringirla: en «Restricciones de API», solo PageSpeed Insights API; en «Restricciones de aplicación», direcciones IP, con la IP pública de salida del servidor.

- [ ] `PAGESPEED_API_KEY` solo en el `.env` del servidor. Nunca en el repositorio, en un correo ni en el navegador.
- [ ] La clave está restringida a la **API de PageSpeed Insights** y a la **IP del servidor**.

## 10. Instalar y actualizar

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/migrar.php                       # con el usuario proconseo_admin
php bin/comprobar.php --produccion       # tiene que terminar en «Todo listo»
systemctl restart proconseo-worker
```

`bin/comprobar.php` revisa la versión de PHP y sus extensiones, la conexión con MySQL, las migraciones pendientes, el perfil de ejecución, los permisos de `storage/`, los certificados de cURL, la clave de PageSpeed y si el worker está trabajando. Nunca enseña contraseñas ni claves. Con `--red` hace además una petición https real a example.com. Termina con código 1 si hay errores, así que se puede usar en un script de despliegue.

## 11. En caso de emergencia

- **Parar todos los análisis**: `php bin/interruptor.php activar "motivo"`. Se detienen los que estén en marcha (se conserva lo obtenido) y no se pueden lanzar más.
- **Volver a la normalidad**: `php bin/interruptor.php desactivar`.
- **Ver qué ha pasado**: `php bin/auditoria.php 200` muestra las últimas 200 entradas del registro de auditoría.

## 12. En GitHub (SEG-16)

El dueño del repositorio activa en *Settings → Code security*:

- [ ] Dependabot alerts y Dependabot security updates.
- [ ] Secret scanning y push protection, para que no se pueda subir una contraseña por error.
- [x] Regla de rama en `main`: hecha (ruleset «main branch»). Exige PR, una aprobación y que pase la revisión automática.

## 13. Prueba final, antes de abrirla al uso

Con la herramienta ya instalada, desde un ordenador de la red interna:

- [ ] Se abre `https://<dirección de la herramienta>/` y el menú muestra Proyectos, Catálogo y Avisos.
- [ ] Crear un proyecto de prueba con la web de Proconsi y lanzar un análisis **URL individual**. La pantalla de progreso avanza sola y termina en «Completado» en menos de un minuto. Si se queda «en cola», el worker no está en marcha (8).
- [ ] En los resultados aparecen los tres bloques y PageSpeed tiene datos. Si sale «no verificable» en la velocidad, falta la clave o está mal restringida (9).
- [ ] Lanzar un análisis de `http://192.168.1.1/`: tiene que rechazarse antes de empezar, porque es una dirección interna.
- [ ] Programar ese proyecto «cada semana» y, en menos de dos minutos, ver que se lanza solo. Después, pausar la programación.
- [ ] `php bin/auditoria.php 20` muestra esos lanzamientos con su IP.
- [ ] Borrar el proyecto de prueba.

Cuando todo esto funcione, queda el pentest del equipo de ciberseguridad (SEG-19).
