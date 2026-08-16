<?php

$openRouterKey = getenv('OPENROUTER_API_KEY');

return [
    'agents' => [
        [
            'modelLink' => 'poolside/laguna-s-2.1:free',
            'apiKey' => $openRouterKey,
        ],
        [
            'modelLink' => 'cohere/north-mini-code:free',
            'apiKey' => $openRouterKey,
        ],
        [
            'modelLink' => 'nvidia/nemotron-3-ultra-550b-a55b:free',
            'apiKey' => $openRouterKey,
        ],
        [
            'modelLink' => 'google/gemma-4-31b-it:free',
            'apiKey' => $openRouterKey,
        ],
        [
            'modelLink' => 'openai/gpt-oss-20b:free',
            'apiKey' => $openRouterKey,
        ],
    ],
];
