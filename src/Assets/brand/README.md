# Ícones de família para o menu do painel

Dois arquivos, um por família. O plugin declara a qual família pertence; o ícone
do produto individual **não** é usado no menu — ver a decisão em
`V3RTECH-DF/V3RCore-Code#25` (posição, nome e ícone das entradas no menu).

| Arquivo | Família | Forma |
|---|---|---|
| `familia-v3rtech.svg` | V3RTECH | duplo V (chevron duplo) |
| `familia-rit.svg` | RIT | rosa dos ventos, **sem o anel** |

## Regras

- **`fill="currentColor"`, sem fundo.** O WordPress tinge o ícone sozinho: ele
  acompanha a cor do menu e acende na seção ativa, inclusive em painéis com
  esquema de cor alterado. Cor fixa ou fundo branco viram um quadrado aceso na
  coluna escura.
- **Desenhados para 20px**, que é o tamanho real no menu. Não acrescentar
  detalhe: a rosa dos ventos perdeu o anel justamente porque a 20px ele apertava
  as pontas contra a borda até a figura virar mancha.
- O anel continua na marca completa da RIT — o corte vale só para o ícone do menu.

## ⚠️ Estado da arte

**Reconstrução, não derivação do original.** O duplo V não tem vetor versionado
em nenhum repositório da casa: só existe como imagem de mapa de bits, dentro do
logotipo institucional em
`V3RLicense/Code/plugin/assets/email/v3rtech-logo-completa.png`.

A rosa dos ventos tem vetor oficial em
`RIT/RIT360/Projeto/Brand/Familia/logo/rit360-logo-icone.svg`, e o desenho aqui é
uma simplificação dele para tamanho pequeno.

Aprovados pelo Bruno em 05/09/2026 como forma. Substituir pela arte derivada do
vetor oficial quando ela existir, mantendo as regras acima.
