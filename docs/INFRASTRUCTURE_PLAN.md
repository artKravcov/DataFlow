# Репозиторий и локальная среда

## Будущая структура

```text
dataflow/
├── AGENTS.md
├── PROJECT_CONTEXT.md
├── README.md
├── backend/                 Laravel
├── frontend/                Vue + TypeScript
├── worker/                  Node + TypeScript
├── contracts/               JSON Schema и fixtures
├── infrastructure/
│   ├── docker/
│   └── nginx/
├── docs/
├── codex-prompts/
└── compose.yaml
```

Это целевая структура после задания 001. Текущий пакет содержит только документацию. Выбрать одно имя Compose-файла (`compose.yaml`) и использовать его в командах и CI.

## Сервисы по этапам

На foundation: reverse proxy, Laravel runtime, frontend dev server, PostgreSQL приложения, Redis. При execution: Node worker и Laravel dispatcher/reconciler, использующий тот же backend image. Reconciler не является пользовательским scheduler расписаний.

Данные PostgreSQL, Redis и файлов сохраняются в отдельных именованных volumes. Assets доступны через Laravel, worker не получает произвольный путь к host filesystem. Внешние PostgreSQL/MySQL test targets и REST mock включаются отдельным профилем для интеграционных тестов. Не использовать реальные production destinations в demo.

## Конфигурация

`.env.example` перечисляет адрес приложения, DB/Redis connection, auth/session параметры, app encryption key placeholder, internal service token placeholder, storage location и лимиты. Реальные секреты генерируются локально и не коммитятся. Worker не получает пароль БД приложения или app encryption key.

Browser и API в production ориентируются на один origin. В dev proxy сохраняет cookie/CSRF поведение; не включать permissive CORS с credentials. PostgreSQL/Redis и internal API не публиковать в общую сеть; при необходимости локальный порт привязать к loopback.

## Воспроизводимость

В задании 001 проверить совместимые поддерживаемые PHP/Laravel и Node версии, закрепить контейнерные версии и Composer/npm lockfiles. Один package manager для frontend/worker, без дополнительного monorepo orchestrator. README должен содержать фактически проверенные команды установки, запуска, миграций, seed, тестов, остановки и восстановления среды без удаления данных.

Health endpoints разделяют liveness процесса и readiness зависимостей. Приложение не должно объявлять готовность при неприменённых миграциях. Startup order не заменяет проверку доступности зависимостей и ограниченный retry подключения.

## CI и поставка

По мере появления компонентов: backend tests, frontend/worker typecheck и build, контрактные fixtures; интеграционные тесты с PostgreSQL/Redis и тестовыми destinations. Выбор Git hosting, лицензии, домена и production deployment остаётся открытым. Публикация приложения не является частью создания этого blueprint.
