<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Sin este archivo, Laravel usa su default ('allowed_origins' => ['*']),
    | que acepta peticiones de cualquier sitio web. Como el frontend de este
    | proyecto todavía corre solo en local (XAMPP / VS Code Live Server), se
    | restringe a esos orígenes. Cuando el frontend se despliegue a un
    | dominio real, hay que agregarlo a "allowed_origins" (o a
    | "allowed_origins_patterns" si el dominio final todavía no se conoce).
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost',
        'http://localhost:5501',
        'http://localhost:8000',
        'http://127.0.0.1',
        'http://127.0.0.1:5501',
        'http://127.0.0.1:8000',
        // 'null': el Origin que manda el navegador cuando index.html se abre
        // directo con doble clic (protocolo file://) en vez de sevirse por
        // Live Server o XAMPP. Sin esto, el login y toda llamada a la API
        // fallan con "Failed to fetch" al abrirlo así.
        'null',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
