/**
 * v3r-core — sugestão de correção de domínio de e-mail (V3RCore-Code#23).
 *
 * Espelho no navegador de `V3R\Core\Support\EmailSuggestion::suggest()` —
 * mesmo algoritmo, mesmo critério. Esta metade é a que tem valor para quem
 * preenche o formulário: a sugestão aparece enquanto a pessoa digita, e não
 * depois do envio.
 *
 * NÚCLEO PURO: não toca DOM, não depende de jQuery nem do WordPress. A cola
 * com o formulário (mostrar a sugestão, aplicar no clique) é de cada
 * plugin hospedeiro; a lista de domínios reconhecidos chega pronta do
 * servidor (ver o catálogo em docs/sugestao-de-dominio-de-email.md).
 *
 * NUNCA bloqueia: `suggest()` devolve uma sugestão ou null, e nada mais.
 *
 * As duas metades são exercitadas pelo MESMO conjunto de casos
 * (`../data/email-suggestion-cases.json`) — é o que impede navegador e
 * servidor de descolarem.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.V3RCoreEmailSuggestion = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // Distância de edição (Levenshtein), sem dependência externa.
    function levenshtein(a, b) {
        var m = a.length;
        var n = b.length;
        if (m === 0) return n;
        if (n === 0) return m;

        var prev = new Array(n + 1);
        var curr = new Array(n + 1);
        var i, j;

        for (j = 0; j <= n; j++) prev[j] = j;

        for (i = 1; i <= m; i++) {
            curr[0] = i;
            for (j = 1; j <= n; j++) {
                var cost = a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1;
                curr[j] = Math.min(
                    prev[j] + 1,        // deleção
                    curr[j - 1] + 1,    // inserção
                    prev[j - 1] + cost  // substituição
                );
            }
            var tmp = prev; prev = curr; curr = tmp;
        }

        return prev[n];
    }

    // Mesma calibração do servidor: rótulo (parte antes do 1º ponto) de até
    // 4 caracteres admite 1 edição; mais longo admite 2.
    function thresholdFor(known) {
        var label = known.split('.')[0] || '';
        return label.length <= 4 ? 1 : 2;
    }

    /**
     * @param {string} email
     * @param {string[]} knownDomains Lista já resolvida (vinda do servidor).
     * @returns {string|null} Sugestão, ou null quando não há nada a sugerir.
     */
    function suggest(email, knownDomains) {
        email = (email === null || email === undefined ? '' : String(email)).trim();

        var at = email.lastIndexOf('@');
        if (at === -1) return null;

        var local = email.substring(0, at);
        var domain = email.substring(at + 1).trim().toLowerCase();
        if (!local || !domain || domain.indexOf('.') === -1) return null;

        var list = knownDomains || [];
        var best = null;
        var bestDistance = null;

        for (var i = 0; i < list.length; i++) {
            var known = (list[i] === null || list[i] === undefined ? '' : String(list[i])).trim().toLowerCase();
            if (!known) continue;

            // Já é exatamente um domínio conhecido — nada a sugerir, por
            // mais perto que esteja de outro da lista.
            if (domain === known) return null;

            var distance = levenshtein(domain, known);
            if (distance > 0 && distance <= thresholdFor(known) && (bestDistance === null || distance < bestDistance)) {
                best = known;
                bestDistance = distance;
            }
        }

        return best === null ? null : local + '@' + best;
    }

    return {
        levenshtein: levenshtein,
        suggest: suggest
    };
});
