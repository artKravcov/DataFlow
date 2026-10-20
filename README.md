# DataFlow — Blueprint v0.1

Дата: 5 сентября 2026. Язык: русский. Статус: проектная документация до начала реализации.

DataFlow — платформа, в которой пользователь визуально собирает поток получения, преобразования и передачи данных. Долгосрочная цель — расширяемый сервис для аналитиков, разработчиков и маркетологов. Начальная цель — один надёжно работающий поток, который можно собрать без кода.

## Как читать

1. [Продукт](docs/PRODUCT_BLUEPRINT.md) — зачем нужен сервис и как им пользоваться.
2. [Границы MVP](docs/MVP_SCOPE.md) — что действительно нужно реализовать сначала.
3. [Архитектура](docs/TECHNICAL_BLUEPRINT.md) и [домен](docs/DOMAIN_MODEL.md).
4. [План разработки](docs/ROADMAP.md), [первые задачи](docs/FIRST_DEVELOPMENT_TASKS.md) и [план курсового прототипа](docs/PROTOTYPE_PLAN.md).
5. [Контекст агенту](PROJECT_CONTEXT.md), [правила агенту](AGENTS.md) и [готовые задания](codex-prompts/README.md).

## Состав

| Документ | Назначение |
|---|---|
| [DATABASE_DRAFT](docs/DATABASE_DRAFT.md) | Таблицы, связи, ограничения |
| [API_DRAFT](docs/API_DRAFT.md) | Пользовательский и внутренний API |
| [FRONTEND_PLAN](docs/FRONTEND_PLAN.md) | Страницы, редактор, состояния интерфейса |
| [WORKER_PLAN](docs/WORKER_PLAN.md) | Runtime, транспорт, восстановление после сбоев |
| [EXECUTION_FLOW](docs/EXECUTION_FLOW.md) | Жизненный цикл запуска и примеры ошибок |
| [NODE_CONTRACT](docs/NODE_CONTRACT.md) | Формат графа, узлов и данных |
| [INFRASTRUCTURE_PLAN](docs/INFRASTRUCTURE_PLAN.md) | Структура репозитория и локальная среда |
| [QUALITY_AND_SECURITY](docs/QUALITY_AND_SECURITY.md) | Проверки и минимальные защитные меры |
| [DECISIONS_AND_QUESTIONS](docs/DECISIONS_AND_QUESTIONS.md) | Принятые направления и новые предложения |
| [PROTOTYPE_PLAN](docs/PROTOTYPE_PLAN.md) | Граница и пошаговая приёмка сдаваемого курсового прототипа |
| [ADR-шаблон](docs/decisions/000-template.md) | Запись будущих архитектурных решений |

## Как перенести в будущий репозиторий

Распаковать архив. Содержимое `dataflow-blueprint/` положить в корень будущего `dataflow/`: `AGENTS.md` и `PROJECT_CONTEXT.md` должны остаться в корне, документы — в `docs/`, задания — в `codex-prompts/`. Относительные ссылки рассчитаны именно на эту структуру. Если README уже существует, объединить вручную или поручить агенту сохранить обе полезные части.

Начать с задания [001-initialize-repository](codex-prompts/001-initialize-repository.md). Выполнять задания по одному; они не означают разрешение на реализацию всего roadmap за один запуск. В этом пакете нет приложения, установленных зависимостей, миграций или созданного Git-репозитория.

## Статус решений

Подтверждённые направления восстановлены из обсуждения «Идеи большого пет проекта». Часть прежних ответов содержала разные варианты очередности. Здесь они сведены в один план: небольшой вертикальный срез → полный MVP → расширения.

Точные ограничения графа, роли, протокол worker, таблицы и API — **предложения Blueprint v0.1**, а не ранее утверждённые пользователем детали. Их можно использовать как исходный вариант; изменять осознанно с записью причины. Версии библиотек здесь намеренно не зафиксированы: совместимые поддерживаемые версии нужно проверить при инициализации и закрепить lock-файлами.

