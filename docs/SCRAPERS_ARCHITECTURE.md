# Scrapers Architecture Schema

## Overview

The scrapers system collects events from multiple external sources, normalizes them into `EventData` DTOs, and upserts them into the database with duplicate detection and optional AI enrichment.

---

## Component Diagram

```mermaid
flowchart TB
    subgraph Entry["Entry Point"]
        CMD["ScrapeEvents Command - events:scrape source"]
        CFG["config/scrapers.php - source to class"]
    end

    subgraph Interface["Contract"]
        IF["ScraperInterface - run days int"]
    end

    subgraph Scrapers["Concrete Scrapers"]
        AS[AbstractScraper]
        OI[OstravaInfoScraper]
        VO[VisitOstravaScraper]
        AE[AllEventsScraper]
        KJ[KulturaJihScraper]
        KZ[KudyZNudyScraper]
    end

    subgraph Services["Services"]
        EU[EventUpsertService]
        DR[DuplicateResolver]
    end

    subgraph Security["Security"]
        US[UrlSafety<br/>static utility]
    end

    subgraph Data["Data Layer"]
        ED[EventData DTO]
        EV[(Event model)]
        EEJ[EnrichEventJob]
    end

    subgraph External["External"]
        HTTP[HTTP / DOM Crawler]
    end

    CMD --> CFG
    CFG -->|resolve class| AS
    AS -.->|implements| IF
    OI --> AS
    VO --> AS
    AE --> AS
    KJ --> AS
    KZ --> AS

    AS -->|depends on| EU
    AS -->|uses| US
    AS -->|produces| ED
    AS -->|fetches via| HTTP

    EU -->|depends on| DR
    EU -->|upserts| EV
    EU -->|dispatches| EEJ
    EU -->|consumes| ED

    DR -->|queries| EV
```

---

## Class Hierarchy

```mermaid
classDiagram
    class ScraperInterface {
        <<interface>>
        +run(days: int): int
    }

    class AbstractScraper {
        <<abstract>>
        #upsertService: EventUpsertService
        #source(): string
        #allowedHosts(): string[]
        #fetchListingUrls(): string[]
        #parseDetailPage(crawler, url): EventData?
        #requestDelayUs(): int
        +run(days): int
        #fetchPage(url): string?
        #toAbsoluteUrl(href, baseHref): string?
        #parseCzechDateTime(text): Carbon?
        #parseCzechDateTimeDotted(text): Carbon?
        #normalizeWhitespace(text): string
    }

    class OstravaInfoScraper
    class VisitOstravaScraper
    class AllEventsScraper
    class KulturaJihScraper
    class KudyZNudyScraper

    ScraperInterface <|.. AbstractScraper
    AbstractScraper <|-- OstravaInfoScraper
    AbstractScraper <|-- VisitOstravaScraper
    AbstractScraper <|-- AllEventsScraper
    AbstractScraper <|-- KulturaJihScraper
    AbstractScraper <|-- KudyZNudyScraper

    AbstractScraper --> EventUpsertService : uses
    AbstractScraper ..> UrlSafety : static
    AbstractScraper ..> EventData : produces
```

---

## Data Flow

```mermaid
sequenceDiagram
    participant CMD as ScrapeEvents
    participant CFG as config/scrapers
    participant SCR as Concrete Scraper
    participant EU as EventUpsertService
    participant DR as DuplicateResolver
    participant DB as Event (DB)
    participant JOB as EnrichEventJob

    CMD->>CFG: resolve source → class
    CMD->>SCR: run(days)
    loop For each listing URL
        SCR->>SCR: fetchPage (UrlSafety check)
        SCR->>SCR: parseDetailPage → EventData
        SCR->>SCR: date filter (now .. now+days)
        SCR->>EU: upsert(EventData)
        EU->>DR: fingerprint / findDuplicateCandidate
        DR->>DB: query existing events
        alt Same source_event_id exists
            EU->>DB: update event
        else Fingerprint match
            EU->>DB: create as duplicate_of
        else Fuzzy match found
            EU->>DB: create with duplicate_of_event_id
        else New event
            EU->>DB: create event
            EU->>JOB: dispatch EnrichEventJob
        end
    end
    SCR-->>CMD: return upserted count
```

---

## Source Configuration

| Source        | Class                 | Default Days | Priority | Schedule              |
|---------------|-----------------------|--------------|----------|-----------------------|
| ostravainfo   | OstravaInfoScraper    | 30           | 1        | Manual (run first)    |
| visitostrava  | VisitOstravaScraper   | 14           | 2        | 06:00, 18:00          |
| allevents     | AllEventsScraper      | 60           | 2        | 07:00, 19:00          |
| kulturajih    | KulturaJihScraper     | 30           | 2        | 08:00, 20:00          |
| kudyznudy     | KudyZNudyScraper      | 30           | 2        | 09:00, 21:00          |

---

## Key Dependencies

| Component        | Depends On                          |
|------------------|-------------------------------------|
| AbstractScraper  | EventUpsertService, UrlSafety       |
| EventUpsertService | DuplicateResolver, Event, EnrichEventJob |
| DuplicateResolver | Event (Eloquent)                   |
| ScrapeEvents     | config/scrapers.php, Laravel container |

---

## File Structure

```
app/
├── Console/Commands/
│   ├── ScrapeEvents.php          # Main entry: events:scrape {source}
│   ├── ScrapeVisitOstrava.php    # Alias → events:scrape visitostrava
│   └── ScrapeAllEvents.php       # Alias → events:scrape allevents
├── DTO/
│   └── EventData.php             # Normalized event payload
├── Services/
│   ├── Scrapers/
│   │   ├── Contracts/
│   │   │   └── ScraperInterface.php
│   │   ├── AbstractScraper.php
│   │   ├── OstravaInfoScraper.php
│   │   ├── VisitOstravaScraper.php
│   │   ├── AllEventsScraper.php
│   │   ├── KulturaJihScraper.php
│   │   ├── KudyZNudyScraper.php
│   │   ├── EventUpsertService.php
│   │   └── DuplicateResolver.php
│   └── Security/
│       └── UrlSafety.php
config/
└── scrapers.php                  # Source → class, days, priority
```
