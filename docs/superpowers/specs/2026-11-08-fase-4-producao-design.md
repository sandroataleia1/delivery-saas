# Fase 4 — produção por item e setor

**Data:** 08/11/2026. **Estado:** desenho aprovado expressamente pelo usuário; implementação autorizada.
**Base:** `82b52cf`, Fase 3 concluída localmente. **Branch:** `phase4/production-flow`.
**Referências:** plano geral de Fundação e Evolução Operacional; desenho e relatório da Fase 3. Regras anteriores continuam válidas, exceto a autorização agora recebida para iniciar a Fase 4.

## 1. Escolha confirmada e objetivo

O usuário escolheu **cada item por setor**: cozinha e bar atualizam o preparo independentemente. Uma bebida pode estar pronta enquanto a comida ainda está em preparo. A alternativa de atualizar o pedido inteiro de uma vez foi apresentada e não foi escolhida.

Construir produção digital para itens de mesas/QR e delivery/retirada, reaproveitando os pedidos e itens existentes. O painel registra recebimento pela produção, preparo, pronto, entrega e cancelamento com responsáveis e datas; nenhuma impressão comprova essas etapas. Manter Laravel, Inertia/Vue, Actions, FormRequests, polling, isolamento por empresa e dinheiro/estoque existentes.

Propostas técnicas abaixo são decisões do implementador para revisão, não novas preferências atribuídas ao usuário. Esta etapa não autoriza implantação ou operação real.

## 2. Escopo e limites

Incluído: setores por empresa e categoria; painel com filtro por setor; encaminhamento digital persistido e idempotente; estados por item; entrega pela equipe; cancelamentos com aviso à produção; indicador manual de esgotado; configuração necessária ao fluxo; acompanhamento restrito do QR; auditoria e concorrência. Mesas e delivery usam a mesma infraestrutura de produção, sem criar outro núcleo de pedidos ou financeiro.

Não incluído: impressão automática/agente local, WebSocket, adicionais, filiais, pedido online do delivery, gateways, fiscal, planos, novas baixas financeiras, alteração do momento da baixa de estoque, infraestrutura/implantação. A impressão manual atual continua como segunda via operacional, explicitamente sem comprovar preparo/entrega.

Fechamento por estados precisa ser seguro já nesta entrega: antecipar da Fase 5 apenas a trava mínima que impede fechar com item não entregue, não encaminhado ou legado sem classificação. Taxa por zona, reversões de origem e demais trabalhos da Fase 5 continuam fora do escopo.

## 3. Setores e encaminhamento

Setores têm empresa, nome e atividade. Categoria aponta para um setor da mesma empresa; empresa pode definir setor padrão para produtos sem categoria/setor específico. Não permitir inativar destino com trabalho ativo nem apagar setor com histórico. Mudança de categoria/setor afeta somente trabalhos futuros; cada trabalho guarda snapshot do destino.

Cada linha de pedido é uma unidade operacional com a quantidade inteira daquela linha, inclusive quantidades decimais já permitidas aos funcionários. Não dividir quantidade em entregas parciais nem alterar preço/quantidade durante o preparo nesta fase. Permitir atualizações independentes das linhas, não de unidades individuais dentro da mesma linha.

Um registro de produção referencia exatamente uma origem: item de comanda ou item de delivery. FKs e unicidade por origem impedem duplicação; empresa da origem, produto e destino é validada no servidor. Snapshots operacionais contêm nome, unidade, quantidade, observações e setor, sem duplicar dinheiro/contas. A fonte de preço/cobrança continua sendo o item original.

A confirmação manual do QR cria itens e os encaminha ao painel na mesma transação. Lançamento da equipe e criação do delivery também criam/encaminham trabalho digital. Sem destino válido, recusar a operação antes de cobrar/criar trabalho parcial, com orientação para configurar categoria/setor padrão. Não usar uma fila invisível nem descartar item. Reenvio/duplo clique retorna o resultado existente; lote de impressão não é chave de idempotência da produção.

O botão Cozinha existente passa a consultar o encaminhamento já persistido e permitir impressão manual, sem duplicar trabalho nem avançar estado. Envio em comanda fechada é recusado. Preservar snapshots históricos de lote, sem inferir estados novos deles.

## 4. Estados e entrega

Aprovação do recebimento QR permanece separada: `pending`, `confirmed`, `rejected`. Produção: `waiting` → `preparing` → `ready`. Entrega é um registro separado, permitida apenas quando pronto. Cancelamento é terminal e não é entrega.

Não permitir saltos/regressões por POST, replay de transição ou tela antiga. Cada transição recebe estado/versão esperado; novo clique após alteração gera resposta clara sem avançar duas etapas. Preparar não cobra, não vende e não baixa estoque. Encaminhamento digital não marca automaticamente preparando.

