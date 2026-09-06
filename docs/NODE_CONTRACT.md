# Граф, узлы и Dataset

DataFlow ориентирован на data workflows. Между узлами передаётся не arbitrary JSON, а Dataset — логическое представление набора данных с понятной структурой.

## Dataset v1

Dataset v1 — batch-oriented объект runtime:

| Поле | Смысл |
|---|---|
| `schema` | DataSchema: список полей и их простые типы |
| `records` | Упорядоченный batch плоских записей |
| `metadata` | Размеры, source hints, warnings, безопасные технические сведения |
| `execution_context` | Не сериализуется в граф; содержит лимиты, cancellation, logger, resolvers |

Будущие расширения: chunking, pagination, streaming, backpressure, distributed execution и lineage. Они не входят в MVP и не требуют проектировать production-grade streaming engine сейчас.

## Data Schema

Schema описывает поля Dataset. Минимальное поле schema:

```json
{"name":"campaign_id","type":"string","nullable":false,"metadata":{"label":"Campaign ID"}}
```

Типы v1: `string`, `integer`, `float`, `decimal`, `boolean`, `date`, `datetime`, `json`, `unknown`. Не вводить сложную собственную type system до реальной потребности. CSV source может сначала объявлять все поля как `string` или `unknown`; DB sources могут объявлять schema из metadata выбранной таблицы.

Пример schema:

```json
[
  {"name":"campaign_id","type":"string","nullable":false},
  {"name":"campaign_name","type":"string","nullable":true},
  {"name":"clicks","type":"integer","nullable":false},
  {"name":"cost","type":"decimal","nullable":false},
  {"name":"date","type":"date","nullable":false}
]
```

Schema нужна для builder, validation, mapping, execution metrics и будущего lineage. Она не должна содержать credentials или реальные значения записей.

## Node Registry v1

Registry — каталог типов, встроенных разработчиком. Это точка расширения кода, не marketplace пользовательского кода. Каждый тип задаёт `type`, `type_version`, label, category, input/output ports, config schema, input contract, output contract, validation rules и runtime handler. Backend и worker используют согласованную версию каталога; CI сверяет общие fixtures.

Концептуальная форма, не обязательный финальный интерфейс:

```ts
type NodeDefinition = {
  type: string;
  typeVersion: number;
  category: 'source' | 'transform' | 'destination' | 'control';
  configSchema: unknown;
  inputContract: unknown;
  outputContract: unknown;
};

type NodeExecutionHandler = {
  validate(config: unknown, inputSchema?: unknown): Promise<void>;
  execute(config: unknown, input: Dataset | null, context: ExecutionContext): Promise<Dataset | NodeResult>;
};
```

Source nodes создают Dataset. Transform nodes принимают Dataset и возвращают новый Dataset. Destination nodes принимают Dataset и возвращают metadata результата, не новый records port. Control nodes описывают trigger/orchestration semantics и не обязаны быть data nodes в первом MVP.

| Тип | Вход → выход | Настройки / этап |
|---|---|---|
| csv.source | нет → records | asset_id, delimiter, header=true; первый срез |
| transform.filter | records → records | field, operator, value; первый срез |
| transform.map | records → records | mappings source→target; первый срез |
| csv.destination | records → нет | filename; первый срез |
| json.source | нет → records | asset_id, JSON array of flat objects; полный MVP |
| postgres.source / mysql.source | нет → records | connection_id, schema/table, columns, limit; полный MVP |
| postgres.destination / mysql.destination | records → нет | connection_id, schema/table, column mapping, mode; полный MVP |
| rest.source | нет → records | connection_id, relative_path, array_path; GET; полный MVP |
| rest.destination | records → нет | connection_id, relative_path, method=POST/PUT; JSON array; полный MVP |
| transform.cast | records → records | field, target_type; полный MVP |
| transform.sort | records → records | field, direction; после MVP/v1 |
| transform.group / transform.aggregate | records → records | group keys, aggregate fields; после MVP/v1 |
| transform.join | records + records → records | key fields, join type; future milestone |
| transform.deduplicate | records → records | key fields; future |
| transform.validate | records → records + invalid records | data quality rules; future |

Rename — частный случай Map, отдельный runtime handler не нужен. Manual trigger — metadata Execution, не узел с данными. Расписания позже также создают Execution, не требуют фиктивного data port.

Категории:

| Category | Примеры |
|---|---|
| Source Nodes | PostgreSQL, MySQL, REST API, CSV, JSON |
| Transform Nodes | Filter, Map/Rename, Type Conversion, Sort, Group, Aggregate, Join future, Deduplicate future, Validate/Data Quality future |
| Destination Nodes | PostgreSQL, MySQL, REST API, CSV |
| Control Nodes | Manual Trigger, Scheduled Trigger, Condition/Branch future |

## Формат graph

Ниже полный структурный пример первого сценария. `asset-demo` — демонстрационный идентификатор, при запуске заменяется ID загруженного asset; пример не отправляется в API без замены.

