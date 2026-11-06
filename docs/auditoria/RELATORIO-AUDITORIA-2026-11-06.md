# Auditoria completa — arquitetura, segurança, funcionalidade e prontidão para produção

**Projeto:** Delivery SaaS (`c:\xampp\htdocs\delivery-saas`)
**Data:** 06/11/2026
**Modo:** somente auditoria. Nenhuma correção foi feita.

## Como ler este relatório

Toda afirmação usa uma destas marcas:

| Marca | Significado |
|---|---|
| **[Comprovado]** | Observado em execução, na cópia isolada. A evidência está na seção 12. |
| **[Código]** | Identificado só pela leitura do código ou pela ausência no repositório. Não foi executado. |
| **[Não verificado]** | Não houve como verificar nesta auditoria. |

As referências às provas seguem esta convenção: **P01–P24** são testes de prova de conceito e **C1–C9** são cenários de concorrência. Os arquivos estão em [evidencias/](evidencias/).

Em uma prova de conceito, "passou" significa que **o comportamento descrito existe**. Se esse comportamento for um defeito, o teste passar confirma o defeito.

---

## 1. Diagnóstico geral

O núcleo do sistema é bem construído e sua classificação atual é **Não apto para produção**. Os motivos estão no produto e na operação, não na arquitetura.

**O que está sólido, com execução comprovada:**

- **Isolamento entre empresas:** nenhum vazamento em 50 rotas com ID e 11 referências cruzadas no corpo das requisições.
- **Dinheiro e estoque sob concorrência:** com processos simultâneos em MariaDB, não houve venda duplicada, baixa dupla, caixa duplo nem saldo divergente.
- **Autorização:** 106 de 108 rotas da empresa negam acesso a um usuário sem permissões. As outras duas são esperadas: a página inicial responde 200 e `/relatorios` redireciona.
- **Suíte de testes:** 317 de 317 em SQLite.

**O que impede a produção:**

1. **O pedido do QR Code não chega à cozinha.** O sistema diz ao cliente que chegou, mas ele fica parado até um funcionário abrir aquela comanda e apertar um botão. A lista de mesas não mostra nenhum alerta.
2. **O link do QR Code é permanente e anônimo.** Quem guardou o link pode lançar pedidos de longe, na conta de quem estiver na mesa. Uma única requisição aceita até 1.500 unidades.
3. **O financeiro aceita baixas incoerentes.** Pagar R$ 1 de um fiado de R$ 80 quita a dívida inteira. Uma conta gerada por venda pode ser editada ou cancelada sem refletir na venda.
4. **Faltam itens básicos para vender como SaaS:** planos e cobrança, painel de cozinha, adicionais e variações, pedido online do cliente no delivery, emissão fiscal, backup e monitoramento.

Dois defeitos são Críticos e têm correção de porte pequeno a médio. **Não há motivo técnico para reescrever nada.**

### Problemas por severidade

| Severidade | Quantidade |
|---|---:|
| Crítica | 2 |
| Alta | 11 |
| Média | 18 |
| Baixa | 9 |
| **Total** | **40** |

---

## 2. Arquitetura encontrada

| Item | Encontrado |
|---|---|
| Estilo | Monólito Laravel + Inertia + Vue, sem API separada |
| Backend | Laravel 13.40.0 (`^13.17`), PHP `^8.3`, Inertia Laravel 3.7.0 |
| Frontend | Vue 3.5, Inertia v3, Ziggy, Tailwind 4, Vite 8. JavaScript puro, sem TypeScript |
| Banco | MariaDB/MySQL previsto; SQLite em memória na suíte |
| Organização | 27 Actions, 25 modelos, 14 FormRequests, 10 classes de relatório |
| Rotas | 129 no total, das quais 10 públicas (login, cadastro, senha, QR Code) |
| Multiempresa | Coluna `company_id` + escopo global (`BelongsToCompany`, `App\Support\Tenant`) |
| Permissões | Perfis por empresa (`config/permissions.php`), `Gate::before`, middleware `can:` |
| Auditoria | `audit_logs` imutável por trigger em MariaDB/MySQL |
| Tempo real | Polling Inertia de 10 a 20 s. Sem WebSocket |
| Filas e agendador | Tabelas existem, mas nada usa. `routes/console.php` só tem o comando de exemplo |
| Pagamento, fiscal, webhooks | Inexistentes |
| Upload de arquivos | Inexistente |
| CI/CD, Docker | Inexistentes |

### Versões e suporte

