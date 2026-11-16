import { Controller } from '@hotwired/stimulus';

/*
 * Paramètres > Profil > Objectifs par sport : ajout et retrait des lignes du CollectionType
 * "weeklyGoals". Une ligne ajoutée est copiée depuis le gabarit <template> rendu par Twig ("__name__"
 * remplacé dans les attributs par "new<n>"), sans innerHTML : nom et icône du sport passent par
 * textContent / className. Le menu "Ajouter un sport" ne propose que les sports sans objectif.
 * Rien n'est enregistré ici : tout part avec le bouton Enregistrer du formulaire.
 */
export default class extends Controller {
    static targets = ['list', 'row', 'template', 'option', 'addButton', 'allUsed', 'empty', 'status'];

    static values = {
        removeLabel: String, // "Retirer l'objectif %sport%"
        added: String,       // annonce lecteur d'écran, avec %sport%
        removed: String,
    };

    connect() {
        // Nom de la prochaine ligne : au-delà des lignes "new<n>" renvoyées après une erreur de saisie
        this.counter = 0;
        this.rowTargets.forEach((row) => {
            const match = /^new(\d+)$/.exec(row.dataset.name || '');
            if (match) {
                this.counter = Math.max(this.counter, Number(match[1]) + 1);
            }
        });
        this.sync();

        // Page renvoyée après une erreur de saisie : amener la première ligne en erreur à l'écran
        const invalid = this.element.querySelector('.sp-goal-row.has-error, .sp-goal-input.is-invalid');
        if (invalid) {
            const row = invalid.closest('[data-weekly-goals-target="row"]');
            row.scrollIntoView({ block: 'center' });
            (row.querySelector('.sp-goal-input.is-invalid') ?? row.querySelector('input.sp-goal-input'))?.focus({ preventScroll: true });
        }
    }

    add(event) {
        const { sport, name, icon, color } = event.params;
        const sportId = String(sport ?? '');
        if (sportId === '' || this.usedSportIds().has(sportId)) {
            return;
        }

        const key = `new${this.counter++}`;
        const fragment = this.templateTarget.content.cloneNode(true);
        fragment.querySelectorAll('*').forEach((el) => {
            for (const attr of [...el.attributes]) {
                if (attr.value.includes('__name__')) {
                    el.setAttribute(attr.name, attr.value.replaceAll('__name__', key));
                }
            }
        });

        const row = fragment.querySelector('[data-weekly-goals-target="row"]');
        this.sportInput(row).value = sportId;
        row.querySelector('[data-role="name"]').textContent = name;
        row.querySelector('[data-role="icon"]').className = `bi ${icon}`;
        row.querySelector('[data-role="icon-wrap"]').style.setProperty('--goal-sport-color', color);
        const removeButton = row.querySelector('[data-action~="weekly-goals#remove"]');
        const removeLabel = this.removeLabelValue.replace('%sport%', name);
        removeButton.setAttribute('aria-label', removeLabel);
        removeButton.setAttribute('title', removeLabel);

        this.listTarget.append(row);
        this.sync();
        row.querySelector('input:not([type="hidden"])')?.focus();
        this.announce(this.addedValue.replace('%sport%', name));
    }

    remove(event) {
        const row = event.currentTarget.closest('[data-weekly-goals-target="row"]');
        if (!row) {
            return;
        }
        const name = row.querySelector('[data-role="name"]')?.textContent.trim() ?? '';
        row.remove();
        this.sync();
        this.addButtonTarget.focus();
        this.announce(this.removedValue.replace('%sport%', name));
    }

    sportInput(row) {
        return row.querySelector('input[type="hidden"][name$="[sport]"]');
    }

    usedSportIds() {
        return new Set(this.rowTargets.map((row) => this.sportInput(row)?.value).filter(Boolean));
    }

    sync() {
        const used = this.usedSportIds();
        let available = 0;
        this.optionTargets.forEach((option) => {
            option.hidden = used.has(option.dataset.sportId);
            if (!option.hidden) {
                available++;
            }
        });
        this.addButtonTarget.disabled = available === 0;
        this.allUsedTarget.hidden = available > 0;
        this.emptyTarget.hidden = this.rowTargets.length > 0;
    }

    announce(text) {
        this.statusTarget.textContent = '';
        window.setTimeout(() => { this.statusTarget.textContent = text; }, 50);
    }
}
