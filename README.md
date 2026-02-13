# Resume Parser System

This system connects to an IMAP mailbox, downloads valid resumes (PDF/DOCX), parses them for candidate details using regex/heuristics, and stores them in a database.

## 1. Configuration (.env)

Open the `.env` file in the root directory and configure the following:

### Database
Ensure you have a MySQL database created (default name: `resume_parser_db`). Uses `root` user with no password by default.
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=resume_parser_db
DB_USERNAME=root
DB_PASSWORD=
```

### Email / IMAP
Configure the email account to fetch resumes from:
```env
IMAP_HOST=imap.gmail.com
IMAP_PORT=993
IMAP_ENCRYPTION=ssl
IMAP_VALIDATE_CERT=true
IMAP_USERNAME=your_email
IMAP_PASSWORD=your_actual_password_here
IMAP_DEFAULT_ACCOUNT=default
IMAP_PROTOCOL=imap
```
*Note: If using Gmail, you likely need an "App Password" instead of your regular password.*

## 2. Setup

Run the migrations to create the `candidates` table:
```bash
php artisan migrate
```

## 3. Running the Parser

To manually process resumes from the inbox:
```bash
php artisan process:resumes
```

This will:
1. Connect to the email.
2. Download attachments from unread emails.
3. Parse the resume.
4. Save the candidate info to the database.
5. Mark the email as read.

## 4. Checking Data

### Database
The data is stored in the `candidates` table. You can use any SQL client (like phpMyAdmin, TablePlus, DBeaver) to view it.

Query:
```sql
SELECT * FROM candidates;
```

### Logs
Check `storage/logs/resume_processing.log` to see what the system is doing (e.g., "Found 5 emails", "Processed candidate John Doe").

## 5. Automation (Cron)
See `CRON_SETUP.md` for instructions on how to run this automatically every 5 minutes.
