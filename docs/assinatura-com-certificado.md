# Assinatura de documentos com certificado

> Catálogo do namespace `V3R\Core\Signing\`, promovido em `V3RCore-Code#27` e
> corrigido em `V3RCore-Code#28`. Disponível a partir da tag `v0.11.0`
> (correção de `issue()`/`seal()` na `v0.12.0`; leitor de certificado na
> `v0.13.0`, `#29`).

## 1. Por que a peça existe

O V3RProp já tinha um mecanismo próprio de assinatura, que funciona, e é dele
que vêm os quatro defeitos que esta peça existe para não repetir
(V3RProp-Code#62, #63, #64):

1. **O documento não dizia como foi assinado** — a página era idêntica com e
   sem certificado digital, então quem recebia não tinha como saber se
   aquilo tinha valor de assinatura certificada ou só de registro.
2. **O "código de autenticidade" era derivado de campos públicos**,
   calculado de um jeito diferente conforme o motor de PDF usado, e guardado
   em lugar nenhum — irreproduzível e inverificável.
3. **Não existia nada que conferisse um documento emitido.** O código
   estava impresso no papel, mas não havia onde consultá-lo.
4. **A senha do certificado ficava em texto claro**, e a chave privada era
   escrita numa pasta pública durante a assinatura.

O RIT360 Flow ia precisar do mesmo mecanismo, e reimplementá-lo de novo ali
seria a sexta cópia da mesma história — a promoção evita duas versões
divergentes crescendo em paralelo.

## 2. O corte deliberado

**A biblioteca não gera PDF e não ganha dependência de terceiro.** Bibliotecas
de assinatura de PDF (TCPDF, FPDI e afins) são pesadas, trazem constantes
globais, e brigariam com a prefixação (Strauss) que o hospedeiro aplica —
pagar esse custo para todo consumidor da v3r-core, mesmo quem nunca assina
documento, não compensa.

A biblioteca fica só com o que é comum a qualquer assinador: o **contrato**
(`SignerInterface`) que a implementação concreta de cada plugin precisa
cumprir, e a **guarda do que é sensível** — senha do certificado e código de
autenticidade. Quem gera o PDF e quem assina de fato (TCPDF, FPDI, o que o
plugin já usar) é cada hospedeiro.

## 3. API

### 3.1 Decisão do modo de assinatura

`SigningModeResolver::decide()` é função pura — não toca disco nem banco.
Recebe fatos já apurados por quem chama e devolve sempre os dois juntos, modo
e motivo: nunca existe um sem o outro, porque o motivo é o que explica ao
administrador por que a assinatura saiu degradada.

```php
use V3R\Core\Signing\SigningModeResolver;
use V3R\Core\Signing\SigningMode;

$decision = SigningModeResolver::decide(
    hasCertificateFile: $hasCertFile,
    expiresAt: $expiresAt,   // DateTimeImmutable|null
    now: new DateTimeImmutable(),
);

$decision->mode();        // SigningMode::CERTIFICADO_DIGITAL ou ::REGISTRO_ELETRONICO
$decision->reason();      // SigningModeReason::*
$decision->isDegraded();  // true quando caiu em REGISTRO_ELETRONICO
```

**Degradação é sempre conservadora, nunca o contrário.** O único caminho até
`CERTIFICADO_DIGITAL` é "há arquivo de certificado E há validade conhecida E
ela é futura" — as três condições, nunca menos. Qualquer incerteza (sem
arquivo, validade desconhecida, certificado vencido) cai para
`REGISTRO_ELETRONICO`. Isso vale mesmo quando a causa da incerteza é
diferente — "nunca cadastrou certificado" e "cadastrou, mas venceu" caem no
mesmo modo degradado, mas com motivos (`SigningModeReason`) diferentes, para
a tela poder dizer qual dos dois aconteceu.

### 3.2 Cofre do certificado e entrega do material

`CertificateSecretVault` cifra a senha do certificado com
`sodium_crypto_secretbox` (autenticado), sob uma chave que o **próprio site**
gera e configura — nunca embutida no pacote do plugin (ver §5, decisão de
comportamento e ADR-015).

