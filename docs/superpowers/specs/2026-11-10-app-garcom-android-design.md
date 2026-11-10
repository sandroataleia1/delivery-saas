# App do Garçom (Android nativo) — design

Data: 10/11/2026 · Status: aprovado

## Intenção

O delivery-saas é um produto vendido para restaurantes. Hoje o garçom só consegue
atender mesas pelo navegador do celular (tela Mesas), que é lento e não avisa nada
com o celular no bolso. O app do garçom é um **app Android nativo, parte do produto**,
para o garçom de qualquer restaurante assinante:

- atender mais rápido que pelo navegador;
- **receber notificações** na hora, mesmo com o app fechado.

Sucesso = o garçom passa o turno inteiro no app sem precisar do navegador, e nenhum
pedido de mesa ou item pronto fica esquecido.

## Decisões do dono do produto

- **Um app só na Play Store, com a marca do SaaS.** Não há app por restaurante.
- **Vínculo com a empresa = login.** O garçom entra com o e-mail e a senha cadastrados
  pelo restaurante em Usuários; todo usuário já pertence a uma única empresa.
  Depois do login o app mostra o **nome e a logo do restaurante**.
- **Celulares são do restaurante** e podem passar de um garçom para outro (troca de turno).
  Não existe troca de empresa dentro do app.
- **Login persistente:** o app continua logado até tocar em "Trocar garçom / Sair",
  ou até o usuário ser desativado / a senha ser trocada pelo admin.
- **Notificações (vibra o celular)** em dois momentos:
  1. cliente fez pedido pelo **QR Code da mesa** (ex.: bebida);
  2. **item da cozinha/bar ficou pronto** para levar à mesa.
- **Quem recebe é configurável por empresa:** todos os garçons, ou só o garçom
  responsável pela mesa.
- **Fim do atendimento:** o garçom só **avisa o caixa** que a mesa pediu a conta e
  **imprime a conta**. Receber pagamento e fechar a mesa continuam no caixa.

## Fluxo do turno

1. Abre o app; se não estiver logado, faz login.
2. Tela inicial: **salão** — mesas livres, ocupadas, com conta pedida ou pedindo atenção.
3. Vê **avisos não lidos** (os que chegaram com o celular no bolso também).
4. Atende: abre uma mesa livre e lança o pedido, ou adiciona itens a uma mesa aberta;
   o pedido vai para a cozinha e para os setores (ex.: Bar) como já acontece na web.
5. Pela notificação ou pela lista de avisos: **confirma/rejeita** o pedido do QR e
   **registra a entrega** do item pronto.
6. Mesa pediu a conta: marca "conta pedida" e imprime a conta.

## O que já existe no sistema (reutilizar, não reescrever)

- Mesas e comandas: `DiningTable`, `TableOrder` (guarda `user_id` de quem abriu),
  `TableOrderItem` (já tem `client_uuid` para evitar item duplicado em reenvio).
  Ações em `TableOrderController`: abrir, lançar itens, mandar para a cozinha,
  transferir, pedir conta (`bill_requested_at`), imprimir comanda da cozinha.
- Pedidos do QR: `MenuOrderReceipt` (pendente/confirmado/rejeitado) e
  `MenuOrderReceiptController` (pendentes, confirmar, rejeitar). A empresa já
  escolhe confirmação manual ou automática em `RestaurantOperationSettings`.
- Produção: `ProductionWork` (aguardando → preparando → pronto → entregue) e
  `TransitionProduction`; permissão `production.deliver` para entregar.
- Permissões: `tables.operate`, `tables.confirm`, `production.deliver`; perfil padrão
  "Garçom" já existe.
- Regras de negócio ficam em Actions/Controllers; a API deve chamar as mesmas
  Actions que a web, nunca duplicar a regra.

## O que é novo

### Servidor (Laravel)
- **API para o app** (`/api/v1/...`) com Eloquent API Resources, versionada.
- **Autenticação por token** com Laravel Sanctum (*novo pacote — exige aprovação*).
  Token por aparelho; revogado ao sair, ao desativar o usuário e ao admin trocar a senha.
- **Registro do aparelho para push**: guarda o token do Firebase do aparelho vinculado
  ao usuário logado; trocar de garçom troca o vínculo.
