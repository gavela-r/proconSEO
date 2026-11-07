# Instalación en el servidor de Proconsi (Windows)

Guía para instalar proconSEO en un servidor **Windows de la red local** de Proconsi. Cubre SEG-10, 12, 13, 15, 17 y 18 de la especificación. Lo marcado con **[sistemas]** lo tiene que hacer o confirmar el equipo de sistemas.

Todo lo que se instala es **gratuito** y de su fuente oficial. Los scripts de PowerShell están en [`deploy/windows/`](../deploy/windows/).

Antes de abrirla al uso: pentest del equipo de ciberseguridad (SEG-19).

> **Para probarla en tu ordenador** (no en el servidor): doble clic en `deploy\windows\iniciar-local.cmd`. Comprueba PHP, MySQL, las dependencias y el `.env`, aplica las migraciones, arranca el servidor web y el worker y abre `http://localhost:8090`. Pulsando Enter en su ventana se para todo. No hace falta ningún programa como Local o XAMPP: basta con PHP, MySQL y Composer.

---

## Instalación paso a paso

En este orden. Cada paso remite a su sección.

1. Instalar PHP, IIS, MySQL y Composer (sección 1).
2. Copiar el código en `C:\proconseo` y ejecutar `composer install --no-dev --optimize-autoloader`.
3. Crear la base de datos y sus dos usuarios (sección 6).
4. Copiar `.env.example` como `.env` y rellenarlo (sección 2): datos de MySQL, `APP_ENV=produccion` y la clave de PageSpeed (sección 9).
5. `php bin\migrar.php`, con el usuario de MySQL que puede cambiar tablas (sección 6).
6. Crear el sitio en IIS (sección 4) y el certificado HTTPS (sección 3).
7. Crear la cuenta del worker y aplicar los permisos (sección 5), el cortafuegos (sección 7) y la tarea del worker (sección 8).
8. Ejecutar **`php bin\comprobar.php --produccion --red`**. Tiene que terminar en «Todo listo»; si algo falla, cada línea dice qué falta y cómo arreglarlo.
9. Hacer la prueba de la sección 13.

La revisión automática de GitHub repite los pasos 2, 5 y 8 en una máquina vacía con cada cambio. Si un cambio rompiera la instalación desde cero, no se podría fusionar.

## 1. Requisitos e instalación de los programas [sistemas]

**Servidor**: Windows Server 2019 o posterior, o Windows 10/11 Pro, en la red local. Basta con 2 núcleos, 4 GB de RAM y 20 GB de disco (ver [costes y rendimiento](costes-y-rendimiento.md)). No hace falta ningún navegador, ni Node, ni programas de pago.

| Programa | Dónde | Notas |
|---|---|---|
| **IIS** | Viene con Windows. Se activa en *Características de Windows* (o con *Administrador del servidor* → *Agregar roles*) | Marcar también **CGI**, en *Características de desarrollo de aplicaciones* |
| **URL Rewrite** de IIS | https://www.iis.net/downloads/microsoft/url-rewrite | Módulo oficial de Microsoft |
| **PHP 8.3 x64 NTS** (*Non Thread Safe*) | https://windows.php.net/download | Descomprimir en `C:\php`. Necesita el *Visual C++ Redistributable* que indica esa página |
| **MySQL 8.0 Community** | https://dev.mysql.com/downloads/installer/ | Instalarlo como servicio de Windows, en **este mismo servidor** (lo exige el cortafuegos, sección 7) |
| **Composer** | https://getcomposer.org/Composer-Setup.exe | Que use `C:\php\php.exe` |
| **Git** (opcional) | https://git-scm.com/download/win | Para actualizar con `git pull`. Si no, se copia el código a mano |

Extensiones de PHP, en `C:\php\php.ini` (copiar `php.ini-production` como `php.ini` y quitar el `;` de estas líneas):

```ini
extension_dir = "ext"
extension=curl
extension=intl
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=gd        ; para las capturas de las revisiones humanas
```

`dom`, `json` y `zlib` ya vienen activadas en PHP para Windows.

**Certificados para HTTPS.** PHP en Windows no trae la lista de autoridades de certificación, así que sin ella **no se puede analizar ninguna web https**:

1. Descargar `cacert.pem` de https://curl.se/ca/cacert.pem en `C:\php\extras\ssl\cacert.pem`.
2. Comprobar su huella SHA-256 con https://curl.se/ca/cacert.pem.sha256 (`Get-FileHash C:\php\extras\ssl\cacert.pem`).
3. Apuntar a él en `php.ini`:

```ini
curl.cainfo = "C:\php\extras\ssl\cacert.pem"
openssl.cafile = "C:\php\extras\ssl\cacert.pem"
```

Nunca se desactiva la verificación de certificados para que funcione.

## 2. Acceso a la herramienta (SEG-10) [sistemas]

- [ ] La herramienta **no tiene login**: solo debe ser accesible desde la red local de Proconsi o la VPN. Nunca desde internet: en el router no se abre ningún puerto hacia este servidor.
- [ ] En el Firewall de Windows, la entrada a los puertos 80 y 443 se limita al perfil *Dominio* o *Privado* y a la subred de la oficina.
- [ ] Si hay un proxy o balanceador delante, poner sus IPs en `PROXIES_DE_CONFIANZA` del `.env`. Si no, dejarlo vacío. Sin esto, los límites por IP y la auditoría no ven la IP real de cada persona.

## 3. HTTPS (SEG-18) [sistemas]

- [ ] Un certificado para el nombre interno del servidor (por ejemplo `proconseo.proconsi.local`), emitido por la entidad de certificación interna de Proconsi si la hay. Si no la hay, uno autofirmado (`New-SelfSignedCertificate`) instalado como de confianza en los equipos que la usan.
- [ ] En IIS, enlace **https** del sitio con ese certificado.
- [ ] `public\web.config` ya redirige todo `http://` a `https://` y añade `Strict-Transport-Security`.
- [ ] `APP_ENV=produccion` en el `.env`, para que la cookie de sesión vaya marcada como `Secure`.

## 4. IIS y PHP [sistemas]

1. **Registrar PHP en IIS** (una vez, en PowerShell como administrador):

   ```powershell
   $appcmd = "$env:windir\system32\inetsrv\appcmd.exe"
   & $appcmd set config /section:system.webServer/fastCGI /+"[fullPath='C:\php\php-cgi.exe']"
   & $appcmd set config /section:system.webServer/handlers /+"[name='PHP',path='*.php',verb='*',modules='FastCgiModule',scriptProcessor='C:\php\php-cgi.exe',resourceType='Either']"
   ```

2. **Grupo de aplicaciones** `proconseo`, con *Versión de .NET CLR* «Sin código administrado» e identidad *ApplicationPoolIdentity*. Esa identidad, `IIS AppPool\proconseo`, es la cuenta de la web.
3. **Sitio** `proconSEO` con ese grupo de aplicaciones. Su ruta física es **`C:\proconseo\public`**, nunca `C:\proconseo`: así `.env`, `storage`, `vendor` y el resto no se pueden descargar.
4. `public\web.config` (ya incluido) hace el resto: manda todo a `index.php`, impide ejecutar cualquier otro `.php`, bloquea `.env`, `.git` y similares, y fuerza HTTPS.

En `C:\php\php.ini`, además:

```ini
display_errors = Off
log_errors = On
error_log = "C:\proconseo\storage\php-errores.log"
expose_php = Off
session.cookie_httponly = 1
session.use_strict_mode = 1
allow_url_fopen = Off          ; la red solo se usa a través de cURL, en src/Fetch
cgi.force_redirect = 0
fastcgi.impersonate = 1
```

## 5. Cuentas y permisos (SEG-13) [sistemas]

Dos cuentas sin privilegios:

| Cuenta | Para qué | Cómo se crea |
|---|---|---|
| `IIS AppPool\proconseo` | La web | La crea IIS al crear el grupo de aplicaciones |
| `proconseo-worker` | El worker, que descarga y analiza las webs | Usuario local estándar, **no administrador**: `New-LocalUser proconseo-worker -PasswordNeverExpires` |

Después de copiar el código y crear el `.env`, como administrador:

```powershell
cd C:\proconseo\deploy\windows
.\permisos.ps1 -Ruta C:\proconseo -GrupoIis proconseo -Usuario proconseo-worker
```

El script deja así los permisos:

- Las dos cuentas solo pueden **leer** el código.
- Solo pueden escribir en `storage\`, que guarda las capturas de las revisiones y los logs. Las capturas nunca se sirven directamente, solo a través de la aplicación.
- `.env` solo lo leen esas dos cuentas, los administradores y SYSTEM.

Si PowerShell no deja ejecutar scripts: `Set-ExecutionPolicy -Scope Process RemoteSigned`, solo para esa ventana.

## 6. Base de datos (SEG-13 y SEG-15) [sistemas]

MySQL va en el mismo servidor y solo escucha en `127.0.0.1`: en `my.ini`, `bind-address = 127.0.0.1`.

Dos usuarios de MySQL:

- **`proconseo_admin`**: solo para las migraciones al instalar y actualizar (crea y cambia tablas). Su contraseña **no** va en el `.env`: `actualizar.ps1` la pide cada vez.
- **`proconseo`**: el que usa la aplicación (va en el `.env`). Solo puede leer y escribir datos, nunca cambiar tablas.

```sql
CREATE DATABASE proconseo CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'proconseo_admin'@'localhost' IDENTIFIED BY '<contraseña larga>';
CREATE USER 'proconseo'@'localhost' IDENTIFIED BY '<otra contraseña larga>';
GRANT ALL PRIVILEGES ON proconseo.* TO 'proconseo_admin'@'localhost';

-- Lectura y escritura normal
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.proyectos           TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.analisis            TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.fases_analisis      TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.recursos            TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.grupos_causa        TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.evidencias          TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.resultado_evidencia TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.eventos_seguridad   TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.ajustes_sistema     TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON proconseo.programaciones      TO 'proconseo'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON proconseo.avisos              TO 'proconseo'@'localhost';
GRANT SELECT                         ON proconseo.perfiles_ejecucion  TO 'proconseo'@'localhost';
GRANT SELECT                         ON proconseo.migraciones         TO 'proconseo'@'localhost';   -- para bin\comprobar.php

