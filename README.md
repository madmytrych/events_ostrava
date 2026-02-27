# Events Ostrava

Laravel app for collecting and enriching family event listings in Ostrava. It scrapes multiple sources and delivers results via a Telegram bot.

---

## Project description (overview)

**Events Ostrava** is a local project that helps families in Ostrava discover kids and family-friendly events. The main way people use it is through a Telegram bot called **KidsEvents Ostrava**.

### What the project does

- **Collects events** from trusted public sources (e.g. Visit Ostrava family section, AllEvents kids in Ostrava, Kultura Jih children's events, Kudy z nudy Ostrava). Events are fetched on a schedule and stored in one place.
- **Cleans and enriches** the data: duplicate events are detected and linked, and each event gets a short summary and useful details (age range, indoor/outdoor, etc.) so parents can quickly see if it fits their family.
- **Delivers events via Telegram**: users open the bot, choose their language and (optionally) their child’s age, then get lists of events for today, tomorrow, this week, or this weekend. No ads, no clutter—just event info.
- **Accepts suggestions**: users can submit an event by sending a link; the bot guides them through optional name, description, and contact. Submissions are stored for later review.
- **Optional weekly reminders**: users can turn on weekly notifications and receive a short digest of upcoming weekend events, filtered by their age preference.

### Bot name and identity

The Telegram bot presents itself as **KidsEvents Ostrava**. In the bot, it explains that it is a small local project made by a parent, for parents, to help families spend less time searching and more time together.

### Supported languages

The bot supports **English**, **Ukrainian**, and **Czech**. On first use, the user picks a language; they can change it anytime in Settings.

### Main user flow (scheme)

1. **First contact**  
   User starts the bot → sees a short welcome and is asked to choose a language (EN / UK / CS).

2. **Daily use**  
   After language is set, the main menu shows: **Today**, **Tomorrow**, **This week**, **This weekend**, **By age**, **Settings**, **Submit event**, **About**.  
   - Choosing a time range (e.g. “This weekend”) returns a list of events for that period.  
   - “By age” lets the user set a preferred age band (0–3, 3–6, 6–10, or all ages); the bot then uses this for event lists and for the weekly digest.  
   - **Settings**: language, weekly reminders on/off, view current preferences.  
   - **Submit event**: user sends a URL → optionally name, description, contact → submission is saved for review.  
   - **About**: short description of the project and the bot.

3. **Behind the scenes (data pipeline)**  
   - **Scraping**: scheduled jobs fetch new events from Visit Ostrava (family), AllEvents (kids in Ostrava), Kultura Jih (children's events), and Kudy z nudy (Ostrava region) and insert or update them in the database.  
   - **Enrichment**: each new/updated event is processed to add summaries and metadata (with a fallback if AI is unavailable).  
   - **Deduplication**: same event from different sources is linked to one canonical record.  
   - **Cleanup**: past events are periodically marked inactive.  
   - **Notifications**: if enabled, a scheduled job sends subscribed users a weekend digest based on their age preference.

So in short: **Events Ostrava** is the system that gathers and enriches family events in Ostrava; **KidsEvents Ostrava** is the Telegram bot that gives parents a simple, multilingual way to see those events and optionally submit new ones or get weekly reminders.

---

## Features (technical)
- Scrape VisitOstrava, AllEvents, Kultura Jih, and Kudy z nudy event listings.
- Pluggable scraper architecture — add a new source by extending `AbstractScraper` and registering it in `config/scrapers.php`.
- Deduplicate and link duplicates.
- Hybrid enrichment (AI or rules fallback) with logs.
- Telegram bot commands for family events.

## Tech Stack
- Laravel (PHP 8.3)
- MySQL or SQLite
- Redis (queue/sessions if configured)
- Optional Docker + Nginx

## Docker (quick usage)

Start all services (app, nginx, db, redis, scheduler, queue, telegram-poll):
```bash
docker compose up -d
```

Start only specific services:
```bash
docker compose up -d app nginx db redis   # web only
docker compose up -d telegram-poll        # bot only
```

Enter the app container (pick one):
```bash
docker compose exec app bash
docker exec -it events_app bash
```

App URL (via Nginx): `http://localhost:8081`

### Local vs production (Docker)

The project uses a `docker-compose.override.yml` for local development. Docker Compose merges it automatically with `docker-compose.yml` when the file is present. It is gitignored and should **not** be copied to the VPS.

| | Local | VPS / Production |
|---|---|---|
| Xdebug | enabled via `docker-compose.override.yml` | off (file absent) |
| `restart: unless-stopped` | has no visible effect (you stop manually) | container auto-restarts on crash and after VPS reboot |
| `.env` | `APP_ENV=local`, `APP_DEBUG=true` | `APP_ENV=production`, `APP_DEBUG=false` |

**Local override file** (`docker-compose.override.yml`) — create once, never commit:
```yaml
services:
  telegram-poll:
    environment:
      XDEBUG_MODE: debug
      XDEBUG_CONFIG: client_host=host.docker.internal client_port=9003
      PHP_IDE_CONFIG: serverName=events-ostrava
```

A ready-made copy is included in the repo root as `docker-compose.override.yml` — it is gitignored so each developer or environment keeps their own.

## First Run (from zero)
```bash
cd /path/to/events_ostrava/events-ostrava
cp .env.example .env
composer install
php artisan key:generate
```

### .env setup
Set DB and bot/AI credentials in `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=city_events
DB_USERNAME=city_events
DB_PASSWORD=city_events

ENRICHMENT_OPENAI_API_KEY=
ENRICHMENT_OPENAI_MODEL=gpt-4o-mini
ENRICHMENT_MODE=hybrid
ENRICHMENT_AI_ENABLED=true

TELEGRAM_BOT_TOKEN=
```

### MySQL database setup

**Log in to the MySQL container** (when using Docker):

```bash
docker compose exec db mysql -u root -p
# Enter password: root (or the value of MYSQL_ROOT_PASSWORD from docker-compose)
```

Or by container name:

```bash
docker exec -it events_db mysql -u root -proot
```

Then run the following SQL to create the database and user (only if they don’t already exist; the compose env vars may have created them):

```sql
CREATE DATABASE IF NOT EXISTS city_events CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'city_events'@'%' IDENTIFIED BY 'city_events';
GRANT ALL PRIVILEGES ON city_events.* TO 'city_events'@'%';
FLUSH PRIVILEGES;
```

### Initialize app
```bash
php artisan migrate &&
php artisan events:scrape ostravainfo --days=30 &&
php artisan events:scrape visitostrava --days=14 &&
php artisan events:scrape allevents --days=60 &&
php artisan events:scrape kulturajih --days=30 &&
php artisan events:scrape kudyznudy --days=30 &&
php artisan events:enrich --limit=50 &&
php artisan queue:work 
php artisan telegram:poll
```

## Local Setup (no Docker)
```bash
cd /path/to/events_ostrava/events-ostrava
cp .env.example .env
composer install
php artisan key:generate
```

### Database (SQLite)
```bash
touch database/database.sqlite
```
Update `.env`:
```env
DB_CONNECTION=sqlite
```

Run migrations:
```bash
php artisan migrate
```

### Database (MySQL)
Update `.env` with your MySQL credentials, then:
```bash
php artisan migrate
```

## Run the App
```bash
php artisan serve
```
Default URL: `http://localhost:8000`

## Required .env keys
```env
# LLM enrichment
ENRICHMENT_OPENAI_API_KEY=
ENRICHMENT_OPENAI_MODEL=gpt-4o-mini
ENRICHMENT_MODE=hybrid      # ai|rules|hybrid
ENRICHMENT_AI_ENABLED=true  # true|false (used with hybrid)

# Telegram bot
TELEGRAM_BOT_TOKEN=
```

## Commands (custom)
### Scraping

Unified command — pass the source name as the first argument:
```bash
php artisan events:scrape ostravainfo --days=30   # Official source - run FIRST
php artisan events:scrape visitostrava --days=14
php artisan events:scrape allevents --days=60
php artisan events:scrape kulturajih --days=30
php artisan events:scrape kudyznudy --days=30
```

Legacy aliases still work:
```bash
php artisan events:scrape-visitostrava --days=14
php artisan events:scrape-allevents --days=60
```

### Enrichment (Queue)
Dispatch jobs:
```bash
php artisan events:enrich --limit=50
```

Run worker:
```bash
php artisan queue:work --tries=3 --timeout=120
```

### Telegram bot

**With Docker (recommended):**
```bash
# Start the bot container (auto-restarts on crash)
docker compose up -d telegram-poll

# Follow logs
docker compose logs -f telegram-poll

# Restart after a code change
docker compose build telegram-poll && docker compose up -d telegram-poll

# Verify the update offset is persisted in the cache
docker compose exec telegram-poll php artisan tinker --execute="echo Cache::get('telegram:last_update_id', 'NOT SET');"
```

**Without Docker (local/manual):**
```bash
php artisan telegram:poll
```

The polling offset (`last_update_id`) is stored in the database cache table (`CACHE_STORE=database`), so it survives container restarts and VPS reboots without replaying old messages.

Bot commands:
- `/today [0-3|3-6|6-10]`
- `/tomorrow [0-3|3-6|6-10]`
- `/weekend [0-3|3-6|6-10]`

### Cleanup (mark past events inactive)
```bash
php artisan events:deactivate-past --grace-hours=2
```

### Scheduler
```bash
php artisan schedule:work
```

## Deployment checklist (minimal)
- Set `.env` with `APP_ENV=production`, `APP_DEBUG=false`, DB credentials, OpenAI key (or `ENRICHMENT_MODE=rules`), and `TELEGRAM_BOT_TOKEN`.
- Do **not** copy `docker-compose.override.yml` to the VPS.
- Run `php artisan migrate`.
- Start all long-running services via Docker (they auto-restart on crash and VPS reboot):
  ```bash
  docker compose up -d queue scheduler telegram-poll
  ```
- The `telegram-poll` container uses `restart: unless-stopped` — no separate Supervisor or systemd unit needed.

## Data Model (events)
Fields:
`source`, `source_url`, `source_event_id`, `title`, `start_at`, `end_at`, `venue`, `location_name`, `address`,
`price_text`, `description`, `description_raw`, `summary`, `short_summary`, `age_min`, `age_max`, `tags`,
`kid_friendly`, `indoor_outdoor`, `category`, `language`, `needs_review`, `fingerprint`, `duplicate_of_event_id`,
`status`, `is_active`

## Notes
- The enrichment log table is `event_enrichment_logs`.
- Rules-based enrichment is the fallback when AI is disabled or fails.
