# Fase 3 — recebimento seguro de pedidos por QR Code

**Data:** 07/11/2026. **Estado:** início da fase autorizado; desenho detalhado em revisão antes de migrations ou implementação. Base `d622c79`, branch local `phase3/qr-receiving`. Não iniciar a Fase 4.

**Referências:** seções 3 e 4 do pedido original de continuidade; [plano geral aprovado](../plans/2026-11-06-fundacao-e-evolucao-operacional.md), especialmente seções 4 e 5; [auditoria original](../../auditoria/RELATORIO-AUDITORIA-2026-11-06.md). A numeração vigente é a do plano reorganizado: Fase 2 financeira, Fase 3 recebimento, Fase 4 produção.

## 1. Objetivo e limites

Um cliente anônimo envia um pedido para a mesa, recebe confirmação de persistência e acompanha apenas seu envio. A equipe vê a pendência e confirma ou rejeita. Somente a confirmação cria itens cobrados na comanda existente. Vários celulares compartilham o atendimento da mesa, sem compartilhar acesso ao consumo alheio.

Manter QR fixo, acesso sem login/PIN, uma comanda aberta por mesa, Laravel/Inertia/Vue, Actions, polling, decimal/bcmath e o núcleo financeiro validado na Fase 2. O contexto opaco detecta página antiga; não autentica presença. Um link recém-aberto remotamente ainda pode enviar pedidos. Confirmação manual e limites reduzem esse risco sem eliminá-lo.

Fora desta fase: painel/setores de produção, preparo/pronto/entregue, impressão automática, novas regras de estoque, adicionais, gateway, fiscal, assinaturas e implantação. Não declarar AUD-001 integralmente resolvido enquanto não houver fluxo de produção; nesta fase corrigir recebimento, alerta e mensagem enganosa. Não declarar eliminação do risco remoto de AUD-002.

## 2. Abordagem proposta e alternativas

**Proposta do implementador:** registrar recebimentos e linhas separados dos itens cobrados. A confirmação copia os snapshots para `table_order_items`; venda, caixa e estoque continuam nos fluxos existentes. Isso preserva o significado atual dos itens e evita que um pedido ainda não autorizado entre no subtotal ou no fechamento.

Alternativa considerada: inserir imediatamente em `table_order_items` com flag pendente. Exigiria mudar simultaneamente todas as consultas de subtotal, impressão, fechamento e relatórios para excluir pendentes; uma consulta esquecida cobraria pedido indevido. Não é recomendada para esta fase incremental.

Não criar um segundo núcleo financeiro, contas por visitante nem nova infraestrutura de WebSocket. O desenho segue a direção do plano geral; os detalhes abaixo são propostas técnicas do implementador para revisão, sem presumir nova aprovação do usuário.

## 3. Recebimento, snapshots e idempotência

Criar `MenuOrderReceipt` e `MenuOrderReceiptItem`, com `company_id` e escopo existente. O recebimento guarda mesa original, comanda, contexto, UUID do cliente, hash canônico do conteúdo, hash da chave de acompanhamento, total decimal, aprovação e snapshot de configuração efetiva. As linhas guardam produto, nome, unidade, quantidade inteira, preço obtido no servidor, total e observação.

Unicidade no banco: `(company_id, client_uuid)`. Conteúdo canônico inclui mesa, contexto e linhas na ordem enviada, com IDs/quantidades normalizados e observação normalizada de forma determinística; não inclui preço informado pelo navegador. Reenvio usa exatamente o payload persistido pelo cliente. A cotação e validação de disponibilidade atuais só são feitas para um recebimento novo.

Um reenvio idêntico, com a mesma chave de acompanhamento, retorna o recebimento original mesmo após mudança de preço, inativação do produto, rejeição ou encerramento do atendimento. Isso não abre comanda nem inclui novos itens. UUID reutilizado com outra mesa/contexto/conteúdo é recusado com conflito. UUID não concede acesso ao histórico.

O navegador gera UUID e chave aleatória de 256 bits antes do primeiro envio e preserva ambos com o payload em armazenamento local restrito à mesa/contexto. O servidor guarda somente hash da chave. Consulta de acompanhamento exige a chave em header, nunca na URL; um UUID isolado ou chave inválida não expõe dados. Excluir chave/hash de auditoria e props administrativas. Essa chave protege somente a leitura daquele envio; não é PIN, autorização inicial, sessão autenticada de mesa nem prova de presença. Não usar uma sessão global do navegador para autorizar todos os envios de uma mesa.

A consulta pública apresenta apenas produtos, quantidades, valores e aprovação daquele recebimento. Não retorna subtotal da comanda, consumo de outros celulares, caixa, venda ou identificação de funcionários. Nome público da empresa/mesa e cardápio continuam disponíveis. O recebimento responde “Pedido recebido, aguardando confirmação”; confirmar não pode informar “em preparo” ou “entregue”.

