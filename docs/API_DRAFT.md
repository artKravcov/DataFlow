# Черновик API

Предложение: JSON API под `/api/v1`; browser auth через same-origin session cookie и CSRF-защиту. Конкретную интеграцию auth проверить для выбранной версии Laravel при реализации. Internal worker API отделён от браузерного и защищён сервисной авторизацией.

## Соглашения

Успех: `{ "data": ... }`; списки дополнительно возвращают `meta` с пагинацией. Ошибка: `{ "error": { "code": "GRAPH_INVALID", "message": "...", "fields": {}, "request_id": "..." } }`. 401 — вход нужен; 403 — недостаточная роль; чужой tenant resource — 404; 409 — конфликт revision/активная работа; 422 — валидация; 413 — превышение объёма.

Организация в URL не является разрешением. Каждый вложенный ID проверяется на принадлежность и роль. Время — ISO 8601 UTC, ID — строки. Списки ограничены: по умолчанию 25, максимум 100.

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
| GET /organizations/{org}/executions/{execution} | Snapshot, статусы узлов, output assets |
| GET /organizations/{org}/executions/{execution}/logs | Журнал с cursor |
| GET /organizations/{org}/overview | Активные и недавние запуски |
| GET /node-types | Доступные типы, порты, версии и config schema |

В MVP проверка подключения выполняется при запуске с понятной ошибкой узла. Отдельный асинхронный «Test connection» можно добавить в v1; не выполнять сетевые проверки без лимитов в CRUD-запросах.

## Создание запуска

Заголовок `Idempotency-Key` обязателен, клиент генерирует UUID. Body: `{ "revision": 3 }`. Успех 202: `{ "data": { "id": "<execution-id>", "status": "queued" } }`.

Одинаковый ключ + одинаковые pipeline/revision в организации возвращает тот же Execution, включая завершённый. Тот же ключ с другим запросом — 409. Новая осознанная попытка пользователя получает новый ключ. Pipeline с активным запуском при новом ключе — 409. Проверка уже существующего idempotency key идёт до проверки активности.

## Сохранение графа

Body: `{ "revision": 3, "graph": { "schema_version": 1, "nodes": [], "edges": [] } }`. Успех возвращает revision 4. Пустой draft разрешён к сохранению, но не к запуску. На save проверяются форма, типы, порты, отсутствие циклов и принадлежность ссылок; validate/run дополнительно требуют полного связного графа и обязательной конфигурации.

PATCH connection не возвращает credentials: ответ содержит `has_credentials` и безопасную metadata. Отсутствие поля credentials означает сохранить старые; очистка — отдельное явное действие с валидацией. Любое изменение config/credentials увеличивает revision.

## Internal API

Префикс `/internal/v1`, доступен только worker-сервисам. Все payload ограничены по размеру. Service token идентифицирует доверенный worker; lease token ограничивает конкретный запуск. Ни один из этих токенов не выдаётся браузеру.

| Метод и путь | Контракт |
|---|---|
| POST /executions/{id}/claim | body worker_id; атомарно queued → running; snapshot + lease token + credentials необходимых connections |
| POST /executions/{id}/heartbeat | worker_id + lease token; продлить только действующий lease |
| POST /executions/{id}/events | Упорядоченные event_id/sequence; node transitions и финальный execution event |
| GET /executions/{id}/assets/{asset}/content | Только входные assets snapshot и действующий lease |
| POST /executions/{id}/artifacts | Потоковая загрузка результата, назначение organization на сервере |

Claim проверяет connection revision и ready inputs до старта. Конфликт terminal/уже занятого запуска не выдаёт secrets. Повтор claim после потерянного ответа не создаёт новый lease: worker без подтверждённого токена не исполняет работу, watchdog завершит её как failed.

Повтор events с тем же event_id и тем же payload подтверждается без повторной записи; другой payload под тем же ID — 409. Sequence монотонна, события старого lease отвергаются. После terminal состояния разрешено только подтверждение уже принятого идентичного события.

Подробные правила восстановления: [WORKER_PLAN](WORKER_PLAN.md). Перед реализацией перенести этот draft в OpenAPI/JSON Schema и контрактные fixtures; сейчас это описание, а не готовая спецификация OpenAPI.
