# Rep Tracker

A Laravel API that records where field sales reps walk during their shift. The phone uploads GPS points, and managers can look at any day, from today back to several years ago.

The two requirements, and how they are met:

1. **A complete record with no data loss.** Uploads are safe to repeat and bad points never block good ones. Data is only deleted after it has been archived and checked.
2. **Efficient storage for 3–4 years.** Recent points are kept as normal rows. Older days are kept as one compressed row per rep per day: 3.4 bytes per point instead of 53. Nothing is thrown away.

---

## Run it locally

You only need Docker.

```bash
git clone https://github.com/mnoufal369/rep-tracker.git && cd rep-tracker
docker compose up -d --build                       # app on http://localhost:8000 + MySQL
docker compose logs -f app                         # wait for "Server running on [http://0.0.0.0:8000]"
docker compose exec app php artisan db:seed        # 4 reps, 3 weeks of generated data
```

On first start, the app container installs Composer packages, creates `.env` and runs the migrations (see `docker/start.sh`).

Try it:

```bash
curl localhost:8000/api/v1/reps
curl localhost:8000/api/v1/reps/1/days
curl localhost:8000/api/v1/reps/1/days/2026-10-12
```

The seeder also creates one week from three years ago for rep 1. That week is archived and its raw rows deleted, so it is read from the archive only.

Run the tests:

```bash
docker compose exec app php artisan test       # fast, uses SQLite in memory
docker compose exec app composer test:mysql    # same tests on MySQL, including the partition test
```

---

## API

### Upload points (from the rep's phone)

`POST /api/v1/locations` with header `Authorization: Bearer <device token>`

```json
{
  "points": [
    { "recorded_at": "2026-10-13T09:00:00Z", "lat": 52.3745123, "lng": 4.8810456, "accuracy": 8 }
  ]
}
```

- Up to 1,000 points per request. `recorded_at` can be ISO-8601 or a unix timestamp. `accuracy` (metres) is optional.
- The rep is found from the token, so a phone can only upload its own points.

Response `201`:

```json
{ "received": 3, "stored": 2, "duplicates": 0,
  "rejected": [{ "index": 1, "reason": "lat must be between -90 and 90." }] }
```

A `201` means every point that is not in `rejected` is stored, so the phone can delete the batch from its local buffer.

### Inspect the daily logs

| Request | Returns |
|---|---|
| `GET /api/v1/reps` | All reps |
| `GET /api/v1/reps/{rep}/days?from=Y-m-d&to=Y-m-d` | One summary per day (default: last 30 days) |
| `GET /api/v1/reps/{rep}/days/{date}` | Summary of one day, including its gaps |
| `GET /api/v1/reps/{rep}/days/{date}/points` | Every stored point of that day |

Example day:

```json
{
  "rep": { "id": 1, "name": "Sanne de Vries", "email": "rep1@example.com" },
  "date": "2026-10-12",
  "archived": true,
  "point_count": 2064,
  "first_at": "2026-10-12T09:06:36+02:00",
  "last_at": "2026-10-12T17:13:41+02:00",
  "distance_m": 12863,
  "gap_count": 2,
  "gaps": [
    { "from": "2026-10-12T11:40:01+02:00", "to": "2026-10-12T11:51:01+02:00", "duration_seconds": 660 },
    { "from": "2026-10-12T15:31:46+02:00", "to": "2026-10-12T15:42:46+02:00", "duration_seconds": 660 }
  ]
}
```

A **gap** is a period of more than 5 minutes without any point, for example because the phone was off or had no GPS. Gaps show management exactly where coverage cannot be verified.

### Generate test data

| Request / command | What it does |
|---|---|
| `POST /api/v1/reps/{rep}/days/{date}/generate` | Generates a realistic working day for that rep and uploads it through the normal upload code |
| `php artisan tracking:generate 2026-09-01 2026-09-30 --rep=1` | The same for a range of days (weekdays only) |

---

## How it works

```
phone ──POST /locations──▶ IngestionService ──▶ location_points        (raw points, last 90 days)
                                (1 transaction) └─▶ pending_archive_days  (days that need archiving)

hourly   tracking:archive  ──▶ DayArchiver      ──▶ daily_tracks  (1 compressed row per rep per day, kept for years)
daily    tracking:prune    ──▶ HotStoragePruner ──▶ deletes raw points older than 90 days

GET .../days/{date} ──▶ DayTrackReader: archive + raw points merged ──▶ DaySummary
```