```json
{
  "schema_version": 1,
  "nodes": [
    {"id":"source","type":"csv.source","type_version":1,"position":{"x":0,"y":0},"config":{"asset_id":"asset-demo","delimiter":",","header":true}},
    {"id":"filter","type":"transform.filter","type_version":1,"position":{"x":240,"y":0},"config":{"field":"status","operator":"equals","value":"paid"}},
    {"id":"map","type":"transform.map","type_version":1,"position":{"x":480,"y":0},"config":{"mappings":[{"source":"order_id","target":"id"},{"source":"total","target":"amount"}]}},
    {"id":"output","type":"csv.destination","type_version":1,"position":{"x":720,"y":0},"config":{"filename":"paid-orders.csv"}}
  ],
  "edges": [
    {"id":"e1","source":"source","source_port":"records","target":"filter","target_port":"records"},
    {"id":"e2","source":"filter","source_port":"records","target":"map","target_port":"records"},
    {"id":"e3","source":"map","source_port":"records","target":"output","target_port":"records"}
  ]
}
```

## Records v1

`records` внутри Dataset — упорядоченный список плоских объектов. Значения: string, finite number, boolean, null. Вложенные объекты/массивы отвергаются с понятной ошибкой; flatten позже. Денежные decimal и большие integer из БД передавать строками без потери точности, если нет безопасного decimal representation. Даты — ISO 8601 strings. CSV source сохраняет значения строками, если schema не задана явно и Cast не применён.

Filter: `equals`, `not_equals`, `is_null`, `is_not_null`, `greater_than`, `less_than`. Equals строгое по типу; числовые сравнения требуют number, иначе ошибка конфигурации/данных. Missing field — ошибка, null — существующее пустое значение. Для CSV перед числовым сравнением нужен Cast, поэтому в первом срезе сравниваем строковые статусы.

Map сохраняет только перечисленные поля, позволяет переименовать, отвергает повторяющиеся target и отсутствующие source. Cast поддерживает string/number/boolean; null сохраняется, невалидное значение завершает узел ошибкой. Number — только конечный, безопасный по точности; boolean из строк только `true`/`false`. Нет eval и пользовательских выражений.

Пустой результат — успех. CSV destination пишет заголовки по output schema, mapping или исходным columns, даже при нуле строк. Fan-out получает неизменяемый Dataset; handler не мутирует входной объект для соседней ветви.

## Schema propagation

Pipeline Builder и runtime должны постепенно уметь вычислять output schema следующего шага:

```text
Source schema:
campaign_id: string
cost: decimal

Map:
campaign_id -> id
cost -> ad_cost

Output schema:
id: string
ad_cost: decimal
```

Правила v1 могут быть простыми:

| Узел | Schema rule |
|---|---|
| Source | Объявляет manual/inferred schema |
| Filter | Сохраняет input schema, проверяет наличие field |
| Map/Rename | Возвращает schema из выбранных source fields с новыми именами |
| Cast | Меняет тип выбранного поля |
| Destination | Проверяет, что нужные destination fields могут быть сопоставлены |

Schema propagation в UI — помощь пользователю, а не замена серверной и runtime validation. Если schema неизвестна, draft можно сохранить, но run/validate должны честно сообщать, какие проверки невозможны до исполнения.

## Schema inference и mapping

Некоторые sources могут объявлять schema заранее: PostgreSQL и MySQL через metadata выбранной таблицы. CSV, JSON и REST могут требовать manual schema или simple inference from sample data. Сложное inference, nested JSON flattening, schema drift и evolution — отдельные этапы.

Data Mapping — центральная no-code функция:

```text
source:
campaign_name
clicks
cost

destination:
campaign
click_count
spend

mapping:
campaign_name -> campaign
clicks -> click_count
cost -> spend
```

Map/Rename реализует mapping внутри pipeline. Destination mapping связывает output schema с полями внешней системы. В будущем mapping metadata должна не блокировать field-level lineage.

## Допустимые связи

Порт соединяется по data kind, направлению и cardinality. Source не имеет входов; destination не имеет выходов. В MVP все data ports — records/Dataset, это не гарантия совпадения полей. Проверка структуры происходит до запуска, когда schema известна; фактических полей и значений — во время исполнения. UI показывает «структура данных пока неизвестна», а не ложную зелёную гарантию.

Обязательны: уникальные node/edge IDs, существующие концы рёбер, известные версии типов, отсутствие циклов и дубликатов рёбер, один source, связность всех узлов от source, минимум один destination, отсутствие тупиков transform, один вход на несource. Неполный draft можно сохранить согласно [API_DRAFT](API_DRAFT.md).

## Ограниченные коннекторы

DB source читает разрешённую таблицу и columns, без произвольного SQL. Идентификаторы проходят allowlist/проверку metadata, значения параметризуются. Если набор превышает лимит, ошибка вместо тихого обрезания (читать limit+1). DB destination пишет в уже существующую таблицу: insert либо upsert по явно выбранному уникальному ключу. DDL и автоматическое создание таблиц не входят в MVP.

REST connection содержит base URL и credentials; node — относительный путь. Source ожидает один ограниченный JSON response, массив извлекается по простому пути полей; отсутствие массива — ошибка. Destination передаёт один JSON array, принимает 2xx как транспортный успех без предположения о бизнес-результате. Пагинация и сложный auth позже.
