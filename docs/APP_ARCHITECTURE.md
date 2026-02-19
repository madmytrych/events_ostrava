# Events Ostrava – App Architecture

High-level diagram of console commands, scheduled tasks, jobs, and main services.

## Architecture diagram (Mermaid)

```mermaid
flowchart TB
    subgraph Scheduler["⏰ Laravel Scheduler (Kernel)"]
        S1["events:scrape-visitostrava<br/><i>twice daily 06:00, 18:00</i>"]
        S2["events:scrape-allevents<br/><i>twice daily 07:00, 19:00</i>"]
        S3["events:enrich --limit=50<br/><i>every 30 min</i>"]
        S4["events:deactivate-past --grace-hours=2<br/><i>hourly</i>"]
        S5["telegram:notify<br/><i>weekly Fri 08:00</i>"]
    end

    subgraph Commands["Console commands"]
        C1[ScrapeVisitOstrava]
        C2[ScrapeAllEvents]
        C3[EnrichEvents]
        C4[DeactivatePastEvents]
        C5[TelegramNotify]
        C6[TelegramPoll<br/><i>long-running, not scheduled</i>]
    end

    subgraph Jobs["Queued jobs"]
        J1[EnrichEventJob]
    end

    subgraph Scrapers["Scrapers"]
        VisitOstrava[VisitOstravaScraper]
        AllEvents[AllEventsScraper]
    end

    subgraph Enrichment["Enrichment"]
        EnrichmentSvc[EnrichmentService]
        AiProvider[AiEnrichmentProvider]
        RulesProvider[RulesEnrichmentProvider]
    end

    subgraph Telegram["Telegram bot services"]
        BotSvc[TelegramBotService]
        QuerySvc[EventQueryService]
        Formatter[TelegramEventFormatter]
        Keyboards[TelegramKeyboardService]
        Texts[TelegramTextService]
        Submissions[TelegramSubmissionService]
    end

    subgraph Data["Models / DB"]
        Event[(Event)]
        TelegramUser[(TelegramUser)]
    end

    S1 --> C1
    S2 --> C2
    S3 --> C3
    S4 --> C4
    S5 --> C5

    C1 --> VisitOstrava
    C2 --> AllEvents
    VisitOstrava --> Event
    AllEvents --> Event

    C3 --> Event
    C3 -->|"dispatch per event"| J1
    J1 --> Event
    J1 --> EnrichmentSvc
    EnrichmentSvc --> AiProvider
    EnrichmentSvc --> RulesProvider
    AiProvider --> Event
    RulesProvider --> Event

    C4 --> Event

    C5 --> TelegramUser
    C5 --> QuerySvc
    C5 --> Formatter
    C5 --> BotSvc
    QuerySvc --> Event
    BotSvc -->|"send message"| API[Telegram API]

    C6 --> BotSvc
    C6 --> QuerySvc
    C6 --> Keyboards
    C6 --> Texts
    C6 --> Formatter
    C6 --> Submissions
    C6 --> TelegramUser
    QuerySvc --> Event
    BotSvc --> API
```

## Console commands summary

| Command | Schedule | Description |
|--------|----------|-------------|
| `events:scrape-visitostrava` | Twice daily (06:00, 18:00) | Scrapes VisitOstrava family events, upserts into DB (default 14 days). |
| `events:scrape-allevents` | Twice daily (07:00, 19:00) | Scrapes kids events from AllEvents.in (default 60 days). |
| `events:enrich` | Every 30 min | Finds active events without `short_summary`, dispatches one `EnrichEventJob` per event (default limit 50). |
| `events:deactivate-past` | Hourly | Marks past events as inactive (default grace 2 hours). |
| `telegram:notify` | Weekly Friday 08:00 | Sends weekly digest of weekend events to users with `notify_enabled`. |
| `telegram:poll` | **Not scheduled** | Long-running: polls Telegram API, handles /start, /today, /week, settings, event submission, etc. Run via supervisor or manually. |

## Jobs

| Job | Dispatched by | Purpose |
|-----|----------------|--------|
| `EnrichEventJob` | `events:enrich` command | Queued per event; runs AI/rules enrichment (short_summary, etc.) via `EnrichmentService` and its providers. |

## Data flow (simplified)

1. **Ingest**: Scrapers → `Event` (VisitOstrava, AllEvents).
2. **Enrich**: `events:enrich` → queue `EnrichEventJob` → update `Event` (short_summary, enriched_at, etc.).
3. **Lifecycle**: `events:deactivate-past` → set `is_active = false` for past events.
4. **Telegram**: `telegram:poll` serves users; `telegram:notify` sends weekly digests. Both use `Event` (via `EventQueryService`) and `TelegramUser`.
