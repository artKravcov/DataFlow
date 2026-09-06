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

Завершён M0/T01: Git-репозиторий, Laravel 13.17 backend, Vue 3 + TypeScript + Vite frontend и минимальный Node.js + TypeScript worker созданы в соответствующих каталогах. Добавлены Dockerfiles, Nginx gateway и `compose.yaml` с PostgreSQL, Redis и именованными volumes. Backend публикует liveness `/api/health` и readiness `/api/ready`; readiness проверяет подключение к PostgreSQL и Redis, а также отсутствие неприменённых миграций.

Фактически проверены: `frontend` type-check и production build, `worker` type-check, build и запуск с exit code 0, сборка Compose-образов, healthchecks PostgreSQL/Redis/backend/frontend, доступность gateway и успешный ответ `/api/ready` после `php artisan migrate --force` внутри backend-контейнера. Команды запуска и остановки находятся в [README](README.md).

Ограничения этапа: нет auth, организаций, доменных CRUD, pipeline engine, очереди Redis Streams и доменных миграций. Worker намеренно не остаётся работающим и не выполняет задания. Следующая задача: [002 — auth, организации и проекты](codex-prompts/002-backend-foundation.md).

## Что читать для задачи

- Объём: [MVP_SCOPE](docs/MVP_SCOPE.md).
- Сущности: [DOMAIN_MODEL](docs/DOMAIN_MODEL.md), [DATABASE_DRAFT](docs/DATABASE_DRAFT.md).
- Контракты: [API_DRAFT](docs/API_DRAFT.md), [NODE_CONTRACT](docs/NODE_CONTRACT.md).
- Исполнение: [EXECUTION_FLOW](docs/EXECUTION_FLOW.md), [WORKER_PLAN](docs/WORKER_PLAN.md).
- Очередность: [ROADMAP](docs/ROADMAP.md), [FIRST_DEVELOPMENT_TASKS](docs/FIRST_DEVELOPMENT_TASKS.md).
- Допущения: [DECISIONS_AND_QUESTIONS](docs/DECISIONS_AND_QUESTIONS.md).

После реализации этапа обновить этот раздел: что работает, какие проверки пройдены, что осталось и какая задача следующая. Не переписывать продуктовую цель под случайно выбранную реализацию.
