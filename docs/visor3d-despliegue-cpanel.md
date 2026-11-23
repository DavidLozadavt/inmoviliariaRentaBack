# Visor 3D de edificios — despliegue en cPanel

Módulo de NexiLiving: edificios generados por configuración (TresJS), modos de color (inmobiliaria,
administración, PQRS), recorridos 360° (Photo Sphere Viewer) y enlace público firmado con iframe.

## 1. Base de datos

Correr en phpMyAdmin el script SQL de la migración `2026_11_22_000100_create_visor3d_tables`
(idempotente; crea `buildings`, `unit_types`, `panoramas`, `panorama_hotspots` y agrega a `properties`
`building_id`, `unit_type_id`, `tower`, `floor`, `unit_code`, `commercial_status`).
Si el servidor tiene acceso por terminal, también sirve:
`php artisan migrate --path=database/migrations/2026_11_22_000100_create_visor3d_tables.php`.

## 2. Backend (Laravel)

1. Subir el código y `composer install --no-dev --optimize-autoloader`.
2. `.env`: `APP_FRONTEND_URL` = dominio del front (con él se arma el enlace público y el iframe).
   `APP_KEY` no puede cambiar: firma los enlaces públicos.
3. `php artisan storage:link` (sin él las fotos 360° no cargan). Si el hosting no permite symlinks,
   crear el enlace desde el Administrador de archivos o con un cron de una sola vez.
4. `php artisan config:cache && php artisan route:cache`.
5. `public/.htaccess` ya trae la regla de `/storage/visor3d/`: caché de un año (`immutable`, los nombres son
   aleatorios) y `Access-Control-Allow-Origin: *` (el visor 360° descarga la foto con `fetch` desde el dominio del front).
   Requiere `mod_headers` (activo en casi todos los cPanel).

### Cron (uno solo, cada minuto)
```
* * * * * cd /home/USUARIO/ruta-backend && php artisan schedule:run >> /dev/null 2>&1
```
El módulo no necesita colas. El scheduler corre `visor3d:limpiar-huerfanos` los domingos 03:30
(borra fotos sin fila en `panoramas`). A mano: `php artisan visor3d:limpiar-huerfanos --dry-run`.

### PHP en cPanel (Seleccionar versión de PHP → Extensiones / Opciones)
| Qué | Valor |
|---|---|
| Versión | 8.2 o superior |
| Extensiones | `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` (detecta el tipo de la foto), `tokenizer`, `ctype`, `json`, `zip` |
| `upload_max_filesize` | 20M |
| `post_max_size` | 25M |
| `memory_limit` | 256M |
| `max_execution_time` | 60 |

GD/Imagick **no** son necesarias: la foto llega ya redimensionada y en WebP desde el navegador, y PHP solo lee
el tamaño con `getimagesize` (núcleo de PHP).

## 3. Frontend (Vue)

1. En local: `npm install --force` y `npm run build` con `VITE_API_URL=https://<api>/api` en `.env.production`.
2. Subir el contenido de `dist/` al `public_html` (o subdominio) del front. `public/.htaccess` va dentro del build:
   redirige las rutas del SPA a `index.html` (incluida `/visor/<token>`), cachea `/assets/*` un año e impide
   cachear `index.html`.
3. Nada de Node en el servidor.

## 4. Cloudflare (plan gratuito)

- SSL "Full (strict)".
- Las respuestas ya traen su `Cache-Control`:
  - `/assets/*` del front y `/storage/visor3d/*` del back: `public, max-age=31536000, immutable`.
  - `GET /api/public/buildings/{token}` (visor público): `public, max-age=60, s-maxage=120`.
  - Todo lo demás del visor (`/api/buildings/*`): `private, no-store`.
- Cloudflare no cachea JSON por defecto. Si se quiere cachear el visor público, crear una regla de caché solo
  para `/api/public/buildings/*` con "Respetar encabezados de origen". **No** crear reglas "Cache Everything" sobre `/api/*`.
- Revisar que "Rocket Loader" esté apagado (rompe los módulos de Vite).

## 5. Disco e inodos

- Cada foto 360° ocupa 2 archivos (WebP ≤ 6000 px + miniatura de 480 px). Las fotos van por **tipo** de unidad:
  un edificio de 200 apartamentos con 3 tipos y 5 espacios = 30 archivos.
- Borrar una foto o un tipo borra sus archivos; el comando semanal limpia los que queden sueltos.
- El panel "Fotos 360°" muestra el espacio usado por edificio.

## 6. Prueba después de desplegar

1. Iniciar sesión como arrendador → menú → **Edificios 3D** → crear un edificio y ver la vista previa.
2. Pestaña **Unidades** → generar unidades. Pestaña **Fotos 360°** → subir una foto 2:1.
3. Abrir el visor, clic en un piso, clic en una unidad, **Entrar**.
4. Pestaña **Compartir** → crear enlace, abrirlo en una ventana privada (sin sesión) y comprobar que no aparecen
   inquilino, cartera ni PQRS. Pegar el iframe en una página de prueba.
5. `curl -I https://<api>/api/buildings/1/config` (con token) debe responder `Cache-Control: private, no-store`.
