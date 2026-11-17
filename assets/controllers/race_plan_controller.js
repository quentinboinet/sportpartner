import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/*
 * Plan de course (templates/race/plan.html.twig), ce qui n'est ni la carte (race-route), ni les temps de passage
 * (race-splits), ni la nutrition (race-nutrition), ni la sous-navigation (race-subnav). Remplace l'ancien script en
 * ligne de la page et ses attributs onsubmit (page compatible avec une Content-Security-Policy sans 'unsafe-inline').
 *
 *  - import du GPX (formulaires de la page vide et de la modale « Remplacer ») : fichier choisi ou glissé-déposé dans la
 *    zone [data-gpx-dropzone], extension .gpx / .xml et 15 Mo au plus vérifiés avant l'envoi (bouton désactivé sinon),
 *    retour visuel pendant l'envoi (bouton désactivé + « Import en cours… ») ; un fichier lâché hors de la zone n'ouvre
 *    pas le GPX dans l'onglet ;
 *  - fenêtre « Ajouter / Modifier un repère » (#checkpointModal, données en data-* de la modale) : remplie à
 *    l'ouverture par un bouton (data-checkpoint-id = modification, sinon ajout), par un clic sur le profil ou le tracé
 *    (race-route:pick, km prérempli) ou sur un repère (race-route:select) ; arrêt prévu réservé aux ravitaillements ;
 *    distance au-delà de l'arrivée refusée (setCustomValidity) ; « Pointer sur le tracé » masque la fenêtre, passe la
 *    carte en mode visée (race-route:start-picking) puis la rouvre avec le km choisi (race-route:picked) ou inchangé
 *    (race-route:pick-cancel) ;
 *  - confirmations des suppressions (formulaires data-confirm, action submit->race-plan#confirm).
 * Textes insérés par textContent / value uniquement.
 */

const MAX_BYTES = 15 * 1024 * 1024;
const ROUTE_TOLERANCE_KM = 0.05; // le serveur accepte 50 m au-delà de l'arrivée (saisie arrondie) puis borne

export default class extends Controller {
    static targets = ['cpModal'];

    connect() {
        // Un fichier lâché hors de la zone ne doit pas ouvrir le GPX dans l'onglet
        this.onWindowDrop = (e) => {
            if (!e.target.closest || !e.target.closest('[data-gpx-dropzone]')) e.preventDefault();
        };
        window.addEventListener('dragover', this.onWindowDrop);
        window.addEventListener('drop', this.onWindowDrop);

        if (this.hasCpModalTarget) this.setupCheckpointModal();
    }

    disconnect() {
        window.removeEventListener('dragover', this.onWindowDrop);
        window.removeEventListener('drop', this.onWindowDrop);
        if (this.modalEl) {
            this.modalEl.removeEventListener('show.bs.modal', this.onModalShow);
            this.modalEl.removeEventListener('shown.bs.modal', this.onModalShown);
        }
        document.removeEventListener('race-route:pick', this.onPick);
        document.removeEventListener('race-route:picked', this.onPicked);
        document.removeEventListener('race-route:pick-cancel', this.onPickCancel);
        document.removeEventListener('race-route:select', this.onSelect);
    }

    // ── Confirmations ───────────────────────────────────────────────────────

    /** Formulaire de suppression : envoyé seulement après confirmation (texte dans data-confirm). */
    confirm(event) {
        const message = event.currentTarget.dataset.confirm;
        if (message && !window.confirm(message)) event.preventDefault();
    }

    // ── Import du GPX ───────────────────────────────────────────────────────

    /** Fichier choisi (ou déposé) : nom affiché, contrôles côté navigateur, bouton d'import activé si tout va bien. */
    fileChosen(event) {
        const zone = event.target.closest('[data-gpx-dropzone]');
        if (zone) this.checkFile(zone);
    }

