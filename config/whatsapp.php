<?php

declare(strict_types=1);

return [
    'cloud_api' => [
        'enabled' => (bool) (int) (getenv('WHATSAPP_ENABLED') ?: '0'),
        'access_token' => getenv('WHATSAPP_ACCESS_TOKEN') ?: '',
        'phone_number_id' => getenv('WHATSAPP_PHONE_NUMBER_ID') ?: '',
        'graph_version' => getenv('WHATSAPP_GRAPH_VERSION') ?: 'v24.0',

        // Para mensagens iniciadas pela empresa, use um template utility aprovado
        // com header do tipo document. Se vazio, envia documento livre quando houver janela aberta.
        'template_name' => getenv('WHATSAPP_NFE_TEMPLATE_NAME') ?: '',
        'template_language' => getenv('WHATSAPP_NFE_TEMPLATE_LANGUAGE') ?: 'pt_BR',
    ],
];
