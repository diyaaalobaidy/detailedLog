# Detailed Activity Log Plugin for OJS 3.5

An advanced audit trail and activity log enhancement plugin for **Open Journal Systems (OJS) 3.5**.

---

## 🎯 Overview

In standard OJS 3.5, the submission Activity Log grid only displays three basic columns:
1. **Date**
2. **User**
3. **Event** (a generic translated string such as *"File uploaded"* or *"Metadata updated"*)

Under the hood, OJS stores extensive metadata in the `event_log_settings` table, including:
- Specific file details (`filename`, `fileId`, `submissionFileId`, `fileStage`, `sourceSubmissionFileId`)
- Editorial decisions (`decision`, `editorName`, `editorId`)
- Peer review information (`reviewerName`, `reviewAssignmentId`, `round`, `reviewDueDate`)
- Participant changes (`userFullName`, `username`, `userGroupName`)
- Email communications (`subject`, `senderName`, `recipientName`, `recipientCount`)

In the default OJS interface, **none** of these critical audit details are visible to editors, managers, or auditors. Furthermore, standard OJS only queries events with `assoc_type = ASSOC_TYPE_SUBMISSION`, omitting events logged directly for files (`assoc_type = ASSOC_TYPE_SUBMISSION_FILE`) even when they belong to that submission.

The **Detailed Activity Log Plugin** completely resolves these limitations by transforming the Activity Log into a comprehensive, high-resolution audit trail.

---

## ✨ Features

- **Full Settings Decoding**: Directly reads and interprets every parameter stored in `event_log_settings`.
- **Enhanced Grid Display**:
  - **Date & Time**: Exact timestamp with date and time formatting.
  - **User & Role**: Full user name, `@username`, and styled role badge (e.g., *Journal editor*, *Author*, *Reviewer*).
  - **Categorized Events**: Colored badges and icons for *Editorial Decisions* (⚖️), *Peer Reviews* (🔍), *Files* (📁), *Participants* (👥), *Publications* (📢), and *Emails* (✉️).
  - **Workflow Stages**: Stage indicators (*Submission*, *External Review Round 1*, *Copyediting*, *Production*).
  - **Activity Details Column**: Live summary of filenames, file stages, decision types, reviewer names, review rounds, and assigned roles, plus a count badge of all settings recorded.
- **Interactive "View Details" Modal**:
  - Click the **View Details** button on any activity entry to open a structured breakdown.
  - Dedicated cards for File Details, Editorial Decisions, Peer Review, Participants, and Communication.
  - Complete `event_log_settings` table displaying every raw setting name, locale, raw value, and human-friendly interpretation.
  - Expandable raw JSON view for technical system audits.
- **Comprehensive Event Capture**:
  - Captures direct submission events.
  - Captures file-level events (`assoc_type = 515`) whose files belong to the submission or are linked via `submissionId` in `event_log_settings`.
  - Captures email and notification logs.
- **Audit Exports**:
  - 📥 **Export to CSV**: Download the full audit trail with all metadata and decoded `event_log_settings` into an Excel-compatible CSV file.
  - 📥 **Export to JSON**: Download the complete structured JSON payload.
- **Filtering & Search**:
  - Filter by event category (All, Decisions, Reviews, Files, Participants, Publications, Emails, Metadata).
  - Instant search across usernames, filenames, decision texts, and actions.
- **Anonymity & Security**:
  - Automatically respects blind and double-blind peer review rules: reviewer identities and reviewer files are masked if an author views the log, while editors and administrators retain full visibility.
- **Bilingual (English & Arabic)**:
  - Full gettext `.po` translations for both English and Arabic.

---

## 🗄️ Database Reference (`event_log` & `event_log_settings`)

| Column in `event_log_settings` | Type | Description |
|-------------------------------|------|-------------|
| `event_log_setting_id` | `bigint unsigned` | Primary key |
| `log_id` | `bigint` | References `event_log.log_id` |
| `locale` | `varchar(28)` | Locale code (e.g., `en`, `ar`, or empty) |
| `setting_name` | `varchar(255)` | Name of the parameter (e.g., `filename`, `fileStage`, `decision`, `reviewerName`, `userGroupName`) |
| `setting_value` | `mediumtext` | Serialized or string value of the parameter |

---

## 🚀 Installation

1. Copy or symlink this plugin directory into your OJS installation at:
   ```bash
   plugins/generic/detailedLog
   ```
2. Enable the plugin via **Settings > Website > Plugins > Generic Plugins > Detailed Activity Log Plugin**, or via SQL:
   ```sql
   INSERT INTO plugin_settings (plugin_name, context_id, setting_name, setting_value, setting_type)
   VALUES ('detailedlogplugin', 1, 'enabled', '1', 'bool')
   ON DUPLICATE KEY UPDATE setting_value = '1';
   ```
3. Open any article's **Activity Log** (via the Submissions list or submission information center) to view the full detailed activity log.

---

## 📄 License

Distributed under the **GNU General Public License v3 (GPLv3)**.
