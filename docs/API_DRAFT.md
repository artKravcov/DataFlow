# Черновик API

Предложение: JSON API под `/api/v1`; browser auth через same-origin session cookie и CSRF-защиту. Конкретную интеграцию auth проверить для выбранной версии Laravel при реализации. Internal worker API отделён от браузерного и защищён сервисной авторизацией.

## Соглашения

Успех: `{ "data": ... }`; списки дополнительно возвращают `meta` с пагинацией. Ошибка: `{ "error": { "code": "GRAPH_INVALID", "message": "...", "fields": {}, "request_id": "..." } }`. 401 — вход нужен; 403 — недостаточная роль; чужой tenant resource — 404; 409 — конфликт revision/активная работа; 422 — валидация; 413 — превышение объёма.

Организация в URL не является разрешением. Каждый вложенный ID проверяется на принадлежность и роль. Время — ISO 8601 UTC. В foundation T02–T03 API возвращает числовые PostgreSQL ID; переход на внешний строковый ID требует отдельного согласованного изменения контракта. Списки ограничены: по умолчанию 25, максимум 100.

## Реализовано в foundation T02–T03

Browser-клиент работает через same-origin session Sanctum. Перед изменяющим запросом он получает `GET /sanctum/csrf-cookie` вне префикса `/api/v1`, после чего отправляет session cookie и URL-decoded значение cookie `XSRF-TOKEN` в заголовке `X-XSRF-TOKEN`. Logout инвалидирует session и обновляет CSRF token.

Уже доступны следующие маршруты:

| Метод и путь | Назначение |
|---|---|
| POST /auth/register | Создаёт User, personal Organization и owner Membership в одной транзакции; открывает session |
| POST /auth/login | Вход через same-origin session |
| POST /auth/logout | Выход из session |
| GET /me | User и доступные организации с ролью membership |
| GET/POST /organizations/{org}/projects | Список неархивных проектов / создание |
| GET/PATCH /organizations/{org}/projects/{project} | Чтение / изменение проекта |
| POST /organizations/{org}/projects/{project}/archive | Устанавливает `archived_at`; проект исчезает из обычного списка |

Для проектов `owner` и `editor` могут создавать, изменять и архивировать; `viewer` только читает. Чужая organization или вложенный resource возвращают `404 RESOURCE_NOT_FOUND`, недостаточная роль в доступной organization — `403 FORBIDDEN`. Реализованные ошибки также включают `401 UNAUTHENTICATED`, `419 CSRF_TOKEN_MISMATCH` и `422 VALIDATION_FAILED`; каждая содержит `request_id` и соответствует общему error envelope. Проверка активных executions при архивировании и ответ `409` появятся вместе с execution domain, а не в foundation.

## Пользовательские маршруты

Все пути ниже относительны `/api/v1`.

| Метод и путь | Назначение |
|---|---|
| POST /auth/register, /auth/login, /auth/logout | Регистрация, сессия, выход |
| GET /me | Пользователь и доступные организации |
| GET/POST /organizations | Список / создание |
| GET/PATCH /organizations/{org} | Чтение / имя организации (owner) |
| GET /organizations/{org}/members | Участники |
| PATCH/DELETE /organizations/{org}/members/{member} | Роль / удаление, owner |
| POST /organizations/{org}/invitations | Создать одноразовую ссылку, owner |
| POST /invitations/accept | Принять token после входа и проверки email |
| GET/POST /organizations/{org}/projects | Список / создание |
| GET/PATCH /organizations/{org}/projects/{project} | Детали / изменение |
| POST /organizations/{org}/projects/{project}/archive | Архивирование, 409 при активных работах |
| GET/POST /organizations/{org}/connections | Список / создание |
| GET/PATCH/DELETE /organizations/{org}/connections/{connection} | Metadata / настройка / удалить неиспользуемое |
| GET/POST /organizations/{org}/assets | Список / multipart upload входного файла |
| GET /organizations/{org}/assets/{asset}/download | Авторизованное скачивание ready asset |
| GET/POST /organizations/{org}/projects/{project}/pipelines | Список / создание |
| GET/PATCH /organizations/{org}/pipelines/{pipeline} | Pipeline с графом / имя |
| PUT /organizations/{org}/pipelines/{pipeline}/graph | Атомарная запись графа с revision |
| POST /organizations/{org}/pipelines/{pipeline}/validate | Проверка сохранённой revision |
| POST /organizations/{org}/pipelines/{pipeline}/archive | Архивировать |
| POST /organizations/{org}/pipelines/{pipeline}/executions | Запустить, 202 |
| GET /organizations/{org}/executions | История; фильтры pipeline_id/status/project_id |
| GET /organizations/{org}/executions/{execution} | Snapshot, статусы узлов, output assets, data metrics |
| GET /organizations/{org}/executions/{execution}/logs | Журнал с cursor |
| GET /organizations/{org}/overview | Активные и недавние запуски |
| GET /node-types | Доступные типы, категории, порты, версии, config schema, input/output contracts и schema propagation hints |

