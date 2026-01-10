<?php
/**
 * Address Parser
 *
 * Parses and validates shipping addresses from text input.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Support\Utilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Address Parser Class
 *
 * Handles parsing of addresses from free-form text into structured components.
 * Extracts: name, street, city, state, postal code, and country.
 */
class AddressParser
{
    /**
     * Common country names and aliases
     */
    private const COUNTRIES = [
        'usa' => 'United States',
        'us' => 'United States',
        'united states' => 'United States',
        'uk' => 'United Kingdom',
        'united kingdom' => 'United Kingdom',
        'canada' => 'Canada',
        'australia' => 'Australia',
        'india' => 'India',
        'mexico' => 'Mexico',
        'brazil' => 'Brazil',
    ];

    /**
     * Parse address from text input
     *
     * Attempts to extract address components from multi-line or formatted text.
     *
     * @param string $text Address text
     * @return array<string, string> Parsed address with components
     */
    public function parse(string $text): array
    {
        $text = trim($text);
        $lines = array_filter(array_map('trim', explode("\n", $text)));

        $address = [
            'name' => '',
            'street' => '',
            'city' => '',
            'state' => '',
            'postal_code' => '',
            'country' => '',
        ];

        if (empty($lines)) {
            return $address;
        }

        // Work backwards: last line is usually country
        $lineCount = count($lines);

        // Extract country (last line)
        if ($lineCount > 0) {
            $lastLine = array_pop($lines);
            $address['country'] = $this->parseCountry($lastLine);

            // If country not recognized, treat as part of address
            if (empty($address['country'])) {
                $lines[] = $lastLine;
            }
        }

        // Extract city, state, postal code
        if (count($lines) > 0) {
            $locationLine = array_pop($lines);
            $locationData = $this->parseLocationLine($locationLine);

            $address['city'] = $locationData['city'];
            $address['state'] = $locationData['state'];
            $address['postal_code'] = $locationData['postal_code'];

            // If nothing extracted, add back to lines
            if (empty($locationData['city']) && empty($locationData['state']) && empty($locationData['postal_code'])) {
                $lines[] = $locationLine;
            }
        }

        // Remaining lines are street address and possibly name
        if (count($lines) > 0) {
            $firstLine = $lines[0];

            // First line might be name if it looks like a person's name
            if ($this->looksLikeName($firstLine) && count($lines) > 1) {
                $address['name'] = array_shift($lines);
            }

            // Rest is street address
            if (!empty($lines)) {
                $address['street'] = implode("\n", $lines);
            }
        }

        // Clean up all fields
        return array_map('trim', $address);
    }

    /**
     * Parse location line (city, state, postal code)
     *
     * @param string $line Location line
     * @return array<string, string> Location components
     */
    private function parseLocationLine(string $line): array
    {
        $location = [
            'city' => '',
            'state' => '',
            'postal_code' => '',
        ];

        // Try to extract postal code (various patterns)
        $postalPatterns = [
            '/\b(\d{5}(?:-\d{4})?)\b/', // US ZIP (12345 or 12345-6789)
            '/\b([A-Z]\d[A-Z]\s?\d[A-Z]\d)\b/i', // Canadian (A1A 1A1)
            '/\b(\d{4,6})\b/', // Generic 4-6 digits
        ];

        foreach ($postalPatterns as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                $location['postal_code'] = $matches[1];
                $line = str_replace($matches[0], '', $line);
                break;
            }
        }

        // Split remaining by comma
        $parts = array_map('trim', explode(',', $line));

        if (count($parts) === 2) {
            // Format: "City, State" or "City, ST"
            $location['city'] = $parts[0];
            $location['state'] = $parts[1];
        } elseif (count($parts) === 1 && !empty($parts[0])) {
            // Single value - could be city or state
            $value = $parts[0];
            
            // If it's 2 letters, likely a state abbreviation
            if (strlen($value) === 2 && ctype_alpha($value)) {
                $location['state'] = strtoupper($value);
            } else {
                $location['city'] = $value;
            }
        }

