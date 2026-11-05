<?php

/*
| Catálogo de permissões. Cada módulo novo adiciona seu grupo aqui.
| Perfis com is_admin têm todas as permissões automaticamente.
*/
return [

    'groups' => [
        'sales' => [
            'label' => 'Vendas',
            'permissions' => [
                'sales.create' => 'Vender no PDV',
                'sales.view' => 'Ver vendas e imprimir cupons',
                'sales.discount' => 'Dar desconto',
                'sales.cancel' => 'Cancelar vendas',
            ],
        ],
        'products' => [
            'label' => 'Produtos',
            'permissions' => [
                'products.view' => 'Ver produtos e preços de venda',
                'products.manage' => 'Criar e editar produtos e categorias',
            ],
        ],
        'stock' => [
            'label' => 'Estoque',
            'permissions' => [
                'stock.view' => 'Ver saldos, custos e movimentações',
                'stock.adjust' => 'Ajustar o estoque manualmente',
                'entries.manage' => 'Lançar e cancelar entradas de mercadorias',
                'suppliers.manage' => 'Gerenciar fornecedores',
            ],
        ],
        'users' => [
            'label' => 'Usuários',
            'permissions' => [
                'users.view' => 'Ver usuários',
                'users.manage' => 'Criar, editar e inativar usuários',
            ],
        ],
        'roles' => [
            'label' => 'Perfis de acesso',
            'permissions' => [
                'roles.view' => 'Ver perfis',
                'roles.manage' => 'Criar, editar e excluir perfis',
            ],
        ],
        'audit' => [
            'label' => 'Auditoria',
            'permissions' => [
                'audit.view' => 'Ver a auditoria da empresa',
            ],
        ],
        'company' => [
            'label' => 'Empresa',
            'permissions' => [
                'company.manage' => 'Editar os dados da empresa',
            ],
        ],
    ],

    // Perfis criados em toda empresa nova.
    'default_roles' => [
        ['name' => 'Administrador', 'description' => 'Acesso total ao sistema da empresa.', 'is_admin' => true, 'permissions' => []],
        ['name' => 'Gerente', 'description' => 'Acompanha a operação e a equipe.', 'is_admin' => false, 'permissions' => ['sales.create', 'sales.view', 'sales.discount', 'sales.cancel', 'products.view', 'products.manage', 'stock.view', 'stock.adjust', 'entries.manage', 'suppliers.manage', 'users.view', 'roles.view', 'audit.view']],
        ['name' => 'Caixa', 'description' => 'Opera o caixa e as vendas.', 'is_admin' => false, 'permissions' => ['sales.create', 'sales.view', 'products.view']],
        ['name' => 'Garçom', 'description' => 'Atende as mesas.', 'is_admin' => false, 'permissions' => ['products.view']],
        ['name' => 'Entregador', 'description' => 'Realiza as entregas.', 'is_admin' => false, 'permissions' => []],
    ],

];