```php
use V3R\Core\Signing\CertificateSecretVault;

$vault = CertificateSecretVault::fromConstant( $store, 'ritflow' );
// lê V3R_SIGNING_ENCRYPTION_KEY do wp-config.php, por convenção

if ( $vault->isAvailable() ) {
    $vault->storePassword( $senhaEmTextoPleno );
    $senha = $vault->retrievePassword();
}
```

`EphemeralSecretFile` entrega material sensível em disco quando o assinador
exige arquivo, não bytes em memória — fora da área servida pela web, com
`chmod 0600`, nome imprevisível, e remoção garantida (shutdown function +
destructor como segunda rede; `sweepOrphans()` cobre o caso de processo morto
por sinal não capturável):

```php
use V3R\Core\Signing\EphemeralSecretFile;

$file = EphemeralSecretFile::write( $bytesDoCertificado );
// ... entrega $file->path() ao assinador ...
$file->dispose(); // opcional — a shutdown function já garante a remoção
```

O contrato que o assinador de cada plugin implementa:

```php
use V3R\Core\Signing\SignerInterface;
use V3R\Core\Signing\CertificateMaterial;
use V3R\Core\Signing\SigningException;

final class MeuAssinadorTcpdf implements SignerInterface {
    public function sign( string $unsignedFilePath, CertificateMaterial $material ): string {
        // ... assina com TCPDF/FPDI, traduzindo falha para SigningException ...
    }
}
```

`SigningException` distingue três causas — `CERTIFICADO_ILEGIVEL`,
`SENHA_INVALIDA`, `FALHA_NA_ASSINATURA` — porque o chamador precisa saber se
o problema é "o administrador recadastra a senha" ou "isto pode exigir
suporte".

### 3.3 Código de autenticidade

`AuthenticityCode` é **emitido**, nunca derivado: `random_int()` (CSPRNG)
sobre um alfabeto de 31 símbolos (maiúsculas e dígitos, excluindo `0/O`,
`1/I/L`), formatado em quatro grupos de quatro (`XXXX-XXXX-XXXX-XXXX`) para
ser ditado por telefone sem ambiguidade.

```php
use V3R\Core\Signing\AuthenticityRegistry;
use V3R\Core\Signing\SigningMode;

$registry = new AuthenticityRegistry( $store, 'ritflow' );

// 1. Emitir — o código nasce ANTES de o arquivo final existir.
$record = $registry->issue( SigningMode::CERTIFICADO_DIGITAL );
$codigo = $record->code(); // imprime isto DENTRO do documento

// 2. (gerar o PDF final, já com $codigo impresso nele)

// 3. Selar — só depois de o arquivo pronto existir.
$registry->seal( $codigo, $caminhoAbsolutoDoArquivoFinal );

// Conferência, mais tarde, por qualquer um que tenha o código:
$verificacao = $registry->verifyFile( $codigo, $caminhoApresentadoAgora );
$verificacao->wasFound();      // o código existe?
$verificacao->isAwaitingSeal(); // emitido, ainda não selado
$verificacao->wasTampered();    // selado, e o arquivo não bate
```

A conferência é sempre **consulta** ao que `seal()` gravou — nunca recálculo
a partir de campo do documento. É essa propriedade, não o alfabeto do
código, que torna o código verificável de verdade: quem só olha o documento
não reproduz o código, e quem só tem o código não aprende nada sobre o
documento sem consultar o registro.

### 3.4 Leitor de certificado

`SigningModeResolver::decide()` já recebia `?DateTimeImmutable $expiresAt`
como fato apurado desde a v0.11.0 — mas até a `v0.12.0` **nada na
biblioteca apurava esse fato**. Quem chamava tinha de abrir o PKCS#12 por
conta própria, ou copiar a lógica de quem já tinha feito isso, para
responder à pergunta que a própria biblioteca faz. `CertificateInspector`
(`#29`, promovido do RIT360 Flow) fecha essa lacuna.