| File | Responsibility |
|---|---|
| `app/Tracking/IngestionService.php` | Validates and stores uploaded points |
| `app/Tracking/TrackCodec.php` | Compresses a day of points, and decompresses it |
| `app/Tracking/DayArchiver.php` | Writes and checks the archive of a day |
| `app/Tracking/DayTrackReader.php` | Reads a day from the archive and the raw table |
| `app/Tracking/DaySummary.php` | Distance, first and last point, gaps |
| `app/Tracking/HotStoragePruner.php` | Deletes old raw points |
| `app/Tracking/PartitionManager.php` | Monthly partitions of the raw table (MySQL) |
| `app/Tracking/RouteGenerator.php` | Generates test days |
| `config/tracking.php` | Timezone, retention, gap length |

### Tables

**`location_points`**: raw points of the last 90 days.

| Column | Type | Why |
|---|---|---|
| `rep_id` + `recorded_at` | primary key | MySQL stores rows in primary-key order, so one rep's day sits together on disk and is read in one go. A point sent twice has the same key, so it is stored only once |
| `lat_e7`, `lng_e7` | `INT` | Degrees × 10,000,000. 4 bytes instead of 8 for a `DOUBLE`, still precise to about 1 cm |
| `accuracy_m` | `SMALLINT` | GPS accuracy in metres |

There is no auto-increment id and no extra index, because every query is "this rep, between these two times". On MySQL the table is split into **one partition per month**, so removing an old month is a single, instant `DROP PARTITION`.

**`daily_tracks`**: the archive, one row per rep per day. It holds all points of the day compressed in `points_blob`, plus summary columns (point count, distance, gaps), so listing old days never has to unpack anything.

**`pending_archive_days`**: days that received new points and still need to be archived.

### Balancing complete tracking with efficient storage

A common way to save space is to simplify old routes (keep fewer points). That throws data away, which conflicts with "a complete record". Instead, **we keep every point and only change how it is stored once the day is over**:

- **Recent days (90 days) are kept as normal rows.** They are quick to write, query and correct.
- **Every finished day is also compressed into one archive row.** `TrackCodec` uses the fact that a walking person's next point is only a few seconds and a few metres away. So it stores the *difference* from the previous point, a small number that fits in 1–2 bytes, and then gzips the result.
- **Decompressing gives back exactly the same numbers.** This is checked every time an archive is written.

Measured on the generated data (about 2,300 points per rep per day):

| | Bytes per point | Per rep per day |
|---|---|---|
| Raw row in MySQL (incl. primary key) | 53 | ~120 KB |
| Archive (`points_blob`) | 3.4 | ~8 KB |

For 500 reps over 4 years (250 working days a year), that is about **4 GB of archive** plus about 4 GB for the 90 recent days. Keeping everything as raw rows would take about **60 GB**.

### No data loss, step by step

| Risk | What prevents loss |
|---|---|
| The phone has no internet | The phone keeps points in a local buffer and uploads them later. It only deletes them after a `201` |
| The answer gets lost and the phone sends the batch again | The primary key `(rep_id, recorded_at)` makes duplicates harmless; they are counted, not stored twice |
| One point in the batch is invalid | It is listed in `rejected`; all other points are stored |
| Server crashes halfway | The points and the "needs archiving" mark are saved in one transaction: both or neither |
| The archive is broken | After writing, it is decompressed and compared with the original (checksum) before the day counts as archived |
| Raw points deleted before they were archived | The pruner first archives every pending day, and only then deletes |
| Points for an old day arrive months later | They are stored normally, the day is marked again, and the next archive run merges them into that day's archive |
| The phone recorded nothing (switched off, no GPS) | This cannot be recovered, but it is never hidden: every period over 5 minutes without points is reported as a gap |

### Simplifications

- All reps work in one city, so one timezone (`Europe/Amsterdam`) decides where a day starts and ends.
- The read API has no login, to keep the assessment easy to evaluate. In production it would sit behind manager authentication (e.g. Sanctum), with each manager seeing only their own team.