В MVP проверка подключения выполняется при запуске с понятной ошибкой узла. Отдельный асинхронный «Test connection» можно добавить в v1; не выполнять сетевые проверки без лимитов в CRUD-запросах.

## Создание запуска

Заголовок `Idempotency-Key` обязателен, клиент генерирует UUID. Body: `{ "revision": 3 }`. Успех 202: `{ "data": { "id": 123, "status": "queued" } }` (числовой ID, как у остальных реализованных сущностей).

Одинаковый ключ + одинаковые pipeline/revision в организации возвращает тот же Execution, включая завершённый. Тот же ключ с другим запросом — 409. Новая осознанная попытка пользователя получает новый ключ. Pipeline с активным запуском при новом ключе — 409. Проверка уже существующего idempotency key идёт до проверки активности.

## Сохранение графа

Body: `{ "revision": 3, "graph": { "schema_version": 1, "nodes": [], "edges": [] } }`. Успех возвращает revision 4. Пустой draft разрешён к сохранению, но не к запуску. На save проверяются форма, типы, порты, отсутствие циклов, принадлежность ссылок и известная часть schema contracts; validate/run дополнительно требуют полного связного графа и обязательной конфигурации. Если schema неизвестна до исполнения, API возвращает предупреждение/diagnostic вместо ложной гарантии.

PATCH connection не возвращает credentials: ответ содержит `has_credentials` и безопасную metadata. Отсутствие поля credentials означает сохранить старые; очистка — отдельное явное действие с валидацией. Любое изменение config/credentials увеличивает revision.

## Реализованный graph API (T05)

После входа `GET /node-types` возвращает четыре встроенных типа, описание конфигурации, порты и правила schema; `runtime_available: false` означает, что обработчики исполнения ещё не созданы. В организации owner/editor создают pipeline через `POST /organizations/{org}/projects/{project}/pipelines` с `{ "name": "..." }`, читают его через `GET /organizations/{org}/pipelines/{pipeline}` и меняют имя через PATCH. Viewer имеет только чтение. Список неархивных pipeline доступен через GET на пути проекта; архивирование — POST на `.../archive`.

`PUT .../graph` принимает `revision` и граф v1, заменяет узлы и связи целиком в одной транзакции и увеличивает revision. Например, два редактора открыли revision 2: первое сохранение создаст revision 3, второе получит `409 REVISION_CONFLICT` и должно загрузить новую версию. Ошибка валидации или сбой записи не меняет старый граф. Пустой или частично настроенный draft сохраняется; неизвестные поля конфигурации, типы, неверные порты, повторные связи, циклы и ошибки известной schema отвергаются.

`POST .../validate` принимает `{ "revision": 3 }` и дополнительно требует полный связный граф и обязательные настройки. При неизвестной schema возвращаются предупреждения. Для `csv.source` сервер проверяет, что `asset_id` указывает на готовый файл той же организации. Идентификатор файла в конфигурации графа передаётся строкой. Конфигурации и schema hints используют allowlist; metadata поля schema поддерживает только `label`. Сам Graph API не создаёт запуск; создание и чтение Execution описаны ниже. Контракты и примеры находятся в `contracts/`.
## Зафиксированное намерение запуска (этап A)

`POST /organizations/{org}/pipelines/{pipeline}/executions` доступен owner/editor. Он требует UUID в `Idempotency-Key` и `{ "revision": 3 }`, повторно проверяет полный граф и готовность входного файла, затем атомарно создаёт queued Execution со снимком `{revision, graph}`, pending статусы узлов и запись outbox. Снимок не меняется при последующем редактировании графа. Один и тот же ключ в организации с теми же pipeline и revision возвращает тот же ID и текущий статус даже после завершения; иной запрос с тем же ключом даёт `409 IDEMPOTENCY_CONFLICT`. Для нового ключа устаревшая revision даёт `409 REVISION_CONFLICT`, а уже активный запуск — `409 EXECUTION_ACTIVE`. Проект или pipeline с активным запуском архивировать нельзя.

`GET /organizations/{org}/executions/{id}` доступен участникам организации и возвращает снимок, revision и статусы узлов; чужой ID даёт 404. На текущем этапе outbox ещё не публикуется, worker не исполняет задания, а кнопка запуска отключена: прямой запрос останется в состоянии queued до реализации доставки. Автоматическая доставка, обработка тайм-аутов и завершение запуска пока не подключены.

## Входные CSV-файлы (T07)

`POST /organizations/{org}/assets` принимает multipart поле `file`; owner и editor могут загружать непустые файлы с расширением `.csv`, viewer имеет доступ только к чтению. Базовый лимит — 10 МиБ, контролируется при чтении содержимого, большие файлы получают 413. Нулевые байты и некорректные имена отклоняются; полная проверка структуры CSV относится к исполнению. `GET` возвращает готовые файлы по 25 на страницу: числовой `id`, имя, размер и время создания. `GET .../{asset}/download` доступен участникам той же организации, возвращает файл как attachment с `no-store`. Чужой файл возвращает 404. Внутренний путь и контрольная сумма не возвращаются. Файл записывается в приватное локальное хранилище; при неудачной загрузке или записи метаданных частичный файл удаляется. При аварийном завершении процесса возможен оставшийся неучтённый файл; автоматической очистки пока нет.

