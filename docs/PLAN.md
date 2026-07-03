# ColoniasAPI — Plan completo de proyecto

> Documento de planificación para el equipo de desarrollo y agentes de IA.
> Leer completo antes de implementar cualquier tarea.

---

## Índice

1. [Qué es ColoniasAPI](#1-qué-es-coloniasapi)
2. [Fuentes de datos](#2-fuentes-de-datos)
3. [Arquitectura técnica](#3-arquitectura-técnica)
4. [Endpoints del API](#4-endpoints-del-api)
5. [Milestones y tareas](#5-milestones-y-tareas)
6. [Verificación técnica por milestone](#6-verificación-técnica-por-milestone)
7. [Verificación funcional — checklist para el usuario](#7-verificación-funcional--checklist-para-el-usuario)
8. [Referencia de tamaños de tarea](#8-referencia-de-tamaños-de-tarea)

---

## 1. Qué es ColoniasAPI

ColoniasAPI es un servicio web independiente (REST/JSON) que expone el catálogo completo de colonias de México con dos capacidades principales:

**A. Búsqueda por texto**
Dado un texto parcial, devuelve colonias que coincidan con el nombre de la colonia, código postal, municipio o estado. Diseñado para autocompletar en formularios.

**B. Geolocalización**
Dado un par de coordenadas GPS (latitud, longitud), devuelve la colonia exacta a la que pertenece ese punto, usando el polígono geográfico de cada colonia.

Ambas capacidades son consumibles por cualquier proyecto mediante API key. Los proyectos actuales que lo usarán:
- **MeUnoColonia** — autocompletar colonia en el registro de colonos; detectar colonia por GPS del celular
- **Otros proyectos futuros** — cualquier sistema que necesite datos de colonias mexicanas

### Lo que NO es este proyecto
- No es un mapa ni visualizador
- No tiene panel de administración web (la administración es por scripts de importación)
- No guarda datos de usuarios ni peticiones
- No tiene autenticación de usuarios — solo autenticación de proyectos (API keys)

---

## 2. Fuentes de datos

El catálogo de colonias en México proviene de dos fuentes oficiales gratuitas que se deben combinar:

### 2.1 SEPOMEX — Datos de texto
- **URL de descarga:** https://www.correosdemexico.gob.mx/SSLServicios/ConsultaCP/Descarga.aspx
- **Formato:** CSV, un archivo por estado (o todos juntos)
- **Contenido:** nombre de colonia, código postal, tipo de asentamiento, municipio, estado
- **Tamaño:** ~145,000 registros
- **Actualización:** ocasional (cuando se crean colonias nuevas)
- **Columnas relevantes:**
  - `d_asenta` — nombre de la colonia
  - `d_tipo_asenta` — tipo: Colonia, Fraccionamiento, Pueblo, Ejido, etc.
  - `d_CP` — código postal (5 dígitos)
  - `d_mnpio` — nombre del municipio
  - `d_estado` — nombre del estado
  - `c_estado` — clave de 2 dígitos del estado (para unir con INEGI)
  - `c_mnpio` — clave de municipio

### 2.2 INEGI Marco Geoestadístico — Polígonos
- **URL de descarga:** https://www.inegi.org.mx/app/biblioteca/ficha.html?upc=889463770944
- **Formato:** Shapefile (.shp) o GeoJSON por estado
- **Contenido:** polígono exacto de cada colonia (límites geográficos)
- **Atributos relevantes:**
  - `NOMGEO` — nombre de la colonia
  - `CVE_MUN` — clave de municipio (para unir con SEPOMEX)
  - `CVE_ENT` — clave de estado
  - `geometry` — polígono en coordenadas WGS84

### 2.3 Estrategia de unión
SEPOMEX tiene los CP y tipos; INEGI tiene los polígonos. Se unen por:
```
clave_estado + clave_municipio + nombre_colonia (normalizado: sin acentos, mayúsculas)
```
El script de importación hace esta unión. Los que no emparejan quedan sin polígono (el campo queda NULL) y solo funcionan en búsqueda por texto, no en geolocalización exacta.

---

## 3. Arquitectura técnica

### 3.1 Infraestructura
```
Servidor compartido (mismo que meunocolonia.org)
├── MySQL 8.x
│   ├── meunocoonia        ← BD existente de MeUnoColonia (no tocar)
│   └── colonias_mx        ← BD nueva de ColoniasAPI
└── PHP 8.x
    └── colonias-api/      ← carpeta raíz del proyecto
        ├── index.php      ← router (web root apunta aquí)
        ├── .htaccess
        ├── config/
        ├── middleware/
        ├── handlers/
        ├── helpers/
        ├── cache/
        └── import/
```

### 3.2 Base de datos — Diagrama de tablas

```
api_keys                    estados (32 filas)
  id                          id
  proyecto                    clave CHAR(2)
  key_hash CHAR(64)           nombre
  activa
  ultimo_uso                municipios (~2,500 filas)
                              id
                              estado_id → estados.id
colonias (~145,000 filas)     clave_inegi
  id                          nombre
  nombre
  municipio_id → municipios.id
  codigo_postal             colonia_poligonos (~145,000 filas)
  tipo                        colonia_id → colonias.id  [PK]
  centroide  POINT            poligono   GEOMETRY NOT NULL
  creado_en                   SPATIAL INDEX sp_poligono
```

**Por qué dos tablas para colonias y polígonos:**
- `SPATIAL INDEX` en MySQL requiere columna `NOT NULL`
- Los polígonos se importan en una segunda fase (primero SEPOMEX, luego INEGI)
- Las consultas de texto no necesitan cargar los polígonos (más rápido)
- Permite que el sistema funcione parcialmente desde el día 1

### 3.3 Índices críticos

| Tabla | Índice | Tipo | Para qué sirve |
|-------|--------|------|----------------|
| `colonias` | `ft_nombre` | FULLTEXT | Búsqueda por texto libre |
| `colonias` | `sp_centroide` | SPATIAL (R-Tree) | Búsqueda colonias cercanas |
| `colonias` | `idx_cp` | B-Tree | Búsqueda por código postal |
| `colonias` | `idx_municipio` | B-Tree | Filtrar por municipio |
| `colonia_poligonos` | `sp_poligono` | SPATIAL (R-Tree) | `ST_Within()` para GPS |

### 3.4 Sistema de caché

Sin Redis en el hosting compartido, se usa caché de archivos PHP:
- **Ubicación:** `cache/responses/`
- **Nombre de archivo:** `md5(endpoint + params).json`
- **TTL:** 24 horas (el catálogo de colonias cambia muy poco)
- **Invalidar:** borrar archivos en `cache/responses/` manualmente o por script

```
cache/
  responses/
    a3f8c2...json    ← /buscar?q=polanco
    9d12e4...json    ← /buscar?q=roma
    c71ab3...json    ← /geolocate?lat=19.43&lng=-99.13
```

### 3.5 Coordenadas — Convención importante

El proyecto usa **SRID 0** (Cartesiano) en lugar de SRID 4326 (WGS84 estándar) para evitar problemas de orden de ejes en MySQL 8 (que invierte lat/lng en SRID 4326). A escala de México la diferencia es < 0.01%.

**Convención de almacenamiento:**
```
POINT(longitud, latitud)   — x = lng, y = lat
ST_X(centroide) = longitud
ST_Y(centroide) = latitud
```

**Coordenadas en respuestas JSON siempre como:**
```json
{ "lat": 19.43261, "lng": -99.13321 }
```

---

## 4. Endpoints del API

### Autenticación
Todas las peticiones requieren la API key en el header:
```
Authorization: Bearer {api_key}
```
O como parámetro de URL (menos seguro, solo para pruebas):
```
?api_key={api_key}
```

### Formato de respuesta estándar

**Éxito:**
```json
{
  "ok": true,
  "data": [...],
  "total": 10,
  "tiempo_ms": 14
}
```

**Error:**
```json
{
  "ok": false,
  "error": "Descripción del error en español",
  "codigo": 401
}
```

---

### `GET /buscar`

Búsqueda por texto. Detecta automáticamente el tipo de query:
- Si son 5 dígitos → busca por código postal exacto
- Si no → FULLTEXT en nombre de colonia + municipio

**Parámetros:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `q` | string | ✓ | Texto a buscar (mín. 2 caracteres) |
| `estado_id` | int | — | Filtrar por estado |
| `municipio_id` | int | — | Filtrar por municipio |
| `limit` | int | — | Resultados (default 10, máx 50) |

**Ejemplo:** `GET /buscar?q=polanco&limit=5`
```json
{
  "ok": true,
  "data": [
    {
      "id": 12834,
      "nombre": "Polanco I Sección",
      "tipo": "Colonia",
      "codigo_postal": "11550",
      "municipio": "Miguel Hidalgo",
      "estado": "Ciudad de México",
      "estado_id": 9,
      "municipio_id": 214
    }
  ],
  "total": 1,
  "tiempo_ms": 8
}
```

**Ejemplo CP:** `GET /buscar?q=11550`
```json
{
  "ok": true,
  "data": [
    { "id": 12834, "nombre": "Polanco I Sección", "codigo_postal": "11550", ... },
    { "id": 12835, "nombre": "Polanco II Sección", "codigo_postal": "11550", ... },
    { "id": 12836, "nombre": "Polanco III Sección", "codigo_postal": "11550", ... }
  ],
  "total": 3,
  "tiempo_ms": 4
}
```

---

### `GET /geolocate`

Dado un punto GPS, devuelve la colonia exacta usando polígonos.

**Flujo interno:**
1. Busca con `ST_Within(POINT(lng,lat), poligono)` — exacto, usa SPATIAL INDEX
2. Si no encuentra (punto en frontera o sin polígono), usa Haversine al centroide más cercano — fallback
3. Respuesta incluye campo `metodo` para que el consumidor sepa la precisión

**Parámetros:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `lat` | float | ✓ | Latitud decimal (ej. 19.43261) |
| `lng` | float | ✓ | Longitud decimal (ej. -99.13321) |

**Ejemplo:** `GET /geolocate?lat=19.43261&lng=-99.13321`
```json
{
  "ok": true,
  "data": {
    "id": 12834,
    "nombre": "Polanco I Sección",
    "tipo": "Colonia",
    "codigo_postal": "11550",
    "municipio": "Miguel Hidalgo",
    "estado": "Ciudad de México",
    "estado_id": 9,
    "municipio_id": 214,
    "lat": 19.43200,
    "lng": -99.13400,
    "metodo": "poligono"
  },
  "tiempo_ms": 18
}
```

Campo `metodo`:
- `"poligono"` — resultado exacto por ST_Within (máxima precisión)
- `"centroide"` — resultado aproximado por Haversine (sin polígono disponible)

---

### `GET /estados`

Catálogo de los 32 estados. Sin parámetros.

**Ejemplo:** `GET /estados`
```json
{
  "ok": true,
  "data": [
    { "id": 1, "clave": "01", "nombre": "Aguascalientes" },
    { "id": 9, "clave": "09", "nombre": "Ciudad de México" }
  ],
  "total": 32,
  "tiempo_ms": 2
}
```

---

### `GET /municipios`

Municipios de un estado.

**Parámetros:**

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `estado_id` | int | ✓ | ID del estado |

**Ejemplo:** `GET /municipios?estado_id=9`
```json
{
  "ok": true,
  "data": [
    { "id": 210, "nombre": "Álvaro Obregón", "clave_inegi": "09010" },
    { "id": 214, "nombre": "Miguel Hidalgo", "clave_inegi": "09007" }
  ],
  "total": 16,
  "tiempo_ms": 3
}
```

---

### `GET /colonia/{id}`

Detalle completo de una colonia por ID.

**Ejemplo:** `GET /colonia/12834`
```json
{
  "ok": true,
  "data": {
    "id": 12834,
    "nombre": "Polanco I Sección",
    "tipo": "Colonia",
    "codigo_postal": "11550",
    "municipio": "Miguel Hidalgo",
    "municipio_id": 214,
    "estado": "Ciudad de México",
    "estado_id": 9,
    "lat": 19.43200,
    "lng": -99.13400,
    "tiene_poligono": true
  },
  "tiempo_ms": 3
}
```

---

### `POST /keys/crear` *(solo uso interno — IP whitelist)*

Crea una nueva API key para un proyecto.

**Body JSON:**
```json
{ "proyecto": "MeUnoColonia", "admin_secret": "****" }
```

**Respuesta:**
```json
{
  "ok": true,
  "data": {
    "proyecto": "MeUnoColonia",
    "api_key": "col_a1b2c3d4e5f6..."
  }
}
```

La `api_key` se muestra una sola vez — el sistema guarda solo el hash.

---

## 5. Milestones y tareas

### Referencia de tamaños de IA

| Etiqueta | Modelo adecuado | Tipo de tarea |
|----------|----------------|---------------|
| 🟢 **pequeña** | Haiku / GPT-3.5 | SELECT simple, respuesta JSON estática, configuración |
| 🟡 **mediana** | Sonnet / GPT-4o-mini | Lógica de negocio, múltiples tablas, caché, validaciones |
| 🔴 **grande** | Opus / GPT-4o | Importación GeoJSON, índices espaciales, Haversine, router complejo |
| ✅ **verificación** | Cualquier modelo | QA, checklists, comparar esperado vs actual |

---

### M0 — Fundación del proyecto
*Objetivo: Repositorio funcional con estructura lista para desarrollar.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M0.1 | Crear repo `colonias-api` en GitHub, estructura de carpetas | 🟢 | Repo con carpetas config/, middleware/, handlers/, helpers/, cache/, import/ |
| M0.2 | Crear `schema.sql` con tablas api_keys, estados, municipios, colonias, colonia_poligonos + índices + INSERT de los 32 estados | 🟡 | Archivo ejecutable en MySQL sin errores |
| M0.3 | Crear `config/env.example.php` con todas las constantes requeridas | 🟢 | Archivo documentado con cada constante explicada |
| M0.4 | Crear `config/db.php` con función `getDB()` singleton PDO y función `jsonResponse()` | 🟢 | Conexión a BD y helper de respuesta JSON |
| M0.5 | Crear `.htaccess` que rewrite todo a `index.php` | 🟢 | Todas las rutas llegan a index.php |
| M0.6 | Crear `index.php` como router básico: parsear URI, devolver 404 JSON si ruta no existe | 🟡 | Router que despacha a handlers/ |
| ✅ M0.V | Verificar: `curl /estados` devuelve 401, `curl /estados?api_key=test` devuelve 401, servidor sin errores 500 | ✅ | Checklist completado |

---

### M1 — Autenticación
*Objetivo: Todas las rutas protegidas por API key.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M1.1 | Crear `middleware/auth.php`: leer header `Authorization: Bearer` o `?api_key=`, calcular SHA-256, buscar en `api_keys`, actualizar `ultimo_uso` | 🟡 | Middleware que bloquea sin key válida |
| M1.2 | Crear `handlers/keys.php`: endpoint `POST /keys/crear` protegido por `ADMIN_SECRET` en env | 🟡 | Genera key aleatoria (32 bytes hex), devuelve una vez |
| M1.3 | Insertar manualmente la primera API key para MeUnoColonia vía script CLI | 🟢 | Key guardada en BD, probada con curl |
| ✅ M1.V | Verificar: key inválida → 401, key válida → pasa, key desactivada → 401, `ultimo_uso` se actualiza | ✅ | |

---

### M2 — Endpoints de catálogo (sin geolocalización)
*Objetivo: /estados, /municipios y /buscar funcionando.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M2.1 | `handlers/estados.php` — SELECT todos los estados, respuesta JSON | 🟢 | GET /estados devuelve los 32 estados |
| M2.2 | `handlers/municipios.php` — SELECT municipios WHERE estado_id, validar parámetro | 🟢 | GET /municipios?estado_id=9 |
| M2.3 | `handlers/colonias.php` — GET /colonia/{id} con JOIN a municipio y estado | 🟢 | Detalle de colonia por ID |
| M2.4 | `handlers/buscar.php` — Detectar si query es CP (5 dígitos) o texto; rama CP: WHERE codigo_postal = ?; rama texto: MATCH AGAINST con JOIN a municipio/estado | 🟡 | /buscar?q=polanco y /buscar?q=11550 |
| M2.5 | Caché de archivos en `helpers/cache.php`: `cacheGet(key)` / `cacheSet(key, data, ttl)` usando serialize/unserialize en `cache/responses/` | 🟡 | Segundo request del mismo query usa archivo, no BD |
| M2.6 | Aplicar caché en /buscar y /estados (TTL 24h) | 🟢 | Verificable con `ls cache/responses/` |
| ✅ M2.V | Verificar: buscar por nombre parcial, por CP, por municipio + estado, query < 2 chars → error claro, limit respetado | ✅ | |

---

### M3 — Importación SEPOMEX (datos de texto)
*Objetivo: Las 145,000 colonias en la BD con nombre, CP, municipio, estado.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M3.1 | Descargar CSV de SEPOMEX (manual, el usuario lo hace) y colocarlo en `import/data/sepomex.csv` | — | Archivo CSV en carpeta |
| M3.2 | `import/1_sepomex.php` — leer CSV, insertar/actualizar municipios (deduplicar por clave_inegi), insertar colonias con centroide vacío temporal | 🔴 | Script CLI que corre sin errores en ~5 min |
| M3.3 | Verificar conteos post-importación: `SELECT COUNT(*) FROM colonias` ≈ 145,000 | 🟢 | Query de verificación |
| M3.4 | Calcular centroide promedio por colonia a partir de sus vecinos del mismo CP (estimación provisional hasta tener INEGI) | 🟡 | Campo centroide con valor aproximado |
| ✅ M3.V | Verificar: /buscar?q=roma → colonias de CDMX y otras ciudades; /buscar?q=06600 → colonias de Juárez CDMX | ✅ | |

---

### M4 — Importación INEGI (polígonos geográficos)
*Objetivo: Cada colonia con su polígono exacto en colonia_poligonos.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M4.1 | Descargar Marco Geoestadístico INEGI (manual) — estado por estado en GeoJSON | — | Archivos GeoJSON en `import/data/inegi/` |
| M4.2 | `import/2_inegi_geo.php` — leer cada GeoJSON, normalizar nombre (sin acentos, mayúsculas), buscar colonia en BD por nombre+clave_municipio, insertar en `colonia_poligonos` + actualizar `centroide` real | 🔴 | Script CLI, registra colonias sin match en `import/logs/sin_match.txt` |
| M4.3 | Revisar `sin_match.txt` — las colonias sin match quedan sin polígono; documentar el porcentaje | 🟢 | Porcentaje de cobertura de polígonos conocido |
| M4.4 | Agregar SPATIAL INDEX en `colonia_poligonos.poligono` (ya está en schema, verificar que se creó) | 🟢 | `SHOW INDEX FROM colonia_poligonos` muestra el índice |
| ✅ M4.V | `SELECT COUNT(*) FROM colonia_poligonos` — debería ser > 80% de colonias | ✅ | |

---

### M5 — Endpoint de geolocalización
*Objetivo: /geolocate funciona con polígono exacto y fallback Haversine.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M5.1 | `helpers/geo.php` — función `haversineMetros(lat1,lng1,lat2,lng2)` (tomada del doc de Espejo Electoral) | 🟢 | Función probada con coordenadas conocidas |
| M5.2 | `handlers/geolocate.php` — validar lat/lng (float, rango México: lat 14-33, lng -118/-86), buscar con ST_Within en colonia_poligonos | 🔴 | GET /geolocate?lat=19.43&lng=-99.13 devuelve colonia |
| M5.3 | Implementar fallback Haversine: si ST_Within no encuentra resultado, hacer SELECT de las 20 colonias con centroide más cercano por bounding box, calcular Haversine y devolver la más cercana | 🔴 | Campo `metodo: "centroide"` en respuesta |
| M5.4 | Caché para /geolocate: key = `geo_` + round(lat,3) + round(lng,3) (precisión ~100m) | 🟡 | Coordenadas cercanas usan el mismo cache |
| ✅ M5.V | Probar con 5 puntos conocidos de distintas ciudades; verificar que `metodo` indica la fuente correcta | ✅ | |

---

### M6 — Integración con MeUnoColonia
*Objetivo: El formulario de registro de colonos usa ColoniasAPI.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M6.1 | En MeUnoColonia `portal/registro/`: agregar campo "Colonia" con autocompletar que llama a ColoniasAPI /buscar mientras el usuario escribe | 🟡 | Autocompletar funcional con debounce 300ms |
| M6.2 | Al seleccionar colonia, guardar `colonia_api_id` (el ID de ColoniasAPI) en la tabla `colonos` | 🟢 | Campo `colonia_api_id` en tabla colonos |
| M6.3 | Botón "Detectar mi colonia por GPS" — usa `navigator.geolocation` (código del doc Espejo Electoral) y llama a /geolocate | 🟡 | Botón que llena el campo de colonia automáticamente |
| M6.4 | Manejo de errores: GPS denegado → mensaje claro; sin resultado → pedir texto manual | 🟢 | Mensajes en lenguaje cotidiano |
| ✅ M6.V | Probar registro en celular real; probar con GPS; probar escribiendo texto parcial | ✅ | |

---

### M7 — QA final y documentación
*Objetivo: El API es estable, documentado y listo para nuevos consumidores.*

| # | Tarea | Tamaño | Entregable |
|---|-------|--------|-----------|
| M7.1 | `README.md` del repo colonias-api con: qué es, cómo instalar, cómo usar cada endpoint con ejemplos curl | 🟢 | Documento público |
| M7.2 | Script `import/3_verificar.php` que valida: conteos de tablas, % de polígonos, 10 queries de prueba, tiempo de respuesta | 🟡 | Reporte de salud de la BD |
| M7.3 | Rate limiting básico: máximo 100 requests/minuto por API key, usando tabla `api_rate_log` en MySQL | 🟡 | 429 Too Many Requests si se excede |
| M7.4 | Endpoint `GET /health` público (sin auth) que devuelve: status ok/error, conteo de colonias, latencia de BD | 🟢 | Monitoreo básico |
| ✅ M7.V | Checklist completo de usuario (ver Sección 7) | ✅ | |

---

## 6. Verificación técnica por milestone

### M0 — Verificación de fundación

```bash
# 1. El schema corre sin errores
mysql -u user -p colonias_mx < schema.sql
# Esperado: sin errores, sin warnings

# 2. Los 32 estados están insertados
mysql -u user -p -e "SELECT COUNT(*) FROM colonias_mx.estados;"
# Esperado: 32

# 3. El servidor responde
curl -s https://tu-dominio.com/colonias-api/estados
# Esperado: {"ok":false,"error":"API key requerida","codigo":401}

# 4. Ruta inválida devuelve 404 JSON
curl -s https://tu-dominio.com/colonias-api/inventada
# Esperado: {"ok":false,"error":"Ruta no encontrada","codigo":404}
```

### M1 — Verificación de autenticación

```bash
# Key inválida
curl -H "Authorization: Bearer clave_falsa" https://tu-dominio.com/colonias-api/estados
# Esperado: 401

# Key válida
curl -H "Authorization: Bearer tu_key_real" https://tu-dominio.com/colonias-api/estados
# Esperado: 200 con array de estados

# Verificar que ultimo_uso se actualizó
mysql -u user -p -e "SELECT proyecto, ultimo_uso FROM colonias_mx.api_keys;"
```

### M2 — Verificación de búsqueda

```bash
KEY="tu_api_key"
BASE="https://tu-dominio.com/colonias-api"

# Búsqueda por nombre
curl -s -H "Authorization: Bearer $KEY" "$BASE/buscar?q=polanco"
# Esperado: colonias con "Polanco" en el nombre

# Búsqueda por CP
curl -s -H "Authorization: Bearer $KEY" "$BASE/buscar?q=11550"
# Esperado: colonias de Polanco CDMX con CP 11550

# Query muy corto
curl -s -H "Authorization: Bearer $KEY" "$BASE/buscar?q=a"
# Esperado: {"ok":false,"error":"El texto debe tener al menos 2 caracteres",...}

# Verificar caché
ls -la cache/responses/
# Esperado: archivos .json tras los requests anteriores
```

### M3 — Verificación de importación SEPOMEX

```bash
# Conteos esperados
mysql -u user -p -e "
    SELECT
        (SELECT COUNT(*) FROM colonias_mx.colonias) AS colonias,
        (SELECT COUNT(*) FROM colonias_mx.municipios) AS municipios;
"
# Esperado: colonias > 140000, municipios > 2400

# Verificar que no hay colonias sin municipio
mysql -u user -p -e "
    SELECT COUNT(*) FROM colonias_mx.colonias
    WHERE municipio_id IS NULL;
"
# Esperado: 0
```

### M4 — Verificación de polígonos

```bash
# Cobertura de polígonos
mysql -u user -p -e "
    SELECT
        (SELECT COUNT(*) FROM colonias_mx.colonias) AS total_colonias,
        (SELECT COUNT(*) FROM colonias_mx.colonia_poligonos) AS con_poligono,
        ROUND(
            (SELECT COUNT(*) FROM colonias_mx.colonia_poligonos) * 100.0 /
            (SELECT COUNT(*) FROM colonias_mx.colonias), 1
        ) AS porcentaje;
"
# Esperado: porcentaje > 75%

# Verificar índice espacial
mysql -u user -p -e "SHOW INDEX FROM colonias_mx.colonia_poligonos;"
# Esperado: una fila con Key_name = sp_poligono, Index_type = SPATIAL
```

### M5 — Verificación de geolocalización

```bash
KEY="tu_api_key"
BASE="https://tu-dominio.com/colonias-api"

# CDMX — Polanco
curl -s -H "Authorization: Bearer $KEY" "$BASE/geolocate?lat=19.4326&lng=-99.1940"
# Esperado: Polanco I Sección, Miguel Hidalgo, CDMX

# Monterrey — San Pedro Garza García
curl -s -H "Authorization: Bearer $KEY" "$BASE/geolocate?lat=25.6572&lng=-100.4012"
# Esperado: colonia en San Pedro o Monterrey

# Guadalajara — Zapopan
curl -s -H "Authorization: Bearer $KEY" "$BASE/geolocate?lat=20.7214&lng=-103.3882"
# Esperado: colonia en Zapopan

# Coordenadas fuera de México
curl -s -H "Authorization: Bearer $KEY" "$BASE/geolocate?lat=40.7128&lng=-74.0060"
# Esperado: {"ok":false,"error":"Coordenadas fuera del territorio mexicano",...}

# Verificar campo "metodo" en respuesta
# Si dice "poligono" = exacto con ST_Within
# Si dice "centroide" = aproximado con Haversine
```

---

## 7. Verificación funcional — Checklist para el usuario

Esta sección es para que el usuario (no el desarrollador) pueda confirmar que cada funcionalidad trabaja correctamente. No requiere conocimiento técnico.

### 7.1 Búsqueda por texto — probar en navegador o Postman

Abrir: `https://tu-dominio.com/colonias-api/buscar?api_key=TU_KEY&q=santa+fe`

**¿Qué verificar?**
- [ ] Aparecen resultados con colonias que contienen "Santa Fe"
- [ ] Los resultados incluyen colonia, municipio y estado
- [ ] Incluyen el código postal
- [ ] Si escribo solo "a" aparece un mensaje de error claro (no un error del servidor)
- [ ] Si escribo un CP de 5 dígitos como "06600", aparecen las colonias de ese CP

### 7.2 Búsqueda por estado y municipio

Abrir: `https://tu-dominio.com/colonias-api/estados?api_key=TU_KEY`

- [ ] Aparecen los 32 estados de México con sus nombres correctos
- [ ] Tomar el ID de "Ciudad de México" (debe ser 9) y abrir: `.../municipios?api_key=TU_KEY&estado_id=9`
- [ ] Aparecen las 16 alcaldías de CDMX

### 7.3 Geolocalización — desde el celular

1. Abrir MeUnoColonia en el celular y ir al formulario de registro
2. Tocar el botón "Detectar mi colonia por GPS"
3. Cuando el navegador pregunte permiso de ubicación, dar "Permitir"

**¿Qué verificar?**
- [ ] Aparece un mensaje "Obteniendo ubicación..." mientras procesa
- [ ] En menos de 15 segundos aparece el nombre de la colonia donde estás
- [ ] El municipio y estado son correctos para tu ubicación actual
- [ ] Si rechazas el permiso de GPS, aparece un mensaje claro invitando a escribir tu colonia manualmente

### 7.4 Geolocalización — casos borde

- [ ] Estar en la frontera entre dos colonias: ¿devuelve alguna sin error?
- [ ] Conectado solo a WiFi (sin GPS de satélite): ¿sigue funcionando aunque con menos precisión?
- [ ] En zona rural con colonia poco conocida: ¿devuelve algo o un error claro?

### 7.5 Autocompletar en formulario (MeUnoColonia)

1. Ir al formulario de registro de colono
2. Empezar a escribir en el campo "Colonia"

- [ ] Después de 2 o 3 letras aparecen sugerencias
- [ ] Las sugerencias se actualizan al seguir escribiendo
- [ ] Hacer clic en una sugerencia llena el campo
- [ ] El municipio y estado se llenan automáticamente al seleccionar

### 7.6 Rendimiento

- [ ] La búsqueda responde en menos de 2 segundos
- [ ] La geolocalización responde en menos de 3 segundos
- [ ] Si hago la misma búsqueda dos veces, la segunda es más rápida (caché)

### 7.7 Errores — verificar mensajes amigables

- [ ] Sin internet: aparece mensaje claro (no pantalla en blanco)
- [ ] GPS no disponible en el dispositivo: aparece alternativa para escribir manualmente
- [ ] Colonia no encontrada: mensaje "No encontramos esa colonia, intenta con otro término"

---

## 8. Referencia de tamaños de tarea

Esta tabla es para asignar tareas al agente de IA correcto:

| Tarea | Señales | Modelo |
|-------|---------|--------|
| SELECT simple, INSERT fijo, respuesta JSON de una tabla | Sin joins complejos, sin lógica condicional | 🟢 Haiku |
| Múltiples joins, validaciones, caché de archivos, middleware de auth | Lógica de negocio, varios pasos | 🟡 Sonnet |
| Spatial queries, importación de GeoJSON, Haversine, router con múltiples rutas | Matemáticas, datos geoespaciales, scripts de ETL | 🔴 Opus |
| Checklist de verificación, comparar output esperado vs actual | Lectura y reporte | ✅ Cualquiera |

### Señales de que una tarea es más grande de lo que parece

- Requiere entender datos de INEGI o SEPOMEX (formatos no estándar)
- Involucra coordenadas GPS o geometría
- Necesita manejar errores parciales (algunos registros fallan en importación)
- Modifica tablas con > 10,000 filas existentes
- Requiere entender el CLAUDE.md de este proyecto antes de tocar código