`AGENTS.md` содержит прямое требование читать `PROJECT_CONTEXT.md`: отдельное имя контекстного файла само по себе не предполагает автоматическую загрузку. Основание: [официальная документация OpenAI об AGENTS.md](https://learn.chatgpt.com/docs/agent-configuration/agents-md), проверена 05.09.2026.

## Локальный запуск (M0/T01)

Требования: Docker Desktop с Linux engine, PHP 8.5.10, Node.js 24.20.0 LTS и Composer 2.10.3. Контейнеры используют закреплённые образы PHP 8.5.10, Node.js 24.20.0, PostgreSQL 18.6, Redis 8.10.1, Nginx 1.31.4 и Composer 2.10.3. Зависимости PHP и Node закреплены в `backend/composer.lock`, `frontend/package-lock.json` и `worker/package-lock.json`.

Создайте локальную конфигурацию и заполните только значения-заглушки, не добавляя файл в Git:

```powershell
Copy-Item .env.example .env
```

`APP_KEY` в `.env` должен быть локально сгенерированным Laravel key, а `POSTGRES_PASSWORD` — непустым локальным паролем. Затем запустите стек:

```powershell
docker compose up --build --wait
docker compose exec backend php artisan migrate --force
```

Откройте frontend по `http://localhost:8080`. Gateway — единственный опубликованный сервис: PostgreSQL, Redis и backend доступны только во внутренней Compose-сети.

Проверки:

```powershell
curl.exe -i http://localhost:8080/api/health
curl.exe -i http://localhost:8080/api/ready
docker compose ps --all
docker compose logs worker
```

`/api/health` — liveness и не зависит от БД или Redis. `/api/ready` возвращает `503`, пока PostgreSQL, Redis или миграции недоступны, и `200 {"status":"ready"}` после их готовности. Worker в T01 выводит диагностическое сообщение и завершает процесс с кодом `0`: выполнение pipeline здесь ещё не реализовано.

Для локальной остановки без удаления данных используйте:

```powershell
docker compose down
```

Именованные volumes сохраняют данные PostgreSQL, Redis и файлы приложения. Не используйте `docker compose down --volumes`, если хотите сохранить локальные данные.

## Локальные demo-пользователи

Для учебной проверки ролей заполните в локальном `.env` (файл не попадает в Git):

```dotenv
DATAFLOW_DEMO_ADMIN_EMAIL=artKravcov@inbox.lv
DATAFLOW_DEMO_PASSWORD=выберите-локальный-пароль-не-короче-12-символов
```

После изменения `.env` пересоздайте backend и выполните seed:

```powershell
docker compose up --build -d backend
docker compose exec backend php artisan db:seed
```

Seed идемпотентен: его можно выполнять повторно. Он создаёт две demo-организации, делает указанный admin email `owner` во всех существующих организациях и добавляет в `DataFlow Demo Organization` ещё три роли. Все четыре аккаунта используют пароль из `DATAFLOW_DEMO_PASSWORD`:

| Пользователь | Роль |
|---|---|
| `artKravcov@inbox.lv` | owner во всех организациях |
| `demo-owner@dataflow.local` | owner |
| `demo-editor@dataflow.local` | editor |
| `demo-viewer@dataflow.local` | viewer |

Проверяйте по очереди: owner и editor создают/редактируют проект; viewer видит его, но не может изменить; admin переключается между двумя организациями. Это только локальные учебные аккаунты, не механизм production provisioning.
## Backend tests

Feature tests выполняются на отдельной PostgreSQL базе `dataflow_test`, а не на основной локальной базе приложения. До запуска тестовых миграций тестовый базовый класс проверяет фактическое имя подключённой базы и прерывает запуск, если оно отличается. Новый PostgreSQL volume создаёт её автоматически. Для уже существующего volume создайте её один раз, используя локального пользователя из `.env` (в стандартной конфигурации это `dataflow`):

```powershell
docker compose exec postgres psql -U dataflow -d postgres -c "CREATE DATABASE dataflow_test;"
```

Затем выполните тесты внутри backend-контейнера:

```powershell
docker compose exec backend php artisan test
```

## Назначение процессов

- **frontend**: Vue-интерфейс, доступный пользователю через gateway.
- **backend**: Laravel API и будущая точка авторитетной бизнес-валидации и записи состояния.
- **worker**: отдельный Node-процесс для будущего выполнения pipeline; в T01 лишь проверяется его запуск.
- **postgres**: долговечное состояние приложения.
- **redis**: будущий транспорт задач и runtime cache/session store; не источник истины.
- **gateway**: единый HTTP origin, который направляет `/api/` в backend, а остальные запросы во frontend.

## Pipeline graph API (T05)

После обновления кода пересоберите backend и примените миграции:

```powershell
docker compose up --build -d backend
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan test
```

Доступны каталог `GET /api/v1/node-types` и операции pipeline/graph после входа. Формат запросов и ограничения описаны в [API_DRAFT](docs/API_DRAFT.md). Для повторяемой проверки graph API тесты используют отдельную тестовую PostgreSQL базу и тестовый resolver assets. Загрузка входных CSV реализована; выполнение графов ещё не реализовано.

## Визуальный редактор (T06)

На странице проекта создайте поток и откройте его. Добавляйте блоки из каталога, соединяйте порты мышью или формой «Выход → Вход», затем сохраните черновик. Источник позволяет вручную задать структуру и выбрать CSV; выполнение пока недоступно. Если другой редактор сохранил новую версию, локальные изменения остаются на странице до явной загрузки версии сервера.

Проверки frontend:

```powershell
cd frontend
npm ci
npm run format:check
npm run build
```

## Входные CSV (T07)

После пересборки и миграции owner или editor может загрузить CSV в настройках блока «CSV источник» и выбрать ранее загруженный файл организации. Viewer может просматривать файлы и скачивать их через API. По умолчанию максимальный размер — 10 МиБ; настройки PHP и gateway рассчитаны на этот предел. Файлы хранятся в приватном Docker volume, не в графе. Проверка формата и обработка содержимого появятся на этапе исполнения.

## Намерение запуска (этап A)

Backend сохраняет запрос на запуск, неизменяемый снимок графа и сообщение outbox в одной транзакции. Повтор с тем же ключом возвращает тот же запуск. API и примеры описаны в [API_DRAFT](docs/API_DRAFT.md). Обработчик outbox и worker ещё не подключены: запросы, отправленные напрямую к API, останутся в очереди; кнопка запуска в редакторе пока отключена.