```php
use V3R\Core\Signing\CertificateInspector;
use V3R\Core\Signing\SigningModeResolver;

$inspector  = new CertificateInspector();
$inspection = $inspector->inspect( $material ); // o mesmo CertificateMaterial de SignerInterface::sign()

$decision = SigningModeResolver::decide(
    hasCertificateFile: true,
    expiresAt: $inspection->expiresAt(),
    now: new DateTimeImmutable(),
);

$titular = $inspection->subject(); // CertificateSubject|null
```

`inspect()` é o único ponto da biblioteca que chama
`openssl_pkcs12_read()` — a mesma abertura confirma duas coisas de uma
vez: que a senha bate (senão a extensão recusa abrir) e que o arquivo é
mesmo um PKCS#12 legível, de onde sai um certificado. Note o que ela
**não** confirma: a presença da chave privada não é verificada, então um
PKCS#12 só com certificados é lido como válido aqui e só falha na hora de
assinar. É fiel à implementação de origem, e mudar isso seria endurecer o
comportamento de um consumidor em produção — não uma correção de rota
tomada de passagem. O resultado, `CertificateInspection`
(`expiresAt()` + `subject()`), segue o dialeto que o módulo já usa para
resultado de operação (`AuthenticityVerification`) e alimenta
`SigningModeResolver::decide()` sem tradução no meio.

`CertificateSubject` é o titular lido do certificado: nome, tipo e
dígitos do documento (CNPJ ou CPF), emissor, e se a identidade é
`isAttested()` ou apenas declarada. `maskedDocument()` é o único acessor
pensado para exibição — CNPJ sai inteiro e formatado, delegando a
`Documents\Cnpj::format()` (nunca reimplementa a máscara); CPF sai
mascarado (`***.982.247-**`). Os dígitos crus, em `documentDigits()`,
existem para persistência, nunca para tela, log ou URL.

## 4. A ordem emitir → imprimir → selar

**Esta é a parte mais importante para quem for consumir.** O código de
autenticidade é impresso *dentro* do documento — então, no instante em que
ele é emitido, o arquivo final (o PDF que a pessoa vai receber) **ainda não
existe**. A sequência correta tem três passos, nesta ordem, e não dá para
pular nem inverter nenhum:

1. **Emitir** (`issue()`) — sorteia o código e grava um registro sem resumo
   de arquivo. É este código que vai impresso no documento.
2. **Imprimir** — quem consome gera o PDF final com o código já escrito
   nele. Isto acontece fora da biblioteca.
3. **Selar** (`seal()`) — recebe o código e o caminho do arquivo **já
   pronto, com o código já impresso**, calcula o sha256 dele e grava esse
   resumo no registro que `issue()` criou.

⚠️ **Selar contra um artefato intermediário — o PDF de antes de o código ser
impresso, ou qualquer versão que não seja a entregue de verdade — faz
`verifyFile()` acusar adulteração de um documento íntegro.** Foi exatamente
isto que aconteceu na primeira versão da peça (`v0.11.0`): `issue()` exigia
o arquivo para calcular o resumo, o que obrigava a selar um artefato sem o
código impresso; o resumo gravado nunca batia com o que a pessoa recebia
depois, e `verifyFile()` respondia "documento adulterado" para documentos
que nunca tinham sido alterados. A correção (`v0.12.0`, `#28`) separou
`issue()` de `seal()` justamente para tornar este erro impossível de cometer
por acidente — mas continua possível cometê-lo de propósito, selando contra
o arquivo errado. Não faça isso.

Selar é **idempotente com o mesmo resumo** (permite repetir uma tentativa que
falhou entre emitir e selar) e **recusado com resumo diferente**
(`AuthenticitySealingException::RESUMO_DIVERGENTE`) — uma vez selado, o
registro não pode passar a provar outra coisa.

### 4.1 Onde selar, exatamente: depois da assinatura

O passo 3 não é "depois de montar o PDF" — é **depois de tudo o que ainda
reescreve o arquivo**, e a assinatura digital reescreve. Assinadores
costumam gravar o PDF assinado por cima do caminho recebido: selar logo
depois da montagem grava o resumo de um arquivo que ninguém vai receber, e o
sintoma é o mesmo do defeito da `#28` — `verifyFile()` acusando adulteração
de documento íntegro. Sele no último ponto em que o arquivo já está na forma
entregue.

