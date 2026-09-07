# Componentes compartilhados da família

> **Leia esta página antes de construir qualquer coisa.** Ela lista o que já
> existe pronto para os plugins da casa consumirem, nos **dois** repositórios da
> família. O que não estiver aqui é que se constrói.
>
> Atualizada em 07/09/2026.
>
> ⚠️ A coluna **quem consome** é a mais informativa desta página: ela revela
> capacidade com um consumidor só — e capacidade com nenhum.

## Por que ela existe

O envio de documento ao serviço de geração foi reimplementado **três vezes**, de
forma independente e com comportamentos divergentes. Foi isso que fez esta
biblioteca existir. A regra que evita a quarta é simples e vem antes do código:
**consultar o que já existe.**

⚠️ Cada capacidade abaixo tem um catálogo próprio, ao lado do código, com o
detalhe de uso. Esta página é o índice — ela diz **o que existe e quem já usa**,
não como se usa.

## Os dois repositórios

| | O que é | Como chega ao plugin |
| --- | --- | --- |
| **`v3rtech/v3r-core`** | biblioteca **PHP** | Composer, embutida pelo Strauss |
| **`@v3rtech/v3r-front`** | pacote de **tela** (React) e ferramenta de build | gerenciador de pacotes do JavaScript, por **tag fixa** |

**A biblioteca governa, o pacote desenha**, e a fronteira entre os dois é
**dado**: o PHP produz a árvore de navegação, o componente consome uma forma
documentada. Os dois versionam separado.

⚠️ O pacote de front **não** viaja pela biblioteca PHP: no empacotamento dos
plugins a tela é compilada **antes** de a biblioteca ser embutida, e cada plugin
precisa embutir a própria cópia para dois produtos nossos não colidirem no mesmo
WordPress.

## O que a biblioteca PHP oferece

| Capacidade | O que resolve | Desde | Quem consome hoje | Detalhe |
| --- | --- | --- | --- | --- |
| **Licença e atualização** | o plugin valida a licença e se atualiza sozinho | 0.1.0 | os 9 | `api-contract.md`, `integracao-em-plugin.md` |
| **Documentos** | valida e formata CNPJ e CPF, inclusive o alfanumérico | 0.10.0 | Flow | `documentos-cnpj-cpf.md` |
| **Sugestão de e-mail** | corrige o domínio digitado errado enquanto a pessoa escreve | 0.9.0 | V3REvent | `sugestao-de-dominio-de-email.md` |
| **Ativo de front** | distribui script/estilo da biblioteca para a tela do plugin | 0.9.0 | V3REvent | `sugestao-de-dominio-de-email.md` |
| **Acesso por link temporário** | a pessoa entra por um link enviado ao próprio e-mail | 0.8.0 | V3REvent | `acesso-por-link-temporario.md` |
| **Notificação** | despacho de mensagem multi-canal (hoje, e-mail) | primeiras versões | Flow | — |
| **Assinatura com certificado** | código de autenticidade, leitura do certificado, modo de assinatura | 0.11.0 | Flow | `assinatura-com-certificado.md` |
| **Navegação do painel** | entrada única no menu, navegação interna, permissão e bloqueio de endereço | 0.14.0 | V3RLGPD, Flow | `navegacao-do-painel.md` |
| **Endereços antigos** | o endereço salvo de um submenu que deixou de existir continua funcionando | 0.19.0 | V3RLGPD | `navegacao-do-painel.md` §7 |
| **Papéis orientados a dados** | papéis que o cliente edita, e quem pode o quê | 0.20.0 | V3RLGPD | `papeis-orientados-a-dados.md` |

## O que o pacote de tela oferece

| Peça | O que resolve | Desde | Quem consome hoje |
| --- | --- | --- | --- |
| **Cabeçalho** | logo, título da tela, versão e ações, na régua da família | 0.1.0 | V3RLGPD, Flow |
| **Barra de navegação** | grupos e abas, a partir da árvore que o PHP entrega | 0.1.0 | V3RLGPD, Flow |
| **Área de avisos do painel** | põe os avisos do WordPress no lugar certo | 0.1.0 | V3RLGPD, Flow |
| **Guarda de rota** | responde se a pessoa pode abrir uma rota, negando o desconhecido | 0.2.0 | V3RLGPD, Flow |
| **Correção da cascata** | impede o CSS do painel de derrotar o do plugin, e o do plugin de apagar o componente | 0.6.0 | V3RLGPD (painel e gestão pública) |

Detalhe de todas: `Front/docs/contrato-do-pacote.md`.

## ⚠️ O que este índice revela, e vale saber antes de decidir

**A maior parte tem um consumidor só.** Foi assim que a assinatura, os
documentos e o link temporário nasceram: um produto precisou, a peça subiu, e
ninguém mais foi atrás.

**E cinco dos nove plugins ainda apontam para a `0.7.0`**, de agosto. Estão em
produção e funcionando — só não alcançam nada do que veio depois. Quase tudo
listado aqui está disponível para plugins que não sabem que existe.

⚠️ **Adoção validada sozinha não prova convivência.** Cinco defeitos desta
camada só apareceram quando o **segundo** plugin adotou — e um deles fazia um
produto **cancelar a entrada de menu do outro**, coisa que nenhuma quantidade
de teste em um plugin só revelaria. Quem adotar deve medir num site com mais de
um plugin da casa instalado.

## Como consumir

A distribuição é **opt-in**: o plugin declara o que precisa, e quem não usa não
carrega peso. A receita de integração — declaração da dependência, prefixação,
empacotamento e verificação — está em `integracao-em-plugin.md`.

⚠️ **Fixe sempre uma versão.** "A principal andou" não é "a versão saiu": o
consumidor alcança o que está publicado em tag, e é isso que faz o build de cada
máquina produzir a mesma coisa.

## Quando construir em vez de consumir

Quando a capacidade **não está nesta página**. E, nesse caso, a pergunta
seguinte é se ela deveria estar: peça que sirva a mais de um produto nasce aqui,
não no plugin.

⚠️ O critério de corte não é "parece genérico". As promoções que deram certo
subiram o que **não toca a identidade de nenhum produto** e cujo erro tem
consequência séria. A que foi recusada — o serviço de link temporário inteiro —
foi recusada porque os dois consumidores discordavam exatamente no ponto que a
abstração teria de fixar. Ver `acesso-por-link-temporario.md` §2 e o ADR-016.
