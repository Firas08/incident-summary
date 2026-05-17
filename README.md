# incident-summary

`incident-summary` is a custom iTop extension that adds incident summary fields to Configuration Items and integrates optional AI-powered incident analysis through n8n and Groq.

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

An incident is considered open when its status is different from:

- `resolved`
- `closed`

### AI Incident Analysis

The extension also adds one field to the `Incident` class:

| Field | Type | Description |
|---|---|---|
| `ai_analysis` | `AttributeText` | Full AI-generated incident analysis returned by n8n |

When an incident is created or updated, iTop sends the incident ID to an n8n webhook.  
The n8n workflow retrieves the incident details through the iTop REST API, sends the context to Groq, and writes the generated analysis back into the `ai_analysis` field.

### Visual Highlighting

When `open_incident_count` is greater than zero, the value is highlighted in red in the iTop back-office interface.

---

## Architecture

```text
iTop Extension
    |
    | Object lifecycle hooks
    v
IncidentSummaryHelper
    |
    | OQL queries
    v
Update CI summary fields
    |
    | HTTP POST incident_id
    v
n8n Webhook
    |
    | iTop REST API - GET Incident
    v
Groq LLM API
    |
    | AI response
    v
n8n
    |
    | iTop REST API - UPDATE Incident
    v
Incident.ai_analysis
```

---

## Project Structure

```text
incident-summary/
├── datamodel.incident-summary.xml
├── main.incident-summary.php
├── model.incident-summary.php
├── module.incident-summary.php
├── itop-incident-ai.json
└── README.md
```

| File | Description |
|---|---|
| `module.incident-summary.php` | iTop module declaration |
| `datamodel.incident-summary.xml` | Datamodel extensions for `Server`, `ApplicationSolution`, and `Incident` |
| `main.incident-summary.php` | Business logic for recalculation and n8n notification |
| `model.incident-summary.php` | iTop lifecycle hooks and UI highlighting |
| `itop-incident-ai.json` | Exported n8n workflow |
| `README.md` | Project documentation |

---

## Requirements

### iTop

- iTop 3.x
- Apache 2.4+
- PHP 8.x
- MySQL or MariaDB
- iTop modules:
  - `itop-config-mgmt`
  - `itop-incident-mgmt-itil`

### AI Integration

- n8n
- Groq API key
- iTop REST API user with sufficient permissions

---

## Installation

### 1. Copy the Extension

Copy the module into the iTop `extensions` directory:

```bash
cp -r incident-summary /var/www/html/itop/extensions/
```

Expected path:

```text
/var/www/html/itop/extensions/incident-summary
```

---

### 2. Run the iTop Setup Wizard

Make the iTop configuration file writable:

```bash
sudo chmod 664 /var/www/html/itop/conf/production/config-itop.php
```

Open the setup wizard:

```text
http://localhost/itop/setup
```

Choose:

```text
Upgrade an existing iTop instance
```

Select the module:

```text
Incident Summary
```

Complete the setup wizard.

After the setup is complete:

```bash
sudo chmod 444 /var/www/html/itop/conf/production/config-itop.php
sudo rm -rf /var/www/html/itop/data/cache/*
sudo service apache2 restart
```

---

## Configuration

### n8n Webhook URL

The webhook URL is configured in `main.incident-summary.php`:

```php
$sUrl = 'http://YOUR_N8N_HOST:5678/webhook/itop-incident-ai';
```

If iTop runs in WSL and n8n runs on Windows, do not use `localhost`.

Find the Windows host IP from WSL:

```bash
cat /etc/resolv.conf | grep nameserver
```

Example:

```text
nameserver 172.19.240.1
```

Then configure:

```php
$sUrl = 'http://172.19.240.1:5678/webhook/itop-incident-ai';
```

After changing the PHP file, run the iTop setup wizard again in upgrade mode.

---

### n8n Workflow

Import the exported workflow into n8n:

```text
itop-incident-ai.json
```

In n8n:

1. Open n8n.
2. Import the workflow from `itop-incident-ai.json`.
3. Configure the iTop credentials.
4. Configure the Groq API key.
5. Publish the workflow.

Production webhook URL:

```text
http://localhost:5678/webhook/itop-incident-ai
```

Test webhook URL:

```text
http://localhost:5678/webhook-test/itop-incident-ai
```

The PHP integration must use the production URL.

---

## Security

Do not commit secrets to the repository.

The following values must not be stored in source code:

