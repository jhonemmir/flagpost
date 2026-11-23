# Plan del proyecto: flagpost (feature flags con API en PHP y SDK en C#)

Segundo proyecto de portafolio, pensado para cubrir lo que envparity no muestra: **una API desplegada con demo en vivo**, **PHP moderno** y **dos lenguajes trabajando juntos con un contrato probado**.

**Nombre:** `flagpost` es provisional. Antes de crear el repositorio, verifica que esté libre en GitHub, NuGet y Packagist.
**Tamaño:** más grande que envparity (son dos componentes). Calcula **2,5 a 3 semanas**, con las opciones de recorte de la sección 9 si necesitas acortarlo.

Documentos que ya están escritos y son vinculantes:

| Documento | Para qué sirve |
|---|---|
| [docs/adr/0001-design-decisions.md](adr/0001-design-decisions.md) | Las 20 decisiones de diseño (estado: *Proposed*; pásalo a *Accepted* cuando lo revises) |
| [docs/evaluation-spec.md](evaluation-spec.md) | Algoritmo de evaluación, invariantes y 30 casos |
| [docs/api-contract.md](api-contract.md) | Contrato HTTP v1 |
| [spec/vectors/evaluation.json](../spec/vectors/evaluation.json) | 20 vectores del hash y 30 casos que PHP y C# deben pasar |

---

## 1. Qué hace (versión 1.0)

Un servicio donde defines "feature flags" por entorno y una librería que las consulta desde una aplicación.

**API en PHP**
- CRUD de flags, protegido con una llave de administración.
- Configuración por entorno (`dev`, `staging`, `prod`): activado, porcentaje (0 a 100) y lista de usuarios permitidos.
- Endpoint para SDKs: `GET /v1/sdk/flags`, con `ETag`, respuesta `304` y cabeceras privadas por entorno (`Vary: X-Api-Key`).
- Endpoint `explain`: dado un usuario, dice qué resultado obtiene y por qué (razón y bucket).
- Llaves por entorno, guardadas solo como hash, con varias activas para poder rotarlas.

**SDK en C# (`Flagpost.Client`)**
- `client.IsEnabled("new-checkout", userId, defaultValue: false)`.
- Descarga las reglas, las guarda en memoria y las actualiza cada 30 s con jitter (con `ETag`).
- Evalúa localmente con el mismo algoritmo que la API.
- Si la API falla, usa el último estado bueno; antes de la primera descarga devuelve el valor por defecto. Nunca lanza excepciones al evaluar.
- Extensión para inyección de dependencias en ASP.NET Core.

### Fuera de alcance (a propósito)
Panel web, auditoría de cambios, multi-tenant, atributos de usuario (país, plan), flags no booleanos, experimentos A/B, streaming en tiempo real, SDK para navegador. Todo eso va al roadmap del README.

---

## 2. Estructura del repositorio (monorepo)

```
flagpost/
├── api/                          # Laravel (PHP 8.4)
│   ├── app/
│   │   ├── Evaluation/           # Bucketing, Evaluator, FlagConfig (PHP puro, sin framework)
│   │   ├── Http/Controllers/
│   │   ├── Http/Middleware/      # Autenticación por llave
│   │   ├── Models/
│   │   └── Console/Commands/     # flagpost:key:create, flagpost:key:list, flagpost:key:revoke
│   ├── database/migrations/
│   ├── tests/
│   │   ├── Unit/                 # Contrato (vectores) y pruebas de propiedad
│   │   └── Feature/              # Endpoints
│   ├── Dockerfile
│   └── composer.json
├── sdk-dotnet/
│   ├── src/Flagpost.Client/
│   │   ├── Evaluation/           # Bucketing, Evaluator, FlagConfig
│   │   ├── Sync/                 # Snapshot, poller, HTTP
│   │   ├── FlagClient.cs
│   │   └── ServiceCollectionExtensions.cs
│   └── tests/Flagpost.Client.Tests/
├── spec/vectors/evaluation.json  # Única fuente de verdad del contrato
├── samples/dotnet-webapp/        # App de ejemplo para la demo
├── docs/                         # ADR, spec, contrato y este plan
├── .github/workflows/
├── docker-compose.yml
├── README.md · LICENSE · CHANGELOG.md · SECURITY.md
```

**Principio de diseño:** el evaluador en PHP y en C# es código puro, sin framework, para probarlo solo con los vectores.

---

## 3. Etapas

