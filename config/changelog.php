<?php

/*
| Novidades de cada atualização, a mais recente primeiro. Depois do deploy, quem
| entrar no sistema vê a primeira da lista num aviso, uma única vez.
| "can" (opcional) mostra o item só para quem tem aquela permissão.
| Cada atualização precisa de um "id" novo, senão o aviso não aparece de novo.
*/

return [
    [
        'id' => '2026-11-10.9',
        'date' => '10/11/2026',
        'items' => [
            [
                'title' => 'Produto esgotado não entra mais na mesa',
                'text' => 'Ao lançar itens numa mesa, o sistema recusa produto marcado como esgotado e avisa qual é ("X-Bacon está esgotado."), em vez de mandar para a cozinha.',
                'can' => 'tables.operate',
            ],
            [
                'title' => 'App do garçom: cardápio e pedidos',
                'text' => 'No app do garçom já dá para abrir uma mesa livre, ver a comanda com a situação de cada item e lançar pedidos pelo cardápio, inclusive pizzas e adicionais. O preço sempre vem do sistema.',
                'can' => 'tables.operate',
            ],
        ],
    ],
    [
        'id' => '2026-11-10.8',
        'date' => '10/11/2026',
        'items' => [
            [
                'title' => 'Trocar a senha desconecta os celulares',
                'text' => 'Ao trocar ou redefinir a senha, ou ao inativar um usuário, os acessos dele pelo app do garçom são encerrados na hora. O app do garçom está em preparação e chega em breve.',
            ],
        ],
    ],
    [
        'id' => '2026-11-10.7',
        'date' => '10/11/2026',
        'items' => [
            [
                'title' => 'Sair pelo painel da cozinha',
                'text' => 'O painel da cozinha ganhou o botão "Sair" (fora da tela cheia), para trocar de usuário sem passar pelo menu.',
                'can' => 'production.view',
            ],
            [
                'title' => 'Som do painel da cozinha lembrado',
                'text' => 'Depois de ativar o som uma vez, o painel lembra e não mostra mais o botão "Ativar som": ao recarregar a página, o som volta no primeiro toque em qualquer lugar da tela.',
                'can' => 'production.view',
            ],
        ],
    ],
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
