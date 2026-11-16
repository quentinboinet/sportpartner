import { Controller } from '@hotwired/stimulus';

/*
 * Réglages du lien de partage public d'un plan de course (race/share.html.twig, /courses/{id}/partage) :
 *  - "Copier le lien" : Clipboard API, sinon sélection du champ + execCommand('copy'), sinon le lien reste
 *    sélectionné avec une invitation à le copier à la main ; résultat annoncé (cible copyStatus, role="status") ;
 *  - compteurs de caractères des messages (la limite maxlength est revérifiée par le serveur) ;
 *  - heure prévue de chaque point selon le scénario choisi dans la liste (valeur times : clé du point
 *    "start" | "finish" | "cp<id>" -> id du scénario -> libellé, null = pas d'horaire) ;
 *  - modifications non enregistrées : indicateur, confirmation avant de quitter la page et avant les actions du lien
 *    (activer, désactiver, régénérer : la page est rechargée) ; régénérer demande toujours confirmation.
 * Textes insérés via textContent uniquement.
 */
export default class extends Controller {
    static targets = ['url', 'copyStatus', 'field', 'counter', 'time', 'scenario', 'dirty'];

    static values = {
        times: Object,
        labels: Object, // { copied, copyManual, noTime, unsaved }
    };

    connect() {
        this.dirty = false;
        this.submitting = false;
        this.fieldTargets.forEach((field) => this.count(field));
        this.syncTimes();
        this.onBeforeUnload = (event) => {
            if (this.dirty && !this.submitting) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', this.onBeforeUnload);
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.onBeforeUnload);
    }

    async copy() {
        const input = this.urlTarget;
        let copied = false;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(input.value);
                copied = true;
            }
        } catch (e) {
            copied = false;
        }
        if (!copied) {
            input.focus();
            input.select();
            try {
                copied = document.execCommand('copy');
            } catch (e) {
                copied = false;
            }
        }
        this.copyStatusTarget.textContent = copied ? this.labelsValue.copied : this.labelsValue.copyManual;
        this.copyStatusTarget.classList.toggle('is-ok', copied);
        clearTimeout(this.copyTimer);
        if (copied) {
            this.copyTimer = setTimeout(() => { this.copyStatusTarget.textContent = ''; }, 4000);
        }
    }

    selectUrl() {
        this.urlTarget.select();
    }

    // Saisie dans le formulaire des réglages
    changed(event) {
        if (event.target.matches('[data-race-share-target~="field"]')) {
            this.count(event.target);
        }
        if (event.target.matches('[data-race-share-target~="scenario"]')) {
            this.syncTimes();
        }
        if (!this.dirty) {
            this.dirty = true;
            this.dirtyTargets.forEach((el) => { el.hidden = false; });
        }
    }

    submitted() {
        this.submitting = true;
    }

    // Formulaires des actions du lien : confirmation (régénérer) et avertissement si la saisie n'est pas enregistrée
    guard(event) {
        const messages = [];
        const confirmText = event.params.confirm;
        if (confirmText) {
            messages.push(confirmText);
        }
        if (this.dirty) {
            messages.push(this.labelsValue.unsaved);
        }
        if (messages.length && !window.confirm(messages.join('\n\n'))) {
            event.preventDefault();
            return;
        }
        this.submitting = true;
    }

    count(field) {
        const counter = this.counterTargets.find((el) => el.dataset.for === field.id);
        if (!counter) {
            return;
        }
        const max = Number(field.getAttribute('maxlength')) || 0;
        const length = field.value.length;
        counter.textContent = `${length}/${max}`;
        counter.classList.toggle('is-near', max > 0 && length >= max * 0.9);
    }

    syncTimes() {
        if (!this.hasScenarioTarget) {
            return;
        }
        const scenarioId = this.scenarioTarget.value;
        this.timeTargets.forEach((el) => {
            const label = (this.timesValue[el.dataset.point] || {})[scenarioId];
            el.textContent = label ?? this.labelsValue.noTime;
            el.classList.toggle('is-empty', label === null || label === undefined);
        });
    }
}
