# Первые задачи разработки

Все задачи пока **не начаты**. Отмечать готовность после фактической проверки. Номера T обозначают небольшие единицы; prompts могут охватывать несколько связанных T.

| ID | Задача | Зависит от | Проверяемый результат |
|---|---|---|---|
| T01 | Создать базовые приложения и Compose | Документация | Health endpoint, frontend shell, worker process; README запуска |
| T02 | Auth и personal organization | T01 | Register/login/logout; membership создаётся атомарно |
| T03 | Projects CRUD + policies | T02 | Две организации изолированы; архивирование |
| T04 | Frontend auth/projects | T03 | Пользователь создаёт проект через браузер |
| T05 | Pipeline models + registry + graph API + Dataset/Schema contract | T03 | Revision, cycle validation, неизвестные типы отвергаются; fixtures описывают Dataset v1 |
| T06 | Визуальный schema-aware редактор | T04,T05 | Собрать и сохранить CSV граф без JSON/code input; Filter/Map используют поля schema, если она известна |
| T07 | FileAsset upload/download | T03 | CSV загружается с лимитом, чужой файл недоступен |
| T08 | Execution snapshot + outbox + internal API | T05,T07 | Атомарное создание, idempotency, claim/events/lease tests |
| T09 | Redis dispatcher + Node Dataset runtime | T08 | Реальная доставка, CSV/filter/map, schema propagation, failed recovery |
| T10 | Execution UI и сквозная приёмка | T06,T09 | Скачать ожидаемый CSV и понять ошибку неверного поля |

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
