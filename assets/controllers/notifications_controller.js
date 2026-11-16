import { Controller } from '@hotwired/stimulus';

/*
 * Cloche « À faire » de la barre du haut (partial _notifications.html.twig, dropdown Bootstrap).
 *
 * Charge GET /notifications (app_notifications) APRÈS le chargement de la page, puis remplit la liste :
 * une ligne par élément (lien vers la page concernée + bouton « Masquer »), « Tout marquer comme lu »,
 * « Réafficher N éléments masqués », états chargement / vide (« Rien à signaler ») / erreur (« Réessayer »).
 * Le point rouge et le nombre lu par les lecteurs d'écran (aria-label du bouton) ne comptent que les
 * éléments non masqués. Rechargement à l'ouverture si la liste a échoué ou date de plus de 5 minutes.
 *
 * Éléments masqués : clés dans localStorage (sp.notifications.dismissed.<id utilisateur> : chaque compte
 * du navigateur a sa liste, le nettoyage de l'un n'efface pas l'autre). Une clé contient l'état de
 * l'alerte (gear:12:warn puis gear:12:worn) : l'alerte revient quand la situation change. Les clés que le
 * serveur ne renvoie plus sont supprimées à chaque chargement réussi (le stockage ne grossit pas). Sans
 * stockage (désactivé, privé, exception), le masquage vaut pour la page en cours seulement.
 *
 * Sécurité : les textes (noms de course, de matériel, d'activité) ne passent que par textContent /
 * setAttribute ; les liens doivent être des chemins du site, les icônes des classes bi-*.
 */

const STORAGE_KEY = 'sp.notifications.dismissed';
const FETCH_TIMEOUT_MS = 15000;
const STALE_MS = 5 * 60 * 1000;
const SEVERITIES = new Set(['urgent', 'warning', 'info', 'success']);
const ICON_RE = /^bi-[a-z0-9-]+$/;

/** Crée un élément : attributs (class, text, aria-*…) puis enfants (nœuds ou texte brut, jamais du HTML). */
function h(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [name, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) continue;
        if (name === 'class') el.className = value;
        else if (name === 'text') el.textContent = value;
        else el.setAttribute(name, String(value));
    }
    for (const child of children) {
        if (child !== null && child !== undefined) el.append(child);
    }
    return el;
}

/** Chemin du site seulement (« /courses/5 »), jamais une autre origine ni un schéma (javascript:…). */
function safePath(url) {
    return typeof url === 'string' && /^\/(?![/\\])/.test(url) ? url : null;
}

/** Élément du JSON utilisable, normalisé ; null sinon. */
function normalize(item) {
    if (!item || typeof item !== 'object') return null;
    const url = safePath(item.url);
    if (typeof item.key !== 'string' || item.key === '' || typeof item.title !== 'string' || url === null) return null;
    return {
        key: item.key,
        severity: SEVERITIES.has(item.severity) ? item.severity : 'info',
        icon: typeof item.icon === 'string' && ICON_RE.test(item.icon) ? item.icon : 'bi-bell',
        title: item.title,
        subtitle: typeof item.subtitle === 'string' && item.subtitle !== '' ? item.subtitle : null,
        url,
    };
}

function readDismissed(storageKey) {
    try {
        const list = JSON.parse(window.localStorage.getItem(storageKey) || '[]');
        return Array.isArray(list) ? list.filter((key) => typeof key === 'string') : [];
    } catch {
        return [];
    }
}

function writeDismissed(storageKey, keys) {
    try {
        if (keys.size === 0) window.localStorage.removeItem(storageKey);
        else window.localStorage.setItem(storageKey, JSON.stringify([...keys]));
    } catch {
        // Stockage indisponible : le masquage reste valable pour cette page
    }
}

export default class extends Controller {
    static targets = ['button', 'dot', 'status', 'spinner', 'statusText', 'error', 'list', 'readAll', 'foot', 'restore'];

    static values = { url: String, labels: Object, scope: String };

    connect() {
        this.L = this.labelsValue || {};
        this.items = null;
        this.loadedAt = 0;
        this.request = null;
        this.storageKey = `${STORAGE_KEY}.${this.scopeValue}`;
        this.dismissed = new Set(readDismissed(this.storageKey));

        this.onShow = () => {
            if (this.request === null && (this.items === null || Date.now() - this.loadedAt > STALE_MS)) this.load();
        };
        this.element.addEventListener('show.bs.dropdown', this.onShow);
        // Masquage fait dans un autre onglet
        this.onStorage = (event) => {
            if (event.key !== this.storageKey) return;
            this.dismissed = new Set(readDismissed(this.storageKey));
            this.render();
        };
        window.addEventListener('storage', this.onStorage);

        // Jamais pendant le rendu de la page : après l'événement load
        if (document.readyState === 'complete') {
            this.load();
        } else {
            this.onLoad = () => this.load();
            window.addEventListener('load', this.onLoad, { once: true });
        }
    }

    disconnect() {
        this.element.removeEventListener('show.bs.dropdown', this.onShow);
        window.removeEventListener('storage', this.onStorage);
        if (this.onLoad) window.removeEventListener('load', this.onLoad);
        const pending = this.request;
        this.request = null;
        pending?.abort();
    }

    async load() {
        this.request?.abort();
        const request = new AbortController();
        this.request = request;
        const timer = setTimeout(() => request.abort(), FETCH_TIMEOUT_MS);
        if (this.items === null) this.showState('loading');

        let items = null;
        try {
            const res = await fetch(this.urlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: request.signal,
            });
            // Session expirée : redirection vers la page de connexion (HTML) = erreur
            if (res.ok && (res.headers.get('Content-Type') || '').includes('application/json')) {
                const data = await res.json();
                if (data && Array.isArray(data.items)) items = data.items.map(normalize).filter((item) => item !== null);
            }
        } catch {
            // Réseau, délai dépassé ou JSON invalide
        } finally {
            clearTimeout(timer);
        }
        if (this.request !== request) return; // remplacée, ou controller déconnecté
        this.request = null;

