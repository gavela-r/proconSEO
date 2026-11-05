# Despliegue en el servidor de Proconsi

Lista de comprobación para instalar proconSEO en producción. Cubre SEG-10, 12, 13, 15, 17 y 18 de la especificación. Lo marcado con **[sistemas]** lo tiene que hacer o confirmar el equipo de sistemas.

Antes de abrirla al uso: pentest del equipo de ciberseguridad (SEG-19).

---

## 1. Requisitos

- PHP 8.2 o superior con `curl`, `dom`, `intl`, `json`, `mbstring` y `pdo_mysql`.
- MySQL 8 con `utf8mb4`.
- Composer.
- Un servidor web (Apache o Nginx) con PHP-FPM.

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
GRANT SELECT                         ON proconseo.perfiles_ejecucion TO 'proconseo'@'localhost';

-- Solo se añaden, nunca se cambian: resultados originales, revisiones y auditoría
GRANT SELECT, INSERT ON proconseo.resultados         TO 'proconseo'@'localhost';
GRANT SELECT, INSERT ON proconseo.revisiones         TO 'proconseo'@'localhost';
GRANT SELECT, INSERT ON proconseo.registro_auditoria TO 'proconseo'@'localhost';
```

- [ ] Revisar esta lista cuando las Partes 2 y 3 añadan tablas.
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

Si no se puede usar un servicio, vale un cron cada minuto con `php bin/worker.php --una-vez`.

## 9. Claves de APIs (SEG-17) [sistemas]

- [ ] `PAGESPEED_API_KEY` solo en el `.env` del servidor. Nunca en el repositorio ni en el navegador.
- [ ] En Google Cloud, restringir la clave a la **API de PageSpeed Insights** y a la **IP del servidor**.

## 10. Instalar y actualizar

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/migrar.php        # con el usuario proconseo_admin
systemctl restart proconseo-worker
```

## 11. En caso de emergencia

- **Parar todos los análisis**: `php bin/interruptor.php activar "motivo"`. Se detienen los que estén en marcha (se conserva lo obtenido) y no se pueden lanzar más.
- **Volver a la normalidad**: `php bin/interruptor.php desactivar`.
- **Ver qué ha pasado**: `php bin/auditoria.php 200` muestra las últimas 200 entradas del registro de auditoría.

## 12. En GitHub (SEG-16)

El dueño del repositorio activa en *Settings → Code security*:

- [ ] Dependabot alerts y Dependabot security updates.
- [ ] Secret scanning y push protection, para que no se pueda subir una contraseña por error.
- [ ] Regla de rama en `main`: la revisión automática (`Revisión`) tiene que pasar antes de fusionar.