⚠️ **Este erro não aparece em teste cujo duplo de assinador não reescreve o
arquivo.** Um duplo que só devolve o caminho recebido, sem tocar no
conteúdo, faz o resumo de antes e o de depois coincidirem — e a suíte passa
sobre uma implementação que sela cedo demais. Faça o duplo reescrever o
arquivo; é o que transforma este defeito em teste vermelho. _(Achado do
RIT360 Flow, primeiro consumidor, na adoção da v0.12.0.)_

### 4.2 Expor a conferência numa rota pública

Se a conferência for oferecida numa página aberta, sem autenticação — que é
o caso de uso previsto, já que o ponto do código é ser conferido por quem
recebeu o documento —, duas guardas são do consumidor, não da biblioteca:

1. **Código desconhecido tem de responder a mesma coisa com e sem arquivo.**
   Se a resposta mudar quando um arquivo é enviado, a rota vira oráculo de
   quais códigos existem: basta enviar qualquer arquivo e ler a diferença.
   `verifyFile()` já devolve `notFound()` sem olhar o arquivo; o cuidado é
   não deixar a camada de apresentação vazar a distinção.
2. **O arquivo apresentado é lido para calcular o resumo e nada mais** — não
   é gravado, não é servido de volta, não vira anexo. A biblioteca só lê;
   quem recebe o upload é que decide o resto.

## 5. Decisões de comportamento

Sete escolhas que quem consome precisa conhecer, cada uma com o porquê:

1. **Degradação é sempre conservadora, nunca o contrário.** Qualquer
   incerteza sobre o certificado (sem arquivo, validade desconhecida,
   vencido) cai para `SigningMode::REGISTRO_ELETRONICO`, e o motivo
   (`SigningModeReason`) vem sempre junto — nunca existe modo sem motivo.
   É o que evita a degradação silenciosa que o V3RProp tinha (defeito 1):
   o administrador sempre sabe *por que* a assinatura não saiu como
   esperava, em vez de descobrir sozinho comparando duas páginas idênticas.

2. **`AuthenticityVerification` tem um terceiro estado, e ele nunca conta
   como adulteração.** Além de "não existe" (`notFound()`) e "existe e
   confere" (`found()`), há "emitido e ainda não selado"
   (`isAwaitingSeal()`) — o intervalo real entre `issue()` e `seal()`. Antes
   da correção da `#28`, um registro sem resumo caindo direto no booleano de
   conferência teria produzido `wasTampered() === true` sobre um documento
   intacto — a página teria afirmado que um documento íntegro foi
   adulterado. Hoje `wasTampered()` só é verdadeiro quando o registro está
   selado **e** o resumo não bate; um registro não selado nunca pode ser
   acusado.

3. **Selamento é idempotente com o mesmo resumo, recusado com resumo
   diferente.** Selar de novo com o resumo idêntico não faz nada (permite
   refazer uma tentativa que falhou no meio do caminho, sem penalidade).
   Selar com um resumo diferente é sempre recusado
   (`AuthenticitySealingException::RESUMO_DIVERGENTE`) — aceitar trocaria o
   que o registro promete depois de já ter sido selado, e essa promessa é o
   motivo de o mecanismo existir. Não há saída controlada para corrigir um
   registro selado com o resumo errado: a única correção possível é apagar
   o registro de teste antes de qualquer emissão real, porque depois da
   primeira emissão real isto se torna irreversível (levantamento de
   `#28`, no fechamento da issue).

4. **`file_hash` é opcional no formato persistido, por compatibilidade.** O
   campo só existe no array gravado depois que `seal()` roda; um registro
   recém-emitido e ainda não selado simplesmente não o tem.
   `AuthenticityRecord::fromArray()` aceita a ausência dele. Isso garante
   que um registro gravado **antes** da correção da `#28` — que sempre
   tinha o campo, porque a versão antiga exigia o arquivo em `issue()` —
   continua lendo exatamente como antes: a mudança é aditiva na leitura,
   mesmo mudando a API de escrita.