Para mesa: equipe com permissão registra a entrega do item pronto. Conta aberta soma os itens confirmados não cancelados, para transparência; a venda só fecha quando todos esses itens estiverem entregues. Não cobrar pendente/rejeitado/cancelado. Conservar as conferências monetárias e idempotência do fechamento.

Para delivery/retirada: preparo/pronto do pedido são derivados das linhas. Primeiro item em preparo torna o pedido preparando; só fica pronto quando todas as linhas ativas estiverem prontas. Painel de entrega conserva a atribuição de entregador, saída e conclusão; saída/retirada não pode contornar itens em preparo. A conclusão da entrega marca a entrega das linhas, sem antecipar o acerto financeiro. Remover o avanço de preparo por pedido inteiro que contrariaria a escolha por item; endpoints antigos recusam esse atalho e orientam o painel. Preservar validação `expected_status` e bloqueio de cancelamento comum de entregue.

## 5. Cancelamentos e aviso à produção

Antes de iniciar preparo: manter permissão atual de cancelamento da origem. Desde `preparing` (inclusive pronto/entregue em mesa ainda aberta), exigir também permissão de gestão de produção e motivo. Cancelamento de comanda inteira verifica todas as linhas sob os mesmos locks; nenhum endpoint pode contornar a regra cancelando o pai.

Cancelar atualiza a origem e o trabalho e registra evento de cancelamento por setor na mesma transação. O aviso permanece visível até ciência explícita por operador do setor; registrar quem tomou ciência e quando. Não apagar silenciosamente cartão em preparo. Repetir cancelamento não duplica efeitos ou aviso.

Em delivery/retirada, cancelamento permanece do pedido inteiro: não adicionar cancelamento parcial de linhas nem recálculo de subtotal nesta fase. Cancelar o pedido cancela todos os trabalhos ativos e emite os avisos necessários. Cancelamento comum de delivery entregue/acertado continua proibido conforme Fase 2; não criar devolução/perda financeira nesta fase. Cancelamento de produção não devolve estoque por presunção e não inventa lançamento de perda: domínio atual só baixa estoque no fechamento/acerto. Contas e vendas históricas não são recalculadas.

## 6. Disponibilidade

Adicionar indicador manual de esgotado no produto, separado de atividade e saldo. Produtos esgotados continuam visíveis como indisponíveis no cardápio; servidor recusa novo recebimento/lançamento. Revalidar ao confirmar um QR pendente. Se esgotar enquanto pendente, não cobrar nem substituir: equipe resolve ou rejeita. Trabalho já confirmado preserva snapshot e exige decisão explícita para cancelar.

Reenvio de UUID persistido continua retornando o mesmo recebimento antes de revalidar disponibilidade atual. O indicador rege novos pedidos de restaurante (QR, mesa e delivery), sem alterar o contrato de vendas diretas do PDV. Não converter estoque negativo permitido em bloqueio geral; bloqueio automático por saldo fica fora desta fase.

## 7. Manual, automático e configuração

Manual permanece padrão. Neste fluxo, confirmação manual passa a encaminhar ao painel funcional; opção automática só confirma/encaminha novos recebimentos se destino estiver válido e produção habilitada.

Uma preferência automática salva na Fase 3 **não ativa sozinha** após a atualização. Proposta: exigir ativação explícita pela empresa, com `company.manage`, aviso claro sobre pedido remoto, registro de aceite/responsável/data e checagem de destino padrão ativo. Bloqueio do super administrador prevalece sem apagar preferência/aceite. Ao desabilitar a produção automática ou invalidar o destino, resolver para manual com motivo; não alterar recebimentos antigos.

Manter snapshot de modo/limites/fluxo por recebimento. Alterações de configuração afetam novos recebimentos; não confirmar pendentes antigos retroativamente. Automático funciona em transação única, sem depender de impressão ou worker: falha de encaminhamento reverte a confirmação e retorna erro sem cobrança parcial. Não reduzir limites ou retirar controles de abuso da Fase 3.

## 8. Interface, privacidade e permissões

Painel responsivo com colunas Aguardando/Preparando/Pronto e avisos de cancelamento, filtro por setor e indicação da origem (mesa/comanda ou número da entrega). Ordenação estável pelo recebimento; atraso e sinalização de conexão. Polling de 5 a 10 s com limpeza de timers, deduplicação de eventos e ativação explícita de áudio. Sem prometer SLA em abas suspensas ou rede caída.

A cozinha não precisa de preços, fiado, endereço ou telefone do cliente: não enviar esses dados nas props do painel. QR continua expondo apenas itens do próprio recebimento com chave privada; acrescentar preparo/entrega/cancelamento de cada linha e mensagem correspondente. Não expor funcionários, total da comanda ou outros clientes. Preservar os quatro testes de recuperação do cliente da Fase 3.

Usar o catálogo atual: permissões propostas `production.view`, `production.operate`, `production.deliver`, `production.manage`. Administrador mantém acesso total. Proposta de compatibilidade: perfis que operam mesas recebem visão e registro de entrega; operar preparo e gerir cancelamento pós-preparo ficam inicialmente com administradores e perfis explicitamente configurados, sem conceder gestão por nome de perfil. Setores/configuração são administrados por permissão específica do mesmo catálogo. Não criar uma segunda fonte de autorização.

