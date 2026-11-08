# Fase 4 — produção por item e setor: execução e evidências

**Data de conclusão:** 08/11/2026. **Branch:** `phase4/production-flow`. **Base:** `f66895d` (desenho), sobre a Fase 3 `82b52cf`.
**Parecer:** concluída no escopo aprovado e enviada ao GitHub como branch própria. Não é autorização de implantação nem aprovação para produção. Fase 5 não iniciada.

## 1. Entrega

Cada linha de mesa/QR e de delivery/retirada vira um trabalho de produção com destino por setor. Cozinha e bar avançam de forma independente: uma bebida pode ficar pronta enquanto a comida ainda está em preparo. Os estados são `waiting` → `preparing` → `ready`. A entrega fica registrada à parte (`delivered_at`), e o cancelamento é terminal. Cada transição exige a versão esperada; replay, salto ou tela antiga são recusados com mensagem clara.

- **Setores:** são cadastrados por empresa. Categoria aponta para um setor da mesma empresa, e há um setor padrão da empresa. O trabalho guarda snapshot do destino, então mudar a categoria só afeta trabalhos futuros. Um setor com trabalho ativo não pode ser inativado, e um setor com histórico não pode ser apagado.
- **Encaminhamento:** lançamento da equipe, confirmação do QR e criação de delivery encaminham na mesma transação. Sem destino válido, a operação é recusada antes de criar item ou cobrança. Unicidade por origem impede trabalho duplicado. O replay de um UUID persistido devolve o resultado gravado, mesmo depois de o produto esgotar.
- **Esgotado:** indicador manual no produto, separado de ativo e de saldo. Recusa novos pedidos de restaurante (mesa, QR, delivery) e é revalidado ao confirmar QR pendente. A venda direta do PDV não muda.
- **Mesa:** a equipe registra a entrega do item pronto. O fechamento exige todos os itens ativos entregues e nenhum legado pendente.
- **Delivery:** o preparo/pronto do pedido é derivado das linhas. Saída e retirada exigem todas as linhas prontas, e a conclusão marca a entrega das linhas sem antecipar o acerto. O avanço de preparo pelo pedido inteiro foi removido e o endpoint antigo orienta usar o painel. O cancelamento continua sendo do pedido inteiro.
- **Cancelamento:** a partir de `preparing` exige `production.manage` e motivo, inclusive quando o pai (comanda ou delivery) é cancelado. Emite aviso persistente por setor até a ciência explícita, com responsável e data.
- **Painel** (`/producao`): colunas Aguardando/Preparando/Pronto, avisos de cancelamento, filtro por setor, origem e atraso. Polling de 10 s e indicador de conexão; o áudio só toca depois de ativado. As props não trazem preço, endereço nem telefone.
- **QR:** o cliente vê o preparo, a entrega e o cancelamento só das linhas do próprio envio.
- **Automático:** não é ligado pela preferência salva na Fase 3. A ativação exige `company.manage`, aceite do risco de pedido remoto (com autor e data) e setor padrão ativo. O bloqueio da plataforma prevalece. A confirmação automática roda em transação única, e uma falha no encaminhamento desfaz tudo. Pendentes anteriores não são confirmados retroativamente.

Nenhuma venda, baixa de estoque ou movimento de caixa acontece antes do fechamento/acerto já existentes.

## 2. Schema, legado e permissões

Migrations aditivas:
- **`2026_11_08_095904_create_production_infrastructure.php`:** cria setores, trabalhos e eventos, o setor padrão da empresa, o setor da categoria e o esgotado do produto. Uma constraint de origem única exige exatamente uma origem por trabalho (CHECK no MariaDB, trigger no SQLite), e o evento é único por versão.
- **`2026_11_08_101140_add_explicit_automatic_production_consent.php`:** registra o aceite do automático.

As duas recusam rollback quando já há histórico.

Itens ativos de comandas abertas e de deliveries sem venda, inclusive entregues aguardando acerto, ficam como **legado aguardando reconciliação**. Nada é inferido de `kitchen_batch` e nenhuma data é fabricada. O gestor registra o estágio observado com motivo; o evento e a data da reconciliação ficam auditados. Um delivery já entregue só aceita a observação "entregue", para não ficar impossível de acertar.

Permissões novas no catálogo atual: `production.view`, `production.operate`, `production.deliver`, `production.manage` e `production.configure`.
- **Perfis existentes que operam mesas:** recebem visão e registro de entrega.
- **Operar preparo e gerir cancelamento/legado:** ficam com administradores e perfis configurados explicitamente.

**Atenção para a implantação:**
- Logo após migrar, só administradores avançam o preparo e tomam ciência dos avisos.
- Gerente e Caixa deixam de cancelar item de mesa em preparo e delivery pronto ou em saída.
- Perfis com entregas mas sem mesas não recebem `production.view`.

Por isso, a empresa precisa configurar o perfil da cozinha e o setor padrão antes de operar.

## 3. Verificações finais

