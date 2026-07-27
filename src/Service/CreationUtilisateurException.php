<?php

namespace App\Service;

/**
 * Erreur métier à la création d'un compte : e-mail déjà pris, code PIN en doublon,
 * secret trop faible. Le message est destiné à être montré tel quel à l'utilisateur.
 */
class CreationUtilisateurException extends \RuntimeException
{
}
