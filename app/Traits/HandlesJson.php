<?php

namespace App\Traits;

trait HandlesJson
{
    /**
     * Safely decode JSON string to array
     * 
     * @param string $jsonString
     * @return array
     */
    protected function safeJsonDecode($jsonString)
    {
        // If it's already an array, return as is
        if (is_array($jsonString)) {
            return $jsonString;
        }

        // If it's not a string, return empty array
        if (!is_string($jsonString)) {
            return [];
        }

        // Trim whitespace
        $jsonString = trim($jsonString);
        
        // If empty string, return empty array
        if ($jsonString === '') {
            return [];
        }

        // Try to decode the JSON
        $decoded = json_decode($jsonString, true);
        
        // If decode failed, try to fix common JSON issues
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try to fix common JSON issues
            $jsonString = preg_replace('/,\s*([}\]])/m', '$1', $jsonString);
            $decoded = json_decode($jsonString, true);
            
            // If still error, return empty array
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
        }

        return is_array($decoded) ? $decoded : [];
    }
}
