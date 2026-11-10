<?php

/*
| Novidades de cada atualização, a mais recente primeiro. Depois do deploy, quem
| entrar no sistema vê a primeira da lista num aviso, uma única vez.
| "can" (opcional) mostra o item só para quem tem aquela permissão.
| Cada atualização precisa de um "id" novo, senão o aviso não aparece de novo.
*/

return [
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
                'text' => 'Em Entregas → Entregadores, cadastre quem faz as entregas (com ou sem acesso ao sistema) e escolha o entregador ao despachar.',
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
