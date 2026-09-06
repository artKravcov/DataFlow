# Черновик базы данных

Это схема для будущих миграций, не готовый SQL. База приложения — PostgreSQL. Предложение: UUID для ID сущностей; локальные node/edge IDs — строки внутри pipeline, как в NODE_CONTRACT. UTC для timestamptz, JSONB для конфигурации. Все сущности имеют `created_at`; изменяемые — также `updated_at`.

| Таблица | Основные поля | Ограничения и индексы |
|---|---|---|
| users | id, name, email, password_hash, email_verified_at | unique normalized email |
| organizations | id, name | PK id |
| organization_memberships | id, organization_id, user_id, role | unique(org,user), FK обоих родителей |
| invitations | id, organization_id, email, role, token_hash, expires_at, accepted_at, invited_by | unique token_hash; index(org,email) |
| projects | id, organization_id, name, description, archived_at | index(org,archived_at), unique(id,org) |
| connections | id, organization_id, name, driver, config JSONB, credentials_encrypted, revision | index(org,driver), unique(id,org) |
| file_assets | id, organization_id, execution_id nullable, snapshot_node_id nullable, kind, storage_key, original_name, media_type, size_bytes, checksum, state | unique storage_key; unique(execution_id,snapshot_node_id) для output; index(org,kind); kind=input/output; state=uploading/ready/failed |
| pipelines | id, organization_id, project_id, name, revision, schema_version, archived_at | составной FK(project_id,org) → projects(id,org); unique(id,org) |
| pipeline_nodes | id text, pipeline_id, type, type_version, config JSONB, position JSONB, schema_hints JSONB nullable | PK(pipeline_id,id) |
| pipeline_edges | id text, pipeline_id, source_node_id, source_port, target_node_id, target_port | PK(pipeline_id,id); составные FK(pipeline_id,node_id) для обоих узлов; unique полного ребра |
| executions | id, organization_id, pipeline_id, requested_by, status, trigger_type, snapshot JSONB, idempotency_key, request_hash, worker_id nullable, lease_token_hash nullable, lease_expires_at nullable, started_at, finished_at, error_code nullable | составной FK(pipeline_id,org); unique(org,idempotency_key); index(org,created_at), index(status,lease_expires_at) |
| execution_nodes | id, execution_id, snapshot_node_id, status, started_at, finished_at, rows_in, rows_out, rows_failed nullable, error_code | unique(execution_id,snapshot_node_id) |
| execution_events | id, execution_id, event_id, sequence, payload_hash, kind, accepted_at | unique(execution_id,event_id); unique(execution_id,sequence); дедупликация всех принятых state events |
| execution_logs | id, execution_id, snapshot_node_id nullable, event_id, level, code, message, context JSONB | unique(execution_id,event_id); index(execution_id,id); отдельный ограниченный диагностический журнал |
| outbox_messages | id, execution_id, message_type, payload JSONB, published_at nullable, attempts, next_attempt_at | index(published_at,next_attempt_at) |

`file_assets.execution_id` используется только для результатов. Входной asset закрепляется ссылкой внутри snapshot; сервер проверяет ссылки при запуске и удалении файла. Файл в состоянии uploading не может быть источником. История использует snapshot, поэтому к `pipeline_nodes` у ExecutionNode нет FK.

`schema_hints` — минимальная точка расширения для manual schema, sample-derived schema или source metadata. Это не отдельный schema registry и не обязательная нормализация MVP. Snapshot должен включать schema hints, которые влияют на запуск, чтобы Execution оставался привязанным к фактически выполненной definition.

Dataset records не хранятся в таблицах приложения как промежуточное состояние MVP. Worker держит batch в памяти в рамках лимитов; результаты, которые нужны пользователю, сохраняются как `file_assets` или записываются destination connector. Если позже появятся chunking/streaming, потребуется отдельный storage contract/ADR.

## Целостность графа и конкуренция

Граф сохраняется целиком в одной транзакции. Клиент передаёт ожидаемую revision; если она не совпала, 409. Сервер сначала валидирует весь граф и tenant references, затем заменяет nodes/edges и увеличивает revision. Для нескольких рёбер в один input MVP действует unique(pipeline_id,target_node_id,target_port).

Запуск блокирует строку pipeline, проверяет доступ, revision и отсутствие active execution, формирует snapshot с версиями connections и создаёт Execution, ExecutionNode и OutboxMessage в одной транзакции. Дополнительно частичный unique index на executions(pipeline_id) WHERE status IN ('queued','running') защищает от гонки двух запросов.

JSONB config не обеспечивает FK на connection/asset: обязательна серверная проверка при сохранении и запуске. Удаление dependencies проверяет актуальные графы и активные snapshots в транзакции с блокировками связанных ресурсов. Ограничения FK дублируют критические проверки API, а не заменяют их.

Pipeline revision плюс immutable Execution snapshot закрывают раннюю потребность в versioning. Отдельную таблицу `pipeline_versions` добавить позже, если появятся rollback, approval, audit diffs или shared templates. Не вводить её только ради будущего roadmap.

## История и удаление

Архивирование не каскадирует историю. При физической очистке сначала определить retention; активные Execution и связанные assets не удалять. Логи и artifacts не хранить бессрочно автоматически: в MVP очистка только явная административная процедура, в v1 — конфигурируемый срок с документированными последствиями.

## Порядок миграций

1. Users, organizations, memberships, invitations, projects.
2. Connections, pipelines, nodes, edges.
3. Executions, execution_nodes, execution_events, execution_logs, outbox.
4. File assets с nullable FK на executions.

Миграции сопровождать factories для двух организаций и воспроизводимым demo seed. Не включать настоящие credentials. Таблицы sessions и прочие служебные таблицы framework добавляются по выбранному механизму auth, а не проектируются заново здесь.
