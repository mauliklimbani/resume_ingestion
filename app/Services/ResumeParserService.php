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
            'current_location' => $this->extractCurrentLocation($text),
            'salary' => $this->extractSalary($text),
            'preferred_location' => $this->extractPreferredLocation($text),
        ];
    }

    protected function extractName(string $text): ?string
    {
        $lines = array_map('trim', explode("\n", $text));
        $lines = array_values(array_filter($lines, fn($l) => $l !== ''));

        // 1. Label on same line: "Name: Maulik Limbani" or "Full Name : John Doe"
        $nameLabels = ['name\s*:', 'full\s*name\s*:', 'candidate\s*name\s*:', 'applicant\s*name\s*:', 'person\s*name\s*:', 'name\s+of\s+candidate\s*:'];
        foreach ($nameLabels as $label) {
            if (preg_match('/' . $label . '\s*([^\n]+)/iu', $text, $m)) {
                $candidateName = trim(preg_replace('/\s+/', ' ', $m[1]));
                if ($this->isValidCandidateName($candidateName)) {
                    return $candidateName;
                }
            }
        }

        // 2. Label on previous line: "Name" or "Full Name" alone, next line = candidate name (different formats)
        $labelOnly = ['name', 'full name', 'candidate name', 'applicant name'];
        foreach ($lines as $i => $line) {
            if ($i >= 12) {
                break; // only in first ~12 lines
            }
            $lower = strtolower($line);
            if (in_array($lower, $labelOnly) || preg_match('/^(name|full name|candidate name)\s*:?\s*$/i', $line)) {
                $next = $lines[$i + 1] ?? '';
                if ($next !== '' && $this->isValidCandidateName($next) && !$this->looksLikeDegreeOrHeader($next)) {
                    return $next;
                }
            }
        }

        $topLines = array_slice($lines, 0, 15);
        // 3.0 Strong heuristic: in many resumes the very first line is the candidate name (like in the image)
        $firstLine = $topLines[0] ?? '';
        if ($firstLine !== '' && strlen($firstLine) <= 50 && !str_contains($firstLine, '@') && !preg_match('/\d/', $firstLine)
            && !$this->looksLikeDegreeOrHeader($firstLine)) {
            $upperFirst = strtoupper($firstLine);
            $firstBlocked = false;
            foreach (['RESUME', 'CURRICULUM', 'VITAE', 'CV', 'PROFILE', 'OBJECTIVE', 'CONTACT', 'EXPERIENCE', 'EDUCATION', 'SKILLS', 'DEVELOPER', 'ENGINEER', 'MANAGER'] as $b) {
                if (str_contains($upperFirst, $b)) {
                    $firstBlocked = true;
                    break;
                }
            }
            if (!$firstBlocked && preg_match('/^[A-Za-z\s\.\-\']+$/u', $firstLine)) {
                $wordCount = substr_count(trim($firstLine), ' ') + 1;
                if ($wordCount >= 2 && $wordCount <= 5) {
                    return ucwords(strtolower(trim($firstLine)));
                }
                if ($wordCount === 1 && strlen(trim($firstLine)) >= 2 && strlen(trim($firstLine)) <= 30) {
                    return ucwords(strtolower(trim($firstLine)));
                }
            }
        }

        $blocklist = [
            'RESUME', 'CURRICULUM', 'VITAE', 'CV', 'BIODATA', 'PROFILE', 'OBJECTIVE', 'CONTACT',
            'EXPERIENCE', 'EDUCATION', 'SKILLS', 'PROJECTS', 'DECLARATION', 'UNIVERSITY', 'COLLEGE',
            'INSTITUTE', 'SCHOOL', 'ACADEMY', 'CAMPUS', 'ROAD', 'STREET', 'NAGAR', 'SOCIETY',
            'APARTMENT', 'OPP.', 'NR.', 'MANAGER', 'DEVELOPER', 'ENGINEER', 'OFFICER', 'SUMMARY',
            'CAREER', 'APPLICATION', 'APPLYING', 'DEAR', 'CERTIFICATION', 'TRAINING', 'REFERENCE',
            'DATE OF BIRTH', 'DOB', 'GENDER', 'MARITAL', 'WORK ', ' EXPERIENCE', 'PHONE', 'MOBILE',
            'EMAIL', 'ADDRESS', 'LOCATION', 'PREFERRED', 'SALARY', 'CTC', 'PACKAGE', 'YEAR', 'GRADUAT',
            'PASSED', 'PERCENTAGE', '%', 'CGPA', 'GPA', 'DEGREE', 'QUALIFICATION', 'STREAM', 'BRANCH',
        ];

        // Job-title phrases: name often followed by these on same line (longer phrases first)
        $jobTitleMarkers = [
            'FULL STACK DEVELOPER', 'FULL STACK', 'SOFTWARE DEVELOPER', 'WEB DEVELOPER', 'FRONTEND DEVELOPER',
            'BACKEND DEVELOPER', 'DEVELOPER', 'ENGINEER', 'MANAGER', 'OFFICER', 'DESIGNER', 'ANALYST',
            'CONSULTANT', 'LEAD', 'SPECIALIST', 'COORDINATOR', 'EXECUTIVE', 'ASSOCIATE', 'INTERN',
        ];

        foreach ($topLines as $line) {
            if (strlen($line) > 80 || str_contains($line, '@') || preg_match('/\d/', $line)) {
                continue;
            }
            $upperLine = strtoupper($line);

            foreach ($jobTitleMarkers as $marker) {
                if (str_contains($upperLine, $marker)) {
                    $pos = stripos($line, $marker);
                    if ($pos > 2) {
                        $namePart = trim(substr($line, 0, $pos));
                        if ($this->isValidCandidateName($namePart) && !$this->looksLikeDegreeOrHeader($namePart)
                            && preg_match('/^[A-Za-z\s\.\-\']+$/u', $namePart) && substr_count($namePart, ' ') >= 1 && substr_count($namePart, ' ') <= 4) {
                            return ucwords(strtolower($namePart));
                        }
                    }
                    break; // one marker found, skip other checks for this line
                }
            }

            $blocked = false;
            foreach ($blocklist as $b) {
                if (str_contains($upperLine, $b)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked || $this->looksLikeDegreeOrHeader($line)) {
                continue;
            }

            if (preg_match('/^[A-Z][a-z]+(\s[A-Z]\.?|\s[A-Z][a-z]+|\-[A-Z][a-z]+)*(?:\s(?:Jr\.?|Sr\.?|III|II|IV))?$/u', $line)) {
                return $line;
            }
            if (preg_match('/^[A-Z]{2,}(\s[A-Z]\.?|\s[A-Z]{2,}|\-[A-Z]{2,})*(?:\s(?:JR\.?|SR\.?|III|II|IV))?$/u', $line)) {
                return ucwords(strtolower($line));
            }

            // Relaxed: 2–5 words, only letters (e.g. "Maulik limbani", "MAULIK Limbani")
            if (preg_match('/^[A-Za-z][A-Za-z\.\'\-\s]*(?:\s+[A-Za-z][A-Za-z\.\'-]*){0,4}$/u', $line) && preg_match('/\s/', $line)) {
                return ucwords(strtolower($line));
            }
            // Single word name at top
            if (preg_match('/^[A-Za-z]{2,30}$/u', $line)) {
                return ucwords(strtolower($line));
            }
        }

        return null;
    }

    protected function isValidCandidateName(string $s): bool
    {
        $s = trim($s);
        return strlen($s) >= 2 && strlen($s) <= 60
            && !str_contains($s, '@')
            && !preg_match('/\d/', $s);
    }

    protected function looksLikeDegreeOrHeader(string $line): bool
    {
        return (bool) preg_match('/\b(B\.?Tech|M\.?Tech|B\.?E\.?|M\.?E\.?|B\.?Sc|M\.?Sc|MBA|BCA|MCA|B\.?A\.?|M\.?A\.?|Ph\.?D|PGDM|Diploma|Bachelor|Master|B\.?Com|M\.?Com)\b/i', $line);
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
        // Prefer "CTC: value" / "Salary: value" / "Expected: value" for accuracy
        if (preg_match('/(?:Current\s+CTC|CTC|Salary|Package|Expected\s+Salary|Compensation)\s*[:\-]\s*([\d\.,\s]*(?:LPA|Lac|Lakh|Lacs|K|Thousand|Per\s+Annum)?)/iu', $text, $m)) {
            $val = trim($m[1]);
            if (strlen($val) > 0 && strlen($val) < 50) {
                return $val;
            }
        }
        if (preg_match('/(?:CTC|Salary|Package)[:\s]+([\d\.,]+(?:\s*(?:LPA|Lac|Lakh|K))?)/i', $text, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    protected function extractEducation(string $text): ?string
    {
        // 1. Prefer "Education:" / "Qualification:" section value (first line after label)
        if (preg_match('/(?:Education|Qualification|Academic|Degree)\s*[:\-]\s*([^\n]+)/iu', $text, $m)) {
            $block = trim(preg_replace('/\s+/', ' ', $m[1]));
            if (strlen($block) > 3 && strlen($block) < 250) {
                return $block;
            }
        }

        $degrees = [
            'B\.Tech', 'M\.Tech', 'B\.E\.', 'M\.E\.', 'B\.Sc', 'M\.Sc', 'B\.Com', 'M\.Com',
            'B\.A\.', 'M\.A\.', 'MCA', 'BCA', 'MBA', 'BBA', 'PGDM', 'Ph\.D', 'Diploma',
            'Bachelor', 'Master', 'B\.Pharm', 'M\.Pharm', 'B\.Arch', 'M\.Arch', 'LLB', 'LLM',
            'MSW', 'BBA', 'BMS', 'B\.Des', 'M\.Des',
        ];

        $lines = explode("\n", $text);
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            foreach ($degrees as $degree) {
                if (preg_match("/\b$degree\b/i", $line)) {
                    // Keep full line; only strip section header word at start for accuracy
                    $clean = trim(preg_replace('/^(?:Education|Qualification|Academic)\s*[:\-]*\s*/i', '', $line));
                    if (strlen($clean) < 5 && isset($lines[$i + 1])) {
                        $next = trim($lines[$i + 1]);
                        if (strlen($next) > 0 && strlen($next) < 150 && !preg_match('/^(Experience|Work|Skills|Projects|Contact)/i', $next)) {
                            $clean = $clean . ' – ' . $next;
                        }
                    }
                    if (strlen($clean) >= 3) {
                        return $clean;
                    }
                }
            }
        }

        // 3. Fallback: first line containing University/College/Institute
        if (preg_match('/\b([A-Za-z][A-Za-z\s,]*(?:University|College|Institute|Vidyapith))\b/u', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract current location: only from contact/header area (top ~25 lines), skip PROJECT/EXPERIENCE sections.
     * Returns only "City, State" format, not full addresses.
     */
    protected function extractCurrentLocation(string $text): ?string
    {
        $lines = array_map('trim', explode("\n", $text));
        $contactArea = array_slice($lines, 0, 25);
        $contactText = implode("\n", $contactArea);

        $sectionMarkers = ['PROJECT', 'EXPERIENCE', 'WORK EXPERIENCE', 'WORK HISTORY', 'EMPLOYMENT', 'CAREER'];
        $inSection = false;
        $raw = null;

        foreach ($contactArea as $i => $line) {
            $upper = strtoupper($line);
            foreach ($sectionMarkers as $marker) {
                if (str_contains($upper, $marker)) {
                    $inSection = true;
                    break;
                }
            }
            if ($inSection) {
                break;
            }

            if (preg_match('/(?:Current\s+)?(?:Location|Address|City|Residing\s+at|Place)\s*[:\-]\s*([^\n]{2,100})/iu', $line, $m)) {
                $raw = trim(preg_replace('/\s+/', ' ', $m[1]));
                break;
            }
            if (preg_match('/\b(?:Location|Address|City|Place)\s*[:\-]\s*([^\n]{2,100})/iu', $line, $m)) {
                $raw = trim(preg_replace('/\s+/', ' ', $m[1]));
                break;
            }
        }

        if ($raw === null || $raw === '') {
            foreach ($contactArea as $line) {
                $upper = strtoupper($line);
                $hasSection = false;
                foreach ($sectionMarkers as $marker) {
                    if (str_contains($upper, $marker)) {
                        $hasSection = true;
                        break;
                    }
                }
                if ($hasSection) {
                    break;
                }

                foreach (['Location', 'Address', 'City', 'Residing at'] as $kw) {
                    if (stripos($line, $kw) !== false && strlen($line) >= 5 && strlen($line) <= 100) {
                        $raw = trim($line);
                        $raw = preg_replace('/^(?:' . preg_quote($kw, '/') . '|' . preg_quote(ucfirst($kw), '/') . ')\s*[:\-]\s*/iu', '', $raw);
                        if ($raw !== '') {
                            break 2;
                        }
                    }
                }
            }
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->normalizeLocationToCityState($raw);
    }

    /**
     * Normalize location to ONLY "City, State/Country" format - never return full address.
     * For global resumes: extracts city and state/country from any address format.
     */
    protected function normalizeLocationToCityState(string $raw): ?string
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw));
        if ($raw === '') {
            return null;
        }

        $placeParts = $this->extractCityAndRegionFromAddress($raw);
        if (count($placeParts) >= 2) {
            return implode(', ', $placeParts);
        }
        if (count($placeParts) === 1) {
            return $placeParts[0];
        }

        if (strlen($raw) <= 60 && preg_match('/^[A-Za-z\s,\-\.]+$/u', $raw)) {
            $parts = array_map('trim', preg_split('/\s*,\s*/u', $raw));
            $parts = array_filter($parts, fn($p) => $p !== '' && !preg_match('/^\d+$/', $p));
            if (count($parts) >= 1 && count($parts) <= 2) {
                return implode(', ', array_slice($parts, -2));
            }
            return $raw;
        }

        return null;
    }

    /**
     * Normalize location for global resumes: short values kept as-is; long addresses
     * reduced to "City, State/Country" using comma-split (no country-specific list).
     */
    protected function normalizeLocation(string $raw): string
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw));
        if (strlen($raw) <= 60) {
            return $raw;
        }
        $placeParts = $this->extractCityAndRegionFromAddress($raw);
        if ($placeParts !== []) {
            return implode(', ', $placeParts);
        }
        return strlen($raw) > 120 ? substr($raw, 0, 117) . '...' : $raw;
    }

    /**
     * From a long address string, extract last 1–2 place-like segments (city, state/country).
     * Works globally: no fixed list; uses comma/postal pattern.
     */
    protected function extractCityAndRegionFromAddress(string $address): array
    {
        $parts = array_map('trim', preg_split('/\s*,\s*|\s+[–\-]\s+/u', $address));
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
        if ($parts === []) {
            return [];
        }
        $candidates = [];
        foreach (array_reverse($parts) as $p) {
            if (strlen($p) > 45) {
                continue;
            }
            $clean = preg_replace('/\s+/', '', $p);
            if ($clean === '') {
                continue;
            }
            if (preg_match('/^\d{4,10}$/', $clean) || preg_match('/^\d{5,6}$/', $clean)) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9\s\.\-\'\(\)]+$/u', $p) && strlen($p) >= 2) {
                array_unshift($candidates, $p);
                if (count($candidates) >= 2) {
                    break;
                }
            }
        }
        return array_slice($candidates, 0, 2);
    }

    /**
     * Extract preferred location (global); normalizes to "City, State/Country" when long.
     */
    protected function extractPreferredLocation(string $text): ?string
    {
        $raw = null;
        if (preg_match('/(?:Preferred\s+Location|Relocate|Location\s+Preference|Work\s+Location)\s*[:\-]\s*([^\n]{2,200})/iu', $text, $m)) {
            $raw = trim(preg_replace('/\s+/', ' ', $m[1]));
        }
        if ($raw === null || $raw === '') {
            $raw = $this->extractLinesWithKeywords($text, ['Preferred Location', 'Relocate', 'Preference'], 5, 200);
        }
        return $raw !== null && $raw !== '' ? $this->normalizeLocation($raw) : null;
    }

    protected function extractLinesWithKeywords(string $text, array $keywords, int $minLen = 5, int $maxLen = 100): ?string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            foreach ($keywords as $keyword) {
                if (stripos($line, $keyword) !== false) {
                    $trimmed = trim($line);
                    if (strlen($trimmed) >= $minLen && strlen($trimmed) <= $maxLen) {
                        return $trimmed;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Legacy/alternate location extraction – uses same global logic as current_location.
     */
    protected function extractLocation(string $text): ?string
    {
        $raw = $this->extractLinesWithKeywords($text, ['Location', 'Address', 'City', 'Place', 'Residing at'], 2, 200);
        if ($raw === null) {
            if (preg_match('/(?:Location|Address|Place|City)[:\s]+([A-Za-z0-9\s,\-\.]+)/i', $text, $m)) {
                $raw = trim($m[1]);
            }
        }
        return $raw !== null && $raw !== '' ? $this->normalizeLocation($raw) : null;
    }
}