### Etapa 0: Revisión de la especificación (medio día)
- [x] Leer el ADR, la spec y el contrato completos y ajustar lo que no te convenza.
- [ ] Verificar la disponibilidad del nombre (GitHub, NuGet `Flagpost.Client`, Packagist si lo reservas).
- [ ] Cambiar el estado del ADR a *Accepted*.
- [x] Verificar versiones: PHP 8.4 o superior, la versión estable de Laravel que lo soporte y los paquetes de Microsoft.Extensions para `net8.0` y `net10.0`. Anota las versiones y la fecha en el ADR.

**Versiones verificadas (2026-11-23):** PHP 8.4.28, Laravel 13.43.0, Pest 4.7.8, Larastan 3.15.0, Pint 1.32.3, .NET SDK 10.0.404 (biblioteca multi-target `net8.0`/`net10.0`). El SDK 10 descarga los paquetes de referencia de .NET 8 desde NuGet; no hace falta instalar otro SDK.

### Etapa 1: Repositorio (medio día)
- [x] Crear el repo con licencia MIT, `.gitignore` de PHP/.NET y los documentos en `docs/` y `spec/`.
- [x] `global.json` y `Directory.Build.props` para .NET (nullable activado, warnings como errores).
- [x] Proyecto Laravel en `api/` con Pest, Larastan + PHPStan (nivel máximo) y Pint.
- [x] `docker-compose.yml` con la API y Postgres para trabajo local (`scripts/dev-env.sh` genera `.env`).
- [x] Primer commit con README mínimo.

### Etapa 2: Núcleo de evaluación en PHP (2 días)
Primero el algoritmo, antes que cualquier endpoint.
- [x] `Bucketing::bucketDetailed()` con SHA-256, `unpack('N', ...)` y módulo 100; devuelve el hex de los primeros 4 bytes, el entero sin signo y el bucket.
- [x] `Evaluator::evaluate()` con los siete pasos en orden.
- [x] Un lector del archivo `evaluation.json` y tests que lo recorran (20 vectores + 30 casos); los vectores afirman el hex, el entero y el bucket.
- [x] Prueba de propiedad contra un oráculo independiente, no solo la monotonía: precedencia, determinismo, monotonía y totalidad, con 1.000 usuarios generados.
- [x] Pasar Larastan/PHPStan en nivel máximo.

**Resultado:** 56 tests de Pest, 248.080 aserciones. Los tests unitarios no arrancan Laravel: el evaluador es PHP puro.

**Trampa conocida:** si algo falla en los vectores del hash, revisa primero el orden de bytes y que el entero sea sin signo.

### Etapa 3: API (3,5 días)
- [ ] Migraciones: `flags`, `flag_environment_configs`, `api_keys`.
- [ ] Creación de flags: nacen desactivadas al 100 % en los tres entornos.
- [ ] Validación según la sección 5 de la spec (clave, porcentaje entero sin `50.0` ni `1e2`, lista de permitidos en bytes UTF-8, presupuesto de 1 MiB por entorno).
- [ ] Autenticación: llave de administración (comparación en tiempo constante) y llaves por entorno (solo hash en base de datos, prefijo `fp_`, id y prefijo visibles, varias activas por entorno).
- [ ] Comandos `flagpost:key:create`, `flagpost:key:list` y `flagpost:key:revoke`.
- [ ] `GET /v1/sdk/flags` con orden determinista, `ETag`, `304`, `Cache-Control: private, no-cache` y `Vary: X-Api-Key`; el `304` repite las cabeceras.
- [ ] Formas de respuesta completas: lista, objeto individual, `PATCH` de descripción y respuesta del `PUT`.
- [ ] `explain` usando el evaluador de la etapa 2.
- [ ] Errores en formato problem details, sin trazas ni credenciales.
- [ ] Límites de tasa y `GET /up`.
- [ ] Documentación OpenAPI.
- [ ] Tests de feature para cada endpoint y para los códigos 400, 401, 403, 404, 405, 409, 413, 415, 422 y 429.

