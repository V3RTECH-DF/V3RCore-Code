# Validação de CNPJ e CPF

> Catálogo do componente promovido em `V3RCore-Code#22`, a partir das quatro
> cópias que existiam na casa. Namespace `V3R\Core\Documents\`, disponível a
> partir da tag `v0.10.0`.

## 1. Por que promover

CNPJ e CPF estavam reimplementados em quatro plugins — GE Associados,
V3REvent, V3RLGPD e RIT360 Flow (o Solidário tem a sua de CPF) —, e o RIT360
Flow ia virar o quinto. O risco não é a duplicação em si: é o **CNPJ
alfanumérico**, que mudou a regra de formação. Cinco cópias são cinco chances
de uma ficar para trás, e a que ficar vai recusar CNPJ válido ou aceitar
inválido, calada, num campo que alimenta documento com validade jurídica.

## 2. API

```php
use V3R\Core\Documents\Cnpj;
use V3R\Core\Documents\Cpf;

Cnpj::isValid( '12.ABC.345/01DE-35' );   // true  (alfanumérico)
Cnpj::isValid( '12.345.678/0001-95' );   // true  (numérico)
Cnpj::normalize( ' 12.abc.345/01de-35 ' ); // '12ABC34501DE35'
Cnpj::format( '12abc34501de35' );          // '12.ABC.345/01DE-35'

Cpf::isValid( '529.982.247-25' );        // true
Cpf::normalize( '529.982.247-25' );      // '52998224725'
Cpf::format( '52998224725' );            // '529.982.247-25'
```

Classes puras, sem WordPress. Os três métodos são os mesmos nas duas, de
propósito: divergir no dialeto entre elas reintroduziria, dentro da
biblioteca, a diferença que a promoção veio eliminar.

**A biblioteca entrega a regra, não o modelo.** Quem quiser objeto-valor com
identidade (`from()`, `equals()`, exceção em entrada inválida) constrói no
próprio domínio e delega a validação aqui — foi o que evitou obrigar o GE
Associados a reescrever o domínio dele para consumir isto.

## 3. CNPJ alfanumérico

Regra da Receita Federal, em produção a partir de julho de 2026: as 12
primeiras posições aceitam `0-9` e `A-Z`; **os dois dígitos verificadores
continuam numéricos**; a máscara não muda (`XX.XXX.XXX/XXXX-DV`).

O dígito verificador é módulo 11 com os pesos clássicos, sobre o valor
`ASCII(c) - 48` de cada caractere (`0`-`9` → 0-9, `A`-`Z` → 17-42). Como para
dígitos esse valor é o próprio dígito, **o numérico é caso particular do
alfanumérico**: uma implementação valida os dois, e a retrocompatibilidade é
por construção — não há ramo separado que possa ficar para trás.

Os vetores oficiais do documento da Receita estão na suíte: `12.ABC.345/01DE-35`
(com o cálculo demonstrado passo a passo) e `12.345.678/0001-95`.

## 4. Decisões de comportamento

Três escolhas que valem estar escritas, porque quem migrar precisa saber:

1. **"Todos os caracteres iguais" é recusado** — `11111111111111`,
   `00000000000000`, `11111111111`. É convenção da casa, não regra da
   Receita: são os valores que passam pelo módulo 11 por acidente aritmético
   e que alguém digita para preencher campo obrigatório sem informar nada.
   ⚠️ A guarda **não** alcança nenhum CNPJ alfanumérico legítimo, e isso é por
   construção: os verificadores são numéricos, então um CNPJ com letra nunca
   tem os 14 caracteres iguais.
2. **`format()` de entrada incompleta devolve o normalizado, nunca o texto
   cru.** Formatar é operação de exibição; devolver o cru jogaria de volta na
   tela exatamente o que a normalização existe para tirar. **Esta é a única
   divergência real encontrada entre as cópias**: a de CPF do RIT360 Solidário
   devolvia a entrada original.
3. **A normalização remove o que não é dígito/letra, em vez de recusar a
   entrada** — é o que faz a máscara funcionar, e o preço é aceitar caractere
   grudado (`52998224725A` é válido). Comportamento herdado das quatro cópias,
   mantido de propósito: recusar exigiria decidir qual pontuação é máscara
   legítima, e isso é decisão de produto.

## 5. As cópias concordavam — medido, não presumido

A issue previa que as cópias pudessem discordar, e mandava comparar antes de
eleger uma. A comparação foi feita: **200 mil entradas** — aleatórias,
documentos válidos gerados e vizinhos com um caractere trocado — passadas
pelas quatro implementações e pela desta biblioteca. **Zero divergências de
validade.**

O que divergia era a **forma da API** (objeto-valor no GE Associados, métodos
estáticos nos demais; `is_valid` versus `isValid`) e o `format()` do CPF do
Solidário. Ou seja: o risco registrado na issue era **prospectivo** — a
próxima divergência, não uma já instalada. A consolidação é segura.

## 6. Migração dos consumidores

Aditivo: a biblioteca não toca no que já existe. Cada plugin migra quando
puder, trocando a cópia local por `V3R\Core\Documents\`. Quem tem objeto-valor
(GE Associados) mantém a classe e delega só a validação.

⚠️ Quem migrar o **CPF do RIT360 Solidário** herda a mudança do `format()`
descrita em §4.2 — o único ponto em que o comportamento muda.
