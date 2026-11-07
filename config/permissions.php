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
                'sales.credit' => 'Vender fiado e definir limite dos clientes',
            ],
        ],
        'cash' => [
            'label' => 'Caixa',
            'permissions' => [
                'cash.operate' => 'Abrir e fechar o próprio caixa, sangria e suprimento',
                'cash.manage' => 'Ver todos os caixas, valores esperados e o fluxo diário',
            ],
        ],
        'finance' => [
            'label' => 'Financeiro',
            'permissions' => [
                'finance.view' => 'Ver contas a pagar e a receber',
                'finance.manage' => 'Criar, dar baixa, estornar e cancelar contas',
                'finance.discount' => 'Conceder desconto na quitação de contas',
            ],
        ],
        'tables' => [
            'label' => 'Mesas',
            'permissions' => [
                'tables.operate' => 'Abrir mesas, lançar pedidos e transferir',
                'tables.confirm' => 'Confirmar ou rejeitar pedidos públicos',
                'tables.close' => 'Fechar a conta da mesa',
                'tables.cancel' => 'Cancelar itens e comandas',
                'tables.manage' => 'Cadastrar mesas',
            ],
        ],
        'deliveries' => [
            'label' => 'Entregas',
            'permissions' => [
                'deliveries.operate' => 'Criar pedidos e mudar etapas',
                'deliveries.settle' => 'Fazer o acerto das entregas',
                'deliveries.cancel' => 'Cancelar pedidos',
                'deliveries.manage' => 'Definir bairros e taxas',
                'deliveries.courier' => 'Atuar como entregador',
            ],
        ],
        'customers' => [
            'label' => 'Clientes',
            'permissions' => [
                'customers.view' => 'Ver clientes',
                'customers.manage' => 'Cadastrar e editar clientes',
            ],
        ],
        'reports' => [
            'label' => 'Relatórios',
            'permissions' => [
                'reports.view' => 'Ver relatórios de vendas, produtos, estoque e entregas',
                'reports.finance' => 'Ver resultado, margens, custos e conferência de caixas',
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
        ['name' => 'Gerente', 'description' => 'Acompanha a operação e a equipe.', 'is_admin' => false, 'permissions' => ['sales.create', 'sales.view', 'sales.discount', 'sales.cancel', 'sales.credit', 'customers.view', 'customers.manage', 'deliveries.operate', 'deliveries.settle', 'deliveries.cancel', 'deliveries.manage', 'cash.operate', 'cash.manage', 'finance.view', 'finance.manage', 'reports.view', 'reports.finance', 'tables.operate', 'tables.confirm', 'tables.close', 'tables.cancel', 'tables.manage', 'products.view', 'products.manage', 'stock.view', 'stock.adjust', 'entries.manage', 'suppliers.manage', 'users.view', 'roles.view', 'audit.view']],
        ['name' => 'Caixa', 'description' => 'Opera o caixa e as vendas.', 'is_admin' => false, 'permissions' => ['sales.create', 'sales.view', 'cash.operate', 'tables.operate', 'tables.confirm', 'tables.close', 'customers.view', 'customers.manage', 'deliveries.operate', 'deliveries.settle', 'products.view']],
        ['name' => 'Garçom', 'description' => 'Atende as mesas.', 'is_admin' => false, 'permissions' => ['tables.operate', 'tables.confirm', 'products.view']],
        ['name' => 'Entregador', 'description' => 'Realiza as entregas.', 'is_admin' => false, 'permissions' => ['deliveries.courier']],
    ],

];