- Groq API keys;
- iTop passwords;
- n8n credentials;
- access tokens.

Configure credentials directly in n8n or through environment variables.

---

## How It Works

### Lifecycle Hooks

The extension uses iTop object lifecycle hooks:

```php
OnDBInsert($oObject)
OnDBUpdate($oObject)
OnDBDelete($oObject)
```

Each hook calls:

```php
IncidentSummaryHelper::HandleObjectChange($oObject)
```

The helper dispatches the logic based on the object class.

---

### Triggered Objects

| Object | Action |
|---|---|
| `Incident` | Recalculate linked CIs and notify n8n |
| `lnkFunctionalCIToTicket` | Recalculate the linked CI |

---

### OQL Queries

Find all CIs linked to an incident:

```sql
SELECT FunctionalCI AS ci
JOIN lnkFunctionalCIToTicket AS l ON l.functionalci_id = ci.id
WHERE l.ticket_id = :ticket_id
```

Count open incidents linked to a CI:

```sql
SELECT Incident AS i
JOIN lnkFunctionalCIToTicket AS l ON l.ticket_id = i.id
WHERE l.functionalci_id = :ci_id
AND i.status != 'resolved'
AND i.status != 'closed'
```

---

## iTop REST API Usage

The n8n workflow uses the iTop REST API endpoint:

```text
POST /itop/webservices/rest.php?version=1.3
```

### Read Incident

```json
{
  "operation": "core/get",
  "class": "Incident",
  "key": "SELECT Incident WHERE id = 1",
  "output_fields": "id,ref,title,description,status,start_date"
}
```

### Update Incident

```json
{
  "operation": "core/update",
  "class": "Incident",
  "key": "SELECT Incident WHERE id = 1",
  "fields": {
    "ai_analysis": "AI-generated analysis"
  },
  "comment": "AI analysis generated automatically by n8n"
}
```

---

## Testing

### Verify PHP Syntax

```bash
cd /var/www/html/itop/extensions/incident-summary

php -l module.incident-summary.php
php -l model.incident-summary.php
php -l main.incident-summary.php
```

### Verify XML Syntax

```bash
xmllint --noout datamodel.incident-summary.xml
```

### Verify Database Fields

```bash
sudo mysql itop -e "SHOW COLUMNS FROM server LIKE 'open_incident_count';"
sudo mysql itop -e "SHOW COLUMNS FROM server LIKE 'last_incident_date';"
sudo mysql itop -e "SHOW COLUMNS FROM ticket LIKE 'ai_analysis';"
```

### Test Incident Counter

1. Open a `Server`.
2. Create or update an incident.
3. Link the incident to the server.
4. Verify that `open_incident_count` increases.
5. Resolve the incident.
6. Verify that `open_incident_count` decreases.

Example SQL check:

```bash
sudo mysql itop -e "SELECT id, name, open_incident_count, last_incident_date FROM server WHERE name = 'Server1';"
```

### Test AI Analysis

1. Publish the n8n workflow.
2. Create or update an incident in iTop.
3. Verify that n8n receives the webhook call.
4. Verify that the workflow completes successfully.
5. Refresh the incident page in iTop.
6. Verify that `ai_analysis` is populated.

---

## Design Decisions

| Decision | Rationale |
|---|---|
| Standalone iTop module | Keeps the extension upgrade-safe |
| No core modification | Preserves iTop maintainability |
| `ApplicationSolution` as second class | Applications can also be impacted by incidents |
| OQL instead of raw SQL | Uses iTop’s data model abstraction |
| Single `ai_analysis` field | Keeps the incident description unchanged |
| n8n orchestration | Provides a visual and maintainable workflow |
| Groq LLM API | Provides fast AI inference |
| Short HTTP timeout | Prevents iTop from being blocked if n8n is unavailable |

---

## Known Limitations

- The n8n webhook URL must be adapted to the local environment.
- If n8n updates the same incident, the iTop hook can trigger another webhook call.
- In a production setup, loop prevention should be added.
- Secrets must be managed outside the repository.

---

## Future Improvements

- Add loop prevention when `ai_analysis` is updated by n8n.
- Move the webhook URL to a configurable module setting.
- Add a dedicated AI analysis timestamp field.
- Add better error logging for failed n8n calls.
- Add unit or integration tests for the recalculation logic.

---

## Author

Firas Landoulsi  
B.Sc. Computer Science — TU Dortmund