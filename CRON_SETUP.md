# Cron Setup Instructions for Resume Parser System

To ensure `process:resumes` runs automatically, you need to add a single Cron entry to your server.

### 1. Locate PHP Path
Run `which php` to verify the path to your PHP binary (e.g., `/usr/bin/php`).

### 2. Locate Project Path
Your project is located at: `d:/Projects/Laravel/resume` (adjust for Linux path if deploying to prod, e.g., `/var/www/resume`).

### 3. Add Cron Job
Edit your crontab:
```bash
crontab -e
```

Add the following line to run the Laravel scheduler every minute:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Replace `/path-to-your-project` with the absolute path to the project root.

### Windows Task Scheduler (Local Development)
If you are running on Windows locally, you can use Task Scheduler instead of Cron:

1. Create a batch file `scheduler.bat`:
   ```bat
   cd d:\Projects\Laravel\resume
   php artisan schedule:run
   ```
2. Open Task Scheduler and create a Basic Task.
3. Set trigger to "Daily" -> "Repeat task every 5 minutes" for a duration of "Indefinitely".
4. Set action to "Start a program" -> select `scheduler.bat`.

### Verification
Once setup, check `storage/logs/resume_processing.log` to see the output of the process.
