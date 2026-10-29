<?php

return [
    'history' => ['encrypt' => true],
    'ssr' => ['enabled' => false],
    'pages' => ['paths' => [resource_path('js/pages')], 'extensions' => ['vue']],
    'testing' => ['ensure_pages_exist' => true],
];
