<?php
namespace App\Entity;

enum RaceIntent: string
{
    case WantToDo = 'want_to_do';
    case Registered = 'registered';
    case Bookmark = 'bookmark';

    /** Clé de traduction du libellé (races.intent_*). */
    public function labelKey(): string
    {
        return 'races.intent_'.$this->value;
    }

    /** Clé de traduction de la description affichée dans la modale. */
    public function descriptionKey(): string
    {
        return 'races.intent_'.$this->value.'_desc';
    }

    /** Classe Bootstrap Icons. */
    public function icon(): string
    {
        return match ($this) {
            self::WantToDo => 'bi-flag-fill',
            self::Registered => 'bi-patch-check-fill',
            self::Bookmark => 'bi-bookmark-fill',
        };
    }

    /** Variable CSS de couleur (définie dans assets/styles/app.css). */
    public function color(): string
    {
        return match ($this) {
            self::WantToDo => 'var(--sp-orange)',
            self::Registered => 'var(--sp-green)',
            self::Bookmark => 'var(--sp-teal)',
        };
    }
}
