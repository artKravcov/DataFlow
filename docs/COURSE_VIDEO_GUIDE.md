# DataFlow: what to record for the course assignment

This guide is for the stated course criterion: show a small prototype with 2-3 working sections where data is genuinely read, processed, and persisted. The recording should focus on the product's central CSV pipeline, not on authorization or Docker details.

## What the recording proves

| Part to show | What actually happens | Where the result is persisted |
|---|---|---|
| 1. Pipeline editor | A saved graph connects CSV source, Filter, Map/Rename, and CSV destination. | PostgreSQL stores the pipeline, nodes, edges, configuration, and revision. |
| 2. Pipeline execution | The worker reads the actual input CSV, removes non-paid orders, and renames fields. | PostgreSQL stores Execution and per-node statuses; the worker produces the output content. |
| 3. Result and history | The finished CSV is downloadable and the completed run is visible in history. | The output CSV is a private file asset; its metadata and execution history are stored in PostgreSQL. |

These are three parts of the main functionality. Login is useful to enter the application but is not presented as one of the required functional sections.

## Before recording

1. Follow [VIDEO_DEMO_GUIDE](VIDEO_DEMO_GUIDE.md) through the preparation section.
2. Run one trial execution to ensure that worker and queue-manager are active.
3. Start the recording in the browser at `http://localhost:8080`, logged in as `demo-owner@dataflow.local`.
4. Do not record `.env`, terminal output containing secrets, passwords, or service tokens.

Use the ready demo data:

```csv
order_id,status,total
101,paid,12.50
102,pending,8.00
103,paid,20.00
```

Expected result:

```csv
id,amount
101,12.50
103,20.00
```

## Recording plan: 4 minutes

### 0:00-0:20 - purpose

Open **DataFlow Demo Organization** -> **Demonstration DataFlow** -> **Paid orders**.

Say in Latvian:

> DataFlow ir vizuāla datu plūsmu platforma. Šajā prototipā bez programmēšanas apstrādāju pasūtījumu CSV failu: atlasu tikai apmaksātos pasūtījumus un izveidoju jaunu rezultāta failu.

### 0:20-1:20 - section 1: saved visual pipeline

Show the four connected blocks in the editor. Click each block and briefly show its settings:

1. CSV source uses `paid-orders.csv`.
2. Filter keeps records where `status equals paid`.
3. Map/Rename maps `order_id` to `id` and `total` to `amount`.
4. CSV destination creates `paid-orders.csv`.

Click **Save**, then reload the browser page. Show that the nodes, connections, and settings remain. This proves that the graph was saved, not merely drawn in the browser.

Say:

> Pirmā galvenā daļa ir pipeline redaktors. Shēma un katra mezgla konfigurācija tiek saglabāta datubāzē ar versiju, tāpēc pēc lapas pārlādes tā paliek nemainīga.

### 1:20-2:35 - section 2: actual data processing

Click **Run**. In the execution panel, show the statuses changing from queued to running and then succeeded. If the transition is too fast, use the execution panel to show the completed node statuses.

Say:

> Otrā galvenā daļa ir izpilde. Sistēma nolasa reālu CSV failu, worker process pielieto filtru un lauku pārsaukšanu, bet katra mezgla statuss tiek saglabāts izpildes vēsturē. Lietotāja saskarne tikai parāda saglabāto stāvokli.

Optional technical sentence, only if asked:

> API glabā izpildes momentuzņēmumu un stāvokļus PostgreSQL datubāzē, Redis piegādā uzdevumu worker procesam, un Node.js worker neveic tiešas izmaiņas datubāzē.

### 2:35-3:20 - section 3: persisted output

Click the download link for the finished result. Open the downloaded CSV and show the two rows and columns `id,amount`.

Say:

> Trešā galvenā daļa ir rezultāta saglabāšana. Rezultāta CSV fails ir izveidots no apstrādātajiem datiem un tiek glabāts kā privāts artefakts. No trim ievades rindām filtrs atstāja divus apmaksātus pasūtījumus.

### 3:20-3:45 - execution history

Open **Overview**, select the succeeded status filter, and show the run in history. Open it back in the pipeline if desired.

Say:

> Izpildes vēsture ļauj redzēt, kurš pipeline tika palaists, tā statusu un rezultātu. Dati ir piesaistīti organizācijai, tādēļ citas organizācijas lietotājs tos neredz.

### 3:45-4:00 - closing sentence

> Prototips realizē prasību galveno CSV scenāriju: vizuāli saglabāt plūsmu, nolasīt un apstrādāt reālus datus, saglabāt rezultātu un apskatīt izpildes statusu. Nākamajā posmā var pievienot JSON, REST un datubāzu savienotājus.

## What not to claim

Do not state that REST, JSON, PostgreSQL/MySQL connectors, scheduled runs, invitations, member management, fan-out, or structured execution logs are finished. They are in the broader requirements and design, but not in this compact CSV prototype.

## Documented difference to resolve before submission

The requirements specification states that the interface language should be Latvian. The current prototype interface is Russian. Its main CSV behaviour matches the documented first scenario, but the visible labels should be translated to Latvian before final submission if strict compliance with the non-functional requirement is expected.

## GitHub link to include with the submission

Repository: [github.com/artKravcov/DataFlow](https://github.com/artKravcov/DataFlow)

Before submitting, confirm that the latest local commits have been pushed to `origin/main`. The repository must include the source code, Docker Compose configuration, `README.md`, the requirements specification, the system design, and this recording guide.