- **Envio de push** via Firebase Cloud Messaging (*conta Google/Firebase do dono —
  exige aprovação*), disparado em fila quando: chega pedido do QR; um item vira "pronto".
- **Configuração por empresa**: "avisos para todos os garçons" ou "só para o garçom
  da mesa", na tela de configurações de operação.
- **Impressão da conta da mesa** (pré-conta) na impressora do caixa — *não existe hoje*;
  hoje só se imprime a comanda da cozinha (`PrintQueue::queueKitchen`).
- **Lista de avisos** por usuário com lido/não lido, para o garçom ver o que perdeu.

### App (Android)
- Pasta `garcom-app/` na raiz deste repositório.
- Telas: login · salão (mesas) · comanda da mesa · cardápio para lançar itens
  (categorias, busca, adicionais, tamanhos de pizza, observação) · avisos · trocar garçom.
- Recebe push com o app fechado e abre direto na mesa/aviso ao tocar.

## Regras definidas na revisão

Respondido pelo código:
- **Lançar itens** reutiliza a Action `AddOrderItems` e as regras de composição
  (`compositionRules`) do `TableOrderController`; preço, adicionais, pizza e esgotado já
  ficam no servidor. Mover as regras de validação para um FormRequest compartilhado
  entre web e API, sem duplicar.
- **Pré-conta** usa a taxa de serviço que a empresa já configura (mesma conta do
  fechamento em `CloseTableOrder`), só que sem fechar a mesa.

Decisão do dono:
- **Modo "só o garçom da mesa" sem responsável** (mesa aberta pelo caixa ou pelo QR, ou
  garçom deslogado/sem aparelho): o aviso vai para **todos os garçons**, como no modo "todos".
- Sanctum e Firebase entram no plano, mas a instalação/criação só acontece após aprovação
  explícita do dono; o restante do app e da API não depende disso para começar.

Decisões do Claude (o dono pode trocar):
- **Quem recebe cada aviso no modo "todos":** pedido do QR → usuários ativos da empresa
  com `tables.confirm`; item pronto → usuários ativos com `production.deliver`.
  Só aparelhos com token registrado e sessão ativa.
- **Agrupamento:** um push por mesa por acontecimento (ex.: "Mesa 5: 2 itens prontos —
  Bar"); itens de setores diferentes que ficam prontos juntos viram avisos separados por setor.
- **Qualquer garçom pode lançar em qualquer mesa aberta** (igual à web). Transferir mesa
  entra no app, usando a Action `TransferTable`; o responsável passa a ser quem transferiu.
- **Impressora da conta:** nova configuração da empresa "impressora da conta"; se vazia,
  usa a impressora de pedidos (`order_printer_id`); sem impressora ativa, o app mostra o
  erro, como a web faz com a comanda da cozinha.
- **Versão mínima do app:** endpoint `/api/v1/app` informa a versão mínima; abaixo dela o
  app pede para atualizar na Play Store.

## Fora da primeira versão

- iPhone.
- Receber pagamento / fechar a mesa no app.
- Funcionar sem internet (sem conexão o app avisa e não lança pedido).
- App com a marca de cada restaurante.

## Anti-objetivos (falha mesmo que "funcione")

- Pedido lançado duas vezes por toque repetido ou rede instável.
- Garçom vendo ou alterando dados de outra empresa.
- Notificação que não chega com o app fechado, ou que chega para quem não devia.
- Regra de negócio diferente entre app e web (ex.: app deixando lançar produto esgotado).

## Escolhas técnicas (decisão do Claude — o dono pode trocar)

- Kotlin + Jetpack Compose, Android mínimo 8.0 (API 26), Material 3.
- Retrofit/OkHttp + kotlinx.serialization para a API; token guardado com criptografia
  (EncryptedSharedPreferences / DataStore).
- Firebase Cloud Messaging para push.
- Atualização da tela de mesas por consulta periódica enquanto o app está aberto
  (como o painel web); push para quando está em segundo plano.

## Deixado para quem for construir

- Nomes exatos dos endpoints, formato JSON e telas detalhadas.
- Arquitetura interna do app (camadas, injeção de dependência).
- Como o garçom "responsável" muda quando a mesa é transferida
  (sugestão: passa a ser quem fez a transferência).
- Textos das notificações.