    checkFile(zone) {
        const form = zone.closest('form');
        const input = zone.querySelector('input[type="file"]');
        const nameEl = zone.querySelector('[data-gpx-filename]');
        const errEl = form.querySelector('[data-gpx-error]');
        const submit = form.querySelector('button[type="submit"]');
        const file = input.files && input.files[0];
        let error = '';
        if (file && !/\.(gpx|xml)$/i.test(file.name)) error = errEl.dataset.badExt;
        else if (file && file.size > MAX_BYTES) error = errEl.dataset.tooLarge;
        nameEl.textContent = file ? file.name : '';
        nameEl.hidden = !file;
        errEl.textContent = error;
        errEl.hidden = !error;
        zone.classList.toggle('rp-dropzone--ready', !!file && !error);
        submit.disabled = !file || !!error;
    }

    dragOver(event) {
        event.preventDefault();
        event.currentTarget.classList.add('rp-dropzone--drag');
    }

    dragLeave(event) {
        event.preventDefault();
        event.currentTarget.classList.remove('rp-dropzone--drag');
    }

    drop(event) {
        event.preventDefault();
        const zone = event.currentTarget;
        zone.classList.remove('rp-dropzone--drag');
        if (!event.dataTransfer || !event.dataTransfer.files.length) return;
        zone.querySelector('input[type="file"]').files = event.dataTransfer.files;
        this.checkFile(zone);
    }

    /** Envoi d'un import (fichier ou lien) : bouton désactivé avec un indicateur, le serveur peut prendre du temps. */
    importing(event) {
        const submit = event.currentTarget.querySelector('button[type="submit"]');
        if (!submit) return;
        submit.disabled = true;
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm';
        spinner.setAttribute('aria-hidden', 'true');
        submit.replaceChildren(spinner, document.createTextNode(` ${submit.dataset.importing ?? ''}`));
    }

    // ── Fenêtre des repères ─────────────────────────────────────────────────

    setupCheckpointModal() {
        const modalEl = this.cpModalTarget;
        this.modalEl = modalEl;
        this.cpForm = modalEl.querySelector('#checkpointForm');
        this.delForm = document.getElementById('checkpointDeleteForm');
        this.delBtn = modalEl.querySelector('#checkpointDelete');
        this.stopWrap = modalEl.querySelector('#cp-stop-wrap');
        this.options = Array.from(modalEl.querySelectorAll('.sp-intent-option'));
        this.distIn = this.field('distance_km');
        this.maxKm = parseFloat(modalEl.dataset.maxKm);
        let checkpoints = [];
        try {
            checkpoints = JSON.parse(modalEl.dataset.checkpoints || '[]');
        } catch {
            checkpoints = [];
        }
        this.byId = new Map(checkpoints.map(c => [String(c.id), c]));
        this.modal = Modal.getOrCreateInstance(modalEl);
        this.resumeFocus = null;

        // Ouverture via un bouton : data-checkpoint-id = modification, sinon ajout
        // (sans relatedTarget = ouverture depuis la carte / le profil, déjà remplie par fill())
        this.onModalShow = (e) => {
            if (!e.relatedTarget) return;
            const id = e.relatedTarget.dataset.checkpointId;
            this.fill(id ? (this.byId.get(id) ?? null) : null);
        };
        this.onModalShown = () => {
            (this.resumeFocus ?? this.field('name')).focus();
            this.resumeFocus = null;
        };
        modalEl.addEventListener('show.bs.modal', this.onModalShow);
        modalEl.addEventListener('shown.bs.modal', this.onModalShown);

        // Clic sur le profil ou près du tracé : nouveau repère prérempli avec le km
        this.onPick = (e) => {
            this.fill(null, e.detail.distanceKm);
            this.modal.show();
        };
        // Mode visée terminé : la fenêtre revient avec le km choisi (sans relatedTarget : saisie conservée)
        this.onPicked = (e) => {
            this.distIn.value = this.fmtKm(e.detail.distanceKm);
            this.checkDistance();
            this.resumeFocus = this.distIn;
            this.modal.show();
        };
        this.onPickCancel = () => {
            this.resumeFocus = this.distIn;
            this.modal.show();
        };
        // Clic sur un repère (carte ou bulle du profil) : modification
        this.onSelect = (e) => {
            const cp = this.byId.get(String(e.detail.id));
            if (!cp) return;
            this.fill(cp);
            this.modal.show();
        };
        document.addEventListener('race-route:pick', this.onPick);
        document.addEventListener('race-route:picked', this.onPicked);
        document.addEventListener('race-route:pick-cancel', this.onPickCancel);
        document.addEventListener('race-route:select', this.onSelect);
    }

