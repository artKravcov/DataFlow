# DataFlow — контекст проекта

## Что строим

Schema-aware visual data workflow platform: источники → понимание структуры данных → преобразования → проверка → получатели → наблюдение. Пользователь выбирает блоки и настройки без написания кода. Платформа хранит конфигурацию, исполняет её асинхронно и объясняет состояние каждого запуска.

Ключевое отличие от generic workflow automation: между узлами передаётся не произвольный JSON, а логический Dataset со schema, records и execution metadata. Аудитория: аналитик собирает данные; разработчик автоматизирует обмен; маркетолог объединяет информацию для анализа. Произвольные production deployment workflows — долгосрочное направление, не MVP.

## Направления из обсуждения

- Laravel — основной API и бизнес-правила; PostgreSQL — данные приложения.
- Vue 3 + TypeScript, Vite, Pinia, Vue Router — интерфейс.
- Node.js + TypeScript — исполнение; Redis — транспорт задач; Docker Compose — локальная среда.
- Модульный монолит с отдельным worker; выделение сервисов только по реальной необходимости.
- Проекты, подключения, графы, запуски и обзор текущих работ.
- S3 исключён из начального списка. AI, MCP, marketplace и SDK отложены.

## Рабочие предложения v0.1

Первый срез: CSV → Filter → Map → CSV, ручной запуск через настоящий Node worker, статус и скачиваемый результат. Данные в runtime описываются как Dataset v1: schema + batch records. В первом срезе schema может быть задана вручную или получена простым inference из заголовков/sample; сложная schema evolution, lineage, streaming и distributed execution отложены.

Полный MVP добавляет PostgreSQL/MySQL/REST, организации с приглашениями и простыми ролями, историю и разветвление данных.

Граф MVP — DAG с одним источником; у остальных узлов ровно один вход. Возможны несколько выходящих связей, но без Join, циклов и параллельного исполнения. Запуск хранит неизменяемый снимок графа. Новое сохранение не меняет уже запущенную работу.

Laravel — единственный писатель в БД приложения. Предлагаемый транспорт: Redis Streams с JSON-сообщением, transactional outbox в PostgreSQL, internal API для claim/heartbeat/events. Подробности и гарантии находятся в [WORKER_PLAN](docs/WORKER_PLAN.md). Нельзя незаметно заменить этот протокол Laravel Queue/BullMQ.

## Текущее состояние

Завершены M0/T01 и foundation T02–T03. Помимо исходного каркаса с Laravel, Vue, worker, Docker Compose, PostgreSQL, Redis и gateway, backend теперь содержит session/CSRF authentication через Sanctum, users, organizations, memberships и projects. Регистрация атомарно создаёт пользователя, personal organization и owner membership. Роли `owner`, `editor`, `viewer` существуют в membership; управление участниками и invitations намеренно отложены.

Реализованы `register`, `login`, `logout`, `/me`, tenant-scoped CRUD проектов и архивирование. Laravel проверяет membership для organization и повторно ограничивает каждый nested project выбранной organization; чужие ресурсы скрываются ответом 404, viewer не может изменять или архивировать. API использует success/error envelopes и server-generated `request_id`. Factories и безопасный seed создают две demo-организации; реальные demo-пользователи создаются только явным локальным seed без попадания паролей или других секретов в репозиторий.

Фактически проверены: прежние проверки T01, а также `docker compose exec backend php artisan test` — 13 passed, 48 assertions на отдельной PostgreSQL базе `dataflow_test`. Набор покрывает регистрацию с personal organization/owner membership, login, `/me`, logout, создание и валидацию проектов, tenant isolation через чужой nested ID, недостаточную роль viewer и архивирование. Команды запуска, остановки и тестов находятся в [README](README.md).

Завершён T04 frontend foundation: Vue Router и Pinia обеспечивают session/CSRF browser auth, получение `/me`, выбор доступной организации, защищённые маршруты и очистку project state при смене organization. Реализованы login/register, список, создание, загрузка и редактирование проектов через существующий API; viewer получает понятное состояние «только просмотр». Dashboard честно сообщает об отсутствии Execution API. В браузер не попадают secrets; API-клиент перед mutating requests получает Sanctum CSRF cookie и передаёт только decoded XSRF token. Frontend использует закреплённый Prettier с tab indentation; доступны `npm run format` и `npm run format:check`. Локальный idempotent demo seed создаёт указанного в `.env` пользователя как owner всех существующих организаций и owner/editor/viewer в учебной организации; пароль нужен только в локальном `.env`.

Фактически проверены: `npm run format:check`, `npm run type-check`, `npm run build`, `docker compose up --build -d backend`, проверка CSRF-cookie (`204`), guest `/me` (`401`), `docker compose exec gateway nginx -t` и `docker compose exec backend php artisan test` — 13 passed, 48 assertions. Интерактивная browser-проверка недоступна из-за сбоя sandbox browser helper; её нужно выполнить локально. Ограничения этапа: нет invitations/member management, connections, assets, Dataset/DataSchema, pipelines, graph validation, executions, Redis Streams и runtime worker. Следующий этап: UI builder T06, затем assets и runtime.

## Что читать для задачи

- Объём: [MVP_SCOPE](docs/MVP_SCOPE.md).
- Сущности: [DOMAIN_MODEL](docs/DOMAIN_MODEL.md), [DATABASE_DRAFT](docs/DATABASE_DRAFT.md).
- Контракты: [API_DRAFT](docs/API_DRAFT.md), [NODE_CONTRACT](docs/NODE_CONTRACT.md).
- Исполнение: [EXECUTION_FLOW](docs/EXECUTION_FLOW.md), [WORKER_PLAN](docs/WORKER_PLAN.md).
- Очередность: [ROADMAP](docs/ROADMAP.md), [FIRST_DEVELOPMENT_TASKS](docs/FIRST_DEVELOPMENT_TASKS.md).
- Граница сдаваемого курсового прототипа: [PROTOTYPE_PLAN](docs/PROTOTYPE_PLAN.md).
- Допущения: [DECISIONS_AND_QUESTIONS](docs/DECISIONS_AND_QUESTIONS.md).

После реализации этапа обновить этот раздел: что работает, какие проверки пройдены, что осталось и какая задача следующая. Не переписывать продуктовую цель под случайно выбранную реализацию.

## T05 — pipeline и graph API

Реализованы модели Pipeline/Node/Edge с PostgreSQL ограничениями принадлежности проекту и организации, каталог четырёх типов и API создания, чтения, переименования, архивирования, сохранения и проверки графа. Запись заменяет граф атомарно и проверяет revision. Сервер проверяет форму, allowlist конфигурации, типы и версии узлов, порты, циклы, дубликаты и известные поля schema. Неполный draft разрешён; validate проверяет полноту и сообщает об неизвестной schema. Договоры Dataset/DataSchema/graph и примеры лежат в `contracts/`. См. [API_DRAFT](docs/API_DRAFT.md).

Хранилища файлов и runtime ещё нет: resolver по умолчанию отвергает любой asset ID, чтобы не принимать чужой или несуществующий файл. Тесты используют явную подмену resolver. Каталог не обещает работающие обработчики; executions API отсутствует. После подключения assets понадобится повторная проверка их принадлежности и готовности при запуске.

Фактически проверены форматирование изменённых PHP-файлов и полный набор backend-тестов на отдельной PostgreSQL базе: 23 теста, 104 assertions. Среди них tenant isolation, роль viewer, revision conflict, rollback при ошибке вставки, некорректные графы и schema propagation. Следующий этап — T06 UI builder, затем assets/runtime; интерфейс запуска пока недоступен.