### Etapa 4: SDK en C# (3 días)
- [x] `Bucketing` con `SHA256.HashData` y `BinaryPrimitives.ReadUInt32BigEndian`; `Evaluator` con los mismos siete pasos.
- [x] Tests de contrato sobre **el mismo** `spec/vectors/evaluation.json` y la misma prueba de propiedad.
- [ ] `FlagClient` con snapshot inmutable que se reemplaza de forma atómica y `HashSet` ordinal por flag para las listas.
- [ ] Poller con `ETag`, intervalo configurable (mínimo 5 s), jitter de ±20 %, timeout, `Retry-After` y retroceso exponencial en `401`/`403`/`429`.
- [ ] Razón `NOT_READY` antes de la primera descarga.
- [ ] Extensión `AddFlagpost(options => ...)` con servicio en segundo plano.
- [ ] Tests con un `HttpMessageHandler` falso: primera descarga, `304`, fallo tras éxito, JSON inválido, campo con tipo incorrecto, `schemaVersion` desconocido, timeout y `401` con retroceso.
- [x] Multi-target `net8.0` y `net10.0` en la biblioteca (los tests corren en `net10.0`).
- [ ] Verificar que la llave nunca aparece en los logs.

### Etapa 5: CI (1 día)
- [x] `ci.yml` con job de PHP: Pint, Larastan/PHPStan y Pest contra SQLite **y** PostgreSQL.
- [x] `ci.yml` con job de .NET: build multi-target y tests en Linux y Windows.
- [x] **Job de contrato:** `scripts/check_vectors.py` valida `evaluation.json` (ids únicos, `first4BytesHex` coherente con el bucket, `vectorsVersion`) con una tercera implementación independiente. Las suites de PHP y C# corren en sus propios jobs de cada push.
- [x] Badge de estado en el README.
- [ ] Cobertura de código y ejecución de la suite de tests también en `net8.0`.

### Etapa 6: Despliegue y publicación (1,5 días)
- [ ] `Dockerfile` de la API y despliegue en un plan gratuito (Render, Fly.io u Oracle Cloud Always Free) con Postgres gratuito (Neon o Supabase). Revisa las condiciones vigentes; los límites cambian.
- [ ] Migraciones como *release command* (no al arrancar el contenedor) y `GET /up` como health check. Configura `APP_KEY` y `FLAGPOST_ADMIN_KEY` como secretos.
- [ ] Release del SDK a NuGet con publicación confiable (OIDC) desde GitHub Actions, igual que en envparity.
- [ ] Publicar una `0.1.0-alpha.1` cuanto antes para reservar el nombre.

### Etapa 7: App de ejemplo, documentación y release (1,5 días)
- [ ] `samples/dotnet-webapp`: una página que muestra algo distinto según un flag.
- [ ] GIF para el README: cambias el flag por la API y la app cambia sin reiniciarse.
- [ ] README completo (sección 6), `SECURITY.md`, `CHANGELOG.md`.
- [ ] Release `1.0.0` en GitHub, con el paquete publicado.
- [ ] Fijar el repo en tu perfil y añadirlo a tu portafolio web y LinkedIn.

### Colchón (2 días)
Para imprevistos de CI, despliegue y publicación.

| Etapa | Días |
|---|---|
| 0 y 1 | 1 |
| 2 | 2 |
| 3 | 3,5 |
| 4 | 3 |
| 5 | 1 |
| 6 | 1,5 |
| 7 | 1,5 |
| Colchón | 2 |
| **Total** | **15,5** |

---

## 4. Calidad: lo que debe verse en el repositorio

- **Tests en ambos lenguajes**, con el contrato compartido como pieza central.
- **PHP moderno:** `declare(strict_types=1)`, tipos en todo, Larastan/PHPStan en nivel máximo, Pint.
- **Commits y PR claros**, con ramas y CI visible aunque trabajes solo.
- **Sin secretos en el repositorio:** llaves de ejemplo claramente falsas, `.env` fuera del control de versiones.
- **Errores sin filtraciones:** ni trazas ni credenciales ni contenido de la petición.
- **Versiones fijadas** en `composer.lock` y en los `.csproj`.

---

## 5. Seguridad: lo que conviene tener claro

- Las llaves (administración y entorno) son **secretos de servidor**. El SDK no debe usarse en código de navegador ni móvil.
- La lista de permitidos es visible para quien tenga la llave del entorno: guarda **identificadores opacos**, no correos.
- Las llaves de entorno se guardan solo como hash y se muestran una única vez; puede haber varias activas por entorno para rotarlas sin corte.
- Comparaciones de llaves en tiempo constante; ninguna llave en logs ni respuestas.
- El payload del SDK está acotado por entorno (1 MiB de listas) y la respuesta declara `Vary: X-Api-Key` y `private`, para que un caché no mezcle entornos.
- La demo pública no debe exponer la llave de administración. Muestra los cambios con el GIF y una llave de entorno de solo lectura para la demo.

