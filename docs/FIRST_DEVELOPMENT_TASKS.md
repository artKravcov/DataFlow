# Первые задачи разработки

Статусы ниже отражают фактическое состояние на 4 октября 2026 года. Номера T обозначают небольшие единицы; prompts могут охватывать несколько связанных T. Завершённой считается только задача с указанной фактической проверкой.

| ID | Задача | Статус | Зависит от | Проверяемый результат |
|---|---|---|---|---|
| T01 | Создать базовые приложения и Compose | Готово | Документация | Health endpoint, frontend shell, worker process; README запуска |
| T02 | Auth и personal organization | Готово | T01 | Register/login/logout; membership создаётся атомарно |
| T03 | Projects CRUD + policies | Готово | T02 | Две организации изолированы; архивирование |
| T04 | Frontend auth/projects | Готово | T03 | Пользователь создаёт проект через браузер |
| T05 | Pipeline models + registry + graph API + Dataset/Schema contract | Выполнена (assets/runtime — далее) | T03 | Revision, cycle validation, неизвестные типы отвергаются; fixtures описывают Dataset v1 |
| T06 | Визуальный schema-aware редактор | Выполнена | T04,T05 | Собрать и сохранить CSV граф без JSON/code input; Filter/Map используют поля schema, если она известна |
| T07 | FileAsset upload/download | Не начато | T03 | CSV загружается с лимитом, чужой файл недоступен |
| T08 | Execution snapshot + outbox + internal API | Не начато | T05,T07 | Атомарное создание, idempotency, claim/events/lease tests |
| T09 | Redis dispatcher + Node Dataset runtime | Не начато | T08 | Реальная доставка, CSV/filter/map, schema propagation, failed recovery |
| T10 | Execution UI и сквозная приёмка | Не начато | T06,T09 | Скачать ожидаемый CSV и понять ошибку неверного поля |

Фактические результаты T01–T04 и выполненные проверки перечислены в [PROJECT_CONTEXT](../PROJECT_CONTEXT.md). Трекер прототипа для курсовой работы находится в [PROTOTYPE_PLAN](PROTOTYPE_PLAN.md). T05–T10 составляют первый вертикальный срез; завершать M4 для сдачи прототипа не требуется.

## После первого среза

Каждый connector добавлять отдельной задачей: source/destination capability, config form, schema declaration/inference, runtime, error mapping, интеграционная проверка. Затем JSON source и Cast, fan-out, приглашения/роли и полный набор acceptance. Не добавлять все коннекторы одной большой правкой.

Ближайшая очередь после T10:

1. Зафиксировать JSON shape `DataSchema` и Dataset fixtures в `contracts/`.
2. Добавить destination field mapping для CSV/PostgreSQL без пользовательского кода.
3. Добавить PostgreSQL source с объявленной schema из metadata выбранной таблицы.
4. Добавить Cast/Type Conversion как явное изменение schema.
5. Добавить базовые execution metrics: read/processed/written/failed rows.
6. Добавить simple schema inference для CSV/JSON sample.
7. Добавить Sort как одноinput transform без fan-in.
8. Подготовить ADR для Join перед реализацией fan-in.

## Definition of Done задачи

- Есть конкретное наблюдаемое поведение и инструкция проверки.
- Пройдены относящиеся к изменению тесты/сборки; непроведённые проверки явно названы.
- Секреты и данные разных организаций не смешиваются.
- API/graph contracts и документация соответствуют коду.
- PROJECT_CONTEXT содержит фактическое состояние и следующий шаг.

Первая демонстрация не обязана выглядеть как готовый SaaS. Она обязана исполнять реальный поток и корректно показывать состояние. Моки допустимы для изолированных UI тестов, но не как замена приёмки M3.