| Verificação | Resultado observado |
|---|---|
| SQLite completo | 529/531 aprovados; 2 ignorados (trigger MariaDB); 3121 asserções |
| MariaDB completo | 531/531 aprovados; 3125 asserções; 48.2 s |
| Concorrência | C1–C9, F1–F5, R1–R5 e P1–P5: 24 cenários aprovados |
| C9 | 30 rodadas/120 workers; 0 deadlocks 1213, 0 timeouts 1205, 0 estados inválidos |
| Cliente Node | 5/5 (os quatro de recuperação da Fase 3 + resposta antiga que não regride a versão) |
| Build frontend | `npm run build`, exit 0 |
| Pint | `pint --test`, aprovado |
| Composer | `validate --strict` e `check-platform-reqs`, exit 0 |
| Dependências | `composer audit --locked` e `npm audit --omit=dev`: zero vulnerabilidades; sem alteração de pacotes |

Ambiente: MariaDB 10.4.32 (InnoDB, REPEATABLE-READ) em instância temporária própria do harness, nunca na porta operacional; PHP 8.5.12; Node 24.16.0.

O que os cenários de produção provam:
- **P1:** oito lançamentos iguais geram uma linha, um trabalho e um evento.
- **P2:** oito transições com a mesma versão resultam em uma só aceita.
- **P3:** entregar, cancelar e fechar concorrentes terminam sem inconsistência.
- **P4:** confirmar e esgotar concorrentes exercitam as duas ordens.
- **P5:** oito reconciliações do mesmo legado registram uma só observação.

Evidências em UTF-8: [evidencias-fase-4](evidencias-fase-4/), com [SHA256SUMS.txt](evidencias-fase-4/SHA256SUMS.txt). Comandos em [VALIDACAO.md](VALIDACAO.md).

## 4. Commits

| Commit | Conteúdo |
|---|---|
| `5f761d7` | schema, setores e disponibilidade |
| `df96b89` | encaminhamento transacional |
| `c42c9da` | estados, cancelamento, legado e guardas |
| `c6fd4aa` | status do delivery recalculado após reconciliação |
| `7c73829` | ativação explícita do automático |
| `7c1a510` | painel e acompanhamento privado |
| `2a9df38` | fixtures, concorrência e regressão |
| `91efbcd` | correções da revisão independente |

A execução mudou de agente no meio da Tarefa 4 e foi retomada pelo registro de progresso. O trabalho das tarefas 4–6 que ficou sem commit foi separado por grupo de arquivos, e só o estado final foi verificado. Os commits `7c73829`, `7c1a510` e `2a9df38` não foram testados um a um, e a ordem 5 antes de 4 é consequência dessa separação.

## 5. Problemas encontrados e correções

- **Reconciliação sem recálculo:** reconciliar um item de delivery não recalculava o status do pedido. A derivação passou a ser compartilhada pela transição e pela reconciliação, nunca faz regredir um pedido em saída ou entregue e não age enquanto houver outra linha legada desconhecida.
- **Revisão independente** da branch inteira, sem achados críticos:
  - **Replay de mesa após esgotar:** o replay com o mesmo UUID devolvia 422. Corrigido; o esgotado continua barrando lançamentos novos dentro da transação.
  - **Delivery legado entregue travado:** reconciliado como pronto ou anterior, ficava impossível de acertar ou cancelar. Agora só aceita "entregue".
  - **Auditoria de entrega:** concluir a entrega sobrescrevia `delivered_at` e duplicava o evento de linhas já reconciliadas como entregues. Corrigido.
  - Cada correção tem teste que falhou antes e passa depois.
- **Falso positivo:** a revisão apontou que a confirmação automática registraria o funcionário logado como responsável. Não procede, porque o middleware `public.page` descarta o usuário da sessão nas rotas do QR. Foi adicionado um teste de regressão.
- **Fixtures antigas:** passaram a configurar setor e a percorrer o preparo explicitamente. Nenhuma barreira de segurança foi removida para os testes passarem.

## 6. Riscos e limitações restantes

Pendências menores registradas e não corrigidas:
- painel sem mescla por versão contra um polling em andamento (oscilação visual; o servidor recusa);
- setor padrão pode ser inativado ao desmarcar "padrão", e o padrão não é limpo;
- o filtro por setor oculta o bloco de legado e os avisos dele;
- qualquer salvamento das configurações com automático ligado pede novo aceite e atualiza a data do aceite.

O QR continua anônimo; o automático aumenta a exposição a pedidos remotos e só deve ser ativado com esse risco aceito. A impressão manual segue como segunda via e não comprova preparo nem entrega.

Não houve celular físico, impressora, áudio real, carga, ensaio de migração com volume legado real, backup/restauração ou produção. O CI do GitHub não foi acompanhado nesta entrega.

A branch foi enviada como branch nova. A `main` do GitHub tem histórico independente do local e não foi alterada. Implantar exige:
- definir o destino;
- ensaiar com cópia sanitizada;
- interromper as escritas;
- fazer backup e testar a restauração;
- reconciliar as comandas abertas;
- configurar perfis e setor padrão.