| Componente | Situação |
|---|---|
| Laravel 13 | Correções de bugs até o 3º trimestre de 2027 e de segurança até 17/03/2028, segundo a [documentação oficial](https://laravel.com/docs/13.x/releases). Suporta PHP 8.3 a 8.5 |
| PHP | `composer.json` aceita 8.3 ou superior. A CLI local é 8.5.12 e o `CLAUDE.md` cita 8.4. **A versão de produção não está definida** |
| MariaDB | Local 10.4.32, linha antiga e já fora do suporte do fabricante, pelo conhecimento do auditor (não reconsultado). A versão de produção é [Não verificado] |
| Dependências | `composer audit` e `npm audit --omit=dev`: 0 vulnerabilidades conhecidas [Comprovado] |

**Adequação a SaaS:** o modelo de banco único com `company_id` é adequado para o porte esperado. Fica uma ressalva, tratada em AUD-024: o isolamento depende só da aplicação.

---

## 3. Matriz de funcionalidades

"Funcional e comprovada" significa que há testes automatizados passando. Isso não equivale a aprovação para produção, porque nenhum módulo foi validado em operação real.

| Módulo | Situação | Evidência | Problemas | Prioridade |
|---|---|---|---|---|
| Empresas | Funcional e comprovada por testes | `CompanyAdminTest` (9), `RegisterTest` (11) | AUD-022 | P2 |
| Unidades e filiais | Ausente | Não há modelo nem coluna | AUD-030 | P3 |
| Administração e configurações | Funcional e comprovada por testes | `CompanyAdminTest` | — | — |
| Usuários e permissões | Funcional e comprovada por testes | `UserManagementTest` (11), `RoleManagementTest` (8), P24 | AUD-025 | P2 |
| Produtos e categorias | Funcional e comprovada por testes | `ProductTest` (12) | — | — |
| Adicionais e variações | Ausente | Só existe observação em texto livre | AUD-013 | P1 |
| Cardápio digital | Implementada com defeitos identificados | `MenuTest` (21), P01–P09, navegador | AUD-001, 002, 019 | P0 |
| QR Code | Implementada com defeitos identificados | `MenuTest`, P01–P03 | AUD-002, 017 | P0 |
| Mesas e comandas | Implementada com defeitos identificados | `TableTest` (29), P05, P06, C3, C4, C9 | AUD-018, 020 | P1 |
| Garçons | Parcialmente implementada | Perfil e tela mobile existem; navegador | Sem alerta de pedido novo (AUD-001) | P0 |
| Delivery e retirada | Parcialmente implementada | `DeliveryTest` (28), P10–P12 | AUD-007, 012, 014, 015 | P1 |
| Caixa e PDV | Funcional e comprovada por testes | `SaleTest` (29), `CashTest` (21), C1, C2, C7, C8 | AUD-037 | P3 |
| Painel de cozinha e bar | Ausente | Nenhuma rota ou tela | AUD-003 | P1 |
| Impressão | Parcialmente implementada | Só `window.print()`; não testada em impressora | AUD-003 | P1 |
| Clientes | Implementada, mas não validada integralmente | `CreditSaleTest`, `DeliveryTest` | AUD-027 | P2 |
| Estoque e insumos | Funcional e comprovada por testes | `StockTest` (16), `RecipeTest` (18), C2 | AUD-019 | P2 |
| Compras | Parcialmente implementada | Só entrada de mercadoria, sem pedido de compra | — | P3 |
| Financeiro | Implementada com defeitos identificados | `BillTest` (29), C5, P14–P16 | AUD-005, 006, 029 | P1 |
| Contas a pagar e receber | Implementada com defeitos identificados | `BillTest`, P14–P16 | AUD-005, 006 | P1 |
| Formas de pagamento | Funcional e comprovada por testes | `SaleTest` | AUD-028 | P2 |
| Integrações de pagamento | Ausente | Nenhum gateway ou webhook | AUD-028 | P3 |
| Relatórios e dashboards | Implementada, mas não validada integralmente | `ReportTest` (21) | AUD-029, 035 | P2 |
| Notificações e tempo real | Parcialmente implementada | Polling em 5 telas; sem som, push ou e-mail de evento | AUD-001 | P0 |
| Administração SaaS | Parcialmente implementada | Empresas e auditoria; sem métricas ou faturamento | AUD-010 | P1 |
| Planos e assinaturas | Ausente | Nenhum modelo ou rota | AUD-010 | P1 |

---

## 4. Problemas identificados

Os esforços são estimativas do auditor para uma pessoa experiente no projeto. Não são prazo comprometido.

### Críticos

#### AUD-001 — Pedido do QR Code não chega à cozinha e o cliente é informado do contrário

- **Severidade:** Crítica. **Módulo:** QR Code, mesas.
- **Descrição:** o pedido é gravado na comanda, mas só vai à cozinha quando alguém abre a comanda e aperta "Cozinha". Ninguém é avisado. Enquanto isso, o cliente lê "Pedido enviado! Já vai para a cozinha."
- **Evidência:** [MenuController.php:76](../../app/Http/Controllers/MenuController.php#L76) (mensagem); [TableOrderController.php:106-111](../../app/Http/Controllers/TableOrderController.php#L106-L111) (envio só manual); [Index.vue](../../resources/js/pages/tables/Index.vue) não tem indicador de pendência, que só aparece dentro da comanda em [Order.vue:264-266](../../resources/js/pages/tables/Order.vue#L264-L266).
- **Comprovação:** [Comprovado] P04 e navegador: depois do envio, `kitchen_batch` segue `NULL` no banco e a mensagem cita a cozinha.
- **Impacto:** o cliente espera por um pedido que ninguém viu. É perda de venda e reclamação no horário de pico.
- **Como reproduzir:** peça pelo QR Code e consulte `table_order_items.kitchen_batch`.
- **Solução:** resolver junto com AUD-002. O pedido entra como **pendente**, a lista de mesas mostra alerta visual e sonoro usando o polling já existente, e a equipe confirma. Mensagem correta ao cliente: "Pedido recebido, aguardando confirmação". Por configuração da empresa, pode haver envio automático depois da confirmação.
- **Esforço:** 3 a 5 dias, junto com AUD-002. Sem dependências. Não exige WebSocket.

#### AUD-002 — Token do QR Code é permanente e dá acesso à conta de quem está na mesa

- **Severidade:** Crítica. **Módulo:** QR Code, segurança.
- **Descrição:** o link não expira nem depende de presença na mesa. Quem o tiver (foto do QR, histórico do celular, cliente anterior) pode ver a conta aberta e lançar pedidos nela. Os itens entram no subtotal e são cobrados no fechamento. Não há confirmação da equipe.
- **Evidência:** [MenuController.php:43-52](../../app/Http/Controllers/MenuController.php#L43-L52) (expõe a conta); [MenuController.php:56-77](../../app/Http/Controllers/MenuController.php#L56-L77) (limites 30 linhas × 50 unidades); [PlaceMenuOrder.php:19-29](../../app/Actions/PlaceMenuOrder.php#L19-L29).
- **Comprovação:** [Comprovado] P01: visitante sem login lançou 50 unidades na comanda aberta por outra pessoa, e o valor foi cobrado (R$ 2.000 no teste). P02: a conta fica visível para quem tem o link. P03: uma única requisição gerou R$ 60.000 em itens.
- **Impacto:** cobrança indevida, cozinha ocupada com pedido falso e exposição do consumo do cliente. O token tem 32 caracteres e não é adivinhável, mas o problema é a **reutilização** do link, não a adivinhação.
- **Como reproduzir:** garçom abre a mesa; em outro dispositivo, faça POST em `/m/{token}`; feche a conta.
- **Solução:** manter o token da mesa e acrescentar uma **sessão de mesa**: pedidos pendentes de confirmação (AUD-001), limite de valor por pedido não confirmado e expiração ao fechar a conta. O restante da conta só fica visível para quem participa da sessão. Opcionalmente, código curto exibido pela equipe.
- **Esforço:** incluído em AUD-001. Precisa de decisão de produto sobre o nível de atrito aceitável para o cliente.

### Altos

#### AUD-003 — Sem painel de cozinha e bar

- **Severidade:** Alta. **Módulo:** cozinha, impressão.
- **Descrição:** não existe painel nem separação por setor. O envio abre uma página de impressão pelo navegador, e os itens ficam marcados como enviados mesmo que a impressão falhe.
- **Evidência:** [Código] nenhuma rota de cozinha além do envio em `routes/web.php:67-68`; [Ticket.vue:13](../../resources/js/pages/tables/Ticket.vue#L13).
- **Impacto:** o item é considerado enviado sem garantia de que alguém o recebeu. Bebida e comida saem no mesmo papel.
- **Reproduzir:** cancele a impressão do navegador; os itens continuam como enviados.
- **Solução:** tela simples por setor (setor definido na categoria), com polling de 5 a 10 s e confirmação de "preparando/pronto". Não precisa de tecnologia nova.
- **Esforço:** 5 a 8 dias. Depende de AUD-001.

#### AUD-004 — Limites por IP ficam globais atrás de proxy

- **Severidade:** Alta. **Módulo:** segurança, disponibilidade.
- **Descrição:** não há `trustProxies` em `bootstrap/app.php`. Atrás de proxy ou balanceador, todos os clientes parecem ter o mesmo IP, e o limite de 20 pedidos por minuto vira um limite **de toda a plataforma**. Mesmo sem proxy, os clientes de um salão no mesmo Wi-Fi dividem o limite, o que contradiz o comentário em [AppServiceProvider.php:46-47](../../app/Providers/AppServiceProvider.php#L46-L47).
- **Comprovação:** [Comprovado] P17: 20 pedidos de IPs reais diferentes atrás do mesmo proxy bloquearam um 21º cliente com HTTP 429. A topologia real de produção é [Não verificado].
- **Impacto:** no pico, clientes legítimos ficam sem conseguir pedir. Auditoria e limites de login também registram o IP errado.
- **Reproduzir:** rode P17.
- **Solução:** configurar `trustProxies` para o proxy real e limitar o QR por token de mesa + IP, com um teto mais alto por empresa.
- **Esforço:** 0,5 a 1 dia. Depende da definição da hospedagem.

#### AUD-005 — Baixa de valor menor quita a dívida inteira

- **Severidade:** Alta. **Módulo:** contas a receber, fiado.
- **Descrição:** não existe baixa parcial. Qualquer valor maior que zero marca a conta como paga, e o saldo desaparece. Pagar valor diferente é intencional para juros e desconto (`test_pode_pagar_valor_diferente_por_juros_ou_desconto`), mas não há registro da diferença, nem limite, nem permissão específica.
- **Evidência:** [BillController.php:121-138](../../app/Http/Controllers/BillController.php#L121-L138); [SettleBill.php:33-41](../../app/Actions/SettleBill.php#L33-L41); [Customer.php:40-43](../../app/Models/Customer.php#L40-L43).
- **Comprovação:** [Comprovado] P14: fiado de R$ 80, baixa de R$ 1, conta paga, dívida do cliente zerada e limite liberado.
- **Impacto:** perda financeira direta, por erro de digitação ou desvio. Não há alerta.
- **Reproduzir:** rode P14.
- **Solução:** duas operações explícitas: baixa parcial, que mantém o saldo aberto, e quitação com desconto, que exige motivo e permissão e registra a diferença.
- **Esforço:** 3 a 4 dias. Exige migration e revisão dos relatórios de fluxo.

#### AUD-006 — Conta gerada por venda pode ser alterada ou cancelada sem coerência com a venda

- **Severidade:** Alta. **Módulo:** financeiro.
- **Descrição:** a conta de um fiado pode ter o valor editado ou ser cancelada diretamente. A venda continua concluída no valor original, e o recebível some ou diverge. Além disso, a checagem `isOpen` no controller ocorre fora da transação.
- **Evidência:** [BillController.php:112-119](../../app/Http/Controllers/BillController.php#L112-L119); [BillController.php:147-152](../../app/Http/Controllers/BillController.php#L147-L152).
- **Comprovação:** [Comprovado] P15: conta de R$ 80 editada para R$ 5, venda segue R$ 80. P16: conta cancelada, venda segue concluída, cliente sem dívida.
- **Impacto:** divergência entre vendas e contas a receber, e porta para desvio de valores.
- **Reproduzir:** rode P15 e P16.
- **Solução:** bloquear edição e cancelamento direto de contas com `sale_id` ou `stock_entry_id`. A alteração passa pela origem: cancelar a venda ou a entrada.
- **Esforço:** 1 dia.

#### AUD-007 — Pedido já entregue pode ser cancelado sem acerto

- **Severidade:** Alta. **Módulo:** delivery.
- **Descrição:** a única trava do cancelamento é `isSettled()`. Pedido entregue e ainda não acertado pode ser cancelado, sem venda, sem baixa de estoque e sem cobrança.
- **Evidência:** [CancelDeliveryOrder.php:23-28](../../app/Actions/CancelDeliveryOrder.php#L23-L28).
- **Comprovação:** [Comprovado] P11.
- **Impacto:** a mercadoria saiu, o dinheiro pode ter sido recebido pelo entregador, e o pedido some do acerto. É um caminho direto para desvio.
- **Reproduzir:** rode P11.
- **Solução:** proibir o cancelamento comum de pedido entregue e criar uma operação de exceção, com permissão própria, motivo e tratamento do estoque.
- **Esforço:** 0,5 a 1 dia.

#### AUD-008 — Sem backup e restauração definidos

- **Severidade:** Alta. **Módulo:** operação.
- **Descrição:** não há agendador, rotina de backup, procedimento de restauração, Dockerfile nem guia de implantação.
- **Evidência:** [Código, por ausência] `routes/console.php`; não há `.github` nem arquivos de deploy. O ambiente real é [Não verificado].
- **Impacto:** um único incidente pode destruir os dados financeiros de todos os clientes.
- **Reproduzir:** não se aplica.
- **Solução:** backup gerenciado ou agendado, com retenção definida e **restauração testada**.
- **Esforço:** 1 a 3 dias. Depende da hospedagem.

#### AUD-009 — Sem monitoramento nem alertas

- **Severidade:** Alta. **Módulo:** operação.
- **Descrição:** sem rastreamento de erros, métricas ou alertas. `composer.json` não tem Sentry, Pulse, Telescope ou equivalente. Um erro 500 no caixa só seria conhecido pela reclamação do cliente.
- **Evidência:** [Código] `composer.json`; `.env.example:21` com `LOG_LEVEL=debug`.
- **Impacto:** demora para detectar incidentes e dificuldade para investigar.
- **Reproduzir:** não se aplica.
- **Solução:** rastreamento de erros, verificação externa de `/up`, logs com identificação da empresa e alertas básicos.
- **Esforço:** 1 a 2 dias.

#### AUD-010 — Sem planos, assinaturas e cobrança

- **Severidade:** Alta. **Módulo:** administração SaaS.
- **Descrição:** o cadastro público cria empresa ativa e gratuita sem prazo. Não há plano, limite, faturamento ou bloqueio por inadimplência.
- **Evidência:** [Código, por ausência] sem modelo ou rota.
- **Impacto:** não existe modelo de receita implementado.
- **Reproduzir:** não se aplica.
- **Solução:** começar com o simples: plano e vencimento na empresa, bloqueio controlado pelo super administrador e cobrança manual no piloto. A integração com gateway vem depois.
- **Esforço:** 3 a 5 dias para a versão manual.

#### AUD-011 — Sem emissão de documento fiscal

- **Severidade:** Alta. **Módulo:** vendas.
- **Descrição:** o sistema só gera cupom não fiscal. Não há NFC-e nem integração fiscal.
- **Evidência:** [Código, por ausência] [Receipt.vue](../../resources/js/pages/sales/Receipt.vue).
- **Impacto:** restaurantes costumam precisar emitir NFC-e, o que limita a adoção. A obrigação varia por estado e regime: **precisa de validação com contador**, não é parecer fiscal.
- **Reproduzir:** não se aplica.
- **Solução:** integrar um emissor terceirizado, depois da validação do caminho comercial. Não vale construir emissor próprio.
- **Esforço:** a dimensionar depois da escolha do fornecedor.

#### AUD-012 — Delivery não tem pedido online do cliente

- **Severidade:** Alta. **Módulo:** delivery.
- **Descrição:** apesar do nome do produto, só funcionários criam pedidos de entrega. O cliente final não pede sozinho e não acompanha a entrega. Só existe pedido público no QR de mesa.
- **Evidência:** [Código] rotas `entregas` sob `can:deliveries.operate`; P24 confirma a negação a usuários sem permissão.
- **Impacto:** o sistema atende delivery por telefone, mas não substitui um canal online.
- **Reproduzir:** não se aplica.
- **Solução:** cardápio público com endereço, zona e pagamento na entrega, reaproveitando as Actions existentes. **Só depois** de AUD-001/002 e AUD-015, para não repetir os problemas do QR.
- **Esforço:** 8 a 12 dias.

#### AUD-013 — Sem adicionais e variações

- **Severidade:** Alta. **Módulo:** produtos, cardápio.
- **Descrição:** não há tamanhos, sabores, bordas ou complementos com preço. Só existe observação em texto, sem efeito no valor ou no estoque.
- **Evidência:** [Código, por ausência] migrations e modelos. Ponto 4 do fluxo QR (seção 4.1).
- **Impacto:** pizzarias e hamburguerias, que são o público natural, não conseguem cadastrar seu cardápio real. Hoje a alternativa é um produto por combinação.
- **Reproduzir:** não se aplica.
- **Solução:** grupos de opções por produto, com cópia do preço e do nome no item do pedido, seguindo o padrão de snapshot que o sistema já usa.
- **Esforço:** 8 a 12 dias, afeta PDV, mesa, entrega, QR e estoque.

### Médios

Para estes itens, "como reproduzir" é rodar o teste citado; quando não há teste, a descrição indica o caminho.

#### AUD-014 — Cancelar a venda não atualiza a entrega nem a comanda

- **Módulo:** vendas, delivery, mesas. **Evidência:** [CancelSale.php:33](../../app/Actions/CancelSale.php#L33). [Comprovado] P12 e P13.
- **Descrição:** o pedido fica "entregue" e a comanda "fechada", ambos apontando para uma venda cancelada. Como o pedido conta como acertado, não pode ser acertado de novo.
- **Impacto:** o histórico de entregas e os relatórios de entrega continuam contando um pedido desfeito.
- **Solução:** marcar a origem como revertida no cancelamento e filtrar nos relatórios. **Esforço:** 1 a 2 dias.

#### AUD-015 — Taxa de entrega é digitada livremente

- **Módulo:** delivery. **Evidência:** [StoreDeliveryOrderRequest.php:32](../../app/Http/Requests/StoreDeliveryOrderRequest.php#L32), [CreateDeliveryOrder.php:70](../../app/Actions/CreateDeliveryOrder.php#L70). [Comprovado] P10.
- **Descrição:** o valor vem da requisição, sem vínculo com a zona cadastrada. Bairro é texto livre e não há validação de região atendida.
- **Impacto:** taxa errada ou zerada; pedido para região não atendida.
- **Solução:** pedido guarda `delivery_zone_id` e o servidor define a taxa; alteração manual exige permissão. **Esforço:** 1 a 2 dias.

#### AUD-016 — Falha de rede é silenciosa no celular do cliente

- **Módulo:** QR Code, UX. **Evidência:** [Show.vue:47-60](../../resources/js/pages/menu/Show.vue#L47-L60) não trata erro de rede; não há tratador global de `networkError`. [Comprovado] navegador: com o servidor desligado, o botão volta ao normal, sem aviso, e o erro só aparece no console.
- **Impacto:** o cliente acha que pediu. O ponto positivo é que o carrinho e o `client_uuid` são preservados, então reenviar é seguro.
- **Solução:** tratador global com mensagem clara e opção de reenvio. O comportamento das demais telas nessa falha é [Não verificado]. **Esforço:** 0,5 dia.

#### AUD-017 — Pedido pode cair em conta errada e o reenvio tardio duplica

- **Módulo:** QR Code. **Evidência:** [PlaceMenuOrder.php:24](../../app/Actions/PlaceMenuOrder.php#L24) só protege quando `known_order_id` não é nulo; [AddOrderItems.php:29](../../app/Actions/AddOrderItems.php#L29) deduplica só dentro da comanda. [Comprovado] P07 e P08.
- **Descrição:** a página aberta com mesa livre manda o pedido para a comanda de quem chegou depois. Reenvio depois do fechamento abre comanda nova.
- **Impacto:** cobrança na conta errada. A janela é pequena e a sessão de mesa de AUD-002 a elimina.
- **Solução:** sessão de mesa + unicidade de `client_uuid` por empresa. **Esforço:** incluído em AUD-001.

#### AUD-018 — Ciclo da cozinha sem regras de consistência

- **Módulo:** mesas. **Evidência:** [SendToKitchen.php:17](../../app/Actions/SendToKitchen.php#L17) sem `isOpen`; `CancelOrderItem`. [Comprovado] P05 e P06. Cancelamento sem aviso à cozinha é [Código].
- **Descrição:** a conta fecha com itens nunca enviados; comanda fechada ainda aceita envio; item cancelado depois do envio não gera aviso.
- **Impacto:** cliente paga por algo não preparado, ou a cozinha prepara algo cancelado.
- **Solução:** validar comanda aberta, alertar sobre pendências no fechamento e emitir aviso de cancelamento. **Esforço:** 1 dia.

#### AUD-019 — Cardápio não controla disponibilidade

- **Módulo:** QR Code, estoque. **Evidência:** [Comprovado] P09: produto com saldo 0 foi aceito e o saldo ficou em -1. C2 confirma que venda com saldo negativo é permitida: 20 vendidas com saldo 5.
- **Descrição:** o saldo negativo é decisão do sistema (há aviso ao operador no PDV), mas o cliente do QR não recebe esse aviso e não existe "esgotado".
- **Impacto:** pedido aceito que a cozinha não consegue atender.
- **Solução:** indicador manual de "esgotado" e, opcionalmente, bloqueio por saldo por produto. **Esforço:** 1 dia.

#### AUD-020 — Deadlock entre fechar e transferir comanda

- **Módulo:** mesas. **Evidência:** ordem de lock invertida entre [CloseTableOrder.php:28](../../app/Actions/CloseTableOrder.php#L28) → [RegisterSale.php:42](../../app/Actions/RegisterSale.php#L42) e [TransferTable.php:17-20](../../app/Actions/TransferTable.php#L17-L20). [Comprovado] C9: 60 deadlocks em 30 rodadas forçadas.
- **Descrição:** a simultaneidade do teste foi forçada, com a mesma comanda. Na prática exige duas pessoas agindo na mesma comanda quase no mesmo instante.
- **Impacto:** erro 500 para um operador. **Não houve corrupção:** em todas as rodadas o banco reverteu e o estado ficou válido.
- **Solução:** travar a Company primeiro no fechamento, como já fazem as outras Actions, e usar `DB::transaction(..., attempts: 3)`. **Esforço:** 0,5 dia.

#### AUD-021 — Sem headers de segurança e cookie sem `Secure` por padrão

- **Módulo:** segurança. **Evidência:** [Comprovado] resposta HTTP em `APP_ENV=production` sem CSP, `X-Frame-Options`, HSTS e `X-Content-Type-Options`; cookies sem `Secure`, porque `SESSION_SECURE_COOKIE` não vem definido ([session.php:172](../../config/session.php#L172)). `X-Powered-By` revela a versão do PHP.
- **Impacto:** telas podem ser embutidas em outro site (clickjacking) e o navegador tem menos defesas. O teste usou HTTP local; atrás de HTTPS o resultado depende da configuração final.
- **Solução:** middleware de headers, variáveis de produção e `expose_php=Off`. **Esforço:** 0,5 a 1 dia.

#### AUD-022 — Cadastro público sem verificação

- **Módulo:** cadastro. **Evidência:** [RegisterRequest.php:27](../../app/Http/Requests/RegisterRequest.php#L27) e [:30](../../app/Http/Requests/RegisterRequest.php#L30). [Comprovado] P18: a validação antecipada responde se um e-mail e um CNPJ já existem, a 60 consultas por minuto. Login e redefinição de senha evitam essa revelação.
- **Descrição:** cria empresa ativa sem confirmar e-mail ou posse do CNPJ.
- **Impacto:** alguém pode ocupar o CNPJ de outra empresa, e dá para descobrir quais e-mails são clientes da plataforma.
- **Solução:** confirmação de e-mail, tratamento de CNPJ em disputa e redução da validação antecipada nesses campos. **Esforço:** 2 dias.

#### AUD-023 — Seeder com senhas públicas roda em produção

- **Módulo:** operação. **Evidência:** [DatabaseSeeder.php:21](../../database/seeders/DatabaseSeeder.php#L21) tem só um comentário de alerta. [Comprovado] `migrate:fresh --force --seed` executou com `APP_ENV=production` na cópia, criando um super administrador com senha pública.
- **Impacto:** um comando errado cria acesso total conhecido.
- **Solução:** interromper o seeder quando `app()->isProduction()`. **Esforço:** 0,1 dia.

#### AUD-024 — Isolamento depende só da aplicação

- **Módulo:** multiempresa. **Evidência:** [Código] `BelongsToCompany` não filtra quando `Tenant::id()` é nulo (console, jobs, rotas públicas). As FKs são simples, sem `company_id` composto.
- **Descrição:** o isolamento funciona (P22, P23), mas falha para o lado aberto. Um job ou comando futuro sem usuário enxergaria todas as empresas.
- **Impacto:** risco para o crescimento, não vazamento atual.
- **Solução:** manter a arquitetura; adicionar teste automático de varredura como P22/P23, e fazer o escopo falhar quando não houver contexto, com bypass explícito. **Esforço:** 1 a 2 dias.

#### AUD-025 — Sem segundo fator e política de senha básica

- **Módulo:** autenticação. **Evidência:** [AppServiceProvider.php:49](../../app/Providers/AppServiceProvider.php#L49): 8 caracteres com letra e número, sem checar senhas vazadas. Sem 2FA, nem para super administrador. [Código]
- **Impacto:** o roubo de uma senha de super administrador expõe a gestão de toda a plataforma.
- **Solução:** 2FA obrigatório para super administrador e opcional para administradores. **Esforço:** 2 a 3 dias.

#### AUD-026 — Sem CI/CD

- **Módulo:** qualidade. **Evidência:** [Código] sem `.github`; o diretório nem é repositório git. Sem lint de JavaScript e sem análise estática de PHP.
- **Impacto:** a suíte não protege nada se ninguém a executa. Sem histórico, não há rollback nem rastreabilidade.
- **Solução:** repositório git, pipeline com testes **em MariaDB**, Pint, build e `audit`. **Esforço:** 1 dia.

#### AUD-027 — Lacunas de LGPD

- **Módulo:** clientes. **Evidência:** [Código, por ausência] sem exclusão, anonimização ou exportação; sem retenção de auditoria; sem termos e consentimento.
- **Impacto:** dificuldade de atender pedidos de titulares. Requer orientação jurídica; isto não é parecer legal.
- **Solução:** anonimização que preserve o histórico financeiro, exportação e política de retenção. **Esforço:** 2 a 4 dias.

#### AUD-028 — Sem estorno de Pix e cartão nem conciliação

- **Módulo:** financeiro. **Evidência:** [CancelSale.php:94-125](../../app/Actions/CancelSale.php#L94-L125) trata só dinheiro. [Código]
- **Descrição:** cancelar venda paga em Pix ou cartão não registra a devolução. As formas de pagamento são apenas rótulos, sem integração com adquirente.
- **Impacto:** o sistema não diz se o dinheiro foi devolvido e não concilia com extrato ou maquininha.
- **Solução:** registrar estorno manual por forma, com comprovante e responsável; conciliação simples depois. **Esforço:** 2 a 3 dias.

#### AUD-029 — Histórico financeiro muda retroativamente

- **Módulo:** relatórios. **Evidência:** [Código] relatórios filtram por `status`/`created_at`; `ReopenBill` altera o fluxo de dias passados. Só o fechamento de caixa tem foto congelada.
- **Impacto:** um mês já analisado pode mudar sem aviso depois de cancelamentos e estornos.
- **Solução:** registrar a data do cancelamento e tratar a reversão no período em que ocorreu. **Esforço:** 2 a 3 dias.

#### AUD-030 — Sem unidades e filiais

- **Módulo:** empresas. **Evidência:** [Código, por ausência]. No QR, o ponto "unidade" não existe: empresa equivale a um estabelecimento.
- **Impacto:** redes precisam de cadastros separados, sem visão consolidada. Não bloqueia o público de loja única.
- **Solução:** adiar até haver demanda real; é mudança estrutural. **Esforço:** a dimensionar.

#### AUD-031 — Rota pública disputa a fila de vendas da empresa

- **Módulo:** desempenho. **Evidência:** [OpenTable.php:18](../../app/Actions/OpenTable.php#L18) trava a linha da empresa, a mesma fila usada por vendas. [Código]; C3 mostra que a fila é correta, mas serializa.
- **Impacto:** abuso do QR em mesas livres pode atrasar o caixa daquela empresa. O impacto real não foi medido.
- **Solução:** abertura sem trava global, usando o índice único `company_id + open_table_id`. **Esforço:** 1 dia.

### Baixos

| ID | Problema | Evidência | Impacto | Solução | Esforço |
|---|---|---|---|---|---|
| AUD-032 | Filtro enviado como array causa erro 500 em 7 telas | [Comprovado] P20; [CustomerController.php:18](../../app/Http/Controllers/CustomerController.php#L18), [ProductController.php:23](../../app/Http/Controllers/ProductController.php#L23) | Ruído de erro; sem vazamento | Validar filtros | 0,5 dia |
| AUD-033 | `product_id` como `"1abc"` passa na validação e causa 500 no MariaDB | [Comprovado] P21: 500 em MariaDB, 302 em SQLite | Ruído de erro. Mostra a diferença entre bancos | Regra `integer` | 0,1 dia |
| AUD-034 | Login falho com e-mails diferentes não tem limite e grava auditoria | [Comprovado] P19: 40 tentativas, 40 registros; [LoginRequest.php:64](../../app/Http/Requests/LoginRequest.php#L64) | Crescimento de `audit_logs`, que não pode ser limpa | Limite adicional por IP e retenção | 0,5 dia |
| AUD-035 | Relatórios usam ponto flutuante | [Código] `Money::of`, [FinanceReport.php:32](../../app/Reports/FinanceReport.php#L32) | Possível diferença de centavos em totais exibidos; não reproduzido | Agregar com decimal | 1 dia |
| AUD-036 | Cardápio público envia `barcode` e `sku` | [MenuController.php:42](../../app/Http/Controllers/MenuController.php#L42) [Código] | Dado interno exposto, contrariando o comentário do método | Remover do `select` e ajustar a busca no cliente | 0,1 dia |
| AUD-037 | Mensagens permitem sondar o valor esperado no caixa | [RecordCashMovement.php:28](../../app/Actions/RecordCashMovement.php#L28) [Código] | Enfraquece o fechamento às cegas | Mensagem genérica | 0,1 dia |
| AUD-038 | Quadro de entregas e POS sem paginação | [DeliveryController.php:27-38](../../app/Http/Controllers/DeliveryController.php#L27-L38), [:236-244](../../app/Http/Controllers/DeliveryController.php#L236-L244) [Código] | Pedidos entregues sem acerto acumulam na tela | Limite e alerta de pendências antigas | 1 dia |
| AUD-039 | Criação de mesas em lote sem transação; endereços apagados e recriados sem auditoria | `DiningTableController::store` (até 9.999 mesas), `CustomerController::update` [Código] | Lote parcial; perda de rastro | Transação, limite menor e atualização por diferença | 0,5 dia |
| AUD-040 | Higiene do projeto | `composer.json` com nome `laravel/laravel` e sem `ext-bcmath`; Pint falha em `lang/pt_BR/validation.php`; `TenantIsolationTest.php:82` falha em MariaDB [Comprovado] | Implantação pode falhar sem bcmath; suíte quebra no banco real | Ajustar metadados e teste | 0,2 dia |

### 4.1. Fluxo de pedidos por QR Code — os 15 pontos

| # | Ponto | Resultado | Situação |
|---|---|---|---|
| 1 | Identificação | Token de 32 caracteres identifica mesa e empresa. Unidade não existe | OK para loja única. Token único e regenerável (`MenuTest`) |
| 2 | Proteção entre estabelecimentos | Produto e categoria validados pela empresa da mesa. Sem ID público de mesa | **Comprovado**: `MenuTest`, P22, P23. A proteção **dentro** da mesa falha: AUD-002 |
| 3 | Acesso ao cardápio | Sem login, renderizado no celular (390×844) | **Comprovado** no navegador, console limpo |
| 4 | Seleção | Produtos e observações. **Sem complementos** | AUD-013 |
| 5 | Preço e disponibilidade | Preço vem do servidor. Disponibilidade **não** é validada | Preço comprovado (`test_preco_vem_do_servidor`). AUD-019 |
| 6 | Transmissão | POST com CSRF, `client_uuid`, limite por IP | Comprovado. AUD-004 e AUD-016 |
| 7 | Comanda | Abre ou entra na comanda; 20 simultâneos geraram 1 comanda e 20 itens | **Comprovado** C3. AUD-017 |
| 8 | Notificação | **Ninguém recebe alerta** | AUD-001 |
| 9 | Confirmação | Nem manual nem automática: o pedido fica parado | AUD-001 |
| 10 | Chegada à cozinha | **Não chega sozinho** | AUD-001 e AUD-003 |
| 11 | Acompanhamento do garçom | Comanda atualiza a cada 10 s, se estiver aberta | Parcial |
| 12 | Acompanhamento do cliente | Vê itens e total. **Não vê andamento** | Parcial |
| 13 | Consolidação | Itens do QR e do garçom na mesma comanda | Comprovado (`MenuTest`) |
| 14 | Fechamento e pagamento | Só pela equipe; total conferido no servidor; múltiplas formas | **Comprovado** `TableTest`, C4 |
| 15 | Liberação da mesa | Fechar libera a mesa, garantido por índice único | Comprovado (`TableTest`) |

**Riscos pedidos na auditoria:**

| Risco | Resultado |
|---|---|
| Duplicidade por clique ou reenvio | Protegido dentro da comanda. Exceção: AUD-017 |
| Pedido perdido | **Sim, operacionalmente**: AUD-001 |
| Problema de conexão | AUD-016 |
| Preço alterado pelo cliente | Protegido. Comprovado |
| Inconsistência mesa/pagamento | Fechamento correto (C4). Cancelamento posterior: AUD-014 |

### 4.2. Fluxo de delivery

| Etapa | Situação |
|---|---|
| Recebimento | Só por funcionário (AUD-012) |
| Endereço e região atendida | Endereço validado; **região não** (AUD-015) |
| Cálculo de entrega | Taxa livre (AUD-015). Comprovado P10 |
| Etapas e preparo | Sequência linear com `expected_status`, sem pular etapa (`DeliveryTest`) |
| Entregador | Atribuição validada por empresa e permissão (P23, `DeliveryTest`) |
| Acompanhamento | Quadro com polling de 10 s; cliente não acompanha |
| Conclusão | Acerto gera venda e baixa de estoque; idempotente (`DeliveryTest`) |
| Cancelamento e reembolso | AUD-007, AUD-014, AUD-028 |

### 4.3. Consistência operacional

| Proteção | Resultado |
|---|---|
| Processamento duplicado | **Comprovado**: C1, C4, C5, C6, C7 |
| Cancelar concluído sem regra | **Falha**: AUD-007 |
| Alterar pedido pago | Comanda fechada não recebe itens (`AddOrderItems`). Conta: AUD-006 |
| Divergência de totais | Servidor recalcula e confere. C8 confirmou o esperado do caixa |
| Serviço indisponível | Pedido só é persistido com banco ativo; o cliente não é avisado (AUD-016) |
| Notificação duplicada | Não se aplica: não há notificações |
| Estado inválido simultâneo | Protegido por lock; o efeito colateral é o deadlock de AUD-020 |

### 4.4. Financeiro

| Item | Resultado |
|---|---|
| Abertura e fechamento | **Comprovado**: `CashTest`, C7, C8. Um caixa por operador, fechamento às cegas, foto congelada |
| Sangria e suprimento | Comprovado (`CashTest`) |
| Pagamento múltiplo | Comprovado (`SaleTest`, `CreditSaleTest`) |
| Pagamento parcial | **Ausente e perigoso** (AUD-005). Divisão por pessoa ou item não existe |
| Descontos | Com permissão, limitados ao subtotal (`SaleTest`) |
| Representação monetária | Decimal no banco, bcmath no domínio, centavos inteiros no frontend. Correto. Exceção: AUD-035 |
| Cancelamento | Reverte estoque, fiado e caixa uma única vez (C6). Limites: AUD-014, AUD-028 |
| Estoque integrado | Comprovado: C2 (saldo e movimentos conferem), `RecipeTest` |
| Permissões | Comprovado: P24 e `BillTest::test_permissoes` |
| Imutabilidade | Trigger só em `audit_logs` (comprovado no MariaDB). Vendas e movimentos dependem da aplicação |

---

## 5. Avaliação de segurança

### Vulnerabilidades e riscos comprovados

| Item | Severidade |
|---|---|
| Uso remoto do QR em conta alheia (AUD-002) | Crítica |
| Limite por IP compartilhado (AUD-004) | Alta |
| Headers ausentes e cookie sem `Secure` por padrão (AUD-021) | Média |
| Enumeração de e-mail e CNPJ (AUD-022) | Média |
| Seeder com senha pública executável em produção (AUD-023) | Média |
| Inflar auditoria com login falho (AUD-034) | Baixa |
| Erros 500 por entrada malformada (AUD-032, 033) | Baixa |

### Proteções comprovadas por execução

| Proteção | Evidência |
|---|---|
| Acesso direto a recurso de outra empresa | P22: 50 de 50 rotas responderam 404; dados da vítima intactos |
| ID de outra empresa no corpo | P23: 11 de 11 recusados, sem nada gravado |
| Autorização por perfil | P24 |
| Escalada de privilégio | `UserManagementTest`, `RoleManagementTest`, `TenantIsolationTest` |
| Imutabilidade da auditoria | Trigger bloqueou `DELETE` no MariaDB |
| CSRF | POST sem token no QR não processou (redirecionou) |
| CORS | Sem cabeçalho `Access-Control-*` para origem externa |
| `.env` pela web | 404 |
| Dependências | 0 vulnerabilidades conhecidas (Composer e npm de produção) |

### Identificado só por código

- **XSS:** não há `v-html` em `resources/js`; o Vue escapa por padrão.
- **SQL injection:** os usos de SQL bruto encontrados têm parâmetros ligados ou texto fixo.
- **Mass assignment:** campos sensíveis usam `forceFill` controlado e FormRequests.
- **CSV injection:** `Csv::cell` neutraliza fórmulas.
- **Upload e webhooks:** não existem, logo não há superfície.
- **Segredos:** `.env` não existe no projeto e nenhuma credencial real foi encontrada no código.

### Não verificados

- Hospedagem, TLS, firewall e banco de produção; backup real.
- Teste de intrusão manual, varredura externa e testes em sistemas de terceiros, que estavam fora do escopo.
- Redefinição de senha com envio real de e-mail (`MAIL_MAILER=log` no exemplo).
- `ForgetSessionUser` em todos os caminhos. Há teste do funcionário de outra empresa, mas a análise de re-hidratação foi só por código.
- Resposta exibida ao cliente em HTTP 429.

---

## 6. Avaliação de arquitetura

| Decisão | Itens |
|---|---|
| **Manter** | Monólito Inertia; padrão de Actions; `company_id` com escopo; `client_uuid`; fila por lock pessimista; bcmath; fechamento às cegas; auditoria imutável; polling como tempo real inicial |
| **Corrigir** | Ciclo do pedido QR; `trustProxies`; regras de contas vinculadas; ordem de lock em `CloseTableOrder`; consistência no cancelamento de vendas |
| **Simplificar** | Fontes duplicadas de verdade, como a taxa digitada ao lado de zonas cadastradas |
| **Substituir** | **Nada.** Sessão e cache em banco só devem migrar para Redis se o teste de carga demonstrar a necessidade |

**Não se recomendam** microsserviços, mensageria ou WebSocket agora. O polling cobre o problema atual: nada mostra benefício concreto que justifique a complexidade adicional.

---

## 7. Classificação de prontidão

# Não apto para produção

**Bloqueadores:**

1. AUD-001: pedido QR não chega à cozinha.
2. AUD-002: QR permanente sem sessão.
3. AUD-004: limite por IP.
4. AUD-005, 006, 007: incoerências financeiras.
5. AUD-008, 009: sem backup testado e sem monitoramento.
6. AUD-023: seeder sem trava.

**Caminho:** concluído o P0, o sistema pode passar a **apto apenas para piloto controlado**, com poucas lojas acompanhadas de perto. O uso comercial amplo depende também do P1.

---

## 8. Plano de ação

| Prioridade | Item | Dependências |
|---|---|---|
| **P0** | Definir hospedagem e versões | Nenhuma; é a primeira decisão |
| **P0** | Repositório git + CI em MariaDB (AUD-026, 040) | — |
| **P0** | Trava no seeder (AUD-023) | — |
| **P0** | Pedido QR pendente, alerta e sessão de mesa (AUD-001, 002, 017) | Decisão de produto |
| **P0** | Tratamento de erro de rede (AUD-016) | — |
| **P0** | `trustProxies` e limites do QR (AUD-004) | Hospedagem |
| **P0** | Baixa parcial e bloqueio de contas vinculadas (AUD-005, 006) | — |
| **P0** | Cancelamento de pedido entregue (AUD-007) | — |
| **P0** | Backup com restauração testada e monitoramento (AUD-008, 009) | Hospedagem |
| **P0** | Headers e cookies (AUD-021) | Hospedagem |
| **P1** | Painel de cozinha por setor (AUD-003) | AUD-001 |
| **P1** | Adicionais e variações (AUD-013) | — |
| **P1** | Regras do ciclo da cozinha (AUD-018) e disponibilidade (AUD-019) | AUD-003 |
| **P1** | Coerência de cancelamentos (AUD-014), taxa por zona (AUD-015), deadlock (AUD-020) | — |
| **P1** | Planos e cobrança manual (AUD-010) | Decisão comercial |
| **P1** | 2FA (AUD-025) e verificação de cadastro (AUD-022) | E-mail real |
| **P2** | Pedido online do delivery (AUD-012) | AUD-001, 002, 015 |
| **P2** | Fiscal (AUD-011) | Contador e fornecedor |
| **P2** | Estornos (AUD-028), histórico (AUD-029), LGPD (AUD-027), tenant (AUD-024), abertura sem lock (AUD-031) | — |
| **P3** | Filiais (AUD-030), integrações de pagamento, itens Baixos | Demanda real |

---

## 9. Roteiro até o lançamento

| Marco | Entrega | Critério verificável de aceite |
|---|---|---|
| **1. Fundação** | Git, CI, hospedagem, seeder | Cada push roda a suíte em MariaDB, sem falhas; seeder recusa `production` |
| **2. Pedido seguro** | QR pendente, alerta, sessão de mesa | Pedido novo gera alerta em até 15 s na lista de mesas; link após o fechamento não lança na conta; P01, P02, P04, P07 e P08 passam a **falhar** |
| **3. Financeiro coerente** | AUD-005, 006, 007, 014 | P11–P16 passam a **falhar**; soma de vendas = recebimentos + a receber em cenário de teste |
| **4. Pronto para piloto** | Backup, monitoramento, headers, proxy | Restauração cronometrada em ambiente limpo; erro forçado gera alerta; P17 passa a falhar; headers conferidos em HTTPS |
| **5. Piloto** | 2 ou 3 lojas por algumas semanas | Conferência diária de caixa sem divergência inexplicada; nenhum pedido sem resposta; teste real em celulares e impressoras |
| **6. Produto vendável** | Cozinha, adicionais, planos | Cardápio real de uma pizzaria cadastrado sem gambiarra; bloqueio por vencimento funcionando |
| **7. Capacidade** | Testes de carga (seção 11, fase 9) | Metas de latência e erro cumpridas no cenário definido |
| **8. Lançamento comercial** | Fiscal, LGPD, 2FA | Validação do contador e do jurídico registradas; revisão de segurança externa |

---

## 10. Parecer do arquiteto

1. **Está arquiteturalmente bem estruturado?** Sim. É um monólito simples, coerente, com domínio bem isolado em Actions e cuidado real com dinheiro e concorrência.

2. **Há problemas graves à comercialização?** Sim. Dois Críticos no QR, incoerências financeiras em contas e fiado, e ausência de planos, cozinha, adicionais, backup e monitoramento.

3. **QR, mesa e delivery estão corretamente integrados?** Parcialmente. Os dados se integram bem: mesa, comanda, venda, caixa e estoque fecham corretamente. O fluxo operacional não: o pedido do QR não chega à cozinha, e o cancelamento de vendas não volta para a origem.

4. **O financeiro é confiável?** O núcleo é: vendas, caixa e estoque resistiram à concorrência e os totais fecham. A camada de contas ainda não, por causa de AUD-005 e AUD-006. Nenhum teste comparou os números com um caso real de contabilidade.

5. **O isolamento é efetivo?** Sim, nas rotas atuais e com execução comprovada. A ressalva é estrutural (AUD-024) e deve ser tratada antes de haver jobs ou comandos em segundo plano. Isso não substitui um teste de intrusão externo.

6. **Usa tecnologias e práticas modernas?** Sim. As versões são atuais e suportadas, sem vulnerabilidades conhecidas. Faltam práticas de entrega: git, CI, análise estática e monitoramento.

7. **Algum módulo precisa ser reestruturado?** Só o fluxo do pedido QR, e de forma localizada, ganhando estado pendente e sessão de mesa. Contas ganham baixa parcial. Nenhum precisa de reescrita.

8. **Está pronto para clientes reais?** Não. Depois do P0, está pronto para piloto controlado.

9. **Quais as cinco correções mais urgentes?**
   1. Pedido QR pendente com alerta à equipe (AUD-001).
   2. Sessão de mesa para o QR (AUD-002).
   3. Baixa parcial e bloqueio de contas vinculadas (AUD-005, 006).
   4. Cancelamento de pedido entregue (AUD-007).
   5. `trustProxies`, backup testado e monitoramento (AUD-004, 008, 009), que dependem da hospedagem.

10. **O que validar antes de novas funcionalidades?**
    - O fluxo QR completo em restaurante real, com celulares e impressora reais.
    - Restauração de backup.
    - Conferência financeira de um período com um contador.
    - Teste de carga no cenário de pico.
    - Disposição do público a pagar, com planos definidos.
    - Exigência fiscal do público-alvo.

---

## 11. Fases complementares

### Fase 1 e 6 — Banco de dados e integridade

**[Código, com execução das migrations em MariaDB]**

- As migrations rodaram do zero sem erro em MariaDB 10.4.32.
- **Pontos fortes:** valores em `decimal`; unicidade por empresa em números e `client_uuid`; `open_user_id` e `open_table_id` usam índice único para garantir um caixa por operador e uma comanda por mesa; preços e nomes copiados para o histórico; `restrictOnDelete` em vínculos financeiros.
- **Pontos de atenção:**
  - `company_id` usa `cascadeOnDelete`. Não há rota que apague empresa, mas um `DELETE` manual apagaria vendas. A trava é `users.company_id` com `restrictOnDelete`.
  - `table_order_items` tem índice não único em `client_uuid`, compensado pelo lock.
  - Permissões concedidas em migrations por nome de perfil funcionam, mas exigem cuidado com perfis renomeados.
  - N+1: `preventLazyLoading` fora de produção protege os caminhos cobertos pela suíte.
  - Backup: AUD-008.

### Fase 7 — Tempo real

| Tela | Intervalo [Código] |
|---|---|
| Lista de mesas, comanda, quadro de entregas | 10 s |
| Minhas entregas | 15 s |
| Cardápio do cliente | 20 s |

- O banco é a fonte da verdade: o polling só lê, então perder uma atualização não perde o pedido.
- Não há som, push, reconexão explícita ou notificação de pedido novo.
- Abas em segundo plano: o comportamento de throttling do polling é [Não verificado].
- WebSocket não se justifica agora. Avaliar só se o teste de carga mostrar custo excessivo do polling.

### Fase 8 — Qualidade

| Item | Avaliação |
|---|---|
| Código | Consistente, nomes claros, responsabilidades bem divididas |
| Testes | Bons em regras de negócio. Faltam concorrência e banco real na suíte |
| Lint, tipos | Só Pint no PHP. Sem ESLint, TypeScript ou PHPStan |
| CI/CD, observabilidade | Inexistentes (AUD-026, 009) |
| Documentação | README adequado para desenvolvimento; sem guia de operação |

**Limitação importante da suíte:** em SQLite, `lockForUpdate` não faz efeito e os triggers não existem. Por isso, os testes atuais **não provam** concorrência; essa prova veio dos cenários C1–C9.

### Fase 9 — Desempenho e escalabilidade

**Nenhuma capacidade é afirmada aqui.** O único dado medido é do C2: 20 vendas simultâneas terminaram em cerca de 1 s, em máquina de desenvolvimento. Isso mostra serialização correta, não capacidade.

**Hipóteses dos cenários:** horário de pico; 3 telas da equipe com polling de 10 s; 15 celulares com polling de 20 s; 0,2 ação de escrita por segundo por loja. Isso resulta em cerca de 1,2 requisição por segundo por loja. Esses números são suposições do auditor, não medições.

| Lojas ativas | Coincidência no pico | Requisições/s estimadas | Observação |
|---|---|---|---|
| 10 | 100% | ~12 | Servidor único tende a ser suficiente; validar |
| 100 | 100% | ~120 | Sessão e cache em banco passam a pesar; provável Redis e mais workers PHP |
| 1.000 | 60% | ~720 | Exige várias instâncias e balanceador; `trustProxies` obrigatório; banco dedicado e polling revisado |

**Gargalos prováveis [Código]:**

- Sessão, cache e limitador no banco: cada requisição gera leitura e escrita.
- Polling multiplica requisições sem ação de usuário.
- Fila por empresa serializa vendas da mesma loja, sem afetar as demais.
- Relatórios agregam em tempo real, sem cache.
- Não há integrações externas, nem workers em uso.

**Testes de carga necessários:**

1. Pico de uma loja: QR, garçom e caixa simultâneos.
2. Pico multiempresa, com o tráfego de polling realista.
3. Relatórios com um ano de dados por empresa.
4. Resistência por 2 horas, observando tabelas de sessão e cache.
5. Falha e retorno do banco durante o movimento.

**Metas a definir antes do teste:** latência p95, taxa de erro, conexões por instância e custo.

### Fase 10 — Experiência do usuário

**Comprovado no navegador, 390×844:**

- Cardápio: leitura clara, botões com rótulo acessível, barra fixa com total, confirmação em duas etapas.
- Comanda do garçom: abas Cardápio/Comanda e busca de produtos, sem erros no console.

**Por código:**

| Item | Avaliação |
|---|---|
| Duplo clique | Protegido por `form.processing` nos formulários críticos |
| Rascunho do PDV | Salvo em `localStorage` com o `client_uuid` |
| Confirmações | Modais em fechamento, cancelamento e transferência; cancelamento exige motivo |
| Acessibilidade | Atributos `aria-` em 46 arquivos, `role="alert"` em erros. **Não testada com leitor de tela** |
| Erros | Bons nos formulários; ruins em falha de rede (AUD-016) |
| Painel de cozinha | Não existe, logo não há legibilidade a avaliar |

**Maiores riscos de erro humano:** garçom sem alerta (AUD-001), baixa com valor digitado errado (AUD-005) e cliente sem andamento do pedido.

**Não avaliados:** PDV, caixa e relatórios no navegador; tablets; impressoras reais; rapidez operacional com usuários reais.

---

## 12. Testes e evidências

### Ambiente

- **Cópia descartável** do projeto no diretório temporário da sessão. Nada rodou contra o projeto original.
- **PHP** 8.5.12, **Node** 24.16.0, **Composer** 2.10.3 (já existente na máquina).
- **Dependências:** instaladas só na cópia, exatamente pelos lockfiles (`composer install`, `npm ci --ignore-scripts`). Nenhuma ferramenta nova foi instalada.
- **Banco descartável:** instância própria de MariaDB 10.4.32, porta 33977, dados no diretório temporário. O harness recusa rodar em outra porta.
- **O MariaDB da porta 3306 não foi acessado.**

### Execuções

| # | Verificação | Comando | Resultado | Limitações |
|---|---|---|---|---|
| E1 | Suíte em SQLite | `php artisan test --compact` | **317 de 317**, 1.876 asserções, 28 s | Sem locks nem triggers |
| E2 | Suíte + provas em MariaDB | `phpunit -c phpunit.maria.xml` | **340 de 341**, 1.956 asserções | Falha é `TenantIsolationTest.php:82`: o teste tenta apagar `audit_logs` e o trigger bloqueia. Defeito do teste, não da aplicação |
| E3 | Provas de conceito | `php artisan test tests/Feature/AuditProofTest.php` | 24 de 24 confirmados | Cenários sintéticos |
| E4 | Concorrência | `php audit_conc.php` | 8 cenários íntegros + C9 com deadlock | Processos locais, uma máquina |
| E5 | Vulnerabilidades | `composer audit --locked`; `npm audit --omit=dev` | 0 e 0 | Só avisos conhecidos; npm só de produção |
| E6 | Formatação | `pint --test` | 1 arquivo: `lang/pt_BR/validation.php` | — |
| E7 | Build | `npm run build` | Sucesso em 2 s | — |
| E8 | Rotas | `route:list` | 129 rotas, 10 públicas | — |
| E9 | Navegador | Playwright, 390×844, `APP_ENV=production`, `php artisan serve` | QR de ponta a ponta OK; visão do garçom OK; falha de rede silenciosa | Chromium; sem celular real |
| E10 | HTTP | `curl` local | Headers ausentes; CSRF ativo; sem CORS; `.env` 404 | HTTP, sem TLS |

**Não há** lint de JavaScript, verificação de tipos nem análise estática de PHP configurados no projeto. Esses itens não foram executados.

### Concorrência (E4)

| Cenário | Resultado |
|---|---|
| C1: mesma venda 12× | 1 venda, estoque baixou uma vez |
| C2: 20 vendas, saldo 5 | Saldo -15 = 5 - 20, movimentos batem, numeração sem furos. O negativo é política do sistema (AUD-019) |
| C3: 20 pedidos QR em mesa livre | 1 comanda, 20 itens |
| C4: fechar mesma comanda 6× | 1 venda; 5 recusas corretas |
| C5: baixa em dinheiro 6× | 1 movimento de caixa |
| C6: cancelar mesma venda 6× | 1 cancelamento, estoque restaurado. **Ver nota** |
| C7: abrir caixa 8× | 1 sessão |
| C8: fechar caixa com 15 vendas | 5 vendas antes do fechamento, 10 recusadas; esperado R$ 50 = gravado |
| C9: fechar × transferir, 30 rodadas | **60 deadlocks** (AUD-020), estado final íntegro |

**Nota sobre C6:** o arquivo [conc-result.txt](evidencias/conc-result.txt) marca C6 como "FALHOU" porque o auditor esperava um movimento de estorno. A leitura de `CancelSale::refundCash` mostrou que a premissa estava errada: com o caixa de origem aberto, não há movimento, pois o esperado já ignora vendas canceladas. **Não é defeito.**

### Provas de conceito (E3)

| Prova | Comportamento confirmado | Problema |
|---|---|---|
| P01–P03 | Pedido remoto na conta alheia, conta exposta, R$ 60.000 em uma requisição | AUD-002 |
| P04 | QR não vai à cozinha, mensagem contrária | AUD-001 |
| P05, P06 | Fecha sem envio; envia com comanda fechada | AUD-018 |
| P07, P08 | Conta seguinte; duplicação por reenvio tardio | AUD-017 |
| P09 | Sem estoque aceito pelo QR | AUD-019 |
| P10 | Taxa de entrega livre | AUD-015 |
| P11 | Cancela pedido entregue | AUD-007 |
| P12, P13 | Cancelar venda não reflete na origem | AUD-014 |
| P14 | R$ 1 quita R$ 80 | AUD-005 |
| P15, P16 | Conta vinculada alterável | AUD-006 |
| P17 | Limite compartilhado atrás de proxy | AUD-004 |
| P18 | Enumeração de e-mail e CNPJ | AUD-022 |
| P19 | 40 falhas de login sem limite, 40 registros | AUD-034 |
| P20 | HTTP 500 em 7 URLs de filtro | AUD-032 |
| P21 | 500 em MariaDB, 302 em SQLite | AUD-033 |
| P22 | 50 rotas: todas 404; dados intactos | Proteção confirmada |
| P23 | 11 referências cruzadas recusadas | Proteção confirmada |
| P24 | 108 rotas: 106 com 403; início 200; relatórios 302 | Proteção confirmada |

P22 não cobre 3 rotas parametrizadas por tipo (`financeiro/{type}`, `financeiro/{type}/nova`, `relatorios/{report}/exportar`), que não recebem ID de registro.

### Não executado

- Teste de carga.
- Impressora, celular físico e leitor de tela.
- Envio real de e-mail.
- Restauração de backup.
- Teste de intrusão.
- Produção ou qualquer ambiente real.
- Testes das telas de PDV, caixa e financeiro no navegador.

### Interferência no projeto durante a auditoria

- **Criado de propósito:** `docs/auditoria/` com este relatório e as evidências.
- **Criado sem intenção:** a ferramenta de navegador gerou `.playwright-mcp/`, `mesas.png` e `comanda.png` na raiz. Tudo foi conferido e removido.
- **Verificação final:** a comparação entre projeto e cópia e a busca por arquivos alterados na data confirmaram o código-fonte intacto. Nenhum commit, migration ou alteração de configuração foi feito no projeto.