## Internal API

Префикс `/internal/v1` обслуживается напрямую backend внутри сети Docker; gateway его не проксирует. Реализованы claim, heartbeat, события и выдача входного файла; загрузка результата реализована в ограниченном виде. Тела JSON ограничены 2048 байтами. Заголовок `Authorization: Bearer <INTERNAL_SERVICE_TOKEN>` обязателен; пустой, короткий (менее 32 байтов) и примерный токен не принимается. Lease token ограничивает конкретный запуск. Токены не выдаются браузеру; ответы имеют `Cache-Control: no-store`.

| Метод и путь | Контракт |
|---|---|
| POST /executions/{id}/claim | body worker_id; атомарно queued → running; snapshot + lease token (connections в этом срезе отсутствуют) |
| POST /executions/{id}/heartbeat | worker_id + lease token; продлить только действующий lease |
| POST /executions/{id}/events | Реализованы node.started, node.succeeded, node.failed и execution.failed; завершение успехом ожидает сохранения output asset |
| GET /executions/{id}/assets/{asset}/content | Реализовано: только ready CSV из snapshot и той же организации при действующем lease |
| POST /executions/{id}/artifacts | Реализована потоковая загрузка CSV с checksum и привязкой к destination snapshot |

Claim проверяет connection revision и ready inputs до старта. Конфликт terminal/уже занятого запуска не выдаёт secrets. Повтор claim после потерянного ответа не создаёт новый lease: worker без подтверждённого токена не исполняет работу, watchdog завершит её как failed.

Повтор events с тем же event_id и тем же payload подтверждается без повторной записи; другой payload под тем же ID — 409. Sequence монотонна, события старого lease отвергаются. После terminal состояния разрешено только подтверждение уже принятого идентичного события.

Подробные правила восстановления: [WORKER_PLAN](WORKER_PLAN.md). Формат реализованных claim/heartbeat описан в [JSON Schema](../contracts/internal-execution-v1.schema.json) и [примерах](../contracts/fixtures/internal-execution-v1.json); остальные методы остаются черновиком.

Реализованные методы отвечают 401 без сервисного токена, 422 при некорректном JSON, 413 при теле более 2048 байтов и 409 при недоступном запуске или lease. При ошибке повторной проверки snapshot возвращается `SNAPSHOT_UNAVAILABLE` без раскрытия графа. Повтор claim после потерянного ответа не выдаёт токен; пока watchdog не подключён к расписанию, такой запуск останется running.

События передаются по одному, с `worker_id`, `lease_token`, UUID `event_id`, целочисленным `sequence` от 1 до 101 и типом. Для node-события обязателен `node_id`, для failed — ограниченный набор `error_code`. Событие применяется вместе с сохранением идентификатора в одной транзакции. Новое событие требует активный lease и следующий sequence; идентичный повтор возвращает прежний sequence. После `execution.failed` допускается только подтверждение его идентичного повтора с тем же worker и токеном, даже если время lease истекло; новые события отклоняются. После watchdog подтверждение недоступно. `node.started` требует завершения всех предшественников, одновременные узлы не допускаются. До реализации загрузки результата `csv.destination` нельзя отметить успешным, а `execution.succeeded` не принимается. [Схема и пример](../contracts/internal-event-v1.schema.json).

Для чтения входного файла worker отправляет `GET /internal/v1/executions/{id}/assets/{asset}/content` с сервисным Bearer-токеном и заголовками `X-Worker-Id` и `X-Lease-Token`. Сервер сверяет действующий lease, ID файла в CSV source snapshot, организацию, готовность и ограничение размера; файл отдаётся потоком с `no-store`. При недоступном файле возвращается 404, при недействительном lease — 409. Проверка размера не является проверкой содержимого файла.

Загрузка результата: `POST /internal/v1/executions/{id}/artifacts`, `Content-Type: text/csv`, заголовки `X-Worker-Id`, `X-Lease-Token`, `X-Node-Id`, `X-Content-Sha256` (64 строчных hex). Тело — непустой CSV без NUL до `MAX_INPUT_BYTES` (по умолчанию 10 МиБ); checksum вычисляется сервером и сверяется с заявленной. Сервер повторно проверяет lease после приёма, назначает организацию из запуска и имя из snapshot. Доступен только работающий CSV destination этого запуска. Полный файл и metadata становятся ready вместе; повтор той же передачи для пары execution/node возвращает прежний ID, другой контент даёт 409. При аварии процесса может остаться файл без записи в БД; автоматическая очистка пока не реализована. Отметка результата узлом и успешное завершение запуска пока не подключены.

Форматы заголовков и ответа описаны в [схеме](../contracts/internal-artifact-v1.schema.json) и [примере](../contracts/fixtures/internal-artifact-v1.json); тело содержит непосредственно CSV.
