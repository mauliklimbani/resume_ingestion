<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webklex\IMAP\Facades\Client;
use App\Services\ResumeParserService;
use App\Models\Candidate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessResumes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process:resumes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch unread emails from IMAP, download resumes, and parse them.';

    protected $parser;

    public function __construct(ResumeParserService $parser)
    {
        parent::__construct();
        $this->parser = $parser;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Custom logger for resume processing
        $log = Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/resume_processing.log'),
        ]);

        $log->info("Starting resume processing job...");
        $this->info("Starting resume processing job...");

        try {
            $client = Client::account('default'); // defined in config/imap.php
            $client->connect();

            // Get INBOX folder
            $folder = $client->getFolder('INBOX');

            // Fetch unread messages
            // Assuming we only want messages with attachments, but query capabilities vary.
            // We'll filter in PHP.
            $messages = $folder->query()->unseen()->get();

            $log->info("Found " . $messages->count() . " unread messages.");
            $this->info("Found " . $messages->count() . " unread messages.");

            foreach ($messages as $message) {
                try {
                    $subject = $message->getSubject();
                    $log->info("Processing email UID: " . $message->getUid() . " | Subject: " . $subject);

                    // Debugging attachments
                    $totalAttachments = $message->getAttachments()->count();
                    $log->info("Attachment count for UID " . $message->getUid() . ": " . $totalAttachments);

                    // Check for attachments
                    if (!$message->hasAttachments()) {
                        $log->info("Skipping email " . $message->getUid() . " - No attachments.");
                        // Optional: Mark as read even if no attachments? 
                        // Requirement says "process only emails that contain attachments".
                        // If we don't mark as read, we'll fetch it again forever.
                        // Better to mark as read to avoid loop.
                        $message->setFlag(['Seen']);
                        continue;
                    }

                    // Check if email already processed (duplicate check)
                    $emailAddress = $message->getFrom()[0]->mail; // Accessing first sender

                    // Requirement: "Duplicate emails are not processed again".
                    // We check if a candidate with this email exists.
                    // Note: This check happens before parsing. But maybe the candidate applied earlier?
                    // If we skip here, we might miss updates.
                    // But requirement says "Duplicate emails are not processed again (store email UID)".
                    // If we store UID, we can check that.
                    // Let's rely on Candidate email uniqueness for now as per previous thought,
                    // or check DB.
                    if (Candidate::where('email', $emailAddress)->exists()) {
                        $log->info("Skipping email " . $message->getUid() . " - Candidate with email $emailAddress already exists.");
                        $message->setFlag(['Seen']);
                        continue;
                    }

                    $attachments = $message->getAttachments();
                    $processed = false;

                    foreach ($attachments as $attachment) {
                        $filename = $attachment->getName();
                        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                        if (!in_array($extension, ['pdf', 'docx', 'doc'])) {
                            continue; // Skip unsupported files
                        }

                        // Generate unique filename: <candidateID>_<originalFileName>
                        // Wait, we don't have candidate ID yet until we insert.
                        // We can use a temporary ID or UUID, or insert first.
                        // But we need to save file first to parse? NO, we can parse content stream?
                        // Requirements: "Save original file... Rename: <candidateID>_<originalFileName>".
                        // This implies we insert AFTER saving? OR update filename after insert?
                        // Let's use Timestamp + Random or UUID for storage first, then rename or just use that.
                        // Actually, if we need ID, we must insert first. But we don't have data yet.
                        // So: 
                        // 1. Save with temp name.
                        // 2. Parse.
                        // 3. Insert Candidate (get ID).
                        // 4. Rename file with ID.
                        // 5. Update Candidate record with new path.

                        // Step 1: Save temporarily
                        $tempName = Str::random(10) . '_' . $filename;
                        $tempPath = 'server/resumes/' . $tempName;
                        Storage::disk('local')->put($tempPath, $attachment->getContent());

                        $absPath = Storage::disk('local')->path($tempPath);

                        // Step 2: Parse
                        $text = $this->parser->extractText($absPath, $extension);
                        if (empty($text)) {
                            $log->warning("Could not extract text from file: $filename");
                            // Don't delete, maybe manual review needed.
                            continue;
                        }

                        $data = $this->parser->parseData($text);

                        // Fallback for fields if missing
                        if (empty($data['email']))
                            $data['email'] = $emailAddress;

                        // Step 3: Insert
                        try {
                            $candidate = Candidate::create([
                                'full_name' => $data['full_name'] ?? 'Unknown',
                                'email' => $data['email'],
                                'mobile' => $data['mobile'],
                                'education' => $data['education'],
                                'current_location' => $data['current_location'],
                                'salary' => $data['salary'],
                                'preferred_location' => $data['preferred_location'],
                                'resume_file_name' => $filename,
                                'stored_file_path' => $tempPath, // Will update
                                'email_uid' => $message->getUid(),
                            ]);

                            // Step 4: Rename
                            $newFilename = $candidate->id . '_' . $filename;
                            $newPath = 'server/resumes/' . $newFilename;

                            if (Storage::disk('local')->move($tempPath, $newPath)) {
                                $candidate->update(['stored_file_path' => $newPath]);
                            }

                            $processed = true;
                            $log->info("Processed candidate: " . $candidate->full_name . " (ID: " . $candidate->id . ")");

                        } catch (\Exception $e) {
                            $log->error("Database insert failed for $emailAddress: " . $e->getMessage());
                            // Start processing cleanup if needed
                        }
                    }

                    if ($processed) {
                        $message->setFlag(['Seen']); // Mark as read
                        $log->info("Successfully processed email " . $message->getUid());
                    } else {
                        // If no valid attachments found or processing failed
                        $log->warning("No valid resume extracted from email " . $message->getUid());
                        // Mark read to avoid loop? Yes.
                        $message->setFlag(['Seen']);
                    }

                } catch (\Exception $e) {
                    $log->error("Error processing message " . $message->getUid() . ": " . $e->getMessage());
                    // Continue loop
                }
            }

        } catch (\Exception $e) {
            $log->critical("CRITICAL: Resume processing failed: " . $e->getMessage());
            $this->error("Critical error: " . $e->getMessage());
        }
    }
}
