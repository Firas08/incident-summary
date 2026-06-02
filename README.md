# incident-summary

`incident-summary` is a custom iTop extension that adds incident summary fields to Configuration Items.

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

- an incident is created;
- an incident is updated;
- an incident is resolved or closed;
- an incident is deleted;
- a CI is linked to or unlinked from a ticket.

An incident is considered open when its status is different from `resolved` or `closed`.

### Visual Highlighting

When `open_incident_count` is greater than zero, the value is highlighted in red in the iTop back-office interface.

---

## Architecture

```text
iTop Extension
    |
    | EventService lifecycle hooks
    v
IncidentSummaryHelper
    |
    | OQL queries
    v
Update CI summary fields
```

---

## Project Structure

```text
incident-summary/
├── datamodel.incident-summary.xml
├── dictionaries/
│   ├── de.dict.incident-summary.php
│   ├── en.dict.incident-summary.php
│   └── fr.dict.incident-summary.php
├── main.incident-summary.php
├── model.incident-summary.php
├── module.incident-summary.php
└── README.md
```

| File | Description |
|---|---|
| `module.incident-summary.php` | iTop module declaration |
| `datamodel.incident-summary.xml` | Datamodel extensions for `Server` and `ApplicationSolution` |
| `main.incident-summary.php` | Business logic and lifecycle hooks |
| `model.incident-summary.php` | iTop datamodel placeholder |
| `dictionaries/` | Translation files for DE, EN, FR |
| `README.md` | Project documentation |

---

## Requirements

- iTop 3.x
- Apache 2.4+
- PHP 8.x
- MySQL or MariaDB
- iTop modules:
  - `itop-config-mgmt`
  - `itop-incident-mgmt-itil`

---

## Installation

### 1. Copy the Extension

```bash
cp -r incident-summary /var/www/html/itop/extensions/
```

### 2. Run the iTop Setup Wizard

```bash
sudo chmod 664 /var/www/html/itop/conf/production/config-itop.php
```

Open `http://localhost/itop/setup`, choose **Upgrade an existing iTop instance** and select **Incident Summary**.

---

## Testing

```bash
php -l main.incident-summary.php
php -l module.incident-summary.php
xmllint --noout datamodel.incident-summary.xml
```

---

## Author

Firas Landoulsi  

B.Sc. Computer Science — TU Dortmund