---

## 6. Estructura sugerida del README

1. Nombre y descripción en una línea
2. Badges: CI de la API, CI del SDK, versión en NuGet, licencia
3. **GIF** del cambio de un flag sin reiniciar la app
4. Cómo funciona, con un diagrama simple (API, SDK, aplicación)
5. Inicio rápido: `docker compose up`, crear una llave, crear un flag
6. Uso del SDK en C# (5 líneas)
7. **Cómo se evalúa** (resumen del algoritmo y por qué un usuario siempre cae en el mismo grupo)
8. El contrato compartido entre PHP y C#
9. Seguridad y límites
10. Aviso de que la demo gratuita puede tardar en responder la primera vez
11. Roadmap, contribuir y licencia

---

## 7. Extras opcionales (elige uno o dos)

- Un cliente Postman o una colección de ejemplos `.http`.
- `docker compose` que levante también la app de ejemplo.
- Benchmarks del evaluador del SDK con BenchmarkDotNet.
- Un comando `flagpost:flags:export` que vuelque el estado a JSON.
- Un test de interoperabilidad que levante la API y use el SDK real contra ella en CI.

---

## 8. Errores comunes a evitar

- Empezar por los endpoints y dejar el algoritmo para después.
- Implementar el hash con entero con signo, o con el orden de bytes equivocado.
- Mantener dos copias del archivo de vectores.
- Dejar que el SDK lance excepciones cuando la API falla.
- Registrar la llave en un log "solo para depurar".
- Crecer el alcance (panel web, atributos, experimentos).
- Publicar el paquete al final y descubrir que el nombre ya existe.
- Guardar correos como identificadores de usuario en listas de permitidos.
- Suponer que los máximos por ítem caben a la vez: 500 flags × 1000 ids × 256 bytes son 128 MB de payload; el presupuesto por entorno existe por eso.
- Servir el mismo endpoint para varios entornos sin `Vary: X-Api-Key` y `private`.

---

## 9. Si necesitas acortarlo

En orden de lo que menos duele recortar:

1. La extensión de inyección de dependencias del SDK.
2. El endpoint `explain` (pero entonces PHP ya no implementa el algoritmo y el contrato pierde la mitad de su sentido; mejor mantenerlo).
3. La app de ejemplo, sustituida por un script que use el SDK.
4. Los comandos de revocación de llaves.

No recortes los vectores compartidos ni el job de contrato: son lo que hace interesante el proyecto.

---

## 10. Definición de "terminado"

- [ ] `dotnet add package Flagpost.Client` funciona desde NuGet.
- [ ] La API está desplegada y responde en `GET /up`.
- [ ] CI en verde en `main` para API, SDK y contrato.
- [ ] Los 30 casos y los 20 vectores pasan en PHP **y** en C#, afirmando también el hex de los vectores.
- [ ] Larastan/PHPStan en nivel máximo sin errores, y Pest en verde contra SQLite y PostgreSQL.
- [ ] README con GIF, inicio rápido, uso del SDK y explicación del algoritmo.
- [ ] Ninguna llave aparece en logs, respuestas ni errores (hay tests).
- [ ] Release `1.0.0` publicado y repo fijado en tu perfil.

---

## 11. Cómo contarlo en una entrevista

- **¿Qué problema resuelve?** Activar o desactivar funciones, o hacer un lanzamiento gradual, sin volver a desplegar.
- **¿Por qué evaluar en el cliente?** Es rápido, funciona aunque la API se caiga y los identificadores de usuario nunca salen de la aplicación.
- **¿Qué fue lo más difícil?** Lograr que dos lenguajes den exactamente el mismo resultado. Lo resolví con una spec escrita y vectores compartidos.
- **¿Cómo asignas a cada usuario a un grupo?** Hash SHA-256 de la clave del flag y el id del usuario, módulo 100. Así el mismo usuario siempre ve lo mismo, y al subir el porcentaje nunca pierdes a nadie que ya lo tenía.
- **¿Qué pasa si la API se cae?** El SDK conserva el último estado bueno; antes de la primera descarga usa el valor por defecto. Nunca lanza excepciones.
- **¿Qué decisiones de seguridad tomaste?** Llaves por entorno con solo el hash guardado, comparaciones en tiempo constante, nada de llaves en logs y SDK pensado solo para servidores.
- **¿Qué harías después?** Atributos de usuario, flags no booleanos y streaming de cambios.