5. **Sem validade reconhecida no certificado, `CertificateInspection::expiresAt()`
   é sempre `null` — nunca uma data inventada** (`#29`). Campo ausente,
   ilegível, ou a extensão `openssl` indisponível no ambiente: todos caem
   no mesmo `null`. É esse `null` que faz `SigningModeResolver::decide()`
   cair em `SigningModeReason::SEM_VALIDADE_CONHECIDA` — degradar com
   honestidade, nunca chutar uma validade que o certificado não confirmou.

6. **`subjectAltName` não é usada para extrair o documento do titular**
   (`#29`). É onde a ICP-Brasil guarda CPF/CNPJ de forma canônica, mas
   dentro de um `othername` com OID próprio que o PHP não decodifica
   (aparece como `othername:<unsupported>`); varrer aquele bloco atrás de
   uma sequência de 11 dígitos pegaria o NIS ou o RG do responsável no
   lugar do CPF do titular — documento errado impresso é pior que nenhum.
   As fontes usadas são, nesta ordem: o nome comum no formato
   `NOME:DOCUMENTO` (`RIT:12345678000195`) e, quando ele não traz o
   documento, `serialNumber`/`organizationIdentifier` — só aceitos como
   campo **inteiro**, com exatamente 11 ou 14 dígitos, nunca um trecho
   extraído de uma cadeia maior.

7. **"Atestado" significa "não autoassinado", não "emitido pela
   ICP-Brasil"** (`#29`). Um certificado emitido por autoridade
   certificadora teve o nome do titular conferido por alguém antes de
   virar certificado; um autoassinado só tem o que quem gerou o arquivo
   digitou, e fabricar um `CN=RIT:12345678000195` autoassinado é trivial.
   Restringir a uma lista de emissores confiáveis da ICP-Brasil exigiria
   essa lista, e é decisão de produto que não foi tomada aqui — um
   certificado de AC privada conta como atestado do mesmo jeito. Emissor
   ausente ou ilegível é tratado como declarado (`isAttested() === false`),
   o lado conservador.

### 5.1 A extensão `openssl` é sugerida, nunca exigida

`ext-openssl` **não** entra no `require` do `composer.json` — entraria no
pacote de todo plugin que carrega a v3r-core, e boa parte deles nunca
assina documento; quebrar a instalação desses por uma dependência que não
usam não compensa. Ela vive em `suggest`, e `CertificateInspector`
verifica a disponibilidade em tempo de execução: sem a extensão,
`inspect()` devolve `CertificateInspection::failure()` — o mesmo caminho
degradado de qualquer outra causa de "não deu para ler o certificado",
nunca um erro fatal. Quem consome precisa saber que esse caminho existe e
é o comportamento pretendido: um site sem `openssl` não quebra ao tentar
assinar, apenas cai para `SigningMode::REGISTRO_ELETRONICO`.

## 6. Chave do cofre — por que não é como a de licenciamento

`CertificateSecretVault` não reaproveita a convenção do licenciamento
(ADR-010, chave embutida no build do plugin) porque a propriedade exigida é
o oposto: a chave pública de licenciamento é a mesma em todo plugin da casa,
de propósito, e não é segredo; a chave que cifra a senha do certificado
**precisa ser secreta e própria de cada site** — se ela viesse embutida no
pacote, qualquer pessoa que baixasse o plugin teria a chave que decifra a
senha de certificado de **qualquer** cliente que o rodasse. Por isso não há
default de produção, placeholder nem fallback: sem chave configurada e
utilizável, o cofre recusa operar
(`CertificateVaultException::CHAVE_DE_CIFRAGEM_INDISPONIVEL`) em vez de
gravar em texto claro. Detalhe completo em `docs/ARCHITECTURE.md`, ADR-015;
o que o plugin hospedeiro precisa configurar está em
`docs/integracao-em-plugin.md` §7.4.

Perder essa chave é degradação **recuperável**, não perda de dados: os
documentos já emitidos continuam abrindo e o código de autenticidade
continua conferindo, porque nenhum dos dois depende dela — só o certificado
precisa ser recadastrado.