        if (items === null) {
            // Un rechargement raté garde la dernière liste affichée
            if (this.items === null) this.showState('error');
            return;
        }
        this.items = items;
        this.loadedAt = Date.now();
        this.prune();
        this.render();
    }

    /** Oublie les clés masquées que le serveur ne renvoie plus. */
    prune() {
        const current = new Set(this.items.map((item) => item.key));
        const kept = new Set([...this.dismissed].filter((key) => current.has(key)));
        if (kept.size !== this.dismissed.size) {
            this.dismissed = kept;
            writeDismissed(this.storageKey, kept);
        }
    }

    showState(state) {
        const error = state === 'error';
        this.spinnerTarget.hidden = error;
        this.statusTarget.hidden = false;
        this.statusTextTarget.textContent = error ? this.L.error : this.L.loading;
        this.errorTarget.hidden = !error;
        this.listTarget.hidden = true;
        this.listTarget.replaceChildren();
        this.readAllTarget.hidden = true;
        this.footTarget.hidden = true;
        this.setCount(0);
    }

    render() {
        if (this.items === null) return;
        const visible = this.items.filter((item) => !this.dismissed.has(item.key));
        const hidden = this.items.length - visible.length;

        this.listTarget.replaceChildren(...visible.map((item) => this.renderItem(item)));
        this.listTarget.hidden = visible.length === 0;
        this.errorTarget.hidden = true;
        this.spinnerTarget.hidden = true;
        this.statusTarget.hidden = visible.length > 0;
        this.statusTextTarget.textContent = visible.length > 0 ? '' : this.L.empty;
        this.readAllTarget.hidden = visible.length === 0;
        this.footTarget.hidden = hidden === 0;
        this.restoreTarget.textContent = hidden === 1 ? this.L.restoreOne : this.fill(this.L.restoreMany, '%count%', String(hidden));
        this.setCount(visible.length);
    }

    renderItem(item) {
        const prefix = item.severity === 'urgent' ? this.L.urgent : (item.severity === 'warning' ? this.L.warning : null);
        const link = h('a', { class: 'dropdown-item sp-notif-link', href: item.url },
            h('span', { class: 'sp-notif-icon', 'aria-hidden': 'true' }, h('i', { class: `bi ${item.icon}` })),
            h('span', { class: 'sp-notif-text' },
                prefix ? h('span', { class: 'visually-hidden', text: `${prefix} ` }) : null,
                h('span', { class: 'sp-notif-item-title', text: item.title }),
                item.subtitle !== null ? h('span', { class: 'sp-notif-item-sub', text: item.subtitle }) : null));
        const label = this.fill(this.L.dismiss, '%title%', item.title);
        const dismiss = h('button', {
            type: 'button', class: 'sp-notif-dismiss', 'aria-label': label, title: label, 'data-action': 'notifications#dismiss',
        }, h('i', { class: 'bi bi-x-lg', 'aria-hidden': 'true' }));

        const li = h('li', { class: `sp-notif-item sp-notif-item--${item.severity}` }, link, dismiss);
        li.dataset.key = item.key;
        return li;
    }

    /** Point rouge + nombre pour les lecteurs d'écran : éléments non masqués seulement. */
    setCount(count) {
        this.dotTarget.hidden = count === 0;
        const label = count === 0 ? this.L.button : (count === 1 ? this.L.buttonOne : this.fill(this.L.buttonMany, '%count%', String(count)));
        this.buttonTarget.setAttribute('aria-label', label ?? '');
    }

    dismiss(event) {
        event.preventDefault();
        const li = event.currentTarget.closest('li[data-key]');
        if (!li || this.items === null) return;
        const index = [...this.listTarget.children].indexOf(li);
        this.dismissed.add(li.dataset.key);
        writeDismissed(this.storageKey, this.dismissed);
        this.render();

        // Focus sur l'élément suivant (ou précédent), sinon sur « Réafficher »
        const links = [...this.listTarget.querySelectorAll('.sp-notif-link')];
        (links[Math.min(index, links.length - 1)] ?? this.focusFallback()).focus();
    }

    dismissAll(event) {
        event?.preventDefault();
        if (this.items === null) return;
        this.items.forEach((item) => this.dismissed.add(item.key));
        writeDismissed(this.storageKey, this.dismissed);
        this.render();
        this.focusFallback().focus();
    }

    restore(event) {
        event?.preventDefault();
        if (this.items === null) return;
        this.items.forEach((item) => this.dismissed.delete(item.key));
        writeDismissed(this.storageKey, this.dismissed);
        this.render();
        (this.listTarget.querySelector('.sp-notif-link') ?? this.buttonTarget).focus();
    }

    async retry(event) {
        event?.preventDefault();
        await this.load();
        if (!this.errorTarget.hidden) {
            this.errorTarget.querySelector('button')?.focus();
        } else {
            (this.listTarget.querySelector('.sp-notif-link') ?? this.focusFallback()).focus();
        }
    }

    focusFallback() {
        return this.footTarget.hidden ? this.buttonTarget : this.restoreTarget;
    }

    /** Remplace un paramètre sans interpréter « $& » ou « $1 » d'un texte saisi. */
    fill(template, name, value) {
        return String(template ?? '').replace(name, () => value);
    }
}
