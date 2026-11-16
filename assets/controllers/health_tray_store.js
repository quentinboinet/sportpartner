/*
 * Plateau « séance en cours » de l'espace Santé : brouillon gardé dans le navigateur (localStorage), une clé par
 * utilisateur (sp-health-tray:<id>), pour qu'un rechargement ne perde rien. Pourquoi pas le serveur : le plateau
 * est un brouillon éphémère, modifié à chaque frappe ; il est instantané, marche hors ligne pendant la séance et
 * n'écrit rien en base. Limite connue : il n'est pas partagé entre appareils (documenté).
 *
 * Contenu : liste d'éléments {id, sets, value, rest} (id d'exercice et dosage). Les noms et consignes viennent du
 * catalogue de la page de séance. Tout accès au stockage est protégé (navigation privée, stockage bloqué) : en cas
 * d'échec, le plateau reste en mémoire pour la page en cours.
 * Chaque modification émet l'événement « health-tray:change » sur window (detail : {items}).
 */

const VERSION = 1;
const MAX_ITEMS = 30;
const memory = new Map();

function key(user) {
    return `sp-health-tray:${user}`;
}

function isItem(item) {
    return item !== null && typeof item === 'object'
        && ['id', 'sets', 'value', 'rest'].every((k) => Number.isInteger(item[k]) && item[k] >= 0)
        && item.id > 0;
}

export function loadTray(user) {
    let raw = null;
    try {
        raw = window.localStorage.getItem(key(user));
    } catch (e) {
        raw = null;
    }
    if (raw === null) {
        return (memory.get(key(user)) || []).map((item) => ({ ...item }));
    }
    try {
        const data = JSON.parse(raw);
        if (!data || data.v !== VERSION || !Array.isArray(data.items)) return [];
        return data.items.filter(isItem).slice(0, MAX_ITEMS).map(({ id, sets, value, rest }) => ({ id, sets, value, rest }));
    } catch (e) {
        return [];
    }
}

export function saveTray(user, items) {
    const clean = items.filter(isItem).slice(0, MAX_ITEMS).map(({ id, sets, value, rest }) => ({ id, sets, value, rest }));
    memory.set(key(user), clean);
    try {
        if (clean.length === 0) {
            window.localStorage.removeItem(key(user));
        } else {
            window.localStorage.setItem(key(user), JSON.stringify({ v: VERSION, items: clean, savedAt: Date.now() }));
        }
    } catch (e) {
        // Stockage indisponible : le plateau reste en mémoire pour cette page
    }
    window.dispatchEvent(new CustomEvent('health-tray:change', { detail: { items: clean } }));
    return clean;
}

/** Ajoute un exercice en fin de plateau ; renvoie false si le plateau est plein. */
export function addToTray(user, item) {
    const items = loadTray(user);
    if (items.length >= MAX_ITEMS) return false;
    items.push(item);
    saveTray(user, items);
    return true;
}

export function clearTray(user) {
    saveTray(user, []);
}
