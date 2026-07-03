# ColoniasAPI

API REST/JSON que expone el catálogo completo de colonias de México, con dos capacidades principales:

- **Búsqueda por texto** — autocompletar por nombre de colonia, código postal, municipio o estado.
- **Geolocalización** — dado un punto GPS, devuelve la colonia exacta usando polígonos geográficos (con fallback aproximado por distancia).

Ver [`docs/PLAN.md`](docs/PLAN.md) para el plan completo de desarrollo, arquitectura y milestones.

## Instalación

1. Clonar el repo en el servidor (PHP 8.x + MySQL 8.x).
2. Crear la base de datos y correr el schema:
   ```bash
   mysql -u user -p colonias_mx < schema.sql
   ```
3. Copiar la configuración de ejemplo y llenarla:
   ```bash
   cp config/env.example.php config/env.php
   ```
4. Apuntar el web root del servidor a la carpeta del proyecto (usa `.htaccess` para reescribir todo a `index.php`).
5. Importar los datos (ver sección siguiente).
6. Crear la primera API key:
   ```bash
   php import/0_crear_key.php "NombreDelProyecto"
   ```

## Importación de datos

```bash
# 1. Colonias (SEPOMEX) — coloca el CSV en import/data/sepomex.csv
php import/1_sepomex.php
php import/1c_verificar_conteos.php

# 2. Polígonos (INEGI) — coloca los GeoJSON en import/data/inegi/*.geojson
php import/2_inegi_geo.php
php import/1b_centroide_provisional.php

# 3. Verificación de salud general
php import/3_verificar.php
```

## Autenticación

Todas las rutas (excepto `/health`) requieren una API key:

```
Authorization: Bearer col_xxxxx...
```

o como parámetro de URL (solo para pruebas):

```
?api_key=col_xxxxx...
```

## Endpoints

### `GET /estados`

Catálogo de los 32 estados.

```bash
curl -H "Authorization: Bearer $KEY" https://tu-dominio.com/estados
```

### `GET /municipios?estado_id=9`

Municipios de un estado.

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/municipios?estado_id=9"
```

### `GET /buscar?q=polanco`

Búsqueda por texto libre o código postal (si `q` son 5 dígitos).

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/buscar?q=polanco&limit=5"
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/buscar?q=11550"
```

### `GET /colonia/{id}`

Detalle completo de una colonia.

```bash
curl -H "Authorization: Bearer $KEY" https://tu-dominio.com/colonia/12834
```

### `GET /geolocate?lat=&lng=`

Colonia exacta a partir de coordenadas GPS. El campo `metodo` indica si el resultado vino de `poligono` (exacto) o `centroide` (aproximado).

```bash
curl -H "Authorization: Bearer $KEY" "https://tu-dominio.com/geolocate?lat=19.43261&lng=-99.13321"
```

### `GET /health`

Estado del servicio, sin autenticación.

```bash
curl https://tu-dominio.com/health
```

### `POST /keys/crear` *(uso interno)*

Crea una nueva API key, protegido por `ADMIN_SECRET`.

```bash
curl -X POST https://tu-dominio.com/keys/crear \
  -H "Content-Type: application/json" \
  -d '{"proyecto":"MeUnoColonia","admin_secret":"..."}'
```

## Formato de respuesta

**Éxito:**
```json
{ "ok": true, "data": [...], "total": 10, "tiempo_ms": 14 }
```

**Error:**
```json
{ "ok": false, "error": "Descripción del error", "codigo": 401 }
```

## Caché

Respuestas de `/estados`, `/buscar` y `/geolocate` se cachean en archivos planos en `cache/responses/` (TTL configurable, default 24h). No requiere Redis.
