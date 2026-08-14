<?php
// Uso local: copie este arquivo como providers.local.php e preencha as chaves.
// No Render, não envie este arquivo: use a variável secreta AXIS_AGENTS_JSON.
return [
    'agents' => [
        ['modelLink' => 'cohere/north-mini-code:free', 'apiKey' => 'sk-or-v1-COLOQUE-A-CHAVE-1'],
        ['modelLink' => 'nvidia/nemotron-3-super-120b-a12b:free', 'apiKey' => 'sk-or-v1-COLOQUE-A-CHAVE-2'],
        ['modelLink' => 'google/gemma-4-31b-it:free', 'apiKey' => 'sk-or-v1-COLOQUE-A-CHAVE-3'],
    ],
];
