<?php
namespace App\Entity;

enum RaceIntent: string
{
    case WantToDo = 'want_to_do';
    case Bookmark = 'bookmark';
}
