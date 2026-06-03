# incident-summary

`incident-summary` is a custom iTop extension that adds incident summary fields to Configuration Items (CIs).

The extension is implemented as a standalone iTop module and does not modify any iTop core files.

---

## Features

### Incident Summary on Configuration Items

The extension adds calculated incident summary fields to:

- `Server`
- `ApplicationSolution`

| Field | Type | Description |
|---|---|---|
| `open_incident_count` | `AttributeInteger` | Number of currently open incidents linked to the CI |
| `last_incident_date` | `AttributeDateTime` | Date of the most recent open incident linked to the CI |

The counter is automatically recalculated when:

- an incident is created
- an incident is updated
- an incident is resolved or closed
- an incident is deleted
- a CI is linked or unlinked from a ticket

An incident is considered open when its status is different from `resolved` or `closed`.

---

## Architecture

```text
iTop EventService
    |
    | DB lifecycle events
    v
IncidentSummaryExtension
    |
    | Dispatch object change
    v
IncidentSummaryHelper
    |
    | OQL queries
    v
Recalculate CI incident summary fields
```

---

## Project Structure

```text
incident-summary/
├── datamodel.incident-summary.xml
├── model.incident-summary.php
├── main.incident-summary.php
├── module.incident-summary.php
├── dictionaries/
│   ├── de.dict.incident-summary.php
│   ├── en.dict.incident-summary.php
│   └── fr.dict.incident-summary.php
└── README.md
```

| File | Description |
|---|---|
| `module.incident-summary.php` | iTop module declaration |
| `datamodel.incident-summary.xml` | Datamodel extensions for `Server` and `ApplicationSolution` |
| `model.incident-summary.php` | iTop datamodel placeholder |
| `main.incident-summary.php` | Business logic and lifecycle hooks |
| `dictionaries/` | Translations (DE / EN / FR) |
| `README.md` | Project documentation |

---

## Requirements

- iTop 3.x
- Apache 2.4+
- PHP 8.x
- MySQL / MariaDB
- iTop modules:
  - `itop-config-mgmt`
  - `itop-incident-mgmt-itil`

---

## Installation

### 1. Copy the extension

```bash
cp -r incident-summary /var/www/html/itop/extensions/
```

### 2. Run the iTop setup wizard

```bash
chmod 664 /var/www/html/itop/conf/production/config-itop.php
```

Open `http://localhost/itop/setup`, choose **Upgrade an existing iTop instance** and select **Incident Summary**.

---

## How it works

### Event-driven recalculation

The module uses iTop EventService:

- Incident changes → update all linked CIs
- CI-ticket link changes → update specific CI

### OQL logic

Linked CIs for incident:

```sql
SELECT FunctionalCI
JOIN lnkFunctionalCIToTicket AS l ON l.functionalci_id = FunctionalCI.id
WHERE l.ticket_id = :ticket_id
```

Open incidents per CI:

```sql
SELECT Incident AS i
JOIN lnkFunctionalCIToTicket AS l ON l.ticket_id = i.id
WHERE l.functionalci_id = :ci_id
AND i.status != 'resolved'
AND i.status != 'closed'
```

---

## UI Feature

When `open_incident_count` is greater than zero, the value is highlighted in red in the iTop back-office.

---

## Testing

```bash
php -l module.incident-summary.php
php -l model.incident-summary.php
php -l main.incident-summary.php
xmllint --noout datamodel.incident-summary.xml
```

---

## Security

- No external services
- No API keys
- No webhooks
- No AI integration
- Fully offline iTop extension

---

## Design Decisions

| Choice | Reason |
|---|---|
| EventService hooks | Modern iTop 3.x standard |
| OQL queries | iTop-native data access |
| No external services | Simplicity and stability |
| Minimal CI updates | Performance optimization |

---

## Author

Firas Landoulsi  
B.Sc. Computer Science — TU Dortmund