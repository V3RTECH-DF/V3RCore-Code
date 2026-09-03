/**
 * Espelho JS do sugeridor de domínio de e-mail, exercitado pelo MESMO
 * conjunto de casos que o núcleo PHP (src/Assets/data/email-suggestion-cases.json).
 *
 * É este arquivo, junto do EmailSuggestionTest.php, que impede navegador e
 * servidor de descolarem: uma correção aplicada em só uma das metades
 * quebra o outro lado no mesmo commit.
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const suggester = require('../../src/Assets/js/email-suggestion.js');
const spec = JSON.parse(
    fs.readFileSync(path.join(__dirname, '../../src/Assets/data/email-suggestion-cases.json'), 'utf8')
);

test('todos os casos compartilhados', async (t) => {
    for (const caso of spec.casos) {
        await t.test(`${caso.grupo}: ${caso.nome}`, () => {
            const domains = Object.prototype.hasOwnProperty.call(caso, 'dominios')
                ? caso.dominios
                : spec.dominiosPadrao;

            assert.strictEqual(suggester.suggest(caso.email, domains), caso.esperado);
        });
    }
});

test('lista de domínios ausente não quebra a sugestão', () => {
    assert.strictEqual(suggester.suggest('fulano@gmail.con'), null);
    assert.strictEqual(suggester.suggest('fulano@gmail.con', null), null);
});

test('entrada não-string não quebra', () => {
    assert.strictEqual(suggester.suggest(null, spec.dominiosPadrao), null);
    assert.strictEqual(suggester.suggest(undefined, spec.dominiosPadrao), null);
});

test('rótulo curto admite uma edição, não duas', () => {
    assert.strictEqual(suggester.suggest('fulano@uol.com.hr', ['uol.com.br']), 'fulano@uol.com.br');
    assert.strictEqual(suggester.suggest('fulano@dob.com.br', ['uol.com.br']), null);
});

test('rótulo longo admite duas edições', () => {
    assert.strictEqual(suggester.suggest('fulano@glomobail.com', ['globomail.com']), 'fulano@globomail.com');
});

test('levenshtein exposto confere com casos conhecidos', () => {
    assert.strictEqual(suggester.levenshtein('gmail.com', 'gmail.com'), 0);
    assert.strictEqual(suggester.levenshtein('gmail.con', 'gmail.com'), 1);
    assert.strictEqual(suggester.levenshtein('gmial.com', 'gmail.com'), 2);
    assert.strictEqual(suggester.levenshtein('', 'abc'), 3);
    assert.strictEqual(suggester.levenshtein('abc', ''), 3);
});
