<?php

/*
| Novidades de cada atualização, a mais recente primeiro. Depois do deploy, quem
| entrar no sistema vê a primeira da lista num aviso, uma única vez.
| "can" (opcional) mostra o item só para quem tem aquela permissão.
| Cada atualização precisa de um "id" novo, senão o aviso não aparece de novo.
*/

return [
    [
        'id' => '2026-11-10.4',
        'date' => '10/11/2026',
        'items' => [
            [
                'title' => 'Cozinha entra direto no painel',
                'text' => 'Quem tem acesso só à produção (ex.: perfil Cozinha) cai direto no painel do setor ao entrar no sistema. Toque em "Tela cheia" para ocupar a tela toda.',
                'can' => 'production.view',
            ],
        ],
    ],
    [
        'id' => '2026-11-10.3',
        'date' => '10/11/2026',
        'items' => [
            [
                'title' => 'Senha do usuário sem troca obrigatória',
                'text' => 'Ninguém mais é obrigado a trocar a senha no acesso: quem é cadastrado em Usuários entra com a senha definida, e quem já estava com senha provisória foi liberado. A troca continua disponível em Minha conta.',
                'can' => 'users.manage',
            ],
        ],
    ],
    [
        'id' => '2026-11-10.2',
        'date' => '10/11/2026',
        'items' => [
            [
                'title' => 'Novo perfil de acesso mais rápido',
                'text' => 'Em Perfis de acesso, "Novo perfil" abre uma janela só com nome e descrição. Depois, toque no perfil (ou em "Permissões") na lista para escolher o que ele pode fazer.',
                'can' => 'roles.manage',
            ],
            [
                'title' => 'Nova conta sem sair da lista',
                'text' => 'Em Contas a pagar e Contas a receber, "Nova conta" agora abre uma janela na própria lista, com parcelas, fornecedor ou cliente.',
                'can' => 'finance.manage',
            ],
            [
                'title' => 'Busca nas permissões',
                'text' => 'Na tela de permissões do perfil, digite parte do nome (ex.: caixa, desconto, entrega) para achar a permissão sem rolar a lista.',
                'can' => 'roles.manage',
            ],
        ],
    ],
    [
        'id' => '2026-11-10.1',
        'date' => '10/11/2026',
        'items' => [
            [
                'title' => 'Painel da cozinha em tela cheia',
                'text' => 'Em Produção, toque em "Painel tela cheia" e escolha o setor. Cada pedido vira um cartão só com os itens daquele setor; toque no item para avançar ou em "Tudo pronto". A TV lembra o setor escolhido.',
                'can' => 'production.view',
            ],
            [
                'title' => 'Cadastro de entregadores',
                'text' => 'Em Entregas → Entregadores, toque em "Novo entregador" para cadastrar quem faz as entregas (com ou sem acesso ao sistema) e escolha o entregador ao despachar.',
                'can' => 'deliveries.manage',
            ],
            [
                'title' => 'Entrega "Não entregue"',
                'text' => 'Quando o entregador não consegue entregar, marque "Não entregue" com o motivo. O pedido pode voltar para os prontos e sair de novo.',
                'can' => 'deliveries.operate',
            ],
            [
                'title' => 'Contador de pedidos no menu',
                'text' => 'Pedidos online e Mesas mostram no menu quantos pedidos estão aguardando.',
            ],
            [
                'title' => 'Relatório por entregador',
                'text' => 'Em Relatórios → Entregadores: entregas, não entregues, taxas e tempo em rota de cada entregador no período, com planilha.',
                'can' => 'reports.view',
            ],
            [
                'title' => 'Aviso de novidades',
                'text' => 'A cada atualização do sistema, este aviso mostra o que mudou.',
            ],
        ],
    ],
];