        return $location;
    }

    /**
     * Parse country from text
     *
     * @param string $text Country text
     * @return string Country name or empty string
     */
    private function parseCountry(string $text): string
    {
        $normalized = strtolower(trim($text));

        return self::COUNTRIES[$normalized] ?? '';
    }

    /**
     * Check if text looks like a person's name
     *
     * @param string $text Text to check
     * @return bool True if looks like a name
     */
    private function looksLikeName(string $text): bool
    {
        // Names typically have 2-4 words, are short, and don't have numbers
        $words = str_word_count($text);

        if ($words < 2 || $words > 4) {
            return false;
        }

        // Should not contain numbers
        if (preg_match('/\d/', $text)) {
            return false;
        }

        // Should not be too long (names are typically < 50 chars)
        if (strlen($text) > 50) {
            return false;
        }

        return true;
    }

    /**
     * Validate address components
     *
     * @param array<string, string> $address Address data
     * @return array<string, mixed> Validation result with errors
     */
    public function validate(array $address): array
    {
        $errors = [];

        // Required fields
        if (empty($address['street'])) {
            $errors[] = 'Street address is required';
        }

        if (empty($address['city'])) {
            $errors[] = 'City is required';
        }

        if (empty($address['country'])) {
            $errors[] = 'Country is required';
        }

        // Validate postal code format if provided
        if (!empty($address['postal_code']) && !empty($address['country'])) {
            if (!$this->validatePostalCode($address['postal_code'], $address['country'])) {
                $errors[] = 'Invalid postal code format for ' . $address['country'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate postal code format
     *
     * @param string $postalCode Postal code
     * @param string $country Country name
     * @return bool True if valid
     */
    private function validatePostalCode(string $postalCode, string $country): bool
    {
        $patterns = match ($country) {
            'United States' => '/^\d{5}(-\d{4})?$/',
            'Canada' => '/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/i',
            'United Kingdom' => '/^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/i',
            'Australia' => '/^\d{4}$/',
            default => '/^\d{4,6}$/', // Generic pattern
        };

        return (bool) preg_match($patterns, $postalCode);
    }

    /**
     * Format address for display
     *
     * @param array<string, string> $address Address data
     * @return string Formatted address
     */
    public function formatDisplay(array $address): string
    {
        $lines = [];

        if (!empty($address['name'])) {
            $lines[] = $address['name'];
        }

        if (!empty($address['street'])) {
            $lines[] = $address['street'];
        }

        $cityLine = [];
        if (!empty($address['city'])) {
            $cityLine[] = $address['city'];
        }
        if (!empty($address['state'])) {
            $cityLine[] = $address['state'];
        }
        if (!empty($address['postal_code'])) {
            $cityLine[] = $address['postal_code'];
        }

        if (!empty($cityLine)) {
            $lines[] = implode(', ', $cityLine);
        }

        if (!empty($address['country'])) {
            $lines[] = $address['country'];
        }

        return implode("\n", $lines);
    }

    /**
     * Format address as single line
     *
     * @param array<string, string> $address Address data
     * @return string Single line address
     */
    public function formatSingleLine(array $address): string
    {
        $parts = [];

        if (!empty($address['street'])) {
            $parts[] = $address['street'];
        }

        if (!empty($address['city'])) {
            $parts[] = $address['city'];
        }

        if (!empty($address['state'])) {
            $parts[] = $address['state'];
        }

        if (!empty($address['postal_code'])) {
            $parts[] = $address['postal_code'];
        }

        if (!empty($address['country'])) {
            $parts[] = $address['country'];
        }

        return implode(', ', $parts);
    }

    /**
     * Normalize address for comparison
     *
     * @param array<string, string> $address Address data
     * @return string Normalized address string
     */
    public function normalize(array $address): string
    {
        $parts = [
            strtolower(trim($address['street'] ?? '')),
            strtolower(trim($address['city'] ?? '')),
            strtolower(trim($address['state'] ?? '')),
            preg_replace('/\s+/', '', strtolower($address['postal_code'] ?? '')),
        ];

        return implode('|', array_filter($parts));
    }
}
