<?php

namespace App\Tests\Unit;

use App\Repository\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le calcul de pagination, isolé de la base.
 *
 * Les cas qui cassent en production ne sont pas la page 2 sur 10 : ce sont la
 * liste vide, la dernière page incomplète et le numéro de page hors bornes —
 * ceux qu'on ne rencontre pas en développant avec un jeu de données confortable.
 */
class PaginationTest extends TestCase
{
    public function testUneListeVideCompteUneSeulePage(): void
    {
        $page = new Pagination([], 0, 1, 25);

        $this->assertSame(1, $page->pages, 'Zéro page ferait un « page 1 / 0 » absurde.');
        $this->assertTrue($page->estVide());
        $this->assertFalse($page->estPaginee());
        $this->assertFalse($page->aPrecedente());
        $this->assertFalse($page->aSuivante());
        $this->assertSame(0, $page->debut());
        $this->assertSame(0, $page->fin());
    }

    #[DataProvider('decoupages')]
    public function testNombreDePages(int $total, int $parPage, int $attendu): void
    {
        $this->assertSame($attendu, (new Pagination([], $total, 1, $parPage))->pages);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function decoupages(): iterable
    {
        yield 'pile une page' => [25, 25, 1];
        yield 'un de trop' => [26, 25, 2];
        yield 'un de moins' => [24, 25, 1];
        yield 'plusieurs pages pleines' => [100, 25, 4];
        yield 'dernière page incomplète' => [101, 25, 5];
        yield 'un seul élément' => [1, 25, 1];
    }

    public function testBornesAffichees(): void
    {
        // Page 3 de 25, dernière page : 7 éléments sur 57.
        $page = new Pagination(array_fill(0, 7, 'x'), 57, 3, 25);

        $this->assertSame(51, $page->debut());
        $this->assertSame(57, $page->fin());
        $this->assertTrue($page->aPrecedente());
        $this->assertFalse($page->aSuivante(), 'La page 3 sur 3 n\'a pas de suivante.');
    }

    public function testUnePageNegativeOuNulleRamèneALaPremiere(): void
    {
        $this->assertSame(1, Pagination::surTableau(range(1, 50), 0)->page);
        $this->assertSame(1, Pagination::surTableau(range(1, 50), -5)->page);
    }

    /**
     * Lien périmé, élément supprimé entre deux clics : la page demandée n'existe
     * plus. On rend une liste vide plutôt qu'une erreur — un écran de gestion ne
     * s'immobilise pas pour un numéro de page.
     */
    public function testUnePageAuDelaDeLaFinRendUneListeVide(): void
    {
        $page = Pagination::surTableau(range(1, 10), 99, 25);

        $this->assertSame([], $page->items);
        $this->assertSame(10, $page->total);
        $this->assertSame(1, $page->pages);
    }

    public function testDecoupageEnMemoire(): void
    {
        $page = Pagination::surTableau(range(1, 10), 2, 4);

        $this->assertSame([5, 6, 7, 8], array_values($page->items));
        $this->assertSame(10, $page->total);
        $this->assertSame(3, $page->pages);
        $this->assertSame(5, $page->debut());
        $this->assertSame(8, $page->fin());
    }

    /**
     * La fenêtre garde une largeur constante, y compris aux extrémités : la barre
     * ne change pas de taille en cours de navigation, et les numéros ne se
     * déplacent pas sous le curseur au moment du clic.
     */
    public function testFenetreDeLargeurConstante(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], (new Pagination([], 500, 1, 25))->fenetre());
        $this->assertSame([1, 2, 3, 4, 5], (new Pagination([], 500, 2, 25))->fenetre());
        $this->assertSame([3, 4, 5, 6, 7], (new Pagination([], 500, 5, 25))->fenetre());
        $this->assertSame([16, 17, 18, 19, 20], (new Pagination([], 500, 20, 25))->fenetre());
    }

    public function testFenetrePlusPetiteQueLaLargeurDemandee(): void
    {
        // Trois pages seulement : on ne peut pas en proposer cinq.
        $this->assertSame([1, 2, 3], (new Pagination([], 60, 2, 25))->fenetre());
    }
}
