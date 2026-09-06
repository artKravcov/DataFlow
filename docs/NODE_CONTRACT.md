# Граф, узлы и данные

## Node Registry v1

Registry — каталог типов, встроенных разработчиком. Это точка расширения кода, не marketplace пользовательского кода. Каждый тип задаёт `type`, `type_version`, label, input/output ports, config schema и runtime handler. Backend и worker используют согласованную версию каталога; CI сверяет общие fixtures.

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

Rename — частный случай Map, отдельный runtime handler не нужен. Manual trigger — metadata Execution, не узел с данными. Расписания позже также создают Execution, не требуют фиктивного data port.

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

Набор — упорядоченный список плоских объектов. Значения: string, finite number, boolean, null. Вложенные объекты/массивы отвергаются с понятной ошибкой; flatten позже. Денежные decimal и большие integer из БД передавать строками без потери точности. Даты — ISO 8601 strings. CSV source сохраняет значения строками, не угадывает типы.

Filter: `equals`, `not_equals`, `is_null`, `is_not_null`, `greater_than`, `less_than`. Equals строгое по типу; числовые сравнения требуют number, иначе ошибка конфигурации/данных. Missing field — ошибка, null — существующее пустое значение. Для CSV перед числовым сравнением нужен Cast, поэтому в первом срезе сравниваем строковые статусы.

Map сохраняет только перечисленные поля, позволяет переименовать, отвергает повторяющиеся target и отсутствующие source. Cast поддерживает string/number/boolean; null сохраняется, невалидное значение завершает узел ошибкой. Number — только конечный, безопасный по точности; boolean из строк только `true`/`false`. Нет eval и пользовательских выражений.

Пустой результат — успех. CSV destination пишет заголовки по схеме mapping или исходным columns, даже при нуле строк. Fan-out получает неизменяемый результат; handler не мутирует входной объект для соседней ветви.

## Допустимые связи

Порт соединяется по data kind, направлению и cardinality. Source не имеет входов; destination не имеет выходов. В MVP все data ports — records, это не гарантия совпадения полей. Проверка структуры происходит до запуска; фактических полей и значений — во время исполнения. UI показывает «структура данных пока неизвестна», а не ложную зелёную гарантию.

Обязательны: уникальные node/edge IDs, существующие концы рёбер, известные версии типов, отсутствие циклов и дубликатов рёбер, один source, связность всех узлов от source, минимум один destination, отсутствие тупиков transform, один вход на несource. Неполный draft можно сохранить согласно [API_DRAFT](API_DRAFT.md).

## Ограниченные коннекторы

DB source читает разрешённую таблицу и columns, без произвольного SQL. Идентификаторы проходят allowlist/проверку metadata, значения параметризуются. Если набор превышает лимит, ошибка вместо тихого обрезания (читать limit+1). DB destination пишет в уже существующую таблицу: insert либо upsert по явно выбранному уникальному ключу. DDL и автоматическое создание таблиц не входят в MVP.

REST connection содержит base URL и credentials; node — относительный путь. Source ожидает один ограниченный JSON response, массив извлекается по простому пути полей; отсутствие массива — ошибка. Destination передаёт один JSON array, принимает 2xx как транспортный успех без предположения о бизнес-результате. Пагинация и сложный auth позже.
