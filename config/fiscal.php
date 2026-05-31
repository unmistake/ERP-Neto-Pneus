<?php

declare(strict_types=1);

return [
    'focus' => [
        // Ambiente: homologacao ou producao
        'environment' => getenv('FOCUS_ENV') ?: 'homologacao',
        // Token da Focus NFe
        'token' => getenv('FOCUS_TOKEN') ?: 'DD4zlsj6oTJOvmzr3LIXGA6SqfqRdXYU',
        // Serie padrao para emissao NFC-e/NF-e
        'serie' => (int) (getenv('FOCUS_SERIE') ?: 1),
        // URL opcional para sobrescrever endpoint padrao
        'base_url' => getenv('FOCUS_BASE_URL') ?: '',

        // Dados fiscais minimos do emitente. Use somente numeros em CNPJ/IE.
        'issuer' => [
            'cnpj' => getenv('FOCUS_ISSUER_CNPJ') ?: '35732524000151',
            'inscricao_estadual' => getenv('FOCUS_ISSUER_IE') ?: '163854917',
            // 1: Simples Nacional, 3: Regime Normal, 4: MEI
            'regime_tributario' => getenv('FOCUS_ISSUER_TAX_REGIME') ?: '1',
            // Em homologacao BA, a SEFAZ pede o CNPJ abaixo para o escritorio de contabilidade.
            'cpf_cnpj_contabilidade' => getenv('FOCUS_ACCOUNTING_CNPJ') ?: '13937073000156',
        ],

        // Padroes fiscais usados quando o produto ainda nao tem cadastro fiscal proprio.
        // Confirme estes valores com o contador antes de usar em producao.
        'nfe_defaults' => [
            'natureza_operacao' => 'Venda de mercadoria',
            'local_destino' => '1',
            'presenca_comprador' => '1',
            'modalidade_frete' => '9',
            'cfop' => '5102',
            'codigo_ncm' => '40111000',
            'unidade' => 'UN',
            'icms_origem' => '0',
            'icms_situacao_tributaria' => '102',
            'pis_situacao_tributaria' => '49',
            'cofins_situacao_tributaria' => '49',
        ],

        // Endereco usado para testes de NF-e quando o ERP ainda nao tem endereco do cliente.
        // Para producao, cadastre o endereco real do destinatario no CRM antes de emitir.
        'nfe_recipient_address' => [
            'logradouro' => getenv('FOCUS_NFE_RECIPIENT_STREET') ?: '',
            'numero' => getenv('FOCUS_NFE_RECIPIENT_NUMBER') ?: '',
            'bairro' => getenv('FOCUS_NFE_RECIPIENT_DISTRICT') ?: '',
            'municipio' => getenv('FOCUS_NFE_RECIPIENT_CITY') ?: '',
            'uf' => getenv('FOCUS_NFE_RECIPIENT_UF') ?: '',
            'cep' => getenv('FOCUS_NFE_RECIPIENT_ZIP') ?: '',
            'pais' => 'Brasil',
        ],
    ],
];
