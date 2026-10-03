<?php

namespace App\Tests\Unit\Magasin;

use App\Entity\Magasin\MagasinProduit;
use App\Enum\Magasin\CategorieProduitMagasin;
use App\Form\Magasin\MillimesTransformer;
use App\Service\Magasin\QuantiteMagasin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Unités d'un produit du magasin : on achète en sacs, on stocke en kilos.
 * Conversions et saisies en arithmétique entière, jamais de flottant.
 */
class ProduitMagasinTest extends TestCase
{
    private function farine(): MagasinProduit
    {
        return (new MagasinProduit('Farine de blé', CategorieProduitMagasin::MATIERE, 'kg'))
            ->setUniteAchat('sac')
            ->setContenanceAchat(50000);
    }

    public function testVingtSacsDeCinquanteKiloFontMilleKilos(): void
    {
        $this->assertSame(1000000, $this->farine()->versUniteStock(20000));
        $this->assertSame(20000, $this->farine()->versUniteAchat(1000000));
    }

    public function testLaQuantiteSAfficheEnSacsEtEnKilos(): void
    {
        $this->assertSame('20 sacs (1 000 kg)', QuantiteMagasin::formater($this->farine(), 1000000));
        $this->assertSame('1 sac (50 kg)', QuantiteMagasin::formater($this->farine(), 50000));
        $this->assertSame('2,5 sacs (125 kg)', QuantiteMagasin::formater($this->farine(), 125000));
        $this->assertSame('−1 sac (−50 kg)', QuantiteMagasin::formater($this->farine(), -50000));
    }

    public function testSansUniteDAchatLaQuantiteResteEnUniteDeStock(): void
    {
        $sel = new MagasinProduit('Sel', CategorieProduitMagasin::MATIERE, 'kg');

        $this->assertFalse($sel->aUneUniteAchat());
        $this->assertNull($sel->versUniteAchat(5000), '« Non défini » n\'est pas « zéro sac ».');
        $this->assertSame('12,5 kg', QuantiteMagasin::formater($sel, 12500));

        $this->expectException(\LogicException::class);
        $sel->versUniteStock(1000);
    }

    public function testUneConversionInexacteSArrondiAuMillieme(): void
    {
        $oeufs = (new MagasinProduit('Œufs', CategorieProduitMagasin::MATIERE, 'pièce'))->setUniteAchat('plaquette')->setContenanceAchat(30000);

        $this->assertSame(333, $oeufs->versUniteAchat(10000), '10 œufs = 0,333 plaquette.');
        $this->assertSame(-333, $oeufs->versUniteAchat(-10000), 'Symétrique pour un négatif.');
    }

    public function testUneUniteBlancheVautPasDUnite(): void
    {
        $this->assertNull($this->farine()->setUniteAchat('  ')->getUniteAchat());
    }

    public function testLaSaisieSeConvertitEnMilliemesSansFlottant(): void
    {
        $transformateur = new MillimesTransformer();

        $this->assertSame(50000, $transformateur->reverseTransform('50'));
        $this->assertSame(2500, $transformateur->reverseTransform('2,5'));
        $this->assertSame(2125, $transformateur->reverseTransform('2.125'));
        $this->assertSame(1000000, $transformateur->reverseTransform('1 000'));
        $this->assertNull($transformateur->reverseTransform(''));
        $this->assertSame('2,5', $transformateur->transform(2500));
        $this->assertSame('50', $transformateur->transform(50000));
        $this->assertSame('', $transformateur->transform(null));
    }

    public function testUneSaisieIllisibleEstRefusee(): void
    {
        $this->expectException(TransformationFailedException::class);
        (new MillimesTransformer())->reverseTransform('2,5555');
    }
}