## 4. Atendimento e concorrência

Acrescentar uma versão opaca do atendimento à mesa. Inicializar em lotes durante migration aditiva e gerar para mesas novas; não alterar ao primeiro pedido nem ao abrir uma mesa livre pela equipe. Duas páginas abertas na mesa livre podem aderir ao mesmo atendimento.

No recebimento novo: validar empresa/mesa ativas e QR habilitado; travar os registros; conferir contexto atual; abrir ou aderir à comanda; validar produtos e limites; persistir recebimento/linhas e auditoria numa única transação. Não depender de `known_order_id = null`, de validação fora da transação ou do usuário eventualmente autenticado no aparelho.

Fechamento e cancelamento invalidam o contexto antes de concluir sua transação. Transferência invalida o contexto da origem e do destino, preserva a comanda e os recebimentos já registrados, e obriga páginas antigas a recarregar. Recebimentos pendentes transferidos acompanham a comanda; os novos pedidos vêm somente da mesa atual. Acompanhamento de envio anterior continua limitado àquele envio.

**Dependência técnica a antecipar:** padronizar a ordem de locks das Actions afetadas para Company → mesas por ID → comanda → recebimento/linhas. Isso inclui recebimento, confirmação/rejeição, abertura, fechamento, cancelamento e transferência. Evitar locks invertidos na rotação do contexto; executar C9 novamente. Se essa alteração resolver o deadlock conhecido, só registrar AUD-020 como corrigido após evidência; não tratá-lo como resolvido por antecipação.

Acrescentar ao fechamento e ao cancelamento da comanda o bloqueio mínimo de recebimentos pendentes, sob os mesmos locks. A equipe precisa resolver os recebimentos antes de encerrar o atendimento; não rejeitá-los silenciosamente. Não esconder a pendência por ela ainda não aparecer no subtotal. O bloqueio completo de preparo/pronto/entrega continua nas fases 4/5; a Fase 3 isolada não autoriza operação real do fluxo novo.

## 5. Aprovação e integração com a comanda

Estados desta etapa: `pending`, `confirmed`, `rejected`. Estados de produção não serão simulados. Confirmar exige comanda aberta, mesmo atendimento operacional e permissão; rejeitar exige motivo. O atendimento é identificado pela comanda: depois de transferência, o contexto da mesa original não é revalidado para decidir um recebimento já persistido. Registro de decisão contém autor, data e motivo. Tentativas simultâneas não podem confirmar duas vezes nem confirmar e rejeitar o mesmo recebimento.

Ao confirmar, revalidar existência/atividade dos produtos, preservando os preços e nomes recebidos pelo servidor no envio original. Se um produto ficou indisponível, manter pendente com erro claro para a equipe rejeitar ou resolver; não substituir preços ou produtos silenciosamente. Criar os itens existentes com snapshot e referência única ao item recebido. A FK única de origem por linha impede cópia dupla, complementando o lock do recebimento. Não baixar estoque nem movimentar caixa nessa confirmação.

Pedidos confirmados continuam com envio manual atual à cozinha, identificado como manual. A interface informa explicitamente “Confirmado; aguardando envio pela equipe”. Não chamar `window.print()` de encaminhamento automático. Cancelamentos posteriores de itens continuam pelo mecanismo existente; o acompanhamento mostra o cancelamento do próprio item ou do atendimento, quando ocorrer, sem inventar estado de produção ou reescrever a aprovação histórica.

Nova permissão `tables.confirm` para confirmar/rejeitar; `tables.operate` continua governando a leitura operacional. Administradores mantêm acesso pelo catálogo. Proposta para compatibilidade: acrescentar `tables.confirm` aos perfis existentes que já possuem `tables.operate`, preservando as demais permissões e sem inferência pelo nome do perfil. Testar concessão, recusa e isolamento. Alterações futuras permanecem no gerenciamento atual de perfis.

## 6. Operação do Restaurante e modo efetivo

Criar `RestaurantOperationSettings` com uma linha única por empresa e resolvedor `RestaurantOperationPolicy`. A seção “Operação do Restaurante” usa o layout atual de `company/Edit.vue`, com endpoint/FormRequest próprio protegido por `company.manage`. Ausência de registro equivale a defaults seguros.

Persistir preferência de confirmação, limites e preferências de alerta. Bloqueio do automático é administrado exclusivamente pelo super administrador, em operação separada, com motivo obrigatório, responsável, horário e auditoria de bloqueio/desbloqueio. O restaurante não envia nem altera esses campos. Bloquear preserva a preferência e não modifica recebimentos anteriores.

