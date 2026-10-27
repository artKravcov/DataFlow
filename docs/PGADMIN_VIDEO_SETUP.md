# pgAdmin for the DataFlow demonstration

PostgreSQL is available only on the local computer at `127.0.0.1:5433`. The Docker Compose configuration does not expose it to the local network.

## Start or update PostgreSQL

From the project root, run:

```powershell
docker compose up -d postgres
```

The database data stays in the named Docker volume when the container is recreated.

## Register the server in desktop pgAdmin

1. Open pgAdmin and choose **Register -> Server**.
2. On **General**, set the name to `DataFlow local`.
3. On **Connection**, enter:

   | Field | Value |
   |---|---|
   | Host name/address | `127.0.0.1` |
   | Port | `5433` |
   | Maintenance database | `dataflow` |
   | Username | the value of `POSTGRES_USER` in local `.env` |
   | Password | the value of `POSTGRES_PASSWORD` in local `.env` |

4. Select **Save**.
5. Expand **Databases -> dataflow -> Schemas -> public -> Tables**.

Use `dataflow`, not `dataflow_test`: the latter is reserved for automated tests.

## What to open during the video

After the pipeline completes in the browser, right-click each table and choose **View/Edit Data -> All Rows**. Do not edit anything.

1. `pipelines`: show the `Paid orders` row and its `revision`.
2. `executions`: show the newest row with status `succeeded`.
3. `file_assets`: show the CSV input or output file, its state, size, and checksum metadata.

This confirms that the visual editor, the worker result, and execution history are not hard-coded browser data.

## Optional read-only queries

Use **Tools -> Query Tool** only if a table view is inconvenient. These queries do not change data:

```sql
SELECT id, name, revision, created_at
FROM pipelines
ORDER BY id DESC
LIMIT 5;
```

```sql
SELECT id, pipeline_id, status, revision, created_at
FROM executions
ORDER BY id DESC
LIMIT 5;
```

```sql
SELECT id, original_name, state, size_bytes, created_at
FROM file_assets
ORDER BY id DESC
LIMIT 10;
```

Do not show `.env`, passwords, `snapshot`, service tokens, or connection credentials in the recording.
