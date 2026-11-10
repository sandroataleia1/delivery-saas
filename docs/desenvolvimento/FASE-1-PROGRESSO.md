# Execução — plano: docs/superpowers/plans/2026-11-06-fundacao-e-evolucao-operacional.md

Autorização: exclusivamente Fase 1, conforme mensagem do usuário. Sem remoto, push, merge, deploy ou acesso ao banco operacional.

Baseline: `1fe21fc`, 368 arquivos. Inventário SHA-256 anterior às alterações guardado no diretório temporário `delivery-phase1-baseline-6f3b3f2bccb448bbb95578df95f8edf1`.

Pré-verificação: tarefas 1/2 fornecem dependências e ambiente isolado consumidos pelas tarefas 3/4/5. Auditoria original permanece imutável. O banco de produção ainda não tem versão definida.

Decisão: execução na branch local `phase1/foundation`, com baseline recuperável e dados de teste isolados, mantendo os arquivos disponíveis no diretório aberto pelo usuário. Nenhuma aplicação instalada ou banco real configurado nesta cópia.

Decisão: usar `git -c safe.directory=C:/xampp/htdocs/delivery-saas` por comando, devido à diferença de proprietário entre usuário e sandbox; não modificar configuração global.

## Tarefas

1. Concluída: baseline `1fe21fc`, preparação `47ed6d3`; 118 pacotes PHP e 109 pacotes npm instalados pelos locks. Boost 2.10.4 instalado sem atualização de pacotes.
2. Concluída localmente: guard (13 testes); fixture de auditoria corrigida sem desabilitar triggers. Suíte final MariaDB: 435/435. C1–C8 passaram; C9 reproduziu 60 deadlocks com integridade válida em 30 rodadas. Classificação de DeadlockException aninhada corrigida com RED→GREEN (2 testes).
3. Concluída: `e0bce72`, 5 testes, 13 asserções. RED: quatro falhas antes da guarda; GREEN: todos passaram.
4. Concluída: RED de validação em SQLite (48 falhas) e MariaDB (54 falhas); GREEN: 83 casos/346 asserções. Requisito bcmath declarado; Composer recusa plataforma simulada sem extensão (exit 2). Nenhuma versão de pacote mudou.
5. Concluída no âmbito local: CI entregue e sintaxe validada; build, Pint, plataforma e auditorias passaram. Relatório e evidências em `docs/desenvolvimento/`. Execução do runner remoto e equivalência com produção permanecem pendentes, conforme previsto no aceite.

## Decisões e ocorrências

- A chamada `composer -V` ficou rápida com execução fora do sandbox. Após criar `.git`, o executor isolado deixou de iniciar processos (`setup refresh had errors`); comandos autorizados foram executados com escalonamento. Nenhuma alteração global de segurança Git foi feita.
- A passagem de `^2.10` pelo wrapper `.bat` removeu o circunflexo. A restrição foi restaurada chamando diretamente o PHAR; o resultado final de composer.json não muda a versão Boost.
- Boost não registra comandos com ambiente de produção sem debug; instalação executada com APP_ENV=local e DB_DATABASE=:memory:, sem `.env`.
- A primeira baseline SQLite não tinha APP_KEY (285 erros de preparação). Adicionada chave pública exclusiva de teste nos XMLs; nenhuma chave real foi alterada.
- Decisão: `/vendas` não possui filtro `search`; a prova histórica enviava parâmetro ignorado. O teste usa os filtros reais `number`/`status`, sem acrescentar busca nova ao produto.
- `composer update --lock` offline não conseguiu resolver metadados dos pacotes futuros já travados presentes no cache. Decisão: sincronizar exclusivamente `content-hash` e `platform.ext-bcmath` pelas próprias classes Composer\Package\Locker e Composer\Json\JsonFile do PHAR. Nenhuma versão ou referência de pacote muda; `composer validate --strict` e `check-platform-reqs` passaram. package-lock.json permanece idêntico ao baseline.
- Decisão: C9 é uma caracterização do defeito AUD-020 e sua integridade, não uma correção. O código de saída do harness cobre invariantes/erros inesperados; o JSON registra separadamente a falha operacional conhecida. Não considerar o produto livre de deadlocks.
- Decisão: manter o schema e o núcleo financeiro/operacional. Nenhuma migration de negócio ou nova regra de QR é parte desta fase.
- Revisão independente confirmou três melhorias na infraestrutura: recusar DB_SOCKET, sincronizar prontidão de todos os processos e verificar efeitos de vendas/pagamentos/estoque de ambos os UUIDs no C9. Corrigidas em `378e6b6`. Testes RED→GREEN: socket (1 falha), barreira (2 falhas), reconciliação (10 falhas). Nenhuma correção do deadlock de domínio foi feita.
- Uma execução intermediária MariaDB teve `zones.0.fee` ausente. O log mostrou falta de arquivo CSS de fontes no mesmo instante do build paralelo. Causa: concorrência da própria verificação sobre `public/build`. Teste isolado e suíte integral passaram sem build simultâneo; adicionadas asserções de persistência à fixture. Não houve alteração no fluxo de entrega.
- Execução final: SQLite 433 aprovados/2 ignorados, 2.276 asserções, 25,069 s; MariaDB 435 aprovados, 2.280 asserções, 33,539 s. Ignorados no SQLite: dois testes exclusivos de triggers. C1–C8 íntegros e C9 com 0 estados inválidos/0 erros inesperados/60 deadlocks conhecidos.
- Cinco arquivos originais de auditoria conferidos por SHA-256: nenhuma divergência. Diff dos caminhos de Actions, modelos, configurações, rotas, frontend, migrations e package-lock vazio contra baseline. `.env` continua ausente; nenhum remoto configurado.
- O limite temporário da revisão automática interrompeu uma chamada sem executá-la. Após o usuário pedir continuidade, as verificações foram retomadas sem contornar o controle de aprovação.
- Estado final: Fase 1 concluída localmente, com limitações explicitadas no relatório. Aguardar autorização antes de qualquer trabalho da Fase 2.