**Automático nesta fase:** preservar a preferência e o bloqueio, mas manter o modo efetivo manual enquanto não houver encaminhamento funcional à produção. Exibir o motivo na configuração. A disponibilidade de produção é controlada pelo servidor e não por campo do formulário. Nem administrador da empresa nem super administrador podem usar isso para contornar a ausência do encaminhamento. Habilitar automático na Fase 4 dependerá de integração e aceite do risco remoto já previstos no plano.

Snapshot por recebimento: preferência, modo efetivo, motivo da decisão, limites aplicados e opção de alertas. Mudanças posteriores valem para recebimentos novos; não rejeitam ou confirmam os antigos retroativamente. Desativar a empresa/cardápio deve impedir novos envios sem apagar os registros existentes.

## 7. Limites propostos e proteção de abuso

Valores abaixo são **propostas conservadoras e reversíveis do implementador**, não valores já aprovados para uso real. Validar com cardápios reais antes do piloto. Guardar dinheiro como decimal, jamais float.

| Limite | Inicial por empresa | Teto da plataforma proposto |
|---|---:|---:|
| Linhas por envio | 20 | 30 |
| Unidades por linha | 10 | 50 |
| Unidades por envio | 50 | 200 |
| Valor por envio | R$ 500 | R$ 5.000 |
| Recebimentos pendentes por mesa | 5 | 20 |
| Unidades pendentes por mesa | 100 | 500 |
| Valor pendente por mesa | R$ 1.000 | R$ 10.000 |

Teto acumulado é conferido sob lock, incluindo o novo envio, antes de persistir. Recusas não criam comanda vazia nem histórico parcial. Reenvio idempotente não consome o saldo pendente novamente. Confirmados/rejeitados deixam a soma pendente.

Rate limiting proposto: 20 envios/minuto por mesa + IP, 60/minuto por mesa e 600/minuto por empresa. Aplicar chaves prefixadas e sem token público em claro; considerar preços/quantidade como proteção principal. Não existe limite global compartilhado entre empresas. Leitura de acompanhamento tem orçamento separado por recebimento/chave para não bloquear o envio. Produção atrás de proxy confiável continua dependente da topologia da Fase 6; não confiar indiscriminadamente em `X-Forwarded-For`.

Resposta 429 apresenta espera e preserva payload/identificadores. Não usar o polling como produtor de logs de auditoria a cada leitura.

## 8. Alertas e conexão

Lista de mesas e comanda mostram contagem de recebimentos pendentes, idade e ligação para confirmação. Polling operacional de 10 s, com aviso inicial de atraso após 10 minutos, ambos alinhados ao plano. Preferência inicial visual e sonora; som requer interação explícita do usuário para respeitar o navegador. Não prometer som em aba suspensa, aparelho bloqueado ou antes de habilitá-lo.

Controlar IDs já anunciados na aba para não tocar a cada poll; atualização inicial e retorno de conexão mostram pendências existentes sem multiplicar som indefinidamente. O polling sempre recupera o estado persistido. Uma resposta antiga não sobrescreve estado mais recente. Nunca notificar empresa/setor incorretos.

No cliente, erro de rede antes da persistência mostra falha sem sucesso; após persistência com resposta perdida, reenvio mantém chave, UUID, contexto e payload. Não permitir edição silenciosa do mesmo payload enquanto o resultado estiver ambíguo: consultar status ou reenviar o original primeiro. Após erro de validação confirmado, corrigir o pedido e gerar novo envio quando seu conteúdo mudar.

Exibir aviso operacional quando a tela não tiver resposta bem-sucedida por 30 s; limpar somente após resposta real. Persistir pedidos não significa que estão preparados. Não limpar rascunho sem confirmação do backend. Remover consulta pública da comanda completa e dados internos como barcode/SKU da resposta pública afetada.

## 9. Dados, migrations e migração de legado

Novos componentes propostos: `MenuOrderReceipt`, `MenuOrderReceiptItem`, `RestaurantOperationSettings`; Actions `ReceiveMenuOrder`, `ConfirmMenuOrder`, `RejectMenuOrder`, `UpdateRestaurantOperationSettings`, `SetAutomaticConfirmationBlock`; resolvedor `RestaurantOperationPolicy`. Preservar/adaptar `PlaceMenuOrder` como ponto de integração para evitar manter duas implementações de recebimento público.

Migrations aditivas posteriores a `2026_11_07_100004`: versão de atendimento; settings; recebimentos/linhas; referência única nullable em `table_order_items` para a linha recebida. FKs financeiras/históricas com restrição de exclusão. Índices de empresa/status/comanda/data para pendências e unicidade empresa/UUID. Não alterar migrations entregues, apagar auditoria ou aplicar schema fora do harness descartável.

Itens e comandas antigos não serão classificados automaticamente como entregues nem migrados para recebimentos inventados. Inicializar contexto das mesas existentes sem mexer nos preços ou snapshots de comandas abertas. Páginas antigas sem contexto recebem validação e instrução para recarregar. Antes de futura implantação, reconciliar comandas abertas, validar backup/restauração e ensaiar numa cópia sanitizada, conforme o plano geral.