-- Solo se añaden, nunca se cambian: resultados originales, revisiones y auditoría.
-- (Al borrar un análisis, MySQL borra sus resultados y revisiones en cascada.)
GRANT SELECT, INSERT ON proconseo.resultados         TO 'proconseo'@'localhost';
GRANT SELECT, INSERT ON proconseo.revisiones         TO 'proconseo'@'localhost';
GRANT SELECT, INSERT ON proconseo.registro_auditoria TO 'proconseo'@'localhost';
```

Las tablas las crea `php bin\migrar.php`, así que los `GRANT` por tabla se dan **después** de la primera migración. Si se añaden tablas, se revisa la lista (`SHOW TABLES FROM proconseo;`).

- [ ] **Plazo de conservación** de `registro_auditoria`: pendiente de decidir. La purga la hace sistemas con `proconseo_admin`; la aplicación no puede.
- [ ] **Copia de seguridad diaria**: una tarea programada con `mysqldump --defaults-extra-file=C:\copias\mysql.cnf --single-transaction proconseo > C:\copias\proconseo-AAAAMMDD.sql`. El fichero `mysql.cnf` guarda el usuario y la contraseña de un usuario de MySQL solo para copias (`SELECT, SHOW VIEW, TRIGGER, LOCK TABLES`), con permisos de fichero solo para administradores. Las copias se guardan fuera del servidor.

## 7. Cortafuegos de salida (SEG-12) [sistemas]

Es una segunda barrera por si fallara la validación del código (SEG-01 y SEG-02): aunque alguien consiguiera colar una dirección interna, la conexión no saldría. Se hace con el Firewall de Windows, por programa:

- **`php.exe`** (el worker): no puede conectarse a la red local ni a puertos que no sean 80 y 443.
- **`php-cgi.exe`** (la web): no puede salir a ninguna red.

```powershell
cd C:\proconseo\deploy\windows
.\cortafuegos.ps1 -PhpDir C:\php
```

Sobre el DNS y MySQL:

- El DNS no se ve afectado, porque lo resuelve el servicio *Cliente DNS* de Windows y no PHP.
- MySQL en `127.0.0.1` tampoco, porque Windows no filtra el tráfico local.
- Por eso MySQL tiene que estar en el mismo servidor.

- [ ] Probarlo como `proconseo-worker`: el script muestra al terminar dos órdenes. Con la primera, la conexión a `192.168.1.1` tiene que fallar; con `comprobar.php --red`, la de https tiene que funcionar.

## 8. El worker

Es el proceso que analiza las webs en segundo plano. Sin él no funciona nada de esto:

- los análisis se quedan «en cola» para siempre;
- los **análisis programados** no se lanzan;
- no se crean **avisos**.

Se instala como **tarea programada de Windows**: arranca con el servidor, se reinicia sola si falla y funciona aunque nadie haya iniciado sesión. Como administrador:

```powershell
cd C:\proconseo\deploy\windows
.\instalar-worker.ps1 -Ruta C:\proconseo -Php C:\php\php.exe -Usuario proconseo-worker
```

Pide la contraseña de `proconseo-worker` y la guarda Windows. Se puede ver y parar en el *Programador de tareas*, con el nombre «proconSEO worker». `php bin\comprobar.php` avisa si hay análisis esperando más de 5 minutos.

Después de cada actualización del código hay que **reiniciar la tarea**, porque si no sigue con el código anterior. `actualizar.ps1` lo hace solo.

## 9. Clave de Google PageSpeed (SEG-17) [sistemas]

Sirve para medir la velocidad de carga en móvil y ordenador (SEO-11 y ACC-05). Sin ella todo funciona, pero esas mediciones salen como «no verificable». Es gratuita.

Cómo crearla, con una cuenta de Google de Proconsi (no personal):

1. En https://console.cloud.google.com, crear un proyecto, por ejemplo «proconSEO».
2. En «APIs y servicios → Biblioteca», activar **PageSpeed Insights API**.
3. En «APIs y servicios → Credenciales», crear una **clave de API**.
4. Restringirla:
   - en «Restricciones de API», solo PageSpeed Insights API;
   - en «Restricciones de aplicación», direcciones IP, con la IP pública de salida de la oficina.

- [ ] `PAGESPEED_API_KEY` solo en el `.env` del servidor. Nunca en el repositorio, en un correo ni en el navegador.

## 10. Actualizar

```powershell
cd C:\proconseo\deploy\windows
.\actualizar.ps1 -Ruta C:\proconseo -Php C:\php\php.exe
```

El script hace, en orden y parando en el primer fallo:

1. `git pull`;
2. `composer install --no-dev`;
3. las migraciones, pidiendo la contraseña de `proconseo_admin` sin guardarla;
4. `php bin\comprobar.php --produccion`;
5. reiniciar el worker.

Si la comprobación falla, **no se reinicia nada**.

`bin\comprobar.php` revisa:

- la versión de PHP y sus extensiones;
- la conexión con MySQL;
- las migraciones pendientes y el perfil de ejecución;
- los permisos de `storage\` y los certificados;
- la clave de PageSpeed;
- si el worker está trabajando.

Nunca enseña contraseñas ni claves. Con `--red` hace además una petición https real a example.com. Termina con código 1 si hay errores.

## 11. En caso de emergencia

- **Parar todos los análisis**: `php bin\interruptor.php activar "motivo"`. Se detienen los que estén en marcha, se conserva lo obtenido y no se pueden lanzar más.
- **Volver a la normalidad**: `php bin\interruptor.php desactivar`.
- **Ver qué ha pasado**: `php bin\auditoria.php 200` muestra las últimas 200 entradas del registro de auditoría.
- **Parar el worker**: `Stop-ScheduledTask "proconSEO worker"`.

## 12. En GitHub (SEG-16)

El dueño del repositorio activa en *Settings → Code security*:

- [ ] Dependabot alerts y Dependabot security updates.
- [ ] Secret scanning y push protection, para que no se pueda subir una contraseña por error.
- [x] Regla de rama en `main`: hecha (ruleset «main branch»). Exige PR, una aprobación y que pase la revisión automática, que también prueba en Windows.

## 13. Prueba final, antes de abrirla al uso

Con la herramienta ya instalada, desde otro ordenador de la red local:

- [ ] Se abre `https://<nombre del servidor>/` y el menú muestra Proyectos, Catálogo y Avisos.
- [ ] Desde un ordenador **fuera** de la red, o con el móvil sin wifi, no se puede abrir.
- [ ] Crear un proyecto de prueba con la web de Proconsi y lanzar un análisis **URL individual**. La pantalla de progreso avanza sola y termina en «Completado» en menos de un minuto. Si se queda «en cola», el worker no está en marcha (sección 8).
- [ ] En los resultados aparecen los tres bloques y PageSpeed tiene datos. Si la velocidad sale «no verificable», falta la clave o está mal restringida (sección 9).
- [ ] Lanzar un análisis de `http://192.168.1.1/`: tiene que rechazarse antes de empezar, porque es una dirección interna.
- [ ] Programar ese proyecto «cada semana» y ver que en menos de dos minutos se lanza solo. Después, pausar la programación.
- [ ] `php bin\auditoria.php 20` muestra esos lanzamientos con su IP.
- [ ] Borrar el proyecto de prueba.

Cuando todo esto funcione, queda el pentest del equipo de ciberseguridad (SEG-19).
