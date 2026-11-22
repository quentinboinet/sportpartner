import 'bootstrap';
// Le CSS de Bootstrap est lié par <link> dans base.html.twig (pas importé ici : AssetMapper en ferait un module
// data:, que la CSP script-src 'self' + nonce refuserait)
import './controllers/index.js';

// Confirmation avant l'envoi d'un formulaire sensible (suppressions) : <form data-confirm="Question ?">.
// Un seul écouteur délégué, en phase de capture : il passe avant les actions Stimulus et les écouteurs du formulaire.
// Refus : envoi annulé et propagation arrêtée. Remplace les onsubmit="return confirm(...)", bloqués par la CSP (une
// suppression partirait alors sans confirmation). Un controller ne redemande jamais la question de data-confirm.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const message = form.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
        event.stopPropagation();
    }
}, true);