    field(name) {
        return this.cpForm.elements.namedItem(name);
    }

    // Km au dixième (clic sur le profil) ; au mètre près en édition pour ne pas déplacer le repère
    fmtKm(km, precise = false) {
        const text = precise ? String(Math.round(km * 1000) / 1000) : (Math.round(km * 10) / 10).toFixed(1);
        return text.replace('.', this.modalEl.dataset.decimal);
    }

    /** Style des cartes de type + champ « arrêt prévu » réservé aux ravitaillements. */
    syncType() {
        if (!this.options) return;
        let aid = false;
        this.options.forEach((opt) => {
            const input = opt.querySelector('input');
            opt.classList.toggle('sp-intent-option--active', input.checked);
            if (input.checked && input.value === 'aid_station') aid = true;
        });
        this.stopWrap.hidden = !aid;
        this.field('stop_minutes').disabled = !aid;
    }

    /** Distance au-delà de l'arrivée : refusée par le navigateur (message traduit). */
    checkDistance() {
        if (!this.distIn) return;
        const v = parseFloat(this.distIn.value.replace(',', '.'));
        this.distIn.setCustomValidity(Number.isFinite(v) && v > this.maxKm + ROUTE_TOLERANCE_KM + 1e-9 ? this.modalEl.dataset.msgTooFar : '');
    }

    fill(cp, km = null) {
        const ds = this.modalEl.dataset;
        const editing = !!cp;
        this.cpForm.action = editing ? ds.editUrl.replace(ds.placeholder, cp.id) : ds.addUrl;
        if (this.delForm) this.delForm.action = editing ? ds.deleteUrl.replace(ds.placeholder, cp.id) : '';
        this.delBtn.hidden = !editing;
        this.modalEl.querySelector('.modal-title').textContent = editing ? ds.titleEdit : ds.titleAdd;
        this.modalEl.querySelector('#checkpointSubmit').textContent = editing ? ds.submitEdit : ds.submitAdd;

        const c = cp || {};
        this.field('name').value = c.name ?? '';
        this.field('distance_km').value = editing ? this.fmtKm(c.distanceM / 1000, true) : (km !== null ? this.fmtKm(km) : '');
        this.field('stop_minutes').value = c.stopMinutes ?? '';
        this.field('cutoff').value = c.cutoff ?? '';
        this.field('notes').value = c.notes ?? '';
        this.field('public_message').value = c.publicMessage ?? '';
        this.modalEl.querySelector('#cp-assistance').checked = c.assistance === true;

        const type = c.type ?? this.options[0].querySelector('input').value;
        this.options.forEach((opt) => { const i = opt.querySelector('input'); i.checked = i.value === type; });
        this.syncType();
        this.checkDistance();
    }

    /**
     * « Pointer sur le tracé » : la fenêtre s'efface (saisie conservée), la carte passe en mode visée, puis la fenêtre
     * revient avec le km choisi (ou inchangé si annulé).
     */
    pickOnTrack() {
        this.modalEl.addEventListener('hidden.bs.modal', () => {
            document.dispatchEvent(new CustomEvent('race-route:start-picking'));
        }, { once: true });
        this.modal.hide();
    }
}
