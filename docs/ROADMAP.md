# Дорожная карта

Последовательность основана на зависимостях. Календарные сроки не назначены: темп зависит от обучения и доступного времени. Каждая строка заканчивается демонстрацией работающего поведения.

| Этап | Результат | Критерий выхода |
|---|---|---|
| M0 — окружение | Каркас Laravel/Vue/worker, Compose, README | Чистая установка и health checks, сборки |
| M1 — foundation | Auth, personal organization, membership, projects | Проект создаётся через UI, чужая организация недоступна |
| M2 — pipeline core | Pipelines, nodes, edges, registry, validation, visual builder | CSV граф сохраняется и переживает reload |
| M3 — execution core | **Готово для первого среза:** Executions, snapshot, outbox, Redis, Node worker, CSV/filter/map | Ожидаемый CSV результата, статусы, обработка worker loss |
| M4 — data model + connectors MVP | Dataset abstraction, basic schema, field mapping, simple schema propagation, PostgreSQL/MySQL/REST/JSON, Cast, fan-out, приглашения/роли | Все критерии MVP_SCOPE пройдены |
| v1 — operational features | Scheduling, bounded retries, batching, Sort/Group, audit, realtime по потребности, execution metrics | Повторяемые фоновые запуски и восстановление подтверждены тестами |
| v2 — advanced data features | Join/Aggregate, Data Quality, Deduplicate, schema inference/evolution, несколько sources, Condition, webhooks, Google интеграции | Определены join semantics, invalid records и частичные ошибки |
| v3 — platform features | Connector SDK, custom connectors, pipeline templates, subpipelines, public API/CLI | Изоляция расширений и versioned contracts |
| v4 — long-term scale | Lineage, streaming, worker pools, distributed execution, tracing, AI nodes, MCP, marketplace | Измеренная потребность, security model и нагрузочные доказательства |

## Уточнения очередности

Basic roles, encryption, timeouts и ограничение ресурсов присутствуют в MVP, хотя в раннем обсуждении часть называлась production concerns. Откладываются их расширенные варианты, а не базовая защита.

Fan-out нужен для исходной идеи одного источника с несколькими получателями. Conditional branching, независимое время ветвей, Delay и parallel execution — отдельные функции после MVP.

## Контракты будущих функций до начала кода

Для scheduler определить timezone, DST, пропущенные интервалы, overlap policy и unique trigger key `(pipeline_id, scheduled_for)`. Расписание создаёт обычный Execution со snapshot; не отдельный engine.

Для Join определить ключи, тип соединения, обработку дублей/null и память. Для Retry — границу повтора и доказуемую идемпотентность конкретного получателя. Для SDK/AI/MCP — доверие к коду и данным, capabilities и лимиты. Пока записать тему, не создавать незаполненные сервисы «на будущее».

Для Dataset/Schema до M2 определить минимальный JSON shape schema fields и fixtures для CSV → Filter → Map → CSV. Для Data Quality до v2 определить, разделяет ли node поток на valid/invalid records или завершает execution при первом нарушении. Для Lineage до v4 определить, какие mapping events нужно сохранять уже в ранних версиях, чтобы не потерять происхождение полей.

## Учебная линия

M1 — tenant boundaries и policies; M2 — граф, Dataset/Schema и TypeScript contracts; M3 — очереди, ownership и состояние; M4 — неодинаковые внешние системы и schema-aware mapping; v1 — надёжность. Каждый этап сопровождать коротким объяснением причины решения и одной демонстрацией, которую владелец проекта может повторить сам.