## 10. Sequência de implementação após aprovação deste desenho

1. Plano detalhado com arquivos/interfaces e testes RED/GREEN; baseline Git recuperável e confirmação das instruções locais. Sem nova instalação de dependências.
2. Settings/resolvedor, permissão e bloqueio administrativo, com isolamento e snapshot; automático indisponível sem produção.
3. Contexto e disciplina de locks em fechamento/cancelamento/transferência; testes de páginas antigas, adesão simultânea e C9.
4. Recebimentos/linhas, idempotência e limites transacionais; sem cobrança pendente e sem efeitos financeiros/estoque.
5. Confirmação/rejeição manual e bloqueio de fechamento pendente; itens confirmados únicos, rejeitados fora do subtotal.
6. Cardápio/acompanhamento restrito, configurações e alertas operacionais; falhas de conexão com reenvio seguro.
7. Suítes SQLite/MariaDB, concorrência nova e regressões C1–C9/F1–F5, Pint, build em sequência separada dos testes HTTP, revisão independente e relatório. Commits locais; nenhuma integração/deploy.

## 11. Critérios de aceite e foco dos testes

- Dois celulares na mesa livre: um atendimento e uma comanda; ambos os recebimentos persistem como pendentes, subtotal zero e nenhum movimento financeiro/estoque.
- Mesmo UUID/conteúdo/chave simultâneo: um recebimento, mesmo resultado; conteúdo ou mesa divergente recusado; repetição após encerramento não abre comanda.
- Página antiga após fechamento, cancelamento ou transferência: envio novo recusado; contexto da mesa livre antes de abrir também protege o atendimento seguinte.
- Perda da resposta e mudança de preço/produto: repetir recupera o resultado original, sem nova validação comercial invalidar o reenvio já persistido.
- Confirmar × confirmar e confirmar × rejeitar: uma decisão final auditada; preço do servidor preservado; rejeição não gera item cobrado.
- Receber × fechar e confirmar × transferir: resultado coerente ou recusa explícita, sem item órfão, comanda duplicada ou novo erro operacional de lock.
- Fracionamento de pedidos: limites acumulados impedem contorno, inclusive concorrente; requisição recusada não deixa efeitos parciais.
- Empresa A não consulta nem decide recebimentos/configurações de B. Visitante sem chave não lê sequer um envio alheio; usuário autenticado de outra empresa continua visitante no QR.
- Mudanças de preferência e bloqueio preservam snapshots anteriores. Só super administrador administra bloqueio; restaurante preserva preferência sem poder alterar disponibilidade de produção.
- Polling recupera pendências, sinaliza falta de atualização e não repete notificações indefinidamente. Histórico de auditoria permanece imutável.
- Regressões financeiras da Fase 2 continuam aprovadas. Não apresentar teste de SQLite como prova de locks/triggers ou build como teste visual em navegador.

No harness existente, C3 terá seu contrato atualizado: vinte envios públicos agora produzem vinte recebimentos pendentes numa comanda e zero itens cobrados, em vez de vinte itens imediatamente cobrados. A alteração deve preservar as asserções de unicidade e ausência de perdas; não ignorar o cenário nem enfraquecer sua verificação. Os demais cenários continuam exercitando seus contratos próprios.

## 12. Revisão e decisões pendentes

O início da Fase 3 já foi autorizado pelo usuário. Antes de escrever código/schema, este documento precisa da revisão do desenho exigida pelo fluxo de trabalho. Pontos concretos para aprovação: recebimento separado dos itens cobrados, automático preservado como preferência mas indisponível nesta fase, valores iniciais/tetos propostos e antecipação da ordem de locks/bloqueio mínimo de fechamento por dependência de segurança.

O plano de implementação executável será produzido depois dessa aprovação. Nenhuma migration, alteração de fluxo ou dependência foi criada nesta preparação.

Revisão por `phase3_design_check`: identificou a necessidade de definir cancelamento com recebimentos pendentes. Resolvida no desenho com bloqueio até decisão explícita da equipe, coerente com o fechamento e sem exigir rejeição automática ou ampliar implicitamente a permissão de cancelamento. Essa é uma escolha conservadora do implementador apresentada à aprovação, não uma regra nova atribuída ao usuário.

Baseline executada em 07/11/2026, antes de alterações de aplicação: `php artisan test --compact`, 476 casos, 474 aprovados, dois testes de trigger exclusivos de MariaDB ignorados, 2.669 asserções, 48,844 s. Saída zero. Resultado atual de SQLite; MariaDB não foi reexecutado nesta preparação exclusivamente documental. O resultado MariaDB da Fase 2 permanece evidência histórica, sem reapresentá-lo como nova execução.
