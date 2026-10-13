<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Finance pages' "Explain this" assistant, run through laravel/ai (provider keys live in
    // config/ai.php). Leave the provider's key empty to turn the AI off; the reports, health
    // check and suggestions still work without it.
    'finance_ai' => [
        // Any laravel/ai provider: gemini, anthropic, openai...
        'provider' => env('FINANCE_AI_PROVIDER', 'gemini'),
        'model' => env('FINANCE_AI_MODEL', 'gemini-3.8-flash'),
        // "Ask about your business" questions (tool use; needs more judgment than explanations).
        'question_model' => env('FINANCE_AI_QUESTION_MODEL', 'gemini-3.8-flash'),
        // Questions one business may ask per day, to keep API costs predictable.
        'daily_question_limit' => (int) env('FINANCE_AI_DAILY_QUESTIONS', 30),
    ],

    // The AI assistant in the chat drawer: teaches how to use the POS within each user's permissions.
    'app_assistant' => [
        'provider' => env('ASSISTANT_AI_PROVIDER', 'gemini'),
        'model' => env('ASSISTANT_AI_MODEL', 'gemini-3.8-flash'),
        // Messages one user may send per day, to keep API costs predictable.
        'daily_limit' => (int) env('ASSISTANT_AI_DAILY_MESSAGES', 50),
    ],

];
