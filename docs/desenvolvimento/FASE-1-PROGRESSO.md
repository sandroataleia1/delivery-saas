# Execução — plano: docs/superpowers/plans/2026-11-06-fundacao-e-evolucao-operacional.md

Autorização: exclusivamente Fase 1, conforme mensagem do usuário. Sem remoto, push, merge, deploy ou acesso ao banco operacional.

Baseline: `1fe21fc`, 368 arquivos. Inventário SHA-256 anterior às alterações guardado no diretório temporário `delivery-phase1-baseline-6f3b3f2bccb448bbb95578df95f8edf1`.

Pré-verificação: tarefas 1/2 fornecem dependências e ambiente isolado consumidos pelas tarefas 3/4/5. Auditoria original permanece imutável. O banco de produção ainda não tem versão definida.

Decisão: execução na branch local `phase1/foundation`, com baseline recuperável e dados de teste isolados, mantendo os arquivos disponíveis no diretório aberto pelo usuário. Nenhuma aplicação instalada ou banco real configurado nesta cópia.

Decisão: usar `git -c safe.directory=C:/xampp/htdocs/delivery-saas` por comando, devido à diferença de proprietário entre usuário e sandbox; não modificar configuração global.

## Tarefas

1. Em andamento: baseline concluído; preparação de dependências.
2. Pendente: harness, guard, baseline SQLite/MariaDB e concorrência.
3. Pendente: testes e proteção dos dois seeders.
4. Pendente: validação, bcmath e formatação.
5. Pendente: CI, revisão e relatório.