## 9. Legado e migração

Migrations aditivas; nenhuma execução em banco operacional. Itens/comandas/vendas encerrados permanecem com snapshots e valores originais. Não preencher `delivered` a partir de `kitchen_batch`, nem fabricar datas de preparo.

Itens ativos anteriores à implantação ficam explicitamente aguardando reconciliação. Antes de habilitar o fluxo, gestor classifica cada item: reenviar como aguardando, registrar estágio observado (preparando/pronto/entregue) com motivo obrigatório e evidência operacional, ou cancelar conforme autorização. A classificação registra o estado observado agora e a data da reconciliação; não fabrica horário histórico de início/preparo/entrega. Toda classificação é uma operação assistida auditada, restrita ao legado identificado na migração; não é botão para ignorar pendências de itens novos.

Aplicar também aos deliveries não cancelados e ainda sem venda, inclusive entregues aguardando acerto: reconciliar antes de avançar/acertar quando houver linha sem estado conhecido. Histórico já acertado/encerrado não exige reclassificação. Retornos idempotentes de vendas/acertos já gravados permanecem válidos. Produção/fechamento de origem com legado pendente ficam bloqueados com mensagem, sem marcar entrega automaticamente. Rollback que descartaria trabalho/eventos populados é recusado. Futuro deploy exige ensaio com cópia sanitizada, interrupção de escritas, backup/restauração e conferência das comandas abertas.

## 10. Integridade, locks e auditoria

Company primeiro; mesas ordenadas quando aplicável; origem (comanda ou delivery); recebimento quando existir; trabalhos/linhas em ordem de ID. Actions de delivery precisam aderir à mesma ordem para não criar ciclo com produção. Consultas de leitura não adquirem locks de escrita; filtros por empresa explícitos nos caminhos públicos/console.

Atualizar fonte, produção e evento sob uma transação. Eventos e auditoria guardam estado anterior/novo, ator, data, motivo e origem; manter trigger de imutabilidade da auditoria em MariaDB. Não excluir evento para representar cancelamento ou corrigir histórico. Modelo novo não enfraquece os escopos existentes.

Idempotência e constraints impedem duplicar trabalho por confirmação/replay; estado esperado impede preparo/entrega duplicados. Disputas entre entregar/cancelar/fechar, confirmar/esgotar e receber/transferir devem terminar em estado válido e efeitos financeiros únicos.

## 11. Aceite e sequência de implementação

1. Schema, setores/destinos, disponibilidade e testes de isolamento; factories úteis e concessões de permissão verificadas.
2. Trabalhos/encaminhamento idempotente e integração com confirmação/lançamento/delivery; sem novo caminho financeiro.
3. Transições, entrega, cancelamentos com ciência e reconciliação assistida de legado; travas de fechamento/acerto necessárias.
4. Painel, categorias/produtos/configuração e acompanhamento restrito do cliente; rede/áudio e controle de respostas antigas.
5. Automático somente após encaminhamento funcional e ativação explícita; bloqueio administrativo e snapshots preservados.
6. Suítes SQLite/MariaDB descartável, testes cliente, C1–C9/F1–F5/R1–R5 adaptados somente onde muda o contrato de produção; novos cenários concorrentes com invariantes de origem, produção, dinheiro e estoque. Build, Pint, auditorias e revisão independente final; relatório com commits e limitações.

Exigir: bebida pronta não avança comida; transição inválida recusada; cliente não vê envio alheio; cancelar após preparo exige gestor; aviso de cancelamento não some sem ciência; fechado não recebe encaminhamento; pendências/legado não fecham; produto esgotado não entra em pedido novo; replay continua válido; automático bloqueado não confirma; nenhuma venda/baixa de estoque antes do fechamento/acerto.

Após aprovação deste desenho: criar plano executável detalhado antes de alterar schema. Nenhuma Fase 5/6, impressão automática, push, merge, remoto ou deploy está autorizada por esta proposta.


## 12. Revisão do desenho e decisões do implementador

Revisão independente de construção concluída. Única questão importante: extensão de cancelamento individual ao delivery. Resolvida preservando o contrato existente: somente cancelamento do pedido inteiro em delivery/retirada; sem recálculo de subtotal/taxa ou nova devolução financeira. Preparo por item continua independente. Essa é uma proposta técnica de contenção de escopo, apresentada explicitamente à revisão do usuário.

Outras propostas a conferir na aprovação: proteção de fechamento antecipada apenas no necessário; destino obrigatório sem descarte silencioso; reconciliação assistida de legado, inclusive delivery entregue sem acerto; automático somente após ativação explícita e aceite de risco. Sem código de aplicação, migrations executadas, alteração de dependências ou acesso a dados reais nesta preparação.
