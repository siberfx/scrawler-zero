<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Gemini AI Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the Gemini AI API.
    |
    */

    'api_key' => env('GEMINI_API_KEY', 'AIzaSyAq9Ku1VF8SxzkeEXaSxqehJaCxLWgUo50'),
    'api_url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent'),
    'default_model' => env('GEMINI_DEFAULT_MODEL', 'gemini-2.0-flash'),
];
