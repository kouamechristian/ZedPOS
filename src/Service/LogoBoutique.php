<?php

namespace App\Service;

/**
 * Stockage du logo de l'établissement.
 *
 * Le **nom du fichier** est rangé dans la table `parametre`, sous la clé
 * {@see \App\Enum\CleParametre::LOGO} — comme les autres informations imprimées
 * sur le ticket. Le fichier lui-même vit sur le disque, servi en statique : rien
 * n'est stocké en base à part un nom, et déplacer le répertoire n'oblige donc pas
 * à réécrire la table.
 *
 * Répertoire distinct de celui des articles (`/uploads/boutique/`) : le logo n'est
 * pas une photo de touche, et le mélanger aux quarante images du catalogue rendrait
 * une sauvegarde ou un nettoyage impossibles à trier.
 */
class LogoBoutique extends StockageImages
{
    /**
     * Côté maximal, en pixels.
     *
     * Plus généreux que pour une touche produit (400 px) : le logo s'imprime sur
     * toute la largeur utile du papier thermique, soit 384 points à 203 dpi sur
     * 58 mm. La borne reste à 600 px, au-dessus du strict nécessaire : le logo
     * est aussi affiché à l'écran, et une réduction ne se rattrape pas — le
     * fichier d'origine n'est pas conservé. Trop petit, il ressortirait crénelé
     * sur le seul support sans seconde chance, le papier étant déjà sorti.
     */
    private const COTE_MAX = 600;

    public function __construct(string $repertoire)
    {
        parent::__construct($repertoire, '/uploads/boutique/', self::COTE_MAX);
    }
}
