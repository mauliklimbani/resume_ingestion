<?php

namespace App\Services;

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory;
use Illuminate\Support\Facades\Log;

class ResumeParserService
{
    /**
     * Extract text from PDF or DOCX file.
     *
     * @param string $filePath
     * @param string $extension
     * @return string
     */
    public function extractText(string $filePath, string $extension): string
    {
        $extension = strtolower($extension);

        if ($extension === 'pdf') {
            return $this->extractFromPdf($filePath);
        } elseif (in_array($extension, ['docx', 'doc'])) {
            return $this->extractFromDocx($filePath);
        }

        return '';
    }

    /**
     * Extract text from PDF using smalot/pdfparser.
     * Works without installing external binaries on Windows.
     *
     * @param string $filePath
     * @return string
     */
    protected function extractFromPdf(string $filePath): string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($filePath);
            return $pdf->getText();
        } catch (\Exception $e) {
            Log::error("Failed to extract text from PDF ($filePath): " . $e->getMessage());
            return '';
        }
    }

    /**
     * Extract text from DOCX using phpoffice/phpword.
     *
     * @param string $filePath
     * @return string
     */
    protected function extractFromDocx(string $filePath): string
    {
        try {
            $phpWord = IOFactory::load($filePath);
            $text = '';

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText() . "\n";
                    } elseif (method_exists($element, 'getElements')) {
                        // Recursive for nested elements (like tables)
                        foreach ($element->getElements() as $childElement) {
                            if (method_exists($childElement, 'getText')) {
                                $text .= $childElement->getText() . "\n";
                            }
                        }
                    }
                }
            }

            return trim($text);
        } catch (\Exception $e) {
            Log::error("Failed to extract text from DOCX: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Parse and classify data from raw resume text.
     * Uses heuristic regex-based logic (NO AI/ML).
     *
     * @param string $text
     * @return array
     */
    public function parseData(string $text): array
    {
        return [
            'full_name' => $this->extractName($text),
            'email' => $this->extractEmail($text),
            'mobile' => $this->extractMobile($text),
            'education' => $this->extractEducation($text),
            'current_location' => $this->extractLinesWithKeywords($text, ['Location', 'Address', 'City', 'Residing at']),
            'salary' => $this->extractSalary($text),
            'preferred_location' => $this->extractLinesWithKeywords($text, ['Preferred Location', 'Relocate', 'Preference']),
        ];
    }

    protected function extractName(string $text): ?string
    {
        $lines = explode("\n", $text);
        // Words that suggest the line is NOT a person's name
        $blocklist = [
            'RESUME',
            'CURRICULUM',
            'VITAE',
            'CV',
            'BIODATA',
            'PROFILE',
            'OBJECTIVE',
            'CONTACT',
            'EXPERIENCE',
            'EDUCATION',
            'SKILLS',
            'PROJECTS',
            'DECLARATION',
            'UNIVERSITY',
            'COLLEGE',
            'INSTITUTE',
            'SCHOOL',
            'ACADEMY',
            'CAMPUS',
            'ROAD',
            'STREET',
            'NAGAR',
            'SOCIETY',
            'APARTMENT',
            'OPP.',
            'NR.',
            'MANAGER',
            'DEVELOPER',
            'ENGINEER',
            'OFFICER' // Job titles
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            // Limit name length to avoid capturing sentences
            if (strlen($line) > 40)
                continue;

            $upperLine = strtoupper($line);

            // Check against blocklist
            $isBlocked = false;
            foreach ($blocklist as $blocked) {
                if (str_contains($upperLine, $blocked)) {
                    $isBlocked = true;
                    break;
                }
            }
            if ($isBlocked)
                continue;

            // Skip lines with emails, phones, or special chars (@, numbers, slashes)
            if (str_contains($line, '@') || preg_match('/[\d\/]/', $line))
                continue;

            // Heuristic: Name usually has 2-4 words, starts with Capital, mostly alphabets
            // e.g. "Maulik Limbani", "John Doe"
            if (preg_match('/^[A-Z][a-z]+(\s[A-Z][a-z]+){1,3}$/', $line)) {
                return $line;
            }

            // Allow ALL CAPS names too e.g. "MAULIK LIMBANI"
            if (preg_match('/^[A-Z]{2,}(\s[A-Z]{2,}){1,3}$/', $line)) {
                return ucwords(strtolower($line));
            }
        }

        return null; // Could not detect
    }

    protected function extractEmail(string $text): ?string
    {
        preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $text, $matches);
        return $matches[0] ?? null;
    }

    protected function extractMobile(string $text): ?string
    {
        // Improved mobile regex for generic international or Indian formats
        // Matches: +91 9999999999, 99999 99999, 9898989898
        if (preg_match('/(?:\+?\d{1,3}[ -]?)?(\d{5}[ -]?\d{5}|\d{10})/', $text, $matches)) {
            // Filter out if it looks like a year (e.g. 2010-2014) involves more strict logic if needed
            // But basic phone regex usually captures 10 digits
            return $matches[0];
        }
        return null;
    }

    protected function extractSalary(string $text): ?string
    {
        // Look for patterns like "CTC: 5 LPA", "Salary: 50000"
        if (preg_match('/(?:Current CTC|Salary|Package)[:\s]*([\d\.,]+(?:\s*(?:LPA|Lac|Lakh|K|Thousands))?)/i', $text, $matches)) {
            return $matches[1];
        }
        return null;
    }

    protected function extractEducation(string $text): ?string
    {
        // 1. Look for specific Degree Names first (The "Course")
        // Use regex with word boundaries to avoid partial matches (e.g. "MA" in "MAULIK")
        $degrees = [
            'B\.Tech',
            'M\.Tech',
            'B\.E\.',
            'M\.E\.',
            'B\.Sc',
            'M\.Sc',
            'B\.Com',
            'M\.Com',
            'B\.A\.',
            'M\.A\.',
            'MCA',
            'BCA',
            'MBA',
            'BBA',
            'PGDM',
            'Ph\.D',
            'Diploma',
            'Bachelor',
            'Master'
        ];

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            foreach ($degrees as $degree) {
                // Regex search with word boundary \b or non-word char
                // We use /i for case insensitive
                if (preg_match("/\b$degree\b/i", $line)) {
                    // Clean up the line
                    $clean = trim(str_ireplace(['Education', 'Qualification', 'Degree', 'Completed', 'Pursuing', 'Stream', 'Branch'], '', $line));

                    // Extra check: If line length is too short (just "B.Tech"), might need next line? 
                    // But usually it's "B.Tech in Computer Science"
                    if (strlen($clean) > 5) {
                        return $clean;
                    }
                }
            }
        }

        // 2. Fallback to University if no Degree found
        // Only if line explicitly mentions "University" or "College"
        // And is NOT the candidate name line (already handled by blocklist in extractName but good to be safe)
        $pattern = '/\b([A-Z][a-zA-Z\s,]*(?:University|College|Institute|Vidyapith))\b/';
        if (preg_match($pattern, $text, $matches)) {
            // Validate: Should not be "Maulik Limbani" unless his name is "University"
            return $matches[1];
        }

    }

    protected function extractLinesWithKeywords(string $text, array $keywords): ?string
    {
        $lines = explode("\n", $text);
        
        foreach ($lines as $line) {
            foreach ($keywords as $keyword) {
                if (stripos($line, $keyword) !== false) {
                   $trimmed = trim($line);
                   // Heuristic: Avoid lines that are too long (paragraphs) or too short
                   if (strlen($trimmed) < 100 && strlen($trimmed) > 5) { 
                       return $trimmed;
                   }
                }
            }
        }
        
        return null;
    }

    protected function extractLocation(string $text): ?string
    {
        // 1. Explicit Headers
        if (preg_match('/(?:Location|Address|Place|City)[:\s]+([A-Za-z0-9\s,\-\.]+)/i', $text, $matches)) {
            return trim($matches[1]);
        }

        // 2. Search for City Names (Case Insensitive user-friendly list)
        $cities = [
            'Ahmedabad',
            'Surat',
            'Vadodara',
            'Rajkot',
            'Gandhinagar',
            'Bhavnagar',
            'Jamnagar',
            'Anand',
            'Nadiad',
            'Mumbai',
            'Pune',
            'Bangalore',
            'Bengaluru',
            'Delhi',
            'Hyderabad',
            'Chennai',
            'Kolkata',
            'Noida',
            'Gurgaon',
            'Valsad',
            'Vapi',
            'Navsari',
            'Mehsana',
            'Morbi',
            'Junagadh',
            'Amreli',
            'Surendranagar',
            'Bharuch',
            'Ankleshwar'
        ];

        foreach ($cities as $city) {
            // Strict word boundary check to avoid substrings
            if (preg_match("/\b" . preg_quote($city, '/') . "\b/i", $text)) {
                return $city;
            }
        }

        // 3. Fallback: Search for PIN Code (6 digits)
        if (preg_match('/\b3\d{5}\b/', $text, $matches)) { // Gujarat/Western India PIN starts with 3
            // If PIN found, maybe valid address line? 
            // Search line containing PIN
            $lines = explode("\n", $text);
            foreach ($lines as $line) {
                if (str_contains($line, $matches[0])) {
                    return trim($line);
                }
            }
        }

        return null;
    }
}
